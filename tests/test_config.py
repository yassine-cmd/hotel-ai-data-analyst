import pytest
from config import Settings, get_settings


def test_settings_defaults():
    s = Settings()
    assert s.LOG_LEVEL == "INFO"
    # Session lifetimes: memory eviction is short & lossless; disk retention
    # (the only destructive TTL) is business-safe and env-configurable.
    assert s.SESSION_IDLE_MEMORY_MINUTES == 30
    assert s.SESSION_RETENTION_DAYS == 90


def test_settings_can_override():
    s = Settings(LOG_LEVEL="DEBUG")
    assert s.LOG_LEVEL == "DEBUG"
    assert s.SESSION_IDLE_MEMORY_MINUTES == 30
    assert s.SESSION_RETENTION_DAYS == 90
    t = Settings(SESSION_IDLE_MEMORY_MINUTES=10, SESSION_RETENTION_DAYS=7)
    assert t.SESSION_IDLE_MEMORY_MINUTES == 10
    assert t.SESSION_RETENTION_DAYS == 7


def test_get_settings_validates():
    s = get_settings()
    assert s.LLM_API_KEY == "test_key"
    assert len(s.LLM_BASE_URL) > 0


def test_get_settings_fails_without_llm_api_key(monkeypatch):
    from config import get_settings
    get_settings.cache_clear()
    monkeypatch.setenv("LLM_API_KEY", "")
    with pytest.raises(ValueError, match="LLM_API_KEY"):
        get_settings()
