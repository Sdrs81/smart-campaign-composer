# Decisions

## K1: Laravel 12 on FrankenPHP in classic mode (no Octane)

- **Decision:** Backend is Laravel 12 (PHP 8.4) served by FrankenPHP in classic mode. Octane is not used.
- **Rationale:** Queue, Redis throttling, migrations, seeders, Pest and SSE are all built in. One container, no output-buffering tuning for streams, and no risk of Octane state leaking between requests (KISS).
- **Alternatives:** Symfony, Slim; nginx + php-fpm.

## Task 2.1 scaffold choices

- **JSON API only:** `routes/web.php`, the welcome view, Vite, `package.json` and `resources/js|css` are removed. `bootstrap/app.php` registers `routes/api.php` and the `/up` health route.
- **No default auth tables or `User` model:** the users and cache migrations, `User`, and `UserFactory` are removed; `config/auth.php` points at `Illuminate\Foundation\Auth\User` so static analysis resolves. The jobs migration (jobs, job_batches, failed_jobs) is kept for the queue.
- **Pest replaces PHPUnit** as the runner (`pestphp/pest` + `pest-plugin-laravel`); `tests/Pest.php` applies `TestCase` to Feature tests, Unit tests opt in with `uses()`.
- **Larastan 3 at level 6** (`backend/phpstan.neon`), analysing `app`, `bootstrap`, `config`, `database`, `routes`. Pint uses its default Laravel preset.
- **Env files:** the root `.env.example` is created in task 2.3, so none is shipped under `backend/`.

## K3: Redis for queue, global rate limiter and locks

- **Decision:** One Redis 7 instance serves the Laravel queue (separate `sms` and `email` queues), the global rate limiter and locks. `.env.example` sets `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis`.
- **Rationale:** A single component covers all three jobs; RabbitMQ would add operational load at this scale (YAGNI).
- **Alternatives:** RabbitMQ, database queue.

## K9: `LlmClient` interface with a fake driver selected automatically

- **Decision:** `config/llm.php` resolves `LLM_DRIVER=auto` to `claude` when `ANTHROPIC_API_KEY` is set and to `fake` when it is empty. The raw value stays in `llm.requested`. `AppServiceProvider` logs a warning on that fallback. The model comes from `ANTHROPIC_MODEL` (default `claude-sonnet-5-5`; `claude-haiku-5-5` is the cheaper option).
- **Rationale:** Tests and `docker compose up` must work without a key. The warning keeps a missing key from looking like a working Claude setup. The Laravel HTTP client keeps `Http::fake` testing simple and makes a later SDK switch a one-class change.
- **Alternatives:** The official PHP SDK.

## Task 2.3 config choices

- **Single source for limits:** `config/delivery.php` holds the SMS (160), subject (80) and email body (2000) limits, the send rate, workers per channel, provider timeouts and retry policy. No other file repeats these numbers.
- **Mock provider rates** (`MOCK_RATE_*`, latency range) live only in `.env.example`; the mock provider reads them directly, so `config/delivery.php` does not mirror them.
- **Unit tests load config files directly** (no framework boot) per the backend rule; `AppBootTest` moved from `tests/Unit` to `tests/Feature` for the same reason.
