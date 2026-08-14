import os
import time
from pathlib import Path

import pandas as pd

from agent.asset_manager import AssetManager


def test_resolve_asset_path_found(tmp_path):
    am = AssetManager(assets_dir=str(tmp_path), ttl_hours=2)
    asset = tmp_path / "c1" / "s1" / "plots" / "chart.png"
    asset.parent.mkdir(parents=True)
    asset.write_text("data")
    result = am.resolve_asset_path("c1", "s1", "plots", "chart.png")
    assert result == asset.resolve()


def test_resolve_asset_path_not_found(tmp_path):
    am = AssetManager(assets_dir=str(tmp_path), ttl_hours=2)
    result = am.resolve_asset_path("c1", "s1", "plots", "nonexistent.png")
    assert result is None


def test_resolve_asset_path_traversal_blocked(tmp_path):
    am = AssetManager(assets_dir=str(tmp_path), ttl_hours=2)
    result = am.resolve_asset_path("c1", "s1", "..", "..\\.env")
    assert result is None


def test_resolve_asset_path_sibling_traversal_blocked(tmp_path):
    am = AssetManager(assets_dir=str(tmp_path), ttl_hours=2)
    (tmp_path / "secrets.txt").write_text("hack")
    result = am.resolve_asset_path("c1", "s1", "..", "secrets.txt")
    assert result is None


async def test_purge_expired_removes_orphan(tmp_path):
    am = AssetManager(assets_dir=str(tmp_path), ttl_hours=0)
    orphan = tmp_path / "c1" / "orphan" / "plot.png"
    orphan.parent.mkdir(parents=True)
    orphan.write_text("data")
    time.sleep(0.01)
    await am._purge_expired()
    assert not orphan.exists()


async def test_purge_expired_skips_live_session(tmp_path):
    am = AssetManager(assets_dir=str(tmp_path), ttl_hours=0)
    live_asset = tmp_path / "c1" / "live" / "plots" / "chart.png"
    live_asset.parent.mkdir(parents=True)
    live_asset.write_text("data")
    (tmp_path / "c1" / "live" / "session.json").write_text("{}")
    await am._purge_expired()
    assert live_asset.exists()


async def test_purge_expired_within_ttl(tmp_path):
    am = AssetManager(assets_dir=str(tmp_path), ttl_hours=24)
    asset = tmp_path / "c1" / "orphan" / "plot.png"
    asset.parent.mkdir(parents=True)
    asset.write_text("data")
    await am._purge_expired()
    assert asset.exists()


async def test_purge_expired_skips_live_session_dfs(tmp_path):
    # dfs parquet files for a LIVE session (session.json present) must never be
    # reaped by the orphan sweep — only by the size budget / session teardown.
    am = AssetManager(assets_dir=str(tmp_path), ttl_hours=0)
    df = pd.DataFrame({"x": [1, 2, 3]})
    await am.put("c1", "live", "df_a", df)
    (tmp_path / "c1" / "live" / "session.json").write_text("{}")
    time.sleep(0.01)
    await am._purge_expired()
    assert (tmp_path / "c1" / "live" / "dfs" / "df_a.parquet").exists()


async def test_delete_session_removes_whole_dir_with_assets(tmp_path):
    am = AssetManager(assets_dir=str(tmp_path), ttl_hours=2)
    await am.put("c1", "s1", "df_a", pd.DataFrame({"x": [1]}))
    plot = tmp_path / "c1" / "s1" / "plots" / "chart.png"
    plot.parent.mkdir(parents=True, exist_ok=True)
    plot.write_text("data")
    await am.delete_session("c1", "s1")
    assert not (tmp_path / "c1" / "s1").exists()


async def test_df_store_put_get_delete(tmp_path):
    am = AssetManager(assets_dir=str(tmp_path), ttl_hours=2)
    df = pd.DataFrame({"x": [1, 2], "y": ["a", "b"]})
    await am.put("c1", "s1", "df_a", df)
    loaded = await am.get("c1", "s1", "df_a")
    assert loaded is not None
    assert loaded.to_dict() == df.to_dict()
    await am.delete("c1", "s1", "df_a")
    assert await am.get("c1", "s1", "df_a") is None
    assert not (tmp_path / "c1" / "s1" / "dfs" / "df_a.parquet").exists()


async def test_df_store_size_of_missing(tmp_path):
    am = AssetManager(assets_dir=str(tmp_path), ttl_hours=2)
    assert am.size_of("c1", "s1") == 0
    assert am.size_of("c1", "s1", "no_such") == 0
