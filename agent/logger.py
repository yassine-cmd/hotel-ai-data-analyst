"""Logging setup: structured JSON-like log format, contextvars for request-id
and client-id propagation, and audit-log file handler."""

import contextvars
import logging
import os
import sys


request_id_var: contextvars.ContextVar[str] = contextvars.ContextVar("request_id", default="-")
client_id_var: contextvars.ContextVar[str] = contextvars.ContextVar("client_id", default="-")
trace_id_var: contextvars.ContextVar[str] = contextvars.ContextVar("trace_id", default="-")


class ContextVarsLoggingFilter(logging.Filter):
    def filter(self, record: logging.LogRecord) -> bool:
        record.request_id = request_id_var.get()
        record.client_id = client_id_var.get()
        record.trace_id = trace_id_var.get()
        return True


_LOG_FORMAT = "%(asctime)s | %(levelname)-8s | %(name)s | req=%(request_id)s client=%(client_id)s trace=%(trace_id)s | %(message)s"
_LOG_DATEFMT = "%Y-%m-%d %H:%M:%S"


AUDIT_ENABLED = True
AUDIT_RETENTION_DAYS = 90
AUDIT_ALERT_THRESHOLD = 50


def setup_logging(audit_log_file: str = "logs/audit.log", log_level: str = "INFO", audit_enabled: bool = AUDIT_ENABLED):
    level = getattr(logging, log_level.upper(), logging.INFO)

    stdout_handler = logging.StreamHandler(sys.stdout)
    stdout_handler.setLevel(level)
    stdout_handler.setFormatter(logging.Formatter(_LOG_FORMAT, datefmt=_LOG_DATEFMT))
    stdout_handler.addFilter(ContextVarsLoggingFilter())

    logging.basicConfig(
        level=level,
        format=_LOG_FORMAT,
        datefmt=_LOG_DATEFMT,
        force=True,
        handlers=[stdout_handler],
    )

    if audit_enabled:
        audit_logger = logging.getLogger("audit")
        audit_logger.setLevel(logging.WARNING)
        audit_file = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", audit_log_file))
        try:
            os.makedirs(os.path.dirname(audit_file), exist_ok=True)
            fh = logging.FileHandler(audit_file, encoding="utf-8")
            fh.setLevel(logging.WARNING)
            fh.setFormatter(logging.Formatter(
                "%(asctime)s | %(message)s", datefmt="%Y-%m-%d %H:%M:%S"
            ))
            audit_logger.addHandler(fh)
            audit_logger.propagate = False
        except Exception as e:
            print(f"Warning: could not set up audit log file: {e}")

    for name in ("httpx", "httpcore", "openai", "PIL"):
        logging.getLogger(name).setLevel(logging.WARNING)

    logging.getLogger("uvicorn.access").setLevel(level)
    logging.getLogger("uvicorn.error").setLevel(logging.INFO)
