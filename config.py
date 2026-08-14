from typing import Optional
from pydantic import ConfigDict
from pydantic_settings import BaseSettings
from functools import lru_cache


PREINJECTED_PACKAGES = ("pd", "np", "math", "datetime")
BANNED_PACKAGES = ("scipy", "sklearn", "seaborn")


class Settings(BaseSettings):
    # Model / LLM Configuration (any OpenAI-compatible endpoint)
    LLM_API_KEY: str = ""
    LLM_BASE_URL: str = "https://openrouter.ai/api/v1"
    LLM_MODEL: str = "deepseek/deepseek-chat"
    LLM_TIMEOUT_SECONDS: float = 30.0
    LLM_MAX_TOKENS: int = 128000
    # Reasoning effort for thinking models. Accepted values are "low" | "high"
    # | "max" (DeepSeek V4 Flash; "medium"/"xhigh" map to "high", V4 Pro only
    # honors "high"/"max", "low" being a no-op there). OpenRouter's wire
    # contract additionally accepts "minimal" and "none". Lower effort trims
    # thinking tokens; "low" is a genuine reduced level only on DeepSeek V4 Flash.
    LLM_REASONING_EFFORT: str = "high"
    # DeepSeek V4 ignores temperature/top_p while thinking mode is enabled,
    # so LLM_TEMPERATURE / LLM_TOP_P are only sent when thinking is OFF
    # (see agent/llm.py). Keep them so non-thinking models still work.
    LLM_THINKING_ENABLED: bool = True
    LLM_TEMPERATURE: Optional[float] = 1.0
    LLM_TOP_P: Optional[float] = 1.0

    # Sandbox Configuration
    # Base URL (host only) that pool workers are reached at. Worker ports are
    # assigned dynamically per container, so this is the host prefix
    # (e.g. http://localhost) rather than a fixed port. Point this at another
    # host if the sandbox is ever moved to a separate machine.
    SANDBOX_URL: str = "http://localhost"
    SANDBOX_TIMEOUT: int = 30

    # Audit Configuration
    AUDIT_LOG_FILE: str = "logs/audit.log"

    # Session / Client Configuration
    SESSION_QUERY_LIMIT: int = 50
    SESSION_ROW_LIMIT: int = 10_000_000
    SESSION_TIME_LIMIT_SECONDS: float = 60.0
    SESSION_DATAFRAMES_MAX: int = 15
    # Size budget for persisted DataFrames (bytes). Oldest-first eviction keeps
    # total on-disk / in-memory DataFrame usage under this across a session.
    # 128 MiB default. SESSION_DATAFRAMES_MAX remains a prompt-hygiene count
    # ceiling only — this budget is the storage policy.
    SESSION_DF_BUDGET_BYTES: int = 134_217_728
    # How long an idle session's loaded working state (LLM context, tracker,
    # cached DataFrames) stays resident in Python memory before eviction.
    # Eviction is memory-only: the session record, conversation history and
    # DataFrames remain on disk and are lazily reloaded on the next turn or
    # history request. Short keeps RAM bounded; the only cost is a disk load.
    SESSION_IDLE_MEMORY_MINUTES: int = 30
    # How long a session survives on disk after its last activity before it is
    # permanently deleted (history, DataFrames, working context). This is the
    # ONLY destructive session TTL — set it to a business-safe retention period.
    SESSION_RETENTION_DAYS: int = 90
    RECENT_ERRORS_MAX: int = 5

    # Business facts injected into the trailing [ENVIRONMENT FACTS] block of
    # the system prompt (prompts/system_prompt.md). Single source of truth so
    # the prompt can never drift from config.
    DATA_SINCE: str = "2025-03"
    CURRENCY: str = "MAD"

    # Agent loop configuration
    AGENT_MAX_STEPS: int = 20
    AGENT_TOOL_TIMEOUT: int = 120

    # Context window budget (fraction of model context_limit)
    CONTEXT_LIMIT: int = 200_000
    CONTEXT_LIVE_FRACTION: float = 0.15
    CONTEXT_PERSIST_FRACTION: float = 0.03
    CONTEXT_SUMMARIZE_THRESHOLD: int = 32000

    # Backend Selection (for future production use)
    CLIENT_STORE_BACKEND: str = "LOCAL"
    STORAGE_BACKEND: str = "LOCAL"

    # Sandbox Worker Pool Configuration
    # Self-hosted pool of disposable Docker workers. Each execution runs in a
    # fresh container that is destroyed afterwards ("bucket of knives"); the
    # pool keeps SANDBOX_POOL_MIN warm and scales up to a resource-derived
    # ceiling (no arbitrary hard cap).
    SANDBOX_IMAGE: str = "code-sandbox:latest"
    SANDBOX_POOL_MIN: int = 2
    # Memory (in GiB) reserved for the host app + OS; the live-worker ceiling is
    # derived from (host MemTotal - this headroom) / SANDBOX_MEM_LIMIT.
    SANDBOX_HOST_HEADROOM_GIB: float = 1.0
    SANDBOX_MEM_LIMIT: str = "512m"
    SANDBOX_CPUS: float = 1.0
    # Sandbox workers run inside Docker; 9000 is the canonical container-internal
    # port (sandbox = Docker, not host-bound — no web/MySQL conflict). Host side
    # publishing is dynamic per container.
    SANDBOX_PORT: int = 9000
    SANDBOX_CONTAINER_READY_TIMEOUT: int = 30
    # Docker network for sandbox workers. When non-empty, workers join this
    # network and are reached by container name (no port mapping). Leave
    # empty for port-mapping mode (native Windows dev with Docker Desktop).
    SANDBOX_NETWORK: str = ""
    # Optional explicit cap on live workers. If set, it is clamped to the
    # resource-derived ceiling (a warning is logged if it exceeds it).
    SANDBOX_POOL_MAX_OVERRIDE: Optional[int] = None
    # If True, the pool will NOT attempt to build the image when it is missing;
    # startup fails loudly instead. Set on hosts where a pre-built image is
    # provided (e.g. transferred via `docker load`) or where builds are air-gapped.
    SANDBOX_SKIP_BUILD: bool = False
    # If True, force a rebuild at startup even when the image already exists.
    # Enable temporarily so Dockerfile / requirements.txt changes take effect
    # (the pool otherwise only builds when the image is missing). Default OFF:
    # leaving it on would rebuild on every boot.
    SANDBOX_REBUILD: bool = False

    # Session store root: holds per-session metadata (session.json) AND assets
    # (plots, CSVs) under <SESSION_DIR>/<client_id>/<session_id>/.
    SESSION_DIR: str = "./sessions"
    ASSETS_TTL_HOURS: int = 2
    ASSETS_BASE_URL: str = ""

    # Application Configuration
    # Python is an internal orchestration service: Laravel is the sole public
    # entry point and reaches it over loopback. Keep APP_HOST on 127.0.0.1 so
    # the agent is never reachable from the network. Override only behind a
    # private network / firewall.
    APP_HOST: str = "127.0.0.1"
    APP_PORT: int = 8000
    # Uvicorn auto-reload (hot-reload on file change). OFF by default: reload
    # mode spawns a watcher that kills the worker on restart, which can skip the
    # lifespan shutdown and leak sandbox containers. Enable only for active dev.
    UVICORN_RELOAD: bool = False
    LOG_LEVEL: str = "INFO"

    # Instance authentication (Ed25519). Each Laravel instance owns a keypair;
    # the private key lives in its .env, the public key is registered in the
    # shared DB (clients.public_key) and loaded into Python's KeyStore.
    #
    # ADMIN_LARAVEL_URL: a Laravel instance Python can reach to fetch the client
    #   public-key registry (GET /api/internal/public-keys). Loopback-only.
    ADMIN_LARAVEL_URL: str = "http://127.0.0.1:80"
    # ADMIN_PUBLIC_KEY: the admin Laravel instance's Ed25519 public key (hex). Python
    #   uses this to verify admin API calls (/admin/keys/*). Same value that instance
    #   holds the private key for. Empty disables admin API.
    ADMIN_PUBLIC_KEY: str = ""
    # How often (seconds) Python refreshes its client-key cache from Laravel.
    PUBLIC_KEYS_REFRESH_SEC: int = 60

    # System audit event forwarding. Python relays its own events plus those
    # reported by client Laravel instances to the admin instance's daily
    # system-audit log file (ADMIN_LARAVEL_URL/api/internal/events).
    # EVENT_FORWARD_ENABLED toggles relaying; when off, events are dropped.
    EVENT_FORWARD_ENABLED: bool = True
    # Anti-spam window: identical events (same event name + client_id) are
    # forwarded at most once per this many seconds. 0 disables throttling.
    EVENT_FORWARD_THROTTLE_SECONDS: float = 60.0

    # Ed25519 signature replay window (seconds) for Laravel<->Python signed
    # requests. MUST match Laravel's SIGNATURE_MAX_AGE (config/python-proxy.php,
    # default 300). Kept here so the two sides share one documented default.
    SIGNATURE_MAX_AGE_SEC: int = 300

    # Prompt diagnostics (Phase 0a): zone token breakdown + first-differing-byte
    ENABLE_PROMPT_DIAGNOSTICS: bool = False

    model_config = ConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=True,
    )


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    try:
        settings = Settings()
        if not settings.LLM_API_KEY:
            raise ValueError("LLM_API_KEY is required")
        return settings
    except Exception as e:
        raise ValueError(f"Configuration error: {str(e)}")
