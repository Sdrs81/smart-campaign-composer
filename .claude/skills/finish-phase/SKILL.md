---
name: finish-phase
description: Closes the current phase branch. Runs tests and lint, the read-only review and a code review, fixes only real problems in at most two rounds, shows a per-file phase summary and the /export command for the user, then after the export scrubs it and asks approval for the git steps (export commit, push, PR).
when_to_use: Use when the user runs /finish-phase, or says the phase is done and asks to wrap up, review and open the PR.
argument-hint: "[low|medium|high]"
disable-model-invocation: true
---

# Finish the phase

## 0. Locate the phase

- Always read the phase number N from the branch name `phase-N/<name>` (`git branch --show-current`).
- Never continue on `main` or on a branch that does not match; stop and report.
- Always pick the review level from `$ARGUMENTS` when given. Otherwise use `high` for phases 5 and 8 and `medium` for all others.

## 1. Tests and lint

- Always run the project's test and lint commands that exist so far (`make test`, `make lint`, and the `node scripts/test-*.mjs` scripts until the Makefile exists).
- Never go on to the reviews while anything is red; fix it first.

## 2. Reviews

- Always invoke the `review-pr` skill through the Skill tool, then the `code-review` skill with the chosen level.
- Never run more than two review rounds in total.

## 3. Fix

- Always fix only bugs, security problems and rule violations. Rerun tests and lint after the fixes.
- Never fix style preferences or unrelated refactors, and never commit the fixes before approval.
- Always keep a list of rejected findings with a one-line reason each.

## 4. Summary and export command, then stop

- Always end with: a per-file table for every file changed in the phase (`file | new/changed | what | why`, from `git diff --name-status main...HEAD` plus uncommitted files), the test and lint results, the fixes, the rejected findings, and a proposed English PR title.
- Always end the summary by telling the user the exact command to run now, with the file name derived from the branch `phase-N/<topic>`: `/export ai-sessions/phase-N_<topic>.md`. If that name already exists in git, use a counter: `phase-N_<topic>-2.md`.
- Always stop after giving the command and wait for the user to confirm the export is done. Never run `/export` yourself or rebuild the file from the transcript, because it is a built-in command only the user can run.
- Never commit, push, open a PR or merge in this step.

## 5. After the export: check and scrub

- Always check that the export file exists, is not empty and is new (untracked or modified in `git status`). Never continue with a missing, empty or already committed export; report it and repeat the command instead.
- Always run `node scripts/scrub-sessions.mjs`, then `node scripts/scrub-sessions.mjs --check`, which must exit 0. Never go on while `--check` fails.
- Always skim the scrubbed file for names, handles or secrets the patterns cannot know, and report anything found.
- Always finish with the git plan and ask for approval in plain words: commit only the export file with `docs: add phase N session export [Phase N]`, then `git push -u origin <branch>`, then the PR. Never run any of these before the user approves.

## 6. After approval: commit, push, PR

- Always commit the export file alone with the message `docs: add phase N session export [Phase N]` and the usual `Co-Authored-By` trailer, then push with `git push -u origin <branch>`.
- Always open the PR with `gh pr create`. Write the title and body in English, mention the session export in the body, and end it with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
- Never merge; the user does.
