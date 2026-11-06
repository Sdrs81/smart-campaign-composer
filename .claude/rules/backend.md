---
description: Laravel conventions for the backend API: controllers, models, config, errors and tests.
paths:
  - "backend/**/*.php"
---

# Backend (Laravel)

Always add `declare(strict_types=1)` and type every property, parameter and return value. Never rely on PHP's implicit type coercion.
- Silent coercion hides bugs.

Always keep controllers thin: FormRequest validates, one call into an action or service, an API Resource shapes the response. Never put business logic in a controller.
- Logic in controllers cannot be reused or unit tested.

Always put behavior in rich models (`Campaign::approve()`, `Delivery::markSent()`) or service classes inside feature folders (`app/Segment`, `Llm`, `Content`, `Delivery`), and make invalid state transitions throw. Never change a status field from outside its model.
- The rules then live next to the data they protect.

Always use `config()` outside `config/*.php`. Never call `env()` anywhere else.
- `env()` returns null once the config is cached.

Always throw typed exceptions and map them once in `bootstrap/app.php` to `{error:{code,message,details}}`. Never return ad-hoc error arrays.
- Clients and the UI then handle a single error shape.

Always prefer set-based SQL (`INSERT … SELECT`, `COUNT(*) FILTER`). Never loop over rows in PHP when one statement can do the job.
- It is one round trip with constant memory.

Always keep `tests/Unit` free of database, Redis and framework boot, and put HTTP and integration tests in `tests/Feature` against the real Postgres/Redis test database. Never mock the database in integration tests.
- Unit tests stay fast, and `FILTER`, `ON CONFLICT` and throttle behavior only show up against the real services.

Always use neutral fixtures (`example.com` emails, fake phone numbers, seeded Faker). Never use real people or data.
- Fixtures are committed to the repository, and tests must be deterministic.
