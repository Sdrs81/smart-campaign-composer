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

## K6: Segment language is a flat AND list

- **Decision:** A segment rule is a flat list of conditions joined by AND, with a schema `version`. Multiple values use `in` / `not_in`. Operators per field type live in `FieldType::allowedOperators()`: `enum` takes `eq, neq, in, not_in, is_null, not_null`; `number` takes `eq, neq, gt, gte, lt, lte, between, is_null, not_null`; `date` takes `within_last_days, not_within_last_days, between, is_null, not_null`. `not_within_last_days`, `neq` and `not_in` also match null values (never bought, no favorite category), because "not X" should include people who have no X at all. Value limits (`in` ≤ 50 items, days 1-365) are constants on `FieldDefinition`.
- **Rationale:** Typical briefs fit AND lists, so validation and the prompt stay short (YAGNI), and an AND/OR tree can be added later without breaking the version field.
- **Alternatives:** A nested AND/OR tree; JSON Logic.

## K7: LLM output never becomes SQL

- **Decision:** `FieldRegistry` is the single source for fields, columns, types, operators, value limits and Turkish labels. The flow is registry → `SegmentRuleParser` (an invalid `SegmentRule` cannot exist) → a query builder with bound values. Column names reach SQL only through the registry. `age` is registered against `birth_date` with `derivedFromBirthDate`; the compiler (4.3) turns it into a date comparison. The city list is read lazily from the DB and cached for an hour (`FieldRegistry` takes a closure, so unit tests need no database). Contact details, names and consent flags are not registered; consent is a separate channel filter.
- **Rationale:** The case requires it, and one registry feeding the parser, compiler, prompt and JSON schema stops them drifting apart (DRY).
- **Alternatives:** Asking the LLM for SQL or a `where` string.

## K8: Consent is checked twice

- **Decision:** Opt-in is checked when the audience is counted and frozen, and again right before sending; a customer whose consent changed in between is `skipped`. "May receive a channel" (opt-in set and a non-empty address) is defined once, as `SegmentQueryCompiler::consentSql()`. Both the channel filter and the `COUNT(*) FILTER` aggregates in `AudienceService` use it, so counting and filtering cannot disagree. `AudienceService` counts in two queries: one aggregate scan (per-channel counts, unique total, longest personalisation values) and one sample.
- **Rationale:** Consent can change between approval and sending, so a single check is not enough (defensive). One SQL definition stops the count the marketer sees from drifting from the people who are actually sent to.
- **Alternatives:** Checking consent only when the audience is counted; a separate query per channel.

## K9: LLM driver chosen from the API key

- **Decision:** `LLM_DRIVER=auto` resolves to `claude` when `ANTHROPIC_API_KEY` is set, otherwise to `fake` with a logged warning. The model comes from `ANTHROPIC_MODEL` (default `claude-sonnet-5-5`).
- **Rationale:** Tests and `docker compose up` must work without a key; the warning stops a missing key from passing as a working setup.
- **Alternatives:** The official PHP SDK.

## K11: Reliability chain and the LLM call log

- **Decision:** Every LLM answer goes parse → validate → typed value object. Validation errors are fed back to the model, at most 2 repair rounds (`llm.max_repair_attempts`), then the caller gets a path-based error. Text that matches no field goes to `unsupported_criteria` instead of being guessed. Every call writes one `llm_calls` row through `RecordingLlmClient`, a decorator around any `LlmClient`: purpose, prompt version, model, tokens, latency (rounded up, never 0 ms), attempt number, outcome (`succeeded`, `failed`, `invalid`, `aborted`), error message, validation errors and the raw output. The prompt is not stored, so briefs and templates leave no personal data in the log. The decorator can only know about the API call, so the caller marks a call `invalid` (`LlmCall::markInvalid()`, then save) once its own validation fails. A stream is one row, written when it ends, fails or is abandoned. `LlmRequest` carries `purpose`, `promptVersion` and `attempt` for the log only. `LlmClient` stays bound to the raw client, so tests can queue scenarios on `FakeLlmClient`. `RecordingLlmClient` is a second singleton that wraps that same instance (model name `fake` for the offline client); callers that must be logged (`SegmentExtractor` in 5.6, `ContentGenerator` in 5.10) get it through a contextual binding and still type-hint `LlmClient`. An answer with no conditions and a non-empty `unsupported_criteria` is the model saying "nothing here can be expressed" (the prompt tells it to). `SegmentExtractor` checks this before `SegmentRuleParser` and fails at once with the unsupported list, without a repair round, because the parser would reject the empty list and a repair could not fix it.
- **Rationale:** The case rewards reliable structured output. Failures, retries and latency stay visible and measurable (`llm:eval`, metrics), and a decorator adds this without touching the clients (OCP).
- **Alternatives:** A single attempt with no repair; unlimited retries; logging inside each client; storing the full prompt.

## K15: Human approval as a campaign state machine

- **Decision:** `Campaign` is a rich model: `defineAudience()`, `setContent()`, `approve()`, `startSending()`, `complete()` and `fail()` are the only way to change `status`, which is not mass assignable. Flow: `draft → audience_ready → content_ready → approved → sending → completed | failed`. Audience and content can be redone until approval (`audience_ready` and `content_ready` accept themselves, and `content_ready` can return to `audience_ready`), because the rule is read-only and the brief is what changes. `approved → approved` is allowed, so approving twice is a no-op. Any other move throws `InvalidCampaignTransition` (HTTP 409 with `from`, `to` and `allowed`). The methods change the model in memory only; the caller saves it.
- **Rationale:** An invalid transition fails early, next to the data it protects. Idempotent approval makes a double click harmless.
- **Alternatives:** A rule editor (YAGNI); status set from services or controllers.

## K19: Three layers against double sends

- **Decision:** (1) A DB unique key on `deliveries` (`campaign_id`, `customer_id`, `channel`) means a row exists once; the snapshot uses `ON CONFLICT DO NOTHING`. (2) An atomic claim, a conditional `UPDATE` from `pending` (or a stale `sending`) to `sending`, means two workers cannot take the same row. (3) The provider `Idempotency-Key` (sha256 of campaign, customer and channel, unique on the row) deduplicates a retry after a timeout. Different campaigns may reach the same customer (no frequency cap). `Delivery` is a rich model: `markSent()`, `markFailed()`, `scheduleRetry()` (back to `pending`) and `skip()` are the only way to change its status, and `sent`, `failed` and `skipped` are final. `attempts` counts finished provider attempts (sent, retry, failed), not skips. The database fills `created_at` and `updated_at`, because the snapshot is a raw `INSERT … SELECT`.
- **Rationale:** Each layer covers a failure the others cannot: duplicate rows, concurrent workers, and an ambiguous network failure.
- **Alternatives:** A Redis set; only `ShouldBeUnique` jobs.

## Setup notes (Phase 2)

- **API only:** no web routes, views or Vite in the backend; errors share one `{error:{code,message,details}}` body.
- **Tooling:** Pest as the runner, Larastan level 6, Pint with its default preset.
- **Config:** content limits and sending defaults live only in `config/delivery.php`.
- **One backend image, three roles:** `app` migrates and seeds, then serves; the SMS and email workers reuse the image and wait for `app` to be healthy.
- **Local demo credentials:** `DB_PASSWORD=campaign` in `.env.example`; the database is not published to the host.
