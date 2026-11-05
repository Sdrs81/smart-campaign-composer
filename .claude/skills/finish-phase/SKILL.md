---
name: finish-phase
description: Closes the current phase branch. Runs tests and lint, the read-only review and a code review, fixes only real problems in at most two rounds, then prepares a per-file phase summary and stops for approval before push and PR.
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

## 4. Summary, then stop

- Always end with: a per-file table for every file changed in the phase (`file | new/changed | what | why`, from `git diff --name-status main...HEAD` plus uncommitted files), the test and lint results, the fixes, the rejected findings, and a proposed English PR title.
- Never push, open a PR or merge before the user approves in plain words.

## 5. After approval

- Always push with `git push -u origin <branch>`, then open the PR with `gh pr create`. Write the title and body in English and end the body with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
- Never merge; the user does.
