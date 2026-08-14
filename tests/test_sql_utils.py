from agent.sql_utils import validate_sql


def test_rejects_multi_statement():
    ok, v = validate_sql("SELECT 1; SELECT 2")
    assert not ok
    assert "MULTI_STATEMENT_BLOCKED" in v


def test_rejects_dml():
    ok, v = validate_sql("DELETE FROM client")
    assert not ok
    assert "Delete" in v


def test_blocks_metadata_schema():
    ok, v = validate_sql("SELECT * FROM information_schema.tables")
    assert not ok
    assert any("METADATA_BLOCKED" in x for x in v)


def test_accepts_simple_select():
    ok, v = validate_sql("SELECT id FROM reservation")
    assert ok, v


def test_rejects_insert():
    ok, v = validate_sql("INSERT INTO client VALUES (1)")
    assert not ok
    assert any("Insert" in x for x in v)


def test_rejects_update():
    ok, v = validate_sql("UPDATE client SET name='x'")
    assert not ok
    assert any("Update" in x for x in v)


def test_rejects_drop():
    ok, v = validate_sql("DROP TABLE client")
    assert not ok
    assert any("Drop" in x for x in v)


def test_rejects_alter():
    ok, v = validate_sql("ALTER TABLE client ADD COLUMN x INT")
    assert not ok
    assert any("Alter" in x for x in v)


def test_rejects_create():
    ok, v = validate_sql("CREATE TABLE x (id INT)")
    assert not ok
    assert any("Create" in x for x in v)


def test_rejects_truncate():
    ok, v = validate_sql("TRUNCATE client")
    assert not ok
    assert any("Truncate" in x for x in v)


def test_rejects_outfile():
    ok, v = validate_sql("SELECT * INTO OUTFILE '/tmp/x' FROM client")
    assert not ok
    assert any("OUTFILE" in x for x in v)


def test_blocks_mysql_schema():
    ok, v = validate_sql("SELECT * FROM mysql.user")
    assert not ok
    assert any("METADATA_BLOCKED" in x for x in v)


def test_blocks_performance_schema():
    ok, v = validate_sql("SELECT * FROM performance_schema.events")
    assert not ok
    assert any("METADATA_BLOCKED" in x for x in v)


def test_blocks_sys_schema():
    ok, v = validate_sql("SELECT * FROM sys.config")
    assert not ok
    assert any("METADATA_BLOCKED" in x for x in v)


def test_empty_query():
    ok, v = validate_sql("")
    assert not ok
    assert "EMPTY_QUERY" in v


def test_multiple_violations():
    ok, v = validate_sql("DELETE FROM information_schema.tables")
    assert not ok
    assert len(v) >= 2
