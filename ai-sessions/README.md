# AI sessions

Exports of the Claude working sessions used to build this project.

- **Source:** Claude Code `/export`.
- **Naming:** `phase-<N>_<topic>.md`, for example `phase-0_plan.md` or `phase-1_setup.md`. A second session in the same phase gets a counter: `phase-5_llm-2.md`.
- **Cleanup:** run `node scripts/scrub-sessions.mjs` before committing an export, then `node scripts/scrub-sessions.mjs --check`, which must exit 0.

How the work was organized is described in [DECISIONS.md](../DECISIONS.md) (decision 19).

## Where I stepped in

Most messages in the exports approve a task and start the next one. These are the places where I changed the direction or corrected the output.

| Phase | What happened                                                                                                                                                                                                                                                                                      | Result                                                                                     |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| 0     | Answered the plan's open questions: count the characters the recipient sees, treat the sample brief as an example only (so the customer columns stay broad), let different campaigns reach the same person, allow editing only of the generated text, no access to `.env`, core flow in five days. | Recorded in the plan and in `DECISIONS.md`.                                                |
| 1     | Project files and commit tags were written in Turkish; rules repeated `CLAUDE.md` and had no reasons; questioned a review finding ("are you sure?").                                                                                                                                               | Everything in English, rules rewritten with Always/Never and a reason, no duplicates.      |
| 2     | Claude flagged an old `backend/.env` it was not allowed to read. Decision and plan notes were growing too detailed.                                                                                                                                                                                | I checked and deleted the file myself; notes kept short and only on the tasks they affect. |
| 5     | Asked for a fresh review of task 5.5 with no change unless a real gap was found; compacted the long session.                                                                                                                                                                                       | No unneeded change.                                                                        |
| 7     | Mock provider tests were written with PHPUnit although the project uses Pest. The first result of task 7.2 was not good enough.                                                                                                                                                                    | Switched to Pest and corrected the plan; reverted 7.2 and redid it with the `task` skill.  |
| 9     | Asked for a live `curl` check of the stream; applied only the review suggestion marked important (provider state cache).                                                                                                                                                                           | Stream verified live.                                                                      |
| 11    | Ran the code review on the whole repository, not only the phase.                                                                                                                                                                                                                                   | Fixed only two findings: a start job lost after approval and a prompt-tag check mismatch.  |
| 12    | My own browser test: two campaigns showed the same generic delivery error. Scope limited with KISS/YAGNI.                                                                                                                                                                                          | One plain-language message per error code, raw text behind "Technical detail".             |
| 13    | My findings on the key-less demo: the fake audience ignored the brief's filters, SMS and email did not share the offer, "Total" did not add up the channel counts.                                                                                                                                 | Fake reads the brief; SMS and email share the offer; label renamed to total people.        |
| 14    | Audit of the code against the case; asked for a read-only report (critical? structural?) before choosing a fix.                                                                                                                                                                                    | One-command setup, LLM isolation in tests, rate limit measured on the sender side.         |

## What the scrub script masks

| Value                                                                      | Replaced with          | Kept as is                                                            |
| -------------------------------------------------------------------------- | ---------------------- | --------------------------------------------------------------------- |
| API keys and tokens (`sk-ant-…`, GitHub, AWS, Slack, Google, `Bearer …`)   | `[API_KEY]`, `[TOKEN]` | –                                                                     |
| Email addresses                                                            | `[EMAIL]`              | `noreply@anthropic.com`, `noreply@github.com`, `@example.com/org/net` |
| Phone numbers (`+` international, Turkish national, US)                    | `[PHONE]`              | –                                                                     |
| Windows user folder (`C:\Users\<name>`, also `C:/…`, `/c/…`, JSON-escaped) | `[USER]`               | `Public`, `Default`, `All Users`                                      |

`--check` never prints the matched text, only `file:line rule`, and changes nothing.

## Limits

The script finds values by pattern. It does not know names, handles or secrets written in free text, so skim the diff of an export before committing it.
