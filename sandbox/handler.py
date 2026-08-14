import json
import io
import difflib
import contextlib
import traceback
import base64
import math
import datetime
import logging
import threading
import builtins
from http.server import HTTPServer, BaseHTTPRequestHandler
from typing import Any, Dict
import pandas as pd
pd.set_option('display.max_rows', 15)
pd.set_option('display.max_columns', 10)
pd.set_option('display.width', 120)
import numpy as np

logger = logging.getLogger("sandbox")


EXEC_GLOBALS = {
    "pd": pd,
    "np": np,
    "math": math,
    "datetime": datetime,
}

# Restricted builtins — removes exec, eval, compile to prevent accidental
# system access. __import__ is intentionally KEPT: removing it breaks any
# `import` executed in the sandbox frame (CPython resolves IMPORT_NAME through
# the frame's __builtins__), including numpy/pandas internal lazy loads —
# exactly the subordinate package this sandbox is built to serve. Real
# isolation is enforced by Docker + static code validation on the host
# (agent/tools/python_tool.py), not by surgically removing __import__.
_RESTRICTED = {'exec', 'eval', 'compile'}
_SAFE_BUILTINS: dict = {}
for _name in dir(builtins):
    _val = getattr(builtins, _name)
    if _name in _RESTRICTED:
        continue
    _SAFE_BUILTINS[_name] = _val

# Coarse-grained execution lock to ensure stdout captures don't overlap
_execution_lock = threading.Lock()


class ExecuteHandler(BaseHTTPRequestHandler):
    def _read_json_body(self) -> dict:
        content_length = int(self.headers.get('Content-Length') or 0)
        if content_length <= 0:
            raise ValueError("Missing or empty request body")
        body = self.rfile.read(content_length)
        try:
            return json.loads(body.decode('utf-8'))
        except (UnicodeDecodeError, json.JSONDecodeError) as e:
            raise ValueError(f"Invalid JSON body: {e}") from e

    def _send_json(self, data: dict, status: int = 200) -> None:
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(json.dumps(data).encode('utf-8'))

    def do_POST(self):
        if self.path == '/execute':
            try:
                payload = self._read_json_body()
                code = payload.get('code', '')
                dataframes_b64: Dict[str, str] = payload.get('dataframes', {})

                logger.info("Request | code=%d chars | dataframes=%d", len(code), len(dataframes_b64))

                # Coarse-grained execution lock ensures thread-safety over the global stdout capture
                with _execution_lock:
                    exec_globals = dict(EXEC_GLOBALS)
                    exec_globals["__builtins__"] = _SAFE_BUILTINS

                    # Deserialize and inject incoming DataFrames
                    for name, parquet_b64 in dataframes_b64.items():
                        if name in EXEC_GLOBALS or name == "__builtins__":
                            raise ValueError(f"DataFrame name '{name}' conflicts with a reserved sandbox variable")
                        if isinstance(parquet_b64, str):
                            try:
                                parquet_bytes = base64.b64decode(parquet_b64)
                                exec_globals[name] = pd.read_parquet(io.BytesIO(parquet_bytes))
                            except Exception as e:
                                logger.warning("Failed to load DataFrame '%s': %s", name, e)

                    stdout_capture = io.StringIO()

                    try:
                        compiled_code = compile(code, "<string>", "exec")
                        with contextlib.redirect_stdout(stdout_capture):
                            exec(compiled_code, exec_globals)

                        raw_output = stdout_capture.getvalue()
                        max_out_len = 100000
                        if len(raw_output) > max_out_len:
                            output = (
                                raw_output[:max_out_len // 2]
                                + f"\n\n... [TRUNCATED {len(raw_output) - max_out_len} CHARS OF STDOUT] ...\n\n"
                                + raw_output[-max_out_len // 2:]
                            )
                        else:
                            output = raw_output

                        result = {
                            'status': 'success',
                            'output': output,
                            'error': None
                        }
                    except (KeyError, AttributeError) as e:
                        output = stdout_capture.getvalue()[:100000]
                        error_msg = str(e)

                        suggestions = set()
                        for name, val in exec_globals.items():
                            if isinstance(val, pd.DataFrame):
                                for keyword in error_msg.split("'"):
                                    kw = keyword.strip()
                                    if kw and len(kw) > 1 and kw not in val.columns:
                                        matches = difflib.get_close_matches(kw, list(val.columns), n=1, cutoff=0.4)
                                        if matches:
                                            suggestions.add(f"Did you mean '{matches[0]}' in DataFrame '{name}'?")
                                            if len(suggestions) >= 3:
                                                break
                                if len(suggestions) >= 3:
                                    break

                        if suggestions:
                            error_msg += "\n" + "\n".join(list(suggestions)[:3])

                        result = {
                            'status': 'error',
                            'output': output,
                            'error': error_msg,
                            'traceback': traceback.format_exc()
                        }
                        logger.warning("Exec KeyError/AttributeError: %s", error_msg[:200])
                    except Exception as e:
                        output = stdout_capture.getvalue()[:100000]
                        result = {
                            'status': 'error',
                            'output': output,
                            'error': str(e),
                            'traceback': traceback.format_exc()
                        }
                        logger.error("Exec failed: %s", str(e)[:200])
                    finally:
                        # Serialize ALL DataFrames back (including injected ones
                        # that may have been modified by the code). The client
                        # replaces its store with whatever comes back.
                        builtin_names = set(EXEC_GLOBALS.keys())
                        returned_data = {}
                        for var_name, var_val in exec_globals.items():
                            if var_name in builtin_names:
                                continue
                            if isinstance(var_val, pd.DataFrame):
                                try:
                                    buf = io.BytesIO()
                                    var_val.to_parquet(buf, index=False)
                                    returned_data[var_name] = base64.b64encode(buf.getvalue()).decode('utf-8')
                                except Exception as e:
                                    logger.warning("Failed to serialize DataFrame '%s': %s", var_name, e)

                        if 'result' in locals():
                            result['data'] = returned_data
                            logger.info("Exec outcome | status=%s | output=%d chars | returned_dfs=%d",
                                        result.get('status'), len(result.get('output', '')), len(returned_data))

                self._send_json(result)

            except Exception as e:
                logger.error("Execute failed: %s", str(e)[:200])
                self._send_json({
                    'status': 'error', 'output': '', 'error': str(e),
                    'traceback': traceback.format_exc()
                }, status=500)
        else:
            self.send_response(404)
            self.end_headers()

    def do_GET(self):
        if self.path == '/health':
            self._send_json({"status": "ok"})
        elif self.path == '/probe':
            self._send_json(self._run_probe())
        else:
            self.send_response(404)
            self.end_headers()

    def _run_probe(self) -> Dict:
        """Capability probe: verify the execution environment can actually run
        the workloads this sandbox exists to serve (not just that HTTP is up).

        Catches a broken-builtins/failed-bootstrap image at pool ingress
        instead of mid-agent-turn, and distinguishes a deterministic
        environment bug from a transient container outage.
        """
        code = "import pandas as pd\nimport numpy as np\n" \
               "pd.DataFrame({'a':[1,2]})\nnp.linalg.inv(np.eye(2))\n" \
               "print('probe-ok')"
        probe_globals = dict(EXEC_GLOBALS)
        probe_globals["__builtins__"] = _SAFE_BUILTINS
        try:
            with contextlib.redirect_stdout(io.StringIO()):
                exec(compile(code, "<probe>", "exec"), probe_globals)
            return {"status": "ok"}
        except Exception as e:
            logger.error("Sandbox probe failed: %s", str(e)[:200])
            return {"status": "error", "error": str(e)[:500]}

    def log_message(self, format, *args):
        pass


if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO)
    # HTTPServer blocks requests sequentially by default.
    # That design is preserved here — concurrent requests would race on process-wide state
    # (e.g. the stdout capture shared across executions).
    server = HTTPServer(('0.0.0.0', 9000), ExecuteHandler)
    logger.info("Sandbox server running on port 9000")
    server.serve_forever()
