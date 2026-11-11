# Decisions

The important decisions of this project and why they were made.

## 1. Technology: Laravel 12 on FrankenPHP, PostgreSQL, Redis

- **Decision:** Laravel 12 (PHP 8.4) served by FrankenPHP in classic mode, without Octane. PostgreSQL 17 is the only relational store. One Redis 7 serves the queue (separate `sms` and `email` queues), the global rate limiter and locks.
- **Rationale:** Laravel has the queue, Redis throttling, migrations, seeders, Pest and streamed responses built in, and classic mode means one container, no stream buffering to tune and no state leaking between requests. PostgreSQL gives per-channel counts in one query (`COUNT(*) FILTER`), an idempotent audience snapshot (`ON CONFLICT DO NOTHING`) and JSONB for structured values. One Redis does all three of its jobs; RabbitMQ would add operational load at this scale.

## 2. One command to set up, one image in four roles

- **Decision:** `docker compose up` on a clean clone is the whole setup. `.env` is optional: every value the containers need has a default in `docker-compose.yml`, kept equal to `.env.example`, so a `.env` only adds the API key or a tuned value. The backend image runs in four roles: `app` migrates, seeds an empty `customers` table and serves; `worker-sms` and `worker-email` each listen to one channel queue; one `worker-campaign` starts approved campaigns.
- **Rationale:** The case requires one-command setup with the seed and the mock provider, and no secret should enter the repository. One queue per channel keeps a slow channel from holding up the other, and a separate campaign worker keeps an audience freeze from blocking sends.

## 3. Behavior data as wide summary columns on customers

- **Decision:** Demographics, account, purchase, cart, loyalty and consent data live as summary columns on `customers`, and segment conditions are comparisons on indexed columns. Each channel has its own opt-in flag next to its address. A new field means a new column plus a `FieldRegistry` entry. The seed creates 5,000 customers with Faker and a fixed seed, so every setup produces the same data.
- **Rationale:** An event-collection pipeline is out of scope, and indexed column comparisons keep audience counting simple.

## 4. Segment rule: a flat AND list that never becomes SQL

- **Decision:** A segment rule is a flat list of conditions joined by AND, with a schema `version`. Multiple values use `in` / `not_in`, and `not_within_last_days`, `neq` and `not_in` also match null values (never bought, no favorite category), because "not X" should include people who have no X at all. `FieldRegistry` is the single source for fields, columns, types, operators, value limits and Turkish labels. The flow is registry → `SegmentRuleParser` (an invalid `SegmentRule` cannot exist) → a query builder with bound values, so column names reach SQL only through the registry and LLM output never becomes SQL. Consent is not a segment field but a channel filter: a customer may receive a channel when its opt-in is set and its address is not empty, defined once in `SegmentQueryCompiler::consentSql()` and used by both counting and filtering.
- **Rationale:** Asking the model for SQL would make every answer a possible injection; a closed rule language can be validated completely. Typical briefs fit AND lists, so validation and the prompt stay short, and an AND/OR tree can be added later without breaking the version field. One registry feeding the parser, compiler, prompt and JSON schema stops them drifting apart, and one consent definition keeps the count the marketer sees equal to the people who are sent to.

## 5. LLM client behind an interface, with a fake for tests and the key-less demo

- **Decision:** Callers depend on the `LlmClient` interface. `ClaudeLlmClient` talks to the Messages API over Laravel's HTTP client. `FakeLlmClient` answers offline: tests queue the exact answers or failures they expect, and the key-less demo reads the filters a Turkish brief states in plain words with narrow patterns (`BriefReader`). Fake answers go through the same parser and validators as Claude's. `LLM_DRIVER=auto` resolves to `claude` when `ANTHROPIC_API_KEY` is set, otherwise to `fake` with a logged warning. The model comes from `ANTHROPIC_MODEL` (default `claude-sonnet-5-5`).
- **Rationale:** Tests and `docker compose up` must work without a key, and the warning stops a missing key from passing as a working setup. A demo that reacts to the brief shows the whole flow, and narrow patterns stay deterministic: a missed condition is visible, a wrong one would silently pick the wrong customers.

## 6. Prompts: versioned files, the brief as data, no customer data

- **Decision:** Each prompt is a versioned file in `resources/prompts/`, covered by a snapshot test, and its version is logged with every call. Marketer text goes inside `<brief>`, `<tone>` and `<audience>` tags and is treated as data; input or model text that contains those tags is refused. The model never sees a customer row: the content prompt gets channel counts and the longest length of each variable in the audience, not the values.
- **Rationale:** A versioned prompt makes a change visible in review and in the call log. The tags limit prompt injection through the brief. Sending lengths instead of values keeps personal data out of a third-party API while still letting the model leave room for personalization.

## 7. Reliability chain: validate, repair, retry and log

- **Decision:** `ClaudeLlmClient` asks for structured output (`json_schema`; the segment schema is built from `FieldRegistry`), and the backend validates every answer anyway: parse → validate → typed value object. **Repair** is for a wrong answer: it goes back to the model with its path-based errors, at most 2 rounds, then the caller gets the errors. Text no field can express goes to `unsupported_criteria`. **Retry** is for a failed call: 429, 5xx and connection errors get up to 3 attempts, honoring `retry-after`, then raise `LlmUnavailable` (503). A refusal or a cut-off answer is never parsed and maps to 502. `RecordingLlmClient` writes one `llm_calls` row per call (tokens, latency, attempt, outcome, errors, raw output, never the prompt), and `llm:eval` scores 10 golden briefs through the same code.
- **Rationale:** The case rewards reliable structured output. The schema makes a shape mismatch rare, but value limits, operators per type and character limits do not fit a schema, so the validators decide. Feeding the errors back lets the model fix its own answer, while retrying a refusal would only spend money. Failures, retries and latency stay measurable, and a decorator adds the log without touching the clients.

## 8. Content streams over POST server-sent events

- **Decision:** `POST /api/campaigns/{id}/content/stream` answers `text/event-stream` with `delta` events (pieces of the model's raw JSON), then either `final` (the validated content) or `error`. The browser reads it with `fetch` and its own SSE parser, because `EventSource` cannot POST. Only live status (`/stats/stream`, GET) uses `EventSource`.
- **Rationale:** The stream starts a paid, side-effecting generation, so it must not be a GET. The text drawn while streaming is only a preview; what is saved is what passed validation. Both streams are one-way, so WebSockets would add a server for no gain.

## 9. Limits count what the recipient sees

- **Decision:** SMS body ≤ 160, email subject ≤ 80 and email body (plain text) ≤ 2000, counted in visible characters (`grapheme_strlen`; the frontend uses `Intl.Segmenter`). The count is the worst case after personalization: each variable is filled with the longest value in the audience for that channel, or its fallback if that is longer. A message may use only `{{first_name}}`, `{{last_name}}` and `{{city}}`, each with a fallback ("Değerli müşterimiz" for the first name), filled in one regex pass so a value that looks like `{{city}}` is inserted as text, never expanded. Model output and a person's edit both go through the same `ContentValidator`, which returns every problem with its field path. The limits live only in `config/delivery.php`, and the sending step checks again. GSM-7 vs UCS-2 segments are not modeled.
- **Rationale:** The limit must hold on the recipient's phone, not just on the template. One validator and one config stop the model, the editor and the frontend from drifting apart, and a closed variable list leaves no room for template injection.

## 10. Human approval as a campaign state machine

- **Decision:** `Campaign` is a rich model: `defineAudience()`, `setContent()`, `approve()`, `startSending()`, `complete()` and `fail()` are the only way to change `status`. Flow: `draft → audience_ready → content_ready → approved → sending → completed`. Audience and content can be redone until approval, and a person can edit only the generated SMS and email text, and only before approval. `approved → approved` is allowed, so approving twice is a no-op. Any other move throws `InvalidCampaignTransition` (HTTP 409). The one exception is `completeIfFinished()`, which writes `sending → completed` with a single conditional `UPDATE … WHERE NOT EXISTS (pending/sending deliveries)`, so two workers finishing together cannot complete the campaign early or twice. A campaign ends `completed` even when some or all of its deliveries failed; `failed` is defined (`sending → failed`), but no code path uses it yet.
- **Rationale:** An invalid transition fails early, next to the data it protects. Idempotent approval makes a double click harmless. Failures belong to deliveries, where the dashboard shows how many and why; a campaign-level failure rule (for example "nothing was sent") is left open.

## 11. Freeze the audience in SQL at approval, dispatch from a job

- **Decision:** `POST /api/campaigns/{id}/approve` takes a lock, re-reads the campaign, refuses an empty audience (422), re-validates both messages and queues one `StartCampaignJob`, unique per campaign. That job freezes the audience with one `INSERT … SELECT … ON CONFLICT DO NOTHING` per channel, moves the campaign to `sending` and queues one `SendDeliveryJob` per delivery. Running it again adds no rows and re-queues only pending deliveries, and approving a campaign that is still `approved` queues it again, so a lost or crashed start is recovered safely. Opt-in is checked when the audience is counted, when it is frozen, and right before each send; a customer whose consent changed is `skipped`.
- **Rationale:** The freeze is one statement inside Postgres, so no customer row reaches PHP and memory stays flat. A job keeps the request fast and makes the start repeatable. Consent can change between approval and sending, so a single check is not enough.

## 12. One rate limit per channel, enforced on both sides

- **Decision:** Sending is limited to 100 per second per channel (`SEND_RATE_PER_SECOND`). The mock provider enforces the same limit itself and answers 429 with `Retry-After: 1`. The app's limiter waits for a free slot instead of burning a retry. It also records the highest number of calls granted in one second, exposed as `send_peak_per_second{channel}`, and the smoke test checks that value against the limit.
- **Rationale:** Testing the sender against a provider that really refuses proves the throttle. The provider's own peak cannot show that the sender behaved, because the provider refuses anything above the limit, so the sender's own peak is the evidence. Fixed one-second windows allow a short burst at a boundary; in six smoke runs the sender peak never went above 100, so a sliding window was not needed.

## 13. Retry by failure class, with full jitter

- **Decision:** Every provider answer is sorted into one of four classes: 2xx `Sent`, 429 `RateLimited` (with `Retry-After` when usable), 5xx / timeout / connection error `Transient`, any other 4xx `Permanent`. `RetryPolicy` is a pure class: `Transient` waits a random time between 0 and `min(cap, base · 2^(n-1))` (full jitter), `RateLimited` waits the longer of `Retry-After` and that backoff, `Permanent` never retries, and after 5 failed attempts every class gives up. Base (200 ms), cap (10 s) and attempts live in `config/delivery.php`. A permanent or exhausted delivery stays `failed`, with a fixed error code that the dashboard explains in plain language.
- **Rationale:** Retrying a permanent error wastes calls, and retrying all failures at one fixed interval makes workers come back together. Full jitter spreads them, and honoring `Retry-After` avoids a second refusal.

## 14. Three layers against double sends

- **Decision:** (1) A DB unique key on `deliveries` (`campaign_id`, `customer_id`, `channel`) means a row exists once. (2) An atomic claim, a conditional `UPDATE` from `pending` (or a stale `sending`) to `sending`, means two workers cannot take the same row. (3) The provider `Idempotency-Key` (sha256 of campaign, customer and channel, unique on the row) deduplicates a retry after a timeout. `Delivery` is a rich model, and `sent`, `failed` and `skipped` are final.
- **Rationale:** Each layer covers a failure the others cannot: duplicate rows, concurrent workers, and an ambiguous network failure.

## 15. Mock provider as a separate plain-PHP container with fault injection

- **Decision:** `mock-provider/` is its own container: plain PHP (`php -S`, no framework) with counters in Redis. It adds latency and injects 500s, 429s, timeouts and accept-then-500 (stored, but answered with an error) at rates set by `MOCK_*`. A missing `Idempotency-Key` or a bad recipient is a 400 (permanent for the main app). `/stats` reports per channel the total, the duplicates it prevented and the peak per second.
- **Rationale:** Independent counters prove nothing was sent twice, and real network failures happen between containers. Timeouts and accept-then-500 create exactly the ambiguous failures the `Idempotency-Key` exists for.

## 16. Live status computed on request

- **Decision:** `GET /api/campaigns/{id}/stats` reads progress with one `GROUP BY channel, status` query over `deliveries`; every channel and status is returned, zero when absent. The SSE stream polls the same query every second and sends a frame only when the snapshot changed. `/api/metrics` (Prometheus text) and `/api/health` are computed at request time too. Health treats the database and Redis as critical (503 when either is down); a down provider only makes the status `degraded`, because the app still works and sends retry. Customer data is left out of these responses.
- **Rationale:** One indexed query keeps the cost constant per poll, and derived counters could drift from the rows. No extra counter state or container is needed, and phone numbers and emails stay out of the API and logs.

## 17. Frontend: Vue 3, TypeScript, Pinia and Tailwind behind nginx

- **Decision:** The frontend is a Vue 3 single-page app in TypeScript, built with Vite, with Pinia for state, Vue Router for the pages and Tailwind for styling. nginx serves the built app and proxies `/api` to the backend, so the browser talks to one origin. The interface is available in Turkish and English (`vue-i18n`); the switch only changes the interface text, never the message content that is sent to customers.
- **Rationale:** The case asks for Vue. Typed code and a typed store keep the screens that share one campaign (brief, audience, streamed content, dashboard) consistent with the API. One origin avoids CORS and keeps the stream and the dashboard on the same address as the page. The language switch is a display choice of the marketer, so it needs no server state.

## 18. Test strategy: real services, a pinned fake LLM, and end-to-end checks

- **Decision:** Unit tests need no database, Redis or framework boot. Integration tests run against the Postgres and Redis containers, in a separate `campaign_test` database and Redis db. `phpunit.xml` forces the fake LLM driver and an empty API key, and `Http::preventStrayRequests()` makes a test that reaches a real host fail. `make test` runs the backend, mock provider and frontend (Vitest) suites inside Docker. `make smoke` sends a large campaign through the faulty mock and checks the database against the provider's counters, and `make e2e` drives the whole flow in Chromium with Playwright.
- **Rationale:** `FILTER`, `ON CONFLICT` and throttling only show up on the real services, so an in-memory SQLite would hide them; a separate database can be wiped freely. Pinning the driver means a key in the environment can never make a test spend money or give non-deterministic results. Smoke and e2e cover what unit tests cannot: concurrency, real network faults and the browser.

## 19. Working with Claude: plan first, small tasks, a human gate before git

- **Decision:** The plan was written in plan mode before any code: phases of small tasks, each with its files, dependencies and acceptance check (`docs/plan.md`), and the open questions in the case answered by me first. Phase 1 set up the Claude tooling before the app: `CLAUDE.md` with the invariants, path-scoped rules, a `task` skill that queries the docs first (context7, Laravel Boost, the `claude-api` skill), a `finish-phase` skill (tests, lint, review, at most two fix rounds), a guard hook and permission rules that block reading real `.env` files, committing or pushing to `main` and destructive commands, a format hook, and a `test-runner` agent. Every task stops before git with a per-file table and the acceptance evidence; I review the diff, test in the browser and accept review findings selectively. Opus (high effort) planned, Sonnet (medium) implemented, Haiku ran the tests, and long sessions were compacted.
- **Rationale:** The case asks how AI output was checked. Small tasks keep every diff reviewable, the guard makes secrets and `main` safe by construction rather than by trust, and querying the docs keeps generated code on current APIs. Matching the model to the job keeps cost down where the work is routine.
