import asyncio
import json
from datetime import datetime, timedelta

import pandas as pd

from agent.session_manager import SessionManager
from agent.asset_manager import AssetManager
from agent.context import Context
from agent.core import TokenCounter


_DEFAULT_CID = "c1"


async def test_commit_turn_persists_once(tmp_path):
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60)
    calls = []
    sm._persist = lambda key, entry: calls.append(key)

    async def go():
        await sm.get_or_create("s1", client_id=_DEFAULT_CID)
        return await sm.commit_turn(
            "s1", {"query": "q"}, increment=True,
            context=None, tracker=None, dataframes={}, budget=None,
            client_id=_DEFAULT_CID,
        )

    count = await go()
    assert count == 1
    assert len(calls) == 1


async def test_commit_turn_history_capped(tmp_path):
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60)

    async def go():
        await sm.get_or_create("s1", client_id=_DEFAULT_CID)
        for i in range(sm.MAX_HISTORY_TURNS + 5):
            await sm.commit_turn("s1", {"query": str(i)}, increment=False, client_id=_DEFAULT_CID)
        return sm._sessions[sm._key("s1", _DEFAULT_CID)]["history"]

    history = await go()
    assert len(history) == sm.MAX_HISTORY_TURNS


async def test_get_or_create_new_vs_existing(tmp_path):
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60)

    async def go():
        a = await sm.get_or_create("s1", client_id=_DEFAULT_CID)
        b = await sm.get_or_create("s1", client_id=_DEFAULT_CID)
        return a, b

    a, b = await go()
    assert a is b
    assert a["turn_count"] == 0


async def test_commit_turn_increment(tmp_path):
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60)

    async def go():
        await sm.get_or_create("s1", client_id=_DEFAULT_CID)
        await sm.commit_turn("s1", {"query": "q"}, increment=True, client_id=_DEFAULT_CID)
        await sm.commit_turn("s1", {"query": "q2"}, increment=True, client_id=_DEFAULT_CID)
        return sm._sessions[sm._key("s1", _DEFAULT_CID)]["turn_count"]

    assert await go() == 2


async def test_dataframe_storage_roundtrip(tmp_path):
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60)
    df = pd.DataFrame({"x": [1, 2, 3], "y": ["a", "b", "c"]})

    async def go():
        await sm.get_or_create("s1", client_id=_DEFAULT_CID)
        await sm.store.put(_DEFAULT_CID, "s1", "df_a", df)
        loaded = await sm.store.get(_DEFAULT_CID, "s1", "df_a")
        await sm.store.put(_DEFAULT_CID, "s1", "df_b", pd.DataFrame({"z": [4]}))
        names = await sm.store.list(_DEFAULT_CID, "s1")
        return loaded, names

    loaded, names = await go()
    assert loaded is not None
    assert loaded.to_dict() == df.to_dict()
    assert "df_a" in names
    assert "df_b" in names


async def test_df_store_miss_returns_none(tmp_path):
    store = AssetManager(assets_dir=str(tmp_path))

    async def go():
        return await store.get("c1", "nonexistent", "no_such_df")

    assert await go() is None


async def test_df_store_rejects_unsafe_names(tmp_path):
    store = AssetManager(assets_dir=str(tmp_path))
    df = pd.DataFrame({"x": [1]})

    async def go():
        for bad in ["../../etc/passwd", "a" * 200, "", ".hidden"]:
            try:
                await store.put("c1", "s1", bad, df)
            except ValueError:
                continue
            raise AssertionError(f"Expected ValueError for name={bad!r}")
        # Safe names should work
        await store.put("c1", "s1", "safe_name", df)
        await store.put("c1", "s1", "safe.name-v2", df)

    await go()


async def test_df_store_delete_session_removes_all(tmp_path):
    store = AssetManager(assets_dir=str(tmp_path))
    df = pd.DataFrame({"x": [1]})

    async def go():
        await store.put("c1", "s1", "df_a", df)
        await store.put("c1", "s2", "df_b", df)
        assert await store.get("c1", "s1", "df_a") is not None
        await store.delete_session("c1", "s1")
        assert await store.get("c1", "s1", "df_a") is None
        # Other session's cache is unaffected
        assert await store.get("c1", "s2", "df_b") is not None
        # Only c1:s1 entries removed from cache
        assert "c1:s1/df_a" not in store._cache
        assert "c1:s2/df_b" in store._cache

    await go()


async def test_df_store_evict_is_physical(tmp_path):
    store = AssetManager(assets_dir=str(tmp_path))
    df = pd.DataFrame({"x": [1]})

    async def go():
        await store.put("c1", "s1", "df_a", df)
        store.evict("c1", "s1", "df_a")
        # Evict removes the hot-cache entry AND the disk file together
        assert "c1:s1/df_a" not in store._cache
        assert not (tmp_path / "c1" / "s1" / "dfs" / "df_a.parquet").exists()
        return await store.get("c1", "s1", "df_a")

    assert await go() is None, "evict must physically delete file + cache"


async def test_df_store_size_of(tmp_path):
    store = AssetManager(assets_dir=str(tmp_path))
    df = pd.DataFrame({"x": list(range(100))})

    async def go():
        await store.put("c1", "s1", "df_a", df)
        total = store.size_of("c1", "s1")
        single = store.size_of("c1", "s1", "df_a")
        return total, single

    total, single = await go()
    assert single > 0
    assert total == single


async def test_df_store_concurrency_smoke(tmp_path):
    store = AssetManager(assets_dir=str(tmp_path))
    df_a = pd.DataFrame({"x": [1]})

    async def go():
        c, s = "c1", "s1"

        async def writer():
            for i in range(10):
                await store.put(c, s, f"df_{i}", df_a)

        async def reader():
            all_good = True
            for _ in range(10):
                names = await store.list(c, s)
                for n in names:
                    df = await store.get(c, s, n)
                    if df is not None and not df.equals(df_a):
                        all_good = False
            return all_good

        writer_task = asyncio.create_task(writer())
        reader_task = asyncio.create_task(reader())
        await writer_task
        data_ok = await reader_task
        # Verify final state on disk
        all_names = await store.list(c, s)
        return all_names, data_ok

    all_names, data_ok = await go()
    assert data_ok, "concurrent reads returned corrupt data"
    assert len(all_names) == 10


async def test_purge_expired_skips_active(tmp_path):
    # ttl_minutes=60 = memory idle; retention_days=0 so idle DISK-only dirs
    # (no session.json in memory) are deleted by the destructive retention sweep.
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60, retention_days=0)
    base = sm._base_dir

    async def setup():
        await sm.get_or_create("live", client_id="c1")
    await setup()

    expired_dir = base / "c1" / "expired"
    expired_dir.mkdir(parents=True, exist_ok=True)
    (expired_dir / "session.json").write_text(
        json.dumps({
            "turn_count": 1,
            "history": [],
            "created_at": (datetime.now() - timedelta(hours=2)).isoformat(),
            "last_access": (datetime.now() - timedelta(hours=2)).isoformat(),
        }),
        encoding="utf-8",
    )
    other_dir = base / "c2" / "expired2"
    other_dir.mkdir(parents=True, exist_ok=True)
    (other_dir / "session.json").write_text(
        json.dumps({
            "turn_count": 1,
            "history": [],
            "created_at": (datetime.now() - timedelta(hours=2)).isoformat(),
            "last_access": (datetime.now() - timedelta(hours=2)).isoformat(),
        }),
        encoding="utf-8",
    )

    async def purge():
        await sm._purge_expired()
    await purge()

    assert (base / "c1" / "live").exists()
    assert sm._key("live", "c1") in sm._sessions
    assert not expired_dir.exists()
    assert not other_dir.exists()


async def test_memory_eviction_is_lossless_and_disk_survives(tmp_path):
    """Idle loaded state is evicted from RAM but the conversation, context and
    dataframes stay on disk and are lazily reloaded — no data loss."""
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=0, retention_days=90)

    await sm.get_or_create("s1", client_id=_DEFAULT_CID)
    await sm.commit_turn("s1", {"query": "first", "answer": "one"}, increment=True, client_id=_DEFAULT_CID)
    # Age the loaded entry past the memory idle so it qualifies for eviction.
    sm._sessions[sm._key("s1", _DEFAULT_CID)]["last_access"] = datetime.now() - timedelta(minutes=30)

    await sm._purge_expired()

    # Evicted from memory…
    assert sm._key("s1", _DEFAULT_CID) not in sm._sessions
    # …but still on disk, and reloadable.
    assert (sm._base_dir / _DEFAULT_CID / "s1" / "session.json").exists()
    history = await sm.get_history("s1", client_id=_DEFAULT_CID)
    assert [t["query"] for t in history] == ["first"]
    assert sm._key("s1", _DEFAULT_CID) in sm._sessions, "reload must re-cache the entry"


async def test_disk_retention_deletes_only_after_window(tmp_path):
    """The destructive disk TTL is retention_days: disk-only sessions are kept
    within the window and deleted only once idle past it."""
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60, retention_days=1)
    base = sm._base_dir

    def seed(sid, cid, age):
        d = base / cid / sid
        d.mkdir(parents=True, exist_ok=True)
        la = (datetime.now() - timedelta(days=age)).isoformat()
        (d / "session.json").write_text(
            json.dumps({"turn_count": 1, "history": [], "created_at": la, "last_access": la}),
            encoding="utf-8",
        )

    seed("old", "c1", 2)       # past 1-day retention -> deleted
    seed("recent", "c1", 0.1)  # within retention -> kept
    seed("old2", "c2", 5)      # past retention on another client -> deleted

    await sm._purge_expired()

    assert not (base / "c1" / "old").exists()
    assert (base / "c1" / "recent").exists()
    assert not (base / "c2" / "old2").exists()


async def test_disk_retention_skips_memory_resident_sessions(tmp_path):
    """A loaded session is never deleted from disk even when its last_access
    exceeds both windows: the memory object (recently touched) is authoritative."""
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60, retention_days=0)
    await sm.get_or_create("keep", client_id=_DEFAULT_CID)
    await sm.commit_turn("keep", {"query": "q", "answer": "a"}, increment=True, client_id=_DEFAULT_CID)
    # Keep it resident (last_access fresh) — idle memory sweep must not evict.
    sm._sessions[sm._key("keep", _DEFAULT_CID)]["last_access"] = datetime.now()

    await sm._purge_expired()

    assert sm._key("keep", _DEFAULT_CID) in sm._sessions
    assert (sm._base_dir / _DEFAULT_CID / "keep" / "session.json").exists()


async def test_get_history_after_disk_resume(tmp_path):
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60)
    turns = [{"query": "first", "answer": "one"}, {"query": "second", "answer": "two"}]

    async def write():
        await sm.get_or_create("s1", client_id=_DEFAULT_CID)
        for turn in turns:
            await sm.commit_turn("s1", turn, increment=True, client_id=_DEFAULT_CID)
        sm._sessions.pop(sm._key("s1", _DEFAULT_CID))

    async def read():
        history = await sm.get_history("s1", client_id=_DEFAULT_CID)
        cached = sm._sessions.get(sm._key("s1", _DEFAULT_CID))
        return history, cached

    await write()
    history, cached = await read()
    assert [t["query"] for t in history] == ["first", "second"]
    assert [t["answer"] for t in history] == ["one", "two"]
    assert cached is not None, "get_history must re-cache the session entry"


async def test_get_history_empty_for_missing_session(tmp_path):
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60)

    async def go():
        return await sm.get_history("no_such", client_id=_DEFAULT_CID)

    assert await go() == []


async def test_resume_from_disk_rebuilds_context(tmp_path):
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60)

    async def write():
        await sm.get_or_create("s1", client_id=_DEFAULT_CID)
        ctx = Context(system_prompt="sys", user_content="orig")
        ctx.append_user_query("follow-up")
        tracker = TokenCounter(total_prompt=42)
        await sm.commit_turn(
            "s1", {"query": "q"}, increment=True,
            context=ctx, tracker=tracker, dataframes={}, budget=None,
            client_id=_DEFAULT_CID,
        )
        sm._sessions.pop(sm._key("s1", _DEFAULT_CID))

    async def read():
        entry = await sm.get_or_create("s1", client_id=_DEFAULT_CID)
        return entry

    await write()
    entry = await read()
    assert entry["context"] is not None
    assert isinstance(entry["context"], Context)
    assert any(m.get("content") == "orig" for m in entry["context"].messages)
    assert entry["turn_count"] == 1


async def test_record_error_then_get_session_error(tmp_path):
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60)

    async def go():
        await sm.get_or_create("s1", client_id=_DEFAULT_CID)
        await sm.record_error("s1", _DEFAULT_CID, {
            "code": "STREAM_ERROR", "message": "m", "retryable": True, "query": "q",
        })
        return await sm.get_session_error("s1", _DEFAULT_CID)

    error = await go()
    assert error == {
        "code": "STREAM_ERROR", "message": "m", "retryable": True, "query": "q",
    }


async def test_session_error_survives_memory_eviction(tmp_path):
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60)

    async def write():
        await sm.get_or_create("s1", client_id=_DEFAULT_CID)
        await sm.record_error("s1", _DEFAULT_CID, {
            "code": "STREAM_ERROR", "message": "m", "retryable": True, "query": "q",
        })
        sm._sessions.pop(sm._key("s1", _DEFAULT_CID))

    async def read():
        return await sm.get_session_error("s1", _DEFAULT_CID)

    await write()
    assert (await read())["code"] == "STREAM_ERROR"


async def test_successful_turn_clears_session_error(tmp_path):
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60)

    async def go():
        await sm.get_or_create("s1", client_id=_DEFAULT_CID)
        await sm.record_error("s1", _DEFAULT_CID, {
            "code": "STREAM_ERROR", "message": "m", "retryable": True, "query": "q",
        })
        await sm.commit_turn(
            "s1", {"query": "q", "answer": "ok"}, increment=True,
            context=None, tracker=None, dataframes={}, budget=None,
            client_id=_DEFAULT_CID,
        )
        return await sm.get_session_error("s1", _DEFAULT_CID)

    assert await go() is None
