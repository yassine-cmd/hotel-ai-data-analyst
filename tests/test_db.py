import asyncio
import pandas as pd
import pytest

from agent.db import Budget, BudgetExhausted, compile_ddl, _types_compatible, _validate_virtual_fk


# ── Budget ──────────────────────────────────────────────────────────────────


async def test_budget_check_pass():
    b = Budget(query_limit=5, row_limit=1000, time_limit_seconds=60)
    await b.check()
    assert b.queries_used == 0
    assert b.rows_fetched == 0


async def test_budget_exhausted_query_limit():
    b = Budget(query_limit=2, row_limit=1000, time_limit_seconds=60)
    await b.deduct(0, 0)
    await b.deduct(0, 0)
    with pytest.raises(BudgetExhausted, match="query budget"):
        await b.check()


async def test_budget_exhausted_row_limit():
    b = Budget(query_limit=100, row_limit=10, time_limit_seconds=60)
    await b.deduct(10, 0)
    with pytest.raises(BudgetExhausted, match="row budget"):
        await b.check()


async def test_budget_exhausted_time_limit():
    b = Budget(query_limit=100, row_limit=100000, time_limit_seconds=0.001)
    await b.deduct(1, 10)
    with pytest.raises(BudgetExhausted, match="time budget"):
        await b.check()


async def test_budget_exhausted_stays_exhausted():
    b = Budget(query_limit=1, row_limit=1000, time_limit_seconds=60)
    await b.deduct(0, 0)
    with pytest.raises(BudgetExhausted):
        await b.check()
    with pytest.raises(BudgetExhausted):
        await b.check()


async def test_budget_concurrent_deduct_safety():
    b = Budget(query_limit=100, row_limit=100000, time_limit_seconds=60)

    async def hammer():
        await asyncio.gather(*[b.deduct(1, 10) for _ in range(50)])

    await hammer()
    assert b.queries_used == 50
    assert b.rows_fetched == 50
    assert b.time_used == pytest.approx(0.5, rel=0.1)


async def test_budget_summary():
    b = Budget(query_limit=20, row_limit=100, time_limit_seconds=30)
    await b.deduct(5, 1000)
    s = b.summary
    assert "q=1/20" in s
    assert "rows=5/100" in s


# ── Types compatible ────────────────────────────────────────────────────────


def test_types_compatible_exact():
    assert _types_compatible("int", "int") is True


def test_types_compatible_numeric_family():
    assert _types_compatible("int", "decimal") is True
    assert _types_compatible("float", "double") is True


def test_types_compatible_string_family():
    assert _types_compatible("varchar", "text") is True


def test_types_compatible_date_family():
    assert _types_compatible("date", "datetime") is True


def test_types_compatible_mismatch():
    assert _types_compatible("int", "varchar") is False


# ── Virtual FK validation ───────────────────────────────────────────────────


def _simple_schema():
    return {
        "client": {
            "columns": [{"name": "id", "type": "int"}, {"name": "name", "type": "varchar"}],
        },
        "reservation": {
            "columns": [{"name": "id", "type": "int"}, {"name": "client_id", "type": "int"}],
        },
    }


def _all_schemas():
    return _simple_schema()


def test_validate_virtual_fk_ok(caplog):
    _validate_virtual_fk("reservation", "client_id", "client", "id",
                         _simple_schema()["reservation"], _all_schemas(), set(), {})
    # No warning should be emitted for a valid FK
    assert not any("not found in live schema" in r.message for r in caplog.records)
    assert not any("sensitive table" in r.message.lower() for r in caplog.records)
    assert not any("type mismatch" in r.message for r in caplog.records)


def test_validate_virtual_fk_missing_ref_table(caplog):
    _validate_virtual_fk("reservation", "client_id", "ghost", "id",
                         _simple_schema()["reservation"], _all_schemas(), set(), {})
    assert any("not found in live schema" in r.message for r in caplog.records)


def test_validate_virtual_fk_sensitive_ref_table_blocked(caplog):
    _validate_virtual_fk("reservation", "client_id", "client", "id",
                         _simple_schema()["reservation"], _all_schemas(), {"client"}, {})
    assert any("sensitive table" in r.message.lower() for r in caplog.records)


def test_validate_virtual_fk_type_mismatch(caplog):
    schema = {
        "client": {"columns": [{"name": "id", "type": "varchar"}, {"name": "name", "type": "varchar"}]},
        "reservation": {"columns": [{"name": "id", "type": "int"}, {"name": "client_id", "type": "int"}]},
    }
    _validate_virtual_fk("reservation", "client_id", "client", "id",
                         schema["reservation"], schema, set(), {})
    assert any("type mismatch" in r.message for r in caplog.records)


# ── DDL compilation ─────────────────────────────────────────────────────────


def test_compile_ddl():
    schema = {
        "client": {
            "columns": [{"name": "id", "type": "int", "key": "PRI", "description": None, "is_sensitive": None},
                        {"name": "name", "type": "varchar", "key": "", "description": "full name", "is_sensitive": None}],
            "foreign_keys": [],
            "description": "people table",
            "row_count": 100,
        }
    }
    ddl = compile_ddl(schema)
    assert "TABLE: client" in ddl
    assert "people table" in ddl
    assert "[PK]" in ddl
    assert "full name" in ddl


def test_compile_ddl_sensitive_marker():
    schema = {
        "secret": {
            "columns": [{"name": "id", "type": "int", "key": "PRI", "description": None, "is_sensitive": None}],
            "foreign_keys": [],
            "is_sensitive": True,
        }
    }
    ddl = compile_ddl(schema)
    assert "[SENSITIVE]" in ddl


def test_compile_ddl_fk():
    schema = {
        "reservation": {
            "columns": [{"name": "id", "type": "int", "key": "PRI", "description": None, "is_sensitive": None}],
            "foreign_keys": [{"column": "client_id", "ref_table": "client", "ref_col": "id"}],
        }
    }
    ddl = compile_ddl(schema)
    assert "client_id" in ddl
    assert "client" in ddl
    assert "1→many" in ddl


def test_compile_ddl_empty():
    assert compile_ddl({}) == ""


# ── Compile DDL edge cases ──────────────────────────────────────────────────


def test_compile_ddl_virtual_fk_marker():
    schema = {
        "a": {
            "columns": [{"name": "id", "type": "int", "key": "PRI", "description": None, "is_sensitive": None}],
            "foreign_keys": [{"column": "b_id", "ref_table": "b", "ref_col": "id", "_virtual": True}],
        }
    }
    ddl = compile_ddl(schema)
    assert "virtual" in ddl.lower() or "[virtual" in ddl


def test_compile_ddl_enum_values():
    schema = {
        "t": {
            "columns": [{"name": "status", "type": "enum", "key": "", "description": None, "is_sensitive": None,
                         "enum": "'a','b'"}],
            "foreign_keys": [],
        }
    }
    ddl = compile_ddl(schema)
    assert "a" in ddl
    assert "b" in ddl


def test_compile_ddl_value_mappings():
    schema = {
        "t": {
            "columns": [{"name": "flag", "type": "int", "key": "", "description": None, "is_sensitive": None,
                         "values": {0: "off", 1: "on"}}],
            "foreign_keys": [],
        }
    }
    ddl = compile_ddl(schema)
    assert "off" in ddl
    assert "on" in ddl



