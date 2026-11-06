# AI sessions

Exports of the Claude working sessions used to build this project.

- **Source:** Claude Code `/export`.
- **Naming:** `phase-<N>_<topic>.md`, for example `phase-0_plan.md` or `phase-1_setup.md`. A second session in the same phase gets a counter: `phase-5_llm-2.md`.
- **Cleanup:** run `node scripts/scrub-sessions.mjs` before committing an export, then `node scripts/scrub-sessions.mjs --check`, which must exit 0.

## What the scrub script masks

| Value | Replaced with | Kept as is |
| --- | --- | --- |
| API keys and tokens (`sk-ant-…`, GitHub, AWS, Slack, Google, `Bearer …`) | `[API_KEY]`, `[TOKEN]` | – |
| Email addresses | `[EMAIL]` | `noreply@anthropic.com`, `noreply@github.com`, `@example.com/org/net` |
| Phone numbers (`+` international, Turkish national, US) | `[PHONE]` | – |
| Windows user folder (`C:\Users\<name>`, also `C:/…`, `/c/…`, JSON-escaped) | `[USER]` | `Public`, `Default`, `All Users` |

`--check` never prints the matched text, only `file:line rule`, and changes nothing.

## Limits

The script finds values by pattern. It does not know names, handles or secrets written in free text, so skim the diff of an export before committing it.
