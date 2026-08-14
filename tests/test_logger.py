import logging
import os
import tempfile
from agent.logger import setup_logging, request_id_var, client_id_var, ContextVarsLoggingFilter


def test_setup_logging_creates_handlers():
    setup_logging(audit_log_file="logs/audit.log", log_level="INFO")
    root = logging.getLogger()
    assert any(isinstance(h, logging.StreamHandler) for h in root.handlers)


def test_setup_logging_audit_file_created():
    tmp = os.path.join(tempfile.gettempdir(), "test_logger_audit")
    os.makedirs(tmp, exist_ok=True)
    log_path = os.path.join(tmp, "audit.log")
    try:
        setup_logging(audit_log_file=log_path, log_level="INFO")
        assert os.path.exists(log_path)
    finally:
        try:
            os.unlink(log_path)
            os.rmdir(tmp)
        except Exception:
            pass


def test_context_vars_filter_injects_fields():
    request_id_var.set("req-1")
    client_id_var.set("client-1")
    f = ContextVarsLoggingFilter()

    class _Record:
        request_id = ""
        client_id = ""

    record = _Record()
    assert f.filter(record) is True
    assert record.request_id == "req-1"
    assert record.client_id == "client-1"


def test_setup_logging_audit_fallback_on_error():
    tmp = os.path.join(tempfile.gettempdir(), "test_logger_fallback")
    bad_path = os.path.join(tmp, "deep", "subdir", "audit.log")
    try:
        setup_logging(audit_log_file=bad_path, log_level="INFO")
        audit = logging.getLogger("audit")
        assert len(audit.handlers) > 0
        # Fallback should create a StreamHandler, not a FileHandler (since dir doesn't exist)
        assert any(isinstance(h, logging.StreamHandler) for h in audit.handlers)
    finally:
        try:
            import shutil
            shutil.rmtree(tmp, ignore_errors=True)
        except Exception:
            pass
