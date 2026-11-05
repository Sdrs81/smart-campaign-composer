---
description: Language-agnostic code quality principles for every source file in the project.
paths:
  - "backend/**/*.php"
  - "mock-provider/**/*.php"
  - "frontend/src/**/*.{ts,vue}"
  - "scripts/**/*.mjs"
  - ".claude/hooks/**/*.mjs"
---

# Clean code

Always keep one source of truth per fact and extract shared code on the third copy. Never copy a rule into a second place.
- Early extraction guesses the wrong abstraction, and copies drift apart.

Always write the smallest code that meets the task's acceptance criteria. Never add speculative options, layers or abstractions.
- Unused flexibility is code to maintain and test.

Always write comments that explain why. Never leave commented-out code or comments that restate the code.
- What the code does is already readable, and git keeps the history.

Always name things after the domain (campaign, audience, delivery). Never use abbreviations or generic names such as `data`, `util`, `manager`.
- Vague names hide intent.

Always use a named constant or config value. Never use magic numbers or strings.
- A bare `160` or `5` carries no meaning and is hard to change safely.

Always handle the error, rethrow a typed one, or log why ignoring it is safe. Never leave a `catch` empty.
- A swallowed error becomes an unexplained failure later.

Always prefer early returns and one responsibility per function. Never nest conditionals when an early return works.
- Short, shallow functions are easier to read and test.

Always make error messages say what failed, which value or path, and what is allowed. Never write generic messages such as "invalid input".
- The reader (a user, the repair prompt, a log) must be able to fix it without guessing.

Always validate at boundaries (HTTP, LLM, provider). Never re-validate typed values inside the system.
- Checking everywhere duplicates work; checking nowhere lets bad data in.
