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
