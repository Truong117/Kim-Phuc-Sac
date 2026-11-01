import { expect, test } from "@playwright/test";

test("local browser reaches the guarded backend and frontend", async ({
  page,
}) => {
  await page.goto("/login");
  await expect(page.locator("#email")).toBeVisible();
  const response = await page.request.get("http://127.0.0.1:8001/api/health");
  expect(response.status()).toBe(200);
  expect((await response.json()).status).toBe("ok");
});
