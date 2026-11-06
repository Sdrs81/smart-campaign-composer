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

## K5: Behavior data as wide summary columns on customers

- **Decision:** Demographics, account, purchase, cart, loyalty and consent data live as summary columns on `customers`. Segment conditions are comparisons on indexed columns. The sample brief (`last_cart_add_at` in the last 30 days and `last_purchase_at` not in the last 30 days, never-purchased included) is only an example, so the columns are not narrowed to it. A new field means a new column plus a `FieldRegistry` entry. `customers` has no `created_at`/`updated_at`; `registered_at` is the domain date.
- **Rationale:** An event-collection pipeline is out of scope, and indexed column comparisons keep audience counting simple (KISS).
- **Alternatives:** Event tables queried with `EXISTS`.

## K9: LLM driver chosen from the API key

- **Decision:** `LLM_DRIVER=auto` resolves to `claude` when `ANTHROPIC_API_KEY` is set, otherwise to `fake` with a logged warning. The model comes from `ANTHROPIC_MODEL` (default `claude-sonnet-5-5`).
- **Rationale:** Tests and `docker compose up` must work without a key; the warning stops a missing key from passing as a working setup.
- **Alternatives:** The official PHP SDK.

## K15: Human approval as a campaign state machine

- **Decision:** `Campaign` is a rich model: `defineAudience()`, `setContent()`, `approve()`, `startSending()`, `complete()` and `fail()` are the only way to change `status`, which is not mass assignable. Flow: `draft → audience_ready → content_ready → approved → sending → completed | failed`. Audience and content can be redone until approval (`audience_ready` and `content_ready` accept themselves, and `content_ready` can return to `audience_ready`), because the rule is read-only and the brief is what changes. `approved → approved` is allowed, so approving twice is a no-op. Any other move throws `InvalidCampaignTransition` (HTTP 409 with `from`, `to` and `allowed`). The methods change the model in memory only; the caller saves it.
- **Rationale:** An invalid transition fails early, next to the data it protects. Idempotent approval makes a double click harmless.
- **Alternatives:** A rule editor (YAGNI); status set from services or controllers.

## Setup notes (Phase 2)

- **API only:** no web routes, views or Vite in the backend; errors share one `{error:{code,message,details}}` body.
- **Tooling:** Pest as the runner, Larastan level 6, Pint with its default preset.
- **Config:** content limits and sending defaults live only in `config/delivery.php`.
- **One backend image, three roles:** `app` migrates and seeds, then serves; the SMS and email workers reuse the image and wait for `app` to be healthy.
- **Local demo credentials:** `DB_PASSWORD=campaign` in `.env.example`; the database is not published to the host.
