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

- **Decision:** Callers depend on the `LlmClient` interface. `ClaudeLlmClient` talks to the Messages API over Laravel's HTTP client; `FakeLlmClient` answers offline. `LLM_DRIVER=auto` resolves to `claude` when `ANTHROPIC_API_KEY` is set, otherwise to `fake` with a logged warning. The model comes from `ANTHROPIC_MODEL` (default `claude-sonnet-5-5`; Haiku 5.5 is the cheaper option).
- **Rationale:** Tests and `docker compose up` must work without a key; the warning stops a missing key from passing as a working setup. `Http::fake` makes the Claude client easy to test, and moving to an SDK later changes one class (DIP).
- **Alternatives:** The official PHP SDK.

## K10: Structured output from a registry-built JSON schema

- **Decision:** `ClaudeLlmClient` asks the API for structured output (`output_config.format` of type `json_schema`), with the schema built from `FieldRegistry` and the content limits. The backend validates every answer anyway (K11).
- **Rationale:** The case requires JSON output. The schema makes a shape mismatch rare in the model, so fewer repair rounds are needed; business rules (value limits, operators per type, character limits) do not fit a schema and stay in the validators.
- **Alternatives:** Prompt-only JSON; forced tool use as a fallback (not built: the API supports structured output, so it would be unused code).

## K11: Reliability chain and the LLM call log

- **Decision:** An LLM answer goes parse → validate → typed value object. A rejected answer goes back to the model with its path-based errors, at most 2 repair rounds (`llm.max_repair_attempts`), then the caller gets the errors. Text no field can express goes to `unsupported_criteria`; an answer with no conditions and a non-empty `unsupported_criteria` ends at once, since a repair cannot fix it. A brief is 10-1000 characters and may not contain `<brief>` tags (`llm.brief`). Transport failures are never repaired: `LlmUnavailable` maps to 503, the other `LlmException`s to 502, once in `bootstrap/app.php`.
- **Call log:** `RecordingLlmClient` wraps any `LlmClient` and writes one `llm_calls` row per call (purpose, prompt version, model, tokens, latency, attempt, outcome `succeeded|failed|invalid|aborted`, errors, raw output). The prompt is never stored, so the log holds no personal data. `LlmClient` stays the raw client, so tests queue scenarios on `FakeLlmClient`; `LlmServiceProvider` builds the callers that must be logged (`SegmentExtractor`, later `ContentGenerator`) with the recording client, and the extractor marks a rejected call `invalid`.
- **Rationale:** The case rewards reliable structured output. Failures, retries and latency stay visible and measurable (`llm:eval`, metrics), and a decorator adds this without touching the clients (OCP).
- **Alternatives:** A single attempt with no repair; unlimited retries; logging inside each client; storing the full prompt.

## K12: Content streams over POST server-sent events, parsed partially in the browser

- **Decision:** `POST /api/campaigns/{id}/content/stream` answers `text/event-stream` with `delta` (a piece of the model's raw JSON), then either `final` (the validated content and a `repaired` flag) or `error` (code, message, path-based problems). The browser reads it with `fetch` and its own `SseParser` (`streamSse`, 10.1), because `EventSource` cannot POST. While deltas arrive, `useContentStream` (10.4) collects them and, once per animation frame, parses the JSON so far with the `partial-json` library (flags `STR | OBJ`: unfinished strings and objects are completed, unfinished numbers and literals are not guessed). `final` replaces the drawn text with the validated content; cancel aborts the request, which makes the server drop the model stream, and clears the unvalidated text. Only live status (`/stats/stream`, GET) uses `EventSource`.
- **Rationale:** The stream starts a paid, side-effecting generation, so it must not be a GET. Re-parsing the whole prefix per frame is O(n²) but n stays under 3 KB, and one frame bounds the work however fast deltas come. `partial-json` is small, typed and maintained; hand-writing a tolerant JSON parser would be more code to get wrong.
- **Alternatives:** WebSocket (Reverb); polling; `best-effort-json-parser` or `jsonrepair` (heavier, repair guesses more than completing a prefix needs); a hand-written prefix parser; having the server send already-parsed fields.

## K13: Limits count what the recipient sees

- **Decision:** SMS body ≤ 160, email subject ≤ 80 and email body (plain text) ≤ 2000, counted in visible characters (`grapheme_strlen`; the frontend uses `Intl.Segmenter`). The count is the worst case after personalisation: each variable is filled with the longest value in the audience for that channel (`AudienceService`), or its fallback if that is longer. Model output and a person's edit both become a `MessageContent` and go through the same `ContentValidator`, which returns every problem with the field path (`sms.body`, `email.subject`). The limits live only in `config/delivery.php` and are read when the validator is resolved. The sending step checks again. Operator segments (GSM-7 vs UCS-2) are not modelled.
- **Rationale:** The limit must hold on the recipient's phone, not just on the template. One validator and one config stop the model, the editor and the frontend from drifting apart.
- **Alternatives:** Counting the template length, a fixed budget per variable, counting GSM segments.

## K14: Personalisation with a closed variable list

- **Decision:** A message may use only `{{first_name}}`, `{{last_name}}` and `{{city}}` (`Variables`), each with a fallback used when the customer has no value ("Değerli müşterimiz" for the first name). `PersonalizationRenderer` fills them in one regex pass, so cost is O(n) and a value that looks like `{{city}}` is inserted as text, never expanded. An unknown placeholder is reported, not rendered. Length is counted in visible characters with `grapheme_strlen` (a Turkish letter, an emoji or a joined family emoji is 1). The worst case counts each variable as the longer of the audience's longest value and its fallback, since a customer with no value gets the fallback. Spaces inside the braces are accepted (`{{ first_name }}`).
- **Rationale:** A closed list leaves no room for template injection, and one pass keeps rendering linear. Counting what the reader sees keeps the limit true on the recipient's phone.
- **Alternatives:** Twig or Blade as the template engine; counting bytes or code points.

## K15: Human approval as a campaign state machine

- **Decision:** `Campaign` is a rich model: `defineAudience()`, `setContent()`, `approve()`, `startSending()`, `complete()` and `fail()` are the only way to change `status`, which is not mass assignable. Flow: `draft → audience_ready → content_ready → approved → sending → completed | failed`. Audience and content can be redone until approval (`audience_ready` and `content_ready` accept themselves, and `content_ready` can return to `audience_ready`), because the rule is read-only and the brief is what changes. A person can edit only the generated SMS and email text, and only before approval. `approved → approved` is allowed, so approving twice is a no-op. Any other move throws `InvalidCampaignTransition` (HTTP 409 with `from`, `to` and `allowed`). The methods change the model in memory only; the caller saves it.
- **Rationale:** An invalid transition fails early, next to the data it protects. Idempotent approval makes a double click harmless.
- **Alternatives:** A rule editor (YAGNI); status set from services or controllers.
- **One exception (task 8.6):** `Campaign::completeIfFinished()` writes `sending → completed` with a single conditional `UPDATE … WHERE NOT EXISTS (pending/sending deliveries)` instead of `complete()`. The check and the change must be one statement, or two workers finishing together could complete the campaign early or twice. It stays inside the model and only makes the transition `CampaignStatus` already allows.

## K16: Freeze the audience in SQL at approval, dispatch from a job

- **Decision:** `POST /api/campaigns/{id}/approve` takes a `Cache::lock` on the campaign, re-reads it under the lock, and only moves a `content_ready` campaign to `approved` (any other pre-approval state is a 409). It refuses an audience of 0 with 422 `audience_empty`, and re-validates both messages with the same `ContentValidator` against the stored longest values. It then queues one `StartCampaignJob` and returns; a repeat after approval returns 200 with the current state and queues nothing. `StartCampaignJob` runs `AudienceSnapshotter` (one `INSERT … SELECT … ON CONFLICT DO NOTHING` per channel, with the consent condition applied again and the idempotency key `sha256("campaign:customer:channel")` computed in SQL), moves the campaign to `sending`, and streams the pending delivery ids with `lazyById(1000)`, queuing one `SendDeliveryJob` each on the `sms` or `email` queue. A second run adds no rows and re-queues only deliveries still pending, so a crash during dispatch is recovered by running the job again. A held lock gives 409 `approval_in_progress` after `approval_lock_wait_seconds`.
- **Rationale:** The freeze is one statement inside Postgres, so no customer row reaches PHP and memory stays flat. Doing it in a job keeps the request fast and lets a failed start be repeated safely. The lock plus the status re-read turns two clicks into one approval.
- **Alternatives:** Looping over customers in PHP; one batch job for the whole campaign; a `DB::transaction` with `lockForUpdate` instead of a cache lock (the lock must also cover the dispatch).

## K17: One rate limit per channel, enforced on both sides

- **Decision:** Sending is limited to 100 per second per channel (`SEND_RATE_PER_SECOND`). The mock provider enforces the same limit itself, with a per-second Redis `INCR` counter per channel, and answers 429 with `Retry-After: 1` when it is exceeded. The main app's limiter (task 8.4) uses `Redis::throttle(...)->block()` so a worker waits instead of burning a retry.
- **Rationale:** The provider limit is the case requirement. Testing the sender against a provider that really refuses proves the throttle, rather than trusting it. Fixed one-second windows allow a short burst at a boundary, which is measured in 8.8.
- **Alternatives:** A token bucket in Lua; a batch dispatcher that sends once per second.

## K18: Retry by failure class, with full jitter

- **Decision:** `HttpMessageProvider` (8.1) sorts every answer into one of four classes: 2xx `Sent`, 429 `RateLimited` (with the `Retry-After` seconds when usable), 5xx / timeout / connection error `Transient`, any other 4xx `Permanent`. `RetryPolicy` (8.2) is a pure class that turns a failed result and the number of finished attempts into a `RetryDecision`: `Transient` waits a random time between 0 and `min(cap, base · 2^(n-1))` (full jitter, n = finished attempts); `RateLimited` waits `max(Retry-After, that backoff)`; `Permanent` never retries; after the 5th failed attempt every class gives up. The random source is injected. Base (200 ms), cap (10 s) and the 5 attempts live in `config/delivery.php` and are estimates to tune. A permanent or exhausted delivery stays `failed` and shows on the dashboard.
- **Rationale:** Retrying a permanent error wastes calls; retrying all failures at one fixed interval makes workers come back together. Full jitter spreads them, and honouring `Retry-After` avoids a second refusal. A pure class is unit-tested without time or network.
- **Alternatives:** A fixed interval; a circuit breaker (YAGNI).

## K19: Three layers against double sends

- **Decision:** (1) A DB unique key on `deliveries` (`campaign_id`, `customer_id`, `channel`) means a row exists once; the snapshot uses `ON CONFLICT DO NOTHING`. (2) An atomic claim, a conditional `UPDATE` from `pending` (or a stale `sending`) to `sending`, means two workers cannot take the same row. (3) The provider `Idempotency-Key` (sha256 of campaign, customer and channel, unique on the row) deduplicates a retry after a timeout. Different campaigns may reach the same customer (no frequency cap). `Delivery` is a rich model: `markSent()`, `markFailed()`, `scheduleRetry()` (back to `pending`) and `skip()` are the only way to change its status, and `sent`, `failed` and `skipped` are final. `attempts` counts finished provider attempts (sent, retry, failed), not skips. The database fills `created_at` and `updated_at`, because the snapshot is a raw `INSERT … SELECT`.
- **Rationale:** Each layer covers a failure the others cannot: duplicate rows, concurrent workers, and an ambiguous network failure.
- **Alternatives:** A Redis set; only `ShouldBeUnique` jobs.

## K20: Mock provider as a separate plain-PHP container

- **Decision:** `mock-provider/` is its own container: plain PHP (`php -S`, 16 workers, no framework) with counters in Redis. A missing `Idempotency-Key` or a bad recipient is a 400 (permanent for the main app). `/stats` reports per channel `total`, `duplicates_prevented` and `peak_per_second`.
- **Rationale:** Independent counters prove nothing was sent twice, and real network failures happen between containers.
- **Alternatives:** A route inside the Laravel app; a framework.

## K21: Live status from one grouped query, computed on request

- **Decision:** `GET /api/campaigns/{id}/stats` (`CampaignStats`, 9.1) reads progress with a single `GROUP BY channel, status` over `deliveries` (served by the `campaign_id, channel, status` index) that also sums `attempts`. Every channel and status is returned, zero when absent. Retries are `SUM(attempts)` minus one per `sent` or `failed` delivery, because the last attempt of those is not a retry (a `pending` delivery with attempts has that many scheduled retries). The send rate is the `sent` rows with `sent_at` in the last 5 seconds divided by 5. Recent errors are the 10 newest deliveries with a `last_error`, by `updated_at`, without any customer column. The SSE stream (9.2), `/api/metrics` (9.3) and health (9.4) follow the same rule: computed at request time, no extra counter state, no extra container.
- **Stream, metrics, health:** `GET /api/campaigns/{id}/stats/stream` polls the same query every second and sends a `stats` frame only when the snapshot changed, a comment heartbeat every 15 s otherwise, and an `end` event once the campaign is final or the stream is 10 minutes old; the browser's `EventSource` reconnects after 3 s. `/api/metrics` is Prometheus text computed from the tables and Redis on each scrape. `/api/health` treats the database and Redis as critical (503 when either is down); a down provider only makes the status `degraded`, because the app still works and sends retry. The provider state is cached for 15 s so a stalled DNS lookup cannot slow every poll.
- **Rationale:** One indexed query keeps the cost constant per poll, and derived counters could drift from the rows. `EventSource` reconnects by itself and no extra container is needed (KISS). Leaving customer data out keeps phone numbers and emails out of the API and logs.
- **Alternatives:** Polling from the browser; Redis counters; several count queries; Reverb/WebSockets.

## K22: No authentication

- **Decision:** The app has no login, users or tokens. It is a single-marketer local demo, and the README says so.
- **Rationale:** The case does not ask for it (YAGNI), and auth would add routes, state and tests that prove nothing about the campaign flow.
- **Alternatives:** Sanctum token authentication.

## K23: Interface language is chosen in the browser with vue-i18n

- **Decision:** The UI is available in English and Turkish through `vue-i18n` (Composition API, `legacy: false`). One header button switches to the other language, named in itself ("Türkçe" / "English"). The choice is saved in `localStorage` (`locale`); with nothing saved, a Turkish browser gets Turkish and everything else English. The page's `<html lang>` follows the choice. Messages live in `src/i18n/en.ts` (the master) and `tr.ts`, typed `typeof en`, so a missing or extra key fails type-check; a test also checks that both languages use the same `{parameters}` and none of the characters vue-i18n reserves (`{ } @ | $`). Only text the frontend writes is translated: error text from the API, the model-written SMS/email text, the campaign brief, and the segment chip labels (which the backend already sends in Turkish from `FieldRegistry` and `Operator::template()`) are shown as received.
- **Rationale:** The switch is a display choice of one marketer, so it needs no server state or login, and it must not change what is sent to customers (that language is set by the brief and the model). Keeping the rule labels and API messages on the server keeps one source of truth for them (K7, K13) instead of a second copy in the frontend.
- **Alternatives:** Translating API errors by `code` in the frontend (a second copy of every message); the backend choosing the language from an `Accept-Language` header (the content limits and rule labels would then depend on the request); a per-language route prefix (`/tr/campaigns`), which adds routes for no gain in a single-marketer demo.

## Setup notes (Phase 2)

- **API only:** no web routes, views or Vite in the backend; errors share one `{error:{code,message,details}}` body.
- **Tooling:** Pest as the runner, Larastan level 6, Pint with its default preset.
- **Config:** content limits and sending defaults live only in `config/delivery.php`.
- **One backend image, three roles:** `app` migrates and seeds, then serves; the SMS and email workers reuse the image and wait for `app` to be healthy.
- **Local demo credentials:** `DB_PASSWORD=campaign` in `.env.example`; the database is not published to the host.
