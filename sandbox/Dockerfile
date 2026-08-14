FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir --progress-bar off -r requirements.txt

COPY handler.py .

RUN useradd -m sandbox
USER sandbox

# Build-time boot gate: the image must start and answer /health, or the build
# fails — a broken handler can never be baked into the image the pool runs.
RUN set -eu; \
    python handler.py > /tmp/sandbox-boot.log 2>&1 & \
    pid=$!; \
    trap 'kill "$pid" 2>/dev/null || true' EXIT; \
    i=0; \
    until python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:9000/health', timeout=1)" 2>/dev/null; do \
        i=$((i+1)); \
        if [ "$i" -ge 50 ]; then cat /tmp/sandbox-boot.log; exit 1; fi; \
        sleep 0.2; \
    done; \
    kill "$pid" 2>/dev/null || true

EXPOSE 9000

CMD ["python", "handler.py"]
