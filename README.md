# Smart Campaign Composer

> **Draft.** Written during the Phase 1 dry run. Sections marked _TBD_ are filled in by the phase that builds them.

A marketer writes a campaign brief in plain language. Claude turns it into a segment rule and SMS/email copy, a human previews, edits and approves it, the messages go through a queue to a mock provider, and a dashboard shows delivery status live.

## Setup

_TBD (Phase 2): `docker compose up` starts everything, with migrations and seed data applied automatically._

- Copy `.env.example` to `.env`. `ANTHROPIC_API_KEY` is optional: without it the app runs on a built-in fake LLM.
- Requirements: Docker. On Windows, where `make` is usually missing, use the `docker compose` equivalents listed here once the Makefile exists.
- There is no authentication: this is a single-marketer local demo.

## Architecture

_TBD (Phase 2 and later): diagram and component overview._

- Backend: Laravel 12, JSON API only.
- Data and queue: PostgreSQL 17, Redis 7.
- Frontend: Vue 3 and TypeScript.
- Mock provider: a separate container that injects delays and failures.

The decisions behind these choices are recorded in `DECISIONS.md`.

## Flow

_TBD._ Brief, then audience, then content, then approval, then sending, then dashboard.

## Tests

_TBD (Phase 2): `make test`, `make lint`, `make smoke`, `make e2e`._

The Claude Code tooling has its own tests today:

```
node scripts/test-guard.mjs
node scripts/test-format.mjs
node scripts/test-scrub.mjs
```

## Not done

_TBD._ Anything left out on purpose is listed here before delivery.
