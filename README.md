# Hotel AI Data Analyst

An AI analyst for hotel data. Hotel staff type a question in plain language. The system plans SQL, runs it against the hotel database with row and time limits, analyzes the results in Python, and returns an answer with tables, charts, and CSV downloads.

## Problem

Hotels keep operational data in their PMS: reservations, revenue, occupancy, POS sales. Staff who want answers have two options. Static dashboards only answer predefined questions. Ad-hoc SQL needs someone technical. Both are slow and limited.

## Solution

A chat interface backed by an LLM agent.

1. The user asks a question in natural language.
2. The agent plans the SQL and checks it against the schema and the user's permissions.
3. The SQL runs through a read-only connection with row and time limits.
4. The returned data is analyzed with Python.
5. The answer is streamed back with tables, charts, and downloadable CSVs.

## Architecture

Three components:

- Laravel backend. The only public entry point. Handles authentication, tenant/client management, schema metadata, permissions, usage and billing, and the data plane that runs SQL.
- Python agent. Runs the LLM planning loop, manages sessions, and serves assets. Binds to loopback only and is never exposed publicly.
- React frontend. The chat UI.

Deployment is one Laravel instance per hotel plus one central Python agent. Each hotel instance runs next to its own database. Hotels whose databases sit on private networks are supported without exposing those databases. Hotel data stays on the hotel's network. Only orchestration is centralized.

```
┌─────────────┐        ┌──────────────────────────────┐
│ React SPA   │ ─────▶ │ Laravel (per hotel)          │
│ chat UI     │        │ auth · data plane · billing  │
└─────────────┘        └──────────────┬───────────────┘
                                      │ signed requests
                                      ▼
                              ┌────────────────────┐     ┌─────────────────────┐
                              │ Python agent       │ ──▶ │ Docker sandbox      │
                              │ LLM planning loop  │     │ disposable workers  │
                              └────────────────────┘     └─────────────────────┘
```

## Security

- Every call between a hotel instance and the Python agent is signed with Ed25519 and carries a timestamp that prevents replay.
- SQL runs under a per-session delegation token and a read-only database account.
- The read-only account is created the first time the agent touches the database, not at setup. If its stored password stops working, it is re-created automatically.
- SQL is executed as `SELECT * FROM (query) AS _data_plane_sub LIMIT n+1`. The row cap cannot be bypassed.
- Query time is capped. The agent sets `MAX_EXECUTION_TIME`, falls back to `max_statement_time`, and logs a warning if the MySQL build supports neither.
- Generated Python runs in a disposable Docker container that is destroyed after each call.
- Sensitive tables are blocked at the query layer for every user, including administrators. The block cannot be bypassed from the UI.
- Staff access is scoped by role-based permission tokens.
- Session data is retained for a configurable number of days. A session can be erased on its own.
- Row counts for a client are cached for 60 seconds. If the database is unreachable, the failed lookup is cached too, so the connection is not retried on every request.

## Cost control

- LLM usage is metered per client and can be capped with a monthly budget.
- The agent budgets its context window: a small live set, persisted summaries, and automatic summarization as conversations grow.
- It tracks prompt cache hits and misses and reports per-turn cost.
- Reasoning effort is configurable per model.

## Operations

- Schema discovery imports a hotel's tables and columns. Administrators add descriptions, value mappings, and virtual foreign keys.
- Staff users are synced from the hotel's own system.
- Turn completion is idempotent, so usage is not double-billed.
- System audit events are forwarded to a daily audit log.

## Tech stack

| Component | Stack |
| --- | --- |
| Agent | Python, FastAPI, pandas, sqlglot, Docker |
| Backend | Laravel 12 (PHP 8.2), MySQL |
| Frontend | React 18, Vite, Tailwind CSS |
| LLM | Any OpenAI-compatible endpoint (OpenRouter by default) |

## Repository layout

```
├── agent/               Python agent (LLM, DB executor, session, auth, audit)
├── prompts/             LLM prompt templates
├── sandbox/             Disposable Docker sandbox
├── tests/               Pytest suite
├── laravel/             Laravel backend
├── frontend/            React SPA
├── docs/                admin-guide.md, deployment.md, test_queries.md
├── main.py              Agent entry point
├── config.py            Agent configuration
├── .env.example         Agent env template
└── requirements.txt     Python dependencies
```

## Running it

```bash
# Python agent (requires Docker for the sandbox)
pip install -r requirements.txt
cp .env.example .env
python main.py

# Laravel backend
cd laravel
composer install
cp .env.example .env && php artisan key:generate && php artisan migrate

# React frontend
cd frontend
npm install && npm run dev
```

A full single-server setup is in `docs/deployment.md`.

## Tests

- Agent: `pytest`
- Laravel: `php artisan test`
- CI runs the agent suite.

## Documentation

More information is in the docs:

- `docs/admin-guide.md` covers day-to-day administration.
- `docs/deployment.md` covers server deployment.
- `docs/test_queries.md` has sample queries.

## License

All rights reserved.
