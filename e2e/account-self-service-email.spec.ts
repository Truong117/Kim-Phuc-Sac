import { expect, test } from "@playwright/test";
import { browserRequest, login } from "./helpers";

const password = "Kps-V3-E2E-Only!2026";
const updatedPassword = "Kps-Account-E2E-Changed!2026";
const oldEmail = "employee-a@weekly-plan-v3-e2e.example.test";
const newEmail = "employee-a-updated@weekly-plan-v3-e2e.example.test";

test("profile, direct email update, password and next login work through the real local API", async ({
  page,
}, testInfo) => {
  test.setTimeout(120_000);
  const pageErrors: string[] = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));
  await login(page, "employee-a");
  const before = await browserRequest(page, "/api/auth/me");
  const navigation = await browserRequest(page, "/api/navigation");
  await page.goto("/profile");
  await expect(page.locator('main input[name="name"]')).toBeVisible();
  await expect(
    page.locator('main input[name="email"], main input[type="password"]'),
  ).toHaveCount(0);
  await page.locator('input[name="name"]').fill("Account E2E Employee (Fake)");
  const profileSaved = page.waitForResponse(
    (response) =>
      response.url().endsWith("/api/account/profile") &&
      response.request().method() === "PATCH",
  );
  await page.locator('form button[type="submit"]').click();
  expect((await profileSaved).status()).toBe(200);
  await page
    .getByRole("navigation", { name: "Hồ sơ và tài khoản" })
    .getByRole("link", { name: /Cài đặt tài khoản/ })
    .click();
  await expect(page.locator('main input[name="name"]')).toHaveCount(0);
  const form = page
    .locator("form")
    .filter({ has: page.locator("#account-new-email") });

  for (const [email, currentPassword, message] of [
    [newEmail, "incorrect-password", "Mật khẩu hiện tại không chính xác."],
    [
      "employee-b@weekly-plan-v3-e2e.example.test",
      password,
      "Email đã được sử dụng.",
    ],
  ]) {
    await page.locator("#account-new-email").fill(email);
    await page.locator("#account-email-password").fill(currentPassword);
    const rejected = page.waitForResponse(
      (response) =>
        response.url().endsWith("/api/account/email") &&
        response.request().method() === "PATCH",
    );
    await form
      .getByRole("button", { name: "Cập nhật email", exact: true })
      .click();
    expect((await rejected).status()).toBe(422);
    await expect(page.getByRole("alert")).toContainText(message);
    await expect(page.locator("#account-email-password")).toHaveValue("");
  }

  await page
    .locator("#account-new-email")
    .fill(`  ${newEmail.toUpperCase()}  `);
  await page.locator("#account-email-password").fill(password);
  const saved = page.waitForResponse(
    (response) =>
      response.url().endsWith("/api/account/email") &&
      response.request().method() === "PATCH",
  );
  await form
    .getByRole("button", { name: "Cập nhật email", exact: true })
    .click();
  const result = await saved;
  expect(result.status()).toBe(200);
  expect(result.request().postDataJSON()).toEqual({
    email: newEmail,
    current_password: password,
    expected_email: oldEmail,
  });
  await expect(page.getByRole("status")).toContainText(
    "Email đăng nhập đã được cập nhật",
  );
  await expect(page.locator("#account-email-password")).toHaveValue("");
  const after = await browserRequest(page, "/api/auth/me");
  expect(after.status).toBe(200);
  expect(after.body.id).toBe(before.body.id);
  expect(after.body.email).toBe(newEmail);
  for (const field of ["role", "organization", "department", "location"])
    expect(after.body[field]).toEqual(before.body[field]);
  expect((await browserRequest(page, "/api/navigation")).body).toEqual(
    navigation.body,
  );
  await page.reload();
  await expect(page.locator("#account-new-email")).toBeVisible();

  await page.locator("#account-current-password").fill(password);
  await page.locator("#account-new-password").fill(updatedPassword);
  await page.locator("#account-password-confirmation").fill(updatedPassword);
  const passwordSaved = page.waitForResponse(
    (response) =>
      response.url().endsWith("/api/account/password") &&
      response.request().method() === "PATCH",
  );
  await page
    .locator("form")
    .filter({ has: page.locator("#account-current-password") })
    .locator('button[type="submit"]')
    .click();
  expect((await passwordSaved).status()).toBe(200);
  expect((await browserRequest(page, "/api/auth/logout", "POST")).status).toBe(
    204,
  );
  await page.goto("/login");
  await page.locator("#email").fill(oldEmail);
  await page.locator("#password").fill(updatedPassword);
  const oldLogin = page.waitForResponse((response) =>
    response.url().endsWith("/api/auth/login"),
  );
  await page.locator('form button[type="submit"]').click();
  expect((await oldLogin).status()).toBe(422);
  await page.locator("#email").fill(newEmail);
  await page.locator("#password").fill(updatedPassword);
  await page.locator('form button[type="submit"]').click();
  await expect(page).not.toHaveURL(/\/login$/);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/account");
  await expect(page.locator("#account-new-email")).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth + 1,
    ),
  ).toBe(true);
  await page.evaluate(() => document.documentElement.classList.add("dark"));
  await expect(
    page
      .getByRole("navigation", { name: "Hồ sơ và tài khoản" })
      .locator('a[href="/profile"]'),
  ).toHaveCSS("background-color", "rgb(16, 24, 40)");
  await page.screenshot({
    path: testInfo.outputPath("account-mobile-dark.png"),
    fullPage: true,
    animations: "disabled",
  });
  expect(pageErrors).toEqual([]);
});

test("two open tabs dispatch competing email changes and exactly one succeeds", async ({
  page,
  context,
}) => {
  await login(page, "employee-b");
  await page.goto("/account");
  const other = await context.newPage();
  await other.goto("/account");
  const emails = [
    "employee-b-first@weekly-plan-v3-e2e.example.test",
    "employee-b-second@weekly-plan-v3-e2e.example.test",
  ];
  for (const [index, tab] of [page, other].entries()) {
    await tab.locator("#account-new-email").fill(emails[index]);
    await tab.locator("#account-email-password").fill(password);
  }
  const responses = [page, other].map((tab) =>
    tab.waitForResponse(
      (response) =>
        response.url().endsWith("/api/account/email") &&
        response.request().method() === "PATCH",
    ),
  );
  await Promise.all(
    [page, other].map((tab) =>
      tab
        .locator("form")
        .filter({ has: tab.locator("#account-new-email") })
        .locator('button[type="submit"]')
        .click(),
    ),
  );
  const results = await Promise.all(responses);
  expect(results.map((response) => response.status()).sort()).toEqual([
    200, 409,
  ]);
  const winner = results.findIndex((response) => response.status() === 200);
  expect((await browserRequest(page, "/api/auth/me")).body.email).toBe(
    emails[winner],
  );
  await expect([page, other][1 - winner].getByRole("alert")).toContainText(
    "Email đã được cập nhật ở nơi khác",
  );
  await other.close();
});
