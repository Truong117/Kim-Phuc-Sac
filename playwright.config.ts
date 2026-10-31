import { defineConfig, devices } from "@playwright/test";
const frontend = "http://127.0.0.1:5174";

export default defineConfig({
  testDir: "./e2e",
  globalSetup: "./e2e/verify-local-stack.ts",
  workers: 1,
  fullyParallel: false,
  forbidOnly: true,
  timeout: 60_000,
  expect: { timeout: 30_000 },
  reporter: [["list"], ["html", { open: "never" }]],
  outputDir: "test-results/artifacts",
  use: {
    ...devices["Desktop Chrome"],
    baseURL: frontend,
    actionTimeout: 30_000,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
    video: "retain-on-failure",
  },
});
