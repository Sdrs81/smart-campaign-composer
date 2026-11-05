# Smart Campaign Composer

## Tech Stack

Laravel 12 (PHP 8.4, FrankenPHP, JSON API only) · PostgreSQL 17 · Redis 7 · Vue 3 + TypeScript + Vite + Pinia + Tailwind · mock provider (plain PHP + Redis) · Pest, Vitest, Playwright.

## Commands

- `make up` / `make down` / `make fresh` / `make logs`
- `make test`
- `make lint`
- `make smoke`
- `make e2e`
- `node scripts/test-guard.mjs`
- `node scripts/test-format.mjs`

On Windows use the `docker compose` equivalents from the README. Host needs PHP 8.4, Composer, Node, Docker.

## Invariants

- Always validate LLM output (parse → rule check → typed value object).
- Never let LLM output become SQL; column names come only from `FieldRegistry`, values are bound.
- Always check opt-in twice: when counting/freezing the audience and right before sending.
- Always prevent double sends with all three layers: DB unique key, atomic claim, provider `Idempotency-Key`.
- Always read content limits from the single config; LLM and human edits use the same validator.
- Never read or write real env files (`.env*` except `.env.example`) or print secrets.

## Working rules

- Always work on one task at a time, started with `/task N.M`; read the matching rule files and docs first and record new decisions in `DECISIONS.md`.
- Always show a per-file table (`file | new/changed | what | why`) and the acceptance evidence before any git command.
- Never commit or push without explicit approval.
- Never commit on `main`; always use a branch per phase (`phase-N/<name>`).
- Always write commit messages as one short English line ending with `[Phase N.M]`, with trailer `Co-Authored-By: Claude <noreply@anthropic.com>`.
- Never work around a denied tool or command; stop and report.
- Always write project files in English; chat reports and `docs/plan.md` stay Turkish.
