import { expect, test } from "@playwright/test";

const backend = "http://127.0.0.1:8001";

test("synthetic login, Sanctum cookie, CSRF, logout and anonymous rejection", async ({
  page,
}) => {
  test.setTimeout(180_000);
  await page.goto("/login", { waitUntil: "domcontentloaded" });
  const anonymous = await page.evaluate(async (origin) => {
    const response = await fetch(`${origin}/api/weekly-plans`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    });
    return response.status;
  }, backend);
  expect(anonymous).toBe(401);

  await page.locator("#email").fill("hr@weekly-plan-v3-e2e.example.test");
  await page.locator("#password").fill("Kps-V3-E2E-Only!2026");
  await page.locator('form button[type="submit"]').click();
  await expect(page).not.toHaveURL(/\/login$/);

  const authenticated = await page.evaluate(async (origin) => {
    const response = await fetch(`${origin}/api/auth/me`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    });
    return response.status;
  }, backend);
  expect(authenticated).toBe(200);
  await page.reload();
  await expect(page).not.toHaveURL(/\/login$/);

  const cookies = await page.context().cookies(backend);
  expect(cookies.some((cookie) => cookie.name === "XSRF-TOKEN")).toBe(true);
  expect(cookies.some((cookie) => cookie.name === "kps_v3_e2e_session")).toBe(
    true,
  );

  const logout = await page.evaluate(async (origin) => {
    const token = document.cookie
      .split(";")
      .map((part) => part.trim())
      .find((part) => part.startsWith("XSRF-TOKEN="));
    const response = await fetch(`${origin}/api/auth/logout`, {
      method: "POST",
      credentials: "include",
      headers: {
        Accept: "application/json",
        "X-XSRF-TOKEN": token
          ? decodeURIComponent(token.slice("XSRF-TOKEN=".length))
          : "",
      },
    });
    return response.status;
  }, backend);
  expect(logout).toBe(204);

  const afterLogout = await page.evaluate(async (origin) => {
    const response = await fetch(`${origin}/api/auth/me`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    });
    return response.status;
  }, backend);
  expect(afterLogout).toBe(401);
});
