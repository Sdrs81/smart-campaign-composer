# Smart Campaign Composer

> **Work in progress.** Sections marked _TBD_ are filled in by the phase that builds them.

A marketer writes a campaign brief in plain language. Claude turns it into a segment rule and SMS/email copy, a human previews, edits and approves it, the messages go through a queue to a mock provider, and a dashboard shows delivery status live.

## Setup

Requirements: Docker. Node, PHP and Composer are not needed on the host.

```
cp .env.example .env
docker compose up -d --build
```

Then open <http://localhost:8080>. The API health check is at <http://localhost:8080/api/health>.

- Migrations run automatically when the `app` container starts. Seed data is added once a seed table exists.
- `ANTHROPIC_API_KEY` is optional. Without it the app runs on a built-in fake LLM and logs a warning.
- There is no authentication: this is a single-marketer local demo.
- To work on the UI with hot reload, keep the stack running and start `npm run dev` in `frontend/` (needs Node on the host), then open <http://localhost:5173>. Vite forwards `/api` to the stack on port 8080. Without hot reload, `docker compose up -d --build web` rebuilds the served app.

### Commands

`make` targets and their `docker compose` equivalents, for machines without `make` (Windows):

| Target               | Equivalent                                                                                                                                                        |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `make up`            | `docker compose up -d --build`                                                                                                                                    |
| `make down`          | `docker compose down`                                                                                                                                             |
| `make logs`          | `docker compose logs -f --tail=100`                                                                                                                               |
| `make fresh`         | `docker compose exec app php artisan migrate:fresh --seed --force`                                                                                                |
| `make test-backend`  | `docker compose build app`, then `docker compose run --rm app vendor/bin/pest` (the code is baked into the image, so build first)                                 |
| `make test-mock`     | `docker compose run --rm -v ./mock-provider:/mock -w /mock app /app/vendor/bin/pest --configuration=/mock/phpunit.xml` (use an absolute host path for the volume) |
| `make lint-backend`  | `docker compose build app`, then `docker compose run --rm app sh -c "vendor/bin/pint --test && vendor/bin/phpstan --memory-limit=1G"`                             |
| `make test-frontend` | `docker build --target build -t smart-campaign-frontend:tools -f frontend/Dockerfile .` then `docker run --rm smart-campaign-frontend:tools npm test`             |
| `make lint-frontend` | same image, `npm run lint && npm run type-check && npm run format:check`                                                                                          |

## Architecture

_TBD (later phases): diagram and component overview._

- **Backend:** Laravel 12 on FrankenPHP (classic mode), JSON API only.
- **Data and queue:** PostgreSQL 17; Redis 7 for the queue (separate `sms` and `email` queues), the rate limiter and locks.
- **Workers:** the backend image runs again as `worker-sms` and `worker-email`; the replica count per channel comes from `WORKERS_PER_CHANNEL`.
- **Frontend:** Vue 3, TypeScript and Tailwind, served by nginx, which also proxies `/api` to the backend.
- **Mock provider:** a separate container that injects delays and failures (added in Phase 7).

The decisions behind these choices are recorded in [DECISIONS.md](DECISIONS.md).

## Flow

_TBD._ Brief, then audience, then content, then approval, then sending, then dashboard.

## Tests

`make test` runs the backend (Pest), mock provider (Pest, Redis db 1) and frontend (Vitest) suites inside Docker. Backend integration tests use the real Postgres and Redis containers, in a separate `campaign_test` database and Redis db, so development data is never touched. `make lint` runs Pint, Larastan, ESLint, the type check and Prettier.

_TBD (later phases): `make smoke`, `make e2e`._

The Claude Code tooling has its own tests:

```
node scripts/test-guard.mjs
node scripts/test-format.mjs
node scripts/test-scrub.mjs
```

## Not done

_TBD._ Anything left out on purpose is listed here before delivery.
