# Git Bash on Windows rewrites container paths such as "-w /mock" into host paths; this switches that off
# and does nothing elsewhere.
export MSYS_NO_PATHCONV := 1

COMPOSE := docker compose
FRONTEND_IMAGE := smart-campaign-frontend:tools

.PHONY: up down fresh logs test test-backend test-mock test-frontend lint lint-backend lint-frontend smoke e2e frontend-tools

# A fresh clone has no .env; compose and the containers read it.
.env:
	cp .env.example .env

up: .env
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

# Rebuilds the development schema and data without touching volumes.
fresh: .env
	$(COMPOSE) exec app php artisan migrate:fresh --seed --force

logs:
	$(COMPOSE) logs -f --tail=100

# Tests run inside Docker so the host needs no PHP or Node setup.
test: test-backend test-mock test-frontend

# The backend code is baked into the image (no bind mount), so rebuild first or the run tests stale code.
test-backend: .env
	$(COMPOSE) build app
	$(COMPOSE) run --rm app vendor/bin/pest

# The mock provider has no Composer; its tests run with the backend image's Pest against Redis db 1.
test-mock: .env
	$(COMPOSE) run --rm -v "$(CURDIR)/mock-provider:/mock" -w /mock app /app/vendor/bin/pest --configuration=/mock/phpunit.xml

test-frontend: frontend-tools
	docker run --rm $(FRONTEND_IMAGE) npm test

lint: lint-backend lint-frontend

lint-backend: .env
	$(COMPOSE) build app
	$(COMPOSE) run --rm app sh -c "vendor/bin/pint --test && vendor/bin/phpstan --memory-limit=1G"

lint-frontend: frontend-tools
	docker run --rm $(FRONTEND_IMAGE) sh -c "npm run lint && npm run type-check && npm run format:check"

# The build stage of the frontend image already holds Node and the dependencies.
frontend-tools:
	docker build --target build -t $(FRONTEND_IMAGE) -f frontend/Dockerfile .

# Needs the stack from "make up". Sends to the mock provider with its fault injection on.
smoke:
	node scripts/smoke.mjs

# Drives the real stack in Chromium (host needs Node). Rebuilds first so the browser sees the current code;
# --wait returns once every service reports healthy. Screenshots land in frontend/e2e/artifacts/.
# npm ci first: a fresh clone has no node_modules, so Playwright itself is not installed yet.
e2e: .env
	$(COMPOSE) up -d --build --wait
	cd frontend && npm ci && npx playwright install chromium && npx playwright test
