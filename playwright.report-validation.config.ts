import { defineConfig, devices } from "@playwright/test";

// Isolated UI tests: all API calls are intercepted; no database or real login.
export default defineConfig({
  testDir: "./e2e",
  testMatch: [
    "spa-ui.spec.ts",
    "report-validation-ui.spec.ts",
    "task-report-progress-ui.spec.ts",
    "task-progress-reconciliation-ui.spec.ts",
    "facebook-post-picker-ui.spec.ts",
    "facebook-analytics-ui.spec.ts",
    "daily-document-reader-ui.spec.ts",
    "weekly-ai-reader-ui.spec.ts",
    "personal-weekly-report-ui.spec.ts",
    "personal-report-mail-ui.spec.ts",
    "integrations-persistence-ui.spec.ts",
    "social-metrics-ui.spec.ts",
    "mail-foundation-ui.spec.ts",
    "mail-compose-ui.spec.ts",
  ],
  workers: 1,
  // Leave one minute for failure diagnostics before the CI step times out.
  globalTimeout: process.env.CI ? 8 * 60 * 1000 : undefined,
  timeout: 45_000,
  expect: { timeout: 10_000 },
  reporter: "list",
  outputDir: "test-results/report-validation",
  use: {
    ...devices["Desktop Chrome"],
    baseURL: "http://127.0.0.1:5176",
    screenshot: "only-on-failure",
    trace: "retain-on-failure",
  },
  webServer: {
    command: "npm run dev -- --host 127.0.0.1 --port 5176 --strictPort",
    url: "http://127.0.0.1:5176",
    env: {
      VITE_API_BASE_URL: "/",
      VITE_SPA_UI_PREVIEW_ENABLED: process.env.SPA_UI_TEST_ENABLED ?? "true",
    },
    reuseExistingServer: !process.env.CI,
  },
});
