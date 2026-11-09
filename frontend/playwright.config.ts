import { defineConfig, devices } from '@playwright/test'

// The test drives the running compose stack (`make up`) through its nginx, exactly as a marketer's browser would.
const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8080'

export default defineConfig({
  testDir: './e2e',
  // Screenshots (written by the test) and failure traces end up here, ready to be looked at.
  outputDir: './e2e/artifacts/results',
  // One campaign, one flow: steps depend on each other and share the mock provider's rate limit.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  // Sending to the mock provider with its faults on takes several seconds; the test sets its own long waits.
  timeout: 180_000,
  expect: { timeout: 15_000 },
  reporter: [['list']],
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
