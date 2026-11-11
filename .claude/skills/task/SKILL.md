---
name: task
description: Implements exactly one numbered task from docs/plan.md (for example 1.8 or 2.1) end to end, from reading the task and checking the docs to tests, lint and a per-file report. Stops before any git command.
when_to_use: Use when the user runs /task N.M, or says "start task 2.1", "implement task 1.8", "continue task N.M", "do the next plan task" or "görev N.M".
argument-hint: "<N.M>"
---

# Task $ARGUMENTS

Work through the steps in order. Never skip a step; if one does not apply, say why in the report.

## 0. Read the task

- Always stop and ask for a task ID if `$ARGUMENTS` is empty or no `### $ARGUMENTS` heading exists in `docs/plan.md`.
- Always read that task section (work, files, dependencies, acceptance criterion; the plan labels them İş, Dosyalar, Bağımlı, Kabul) and every task it depends on.
- Never start when a dependency is not done (check `git log` and the files it should have produced); report it instead.

## 1. Docs first

- Always query the docs for each library you touch: context7 for JS/PHP libraries, Laravel Boost for Laravel (once it is installed). For LLM tasks always load the `claude-api` skill.
- Never write library or API code from memory when a docs source is available.
- If no docs apply, write one line of justification for the report.

## 2. Implement

- Always build the smallest change that meets the task's acceptance criterion.
- Never edit files outside the task's file list without saying why, and never start another task.

## 3. Test

- Always write the tests the acceptance criterion names and run them, using the `test-runner` agent once it exists.
- Never report a test as passing without having run it.

## 4. Lint

- Always run lint and type-check yourself in the main session, using the project's commands that exist so far. Mark the step "n/a" when no tooling exists yet.

## 5. Decisions

- Always write the decision to `DECISIONS.md` (decision, rationale, alternatives) when this task introduces an important decision.

## 6. Report, then stop

- Always end with: the per-file table, the acceptance evidence (command and output), the docs you queried, and open issues.
- Never run a git command in this skill. Wait for explicit approval.
