---
name: review-pr
description: Read-only review of everything on the current branch against main (committed, staged, unstaged and untracked changes). Reports findings only and never changes files.
when_to_use: Use when the user runs /review-pr, or asks to review the branch, the diff or the changes before a PR or a commit.
context: fork
agent: Explore
---

# Review the branch

## 1. Collect the full change set

- Always read all four sources: `git diff main...HEAD` (commits), `git diff HEAD` (staged and unstaged), the untracked files listed by `git status --short` (marked `??`, read each with Read), and the commit messages from `git log main..HEAD`.
- Never review only the committed diff; uncommitted work is part of the change.
- Never modify a file, stage anything or run a git command that changes state.

## 2. Check

- Always check each item below against the change set:
  - Project invariants are intact: LLM output validated and never SQL, opt-in checked twice, double sends prevented, limits from one config, no real env files touched.
  - No secret is present: API keys (`sk-ant-`), tokens, passwords, contents of real env files.
  - Fixtures are neutral: no real names, emails or phone numbers.
  - Every changed behavior has a test, and the tests would fail without the change.
  - Rule violations: anything that breaks the loaded rules for the files in the change.
- Never report style preferences or refactors as findings.

## 3. Report

- Always list findings as `file:line | problem | why it matters`, most severe first, grouped as Blocking (invariant, security, secret, bug) and Should fix (missing test, rule violation).
- Always say "No findings" explicitly when a group is empty.
- Never propose or apply fixes in this skill; the caller decides.
