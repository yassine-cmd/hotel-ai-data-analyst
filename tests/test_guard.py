import pandas as pd

from agent.tools import SensitiveDataGuard, _parse_sql_safely


FAKE_SCHEMA = {
    "client": {
        "columns": [{"name": "id"}, {"name": "name"}, {"name": "ssn"}],
    },
    "reservation": {
        "columns": [
            {"name": "id"},
            {"name": "client_id"},
            {"name": "amount"},
            {"name": "secret"},
        ],
    },
}


def _make_guard():
    return SensitiveDataGuard(
        lambda: FAKE_SCHEMA,
        sensitive_tables=["client"],
        sensitive_columns={"client": ["ssn"], "*": ["secret"]},
    )


def test_check_access_blocks_sensitive_table():
    guard = _make_guard()
    allowed, blocked = guard.check_access("SELECT id FROM client")
    assert not allowed
    assert any("sensitive table 'client'" in b for b in blocked)


def test_check_access_blocks_sensitive_column_on_safe_table():
    guard = _make_guard()
    allowed, blocked = guard.check_access("SELECT id, secret FROM reservation")
    assert not allowed
    assert any("sensitive column 'reservation.secret'" in b for b in blocked)


def test_check_access_blocks_unresolvable_column():
    guard = _make_guard()
    allowed, blocked = guard.check_access("SELECT notacolumn FROM reservation")
    assert not allowed
    assert any("unresolvable column" in b for b in blocked)


def test_check_access_allows_clean_query():
    guard = _make_guard()
    allowed, blocked = guard.check_access("SELECT id, amount FROM reservation")
    assert allowed, blocked


def test_rewrite_sql_redacts_sensitive_column():
    guard = _make_guard()
    safe_sql, redacted = guard.rewrite_sql("SELECT id, secret FROM reservation")
    assert "secret" not in safe_sql
    assert "[SENSITIVE]" in safe_sql
    assert "reservation.secret" in redacted


def test_strip_columns_drops_sensitive_and_aliased():
    guard = _make_guard()
    df = pd.DataFrame({"id": [1], "secret": ["x"], "s": ["y"]})
    sql = "SELECT id, secret AS s FROM reservation"
    tree = _parse_sql_safely(sql)
    out = guard.strip_columns(df, sql, tree=tree)
    assert "secret" not in out.columns
    assert "s" not in out.columns
    assert "id" in out.columns


def test_alias_map_reuse_joined_query():
    guard = _make_guard()
    sql = "SELECT r.amount, c.ssn FROM reservation r JOIN client c ON r.client_id = c.id"
    tree = _parse_sql_safely(sql)
    alias_map = guard._build_alias_map(tree)

    allowed_a, blocked_a = guard.check_access(sql, tree=tree, alias_map=alias_map)
    allowed_b, blocked_b = guard.check_access(sql, tree=tree)
    assert (allowed_a, blocked_a) == (allowed_b, blocked_b)

    sql_a, red_a = guard.rewrite_sql(sql, tree=tree, alias_map=alias_map)
    sql_b, red_b = guard.rewrite_sql(sql, tree=tree)
    assert sql_a == sql_b
    assert red_a == red_b
    assert "c.ssn" not in sql_a
    assert "[SENSITIVE]" in sql_a


def test_rewrite_sql_star_expansion():
    guard = _make_guard()
    sql = "SELECT * FROM reservation"
    safe_sql, redacted = guard.rewrite_sql(sql)
    assert "secret" not in safe_sql


def test_check_access_sql_parse_fail():
    guard = _make_guard()
    allowed, blocked = guard.check_access("SELECT FROM WHERE")
    assert not allowed
    assert any("parse" in b.lower() for b in blocked)


def test_rewrite_sql_parse_fail_returns_original():
    guard = _make_guard()
    safe_sql, redacted = guard.rewrite_sql("SELECT FROM WHERE")
    assert safe_sql == "SELECT FROM WHERE"
    assert redacted == []


def test_strip_columns_none_tree():
    guard = _make_guard()
    df = pd.DataFrame({"id": [1], "ssn": ["x"]})
    out = guard.strip_columns(df, "SELECT id, ssn FROM client", tree=None)
    assert "ssn" not in out.columns
    assert "id" in out.columns


def test_accessible_columns_excludes_sensitive():
    guard = _make_guard()
    cols = guard.accessible_columns("client")
    assert "ssn" not in cols


def test_guard_callable_providers():
    from agent.tools.guard import SensitiveDataGuard
    schema = {"safe": {"columns": [{"name": "id"}, {"name": "secret_col"}]}}
    guard = SensitiveDataGuard(
        lambda: schema,
        sensitive_tables=lambda: set(),
        sensitive_columns=lambda: {"safe": ["secret_col"]},
    )
    allowed, blocked = guard.check_access("SELECT secret_col FROM safe")
    assert not allowed
    assert any("secret_col" in b for b in blocked)
    # also verify accessible columns filter works
    cols = guard.accessible_columns("safe")
    assert "secret_col" not in cols


def test_guard_static_providers():
    from agent.tools.guard import SensitiveDataGuard
    guard = SensitiveDataGuard(
        lambda: FAKE_SCHEMA,
        sensitive_tables=["client"],
        sensitive_columns={"client": ["ssn"]},
    )
    assert "client" in guard.sensitive_tables
