import { execFileSync } from "node:child_process";
import { resolve } from "node:path";
import { expect, type Page } from "@playwright/test";

export const backend = "http://127.0.0.1:8001";
export const stateOf = (id: number) =>
  JSON.parse(
    execFileSync(
      "php",
      ["tests/Infrastructure/weekly-plan-v3-e2e-state.php", String(id)],
      { cwd: resolve("backend"), encoding: "utf8" },
    ),
  );

export async function login(page: Page, user: string) {
  await page.goto("/login", { waitUntil: "domcontentloaded" });
  await page.locator("#email").fill(`${user}@weekly-plan-v3-e2e.example.test`);
  await page.locator("#password").fill("Kps-V3-E2E-Only!2026");
  for (let attempt = 0; attempt < 3; attempt++) {
    const response = page.waitForResponse(
      (r) =>
        r.url().endsWith("/api/auth/login") && r.request().method() === "POST",
    );
    await page.locator('form button[type="submit"]').click();
    const result = await response;
    if (result.status() !== 429) {
      expect(result.status()).toBe(200);
      break;
    }
    // Respect the real login middleware. Never bypass or clear its limiter.
    expect(attempt).toBeLessThan(2);
    await page.waitForTimeout(
      (Number(result.headers()["retry-after"]) || 60) * 1000 + 100,
    );
  }
  await expect(page).not.toHaveURL(/\/login$/);
}

export async function browserRequest(
  page: Page,
  path: string,
  method = "GET",
  body?: unknown,
  key?: string,
  csrf = true,
) {
  return page.evaluate(
    async ({ origin, path, method, body, key, csrf }) => {
      const token = document.cookie
        .split(";")
        .map((p) => p.trim())
        .find((p) => p.startsWith("XSRF-TOKEN="));
      const response = await fetch(origin + path, {
        method,
        credentials: "include",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          ...(csrf && token
            ? { "X-XSRF-TOKEN": decodeURIComponent(token.slice(11)) }
            : {}),
          ...(key ? { "Idempotency-Key": key } : {}),
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      });
      const text = await response.text();
      return { status: response.status, body: text ? JSON.parse(text) : null };
    },
    { origin: backend, path, method, body, key, csrf },
  );
}

export const pendingOf = (page: Page, id: number) =>
  page.evaluate((id) => {
    const raw = sessionStorage.getItem(
      `kps:weekly-plan-v3-reviewer-recovery:${id}`,
    );
    return raw ? JSON.parse(raw) : null;
  }, id);

export async function selectDecision(
  page: Page,
  employee: string,
  decision: string,
) {
  const toggle = page.getByRole("button", { name: new RegExp(employee) });
  if ((await toggle.getAttribute("aria-expanded")) !== "true")
    await toggle.click();
  await page
    .locator(`#${await toggle.getAttribute("aria-controls")}`)
    .getByRole("button", { name: decision, exact: true })
    .click();
}
