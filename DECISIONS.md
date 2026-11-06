# Decisions

Each decision is written when the first task that needs it lands. Use this template:

```
## K<n>: <short title>

- **Decision:** what was chosen.
- **Rationale:** why, in one or two sentences.
- **Alternatives:** what was rejected.
```

## K1: Laravel 12 on FrankenPHP, classic mode

- **Decision:** Laravel 12 (PHP 8.4) served by FrankenPHP in classic mode, without Octane.
- **Rationale:** Queue, Redis throttling, migrations, seeders, Pest and SSE are built in. One container, no stream buffering to tune, and no state leaking between requests.
- **Alternatives:** Symfony, Slim; nginx + php-fpm.

## K2: PostgreSQL

- **Decision:** PostgreSQL 17 is the only relational store.
- **Rationale:** `COUNT(*) FILTER` gives per-channel counts in one query, `ON CONFLICT DO NOTHING` makes the audience snapshot idempotent, and JSONB holds structured values.
- **Alternatives:** MySQL, SQLite.

## K3: Redis for queue, rate limiter and locks

- **Decision:** One Redis 7 serves the queue (separate `sms` and `email` queues), the global rate limiter and locks.
- **Rationale:** One component does all three jobs; RabbitMQ would add operational load at this scale.
- **Alternatives:** RabbitMQ, database queue.

## K4: Real services for integration tests

- **Decision:** Unit tests need no database, Redis or framework boot. Integration tests run against the Postgres and Redis containers, in a separate `campaign_test` database created by `docker/postgres/init.sql`. `make test` runs inside Docker.
- **Rationale:** `FILTER`, `ON CONFLICT` and throttling only show up on the real services. A separate database can be wiped freely.
- **Alternatives:** SQLite in memory.

## K9: LLM driver chosen from the API key

- **Decision:** `LLM_DRIVER=auto` resolves to `claude` when `ANTHROPIC_API_KEY` is set, otherwise to `fake` with a logged warning. The model comes from `ANTHROPIC_MODEL` (default `claude-sonnet-5-5`).
- **Rationale:** Tests and `docker compose up` must work without a key; the warning stops a missing key from passing as a working setup.
- **Alternatives:** The official PHP SDK.

## Setup notes (Phase 2)

- **API only:** no web routes, views or Vite in the backend; errors share one `{error:{code,message,details}}` body.
- **Tooling:** Pest as the runner, Larastan level 6, Pint with its default preset.
- **Config:** content limits and sending defaults live only in `config/delivery.php`.
- **One backend image, three roles:** `app` migrates and seeds, then serves; the SMS and email workers reuse the image and wait for `app` to be healthy.
- **Local demo credentials:** `DB_PASSWORD=campaign` in `.env.example`; the database is not published to the host.
