import { execFileSync } from "node:child_process";
import { resolve } from "node:path";
import { expect, test } from "@playwright/test";
import {
  browserRequest,
  login,
  pendingOf,
  selectDecision,
  stateOf,
} from "./helpers";

const securityFixture = (action: string) =>
  execFileSync(
    "php",
    ["tests/Infrastructure/weekly-plan-v3-e2e-security-fixture.php", action],
    { cwd: resolve("backend"), encoding: "utf8" },
  );

test("real sessions enforce CSRF, organization, membership, permission and invalidation", async ({
  page,
  browser,
}) => {
  test.setTimeout(240_000);
  await page.goto("/e2e/session-probe.html");
  const loginProbe = async () => {
    await browserRequest(page, "/sanctum/csrf-cookie");
    let result = await browserRequest(page, "/api/auth/login", "POST", {
      email: "security-assistant@weekly-plan-v3-e2e.example.test",
      password: "Kps-V3-E2E-Only!2026",
    });
    if (result.status === 429) {
      await page.waitForTimeout(61_000);
      result = await browserRequest(page, "/api/auth/login", "POST", {
        email: "security-assistant@weekly-plan-v3-e2e.example.test",
        password: "Kps-V3-E2E-Only!2026",
      });
    }
    expect(result.status).toBe(200);
  };
  await loginProbe();
  const decisions = [
    {
      assignee_id: stateOf(9102).workflows[0].assignee_id,
      decision: "ACCEPTED",
      expected_state_version: 1,
      expected_content_version: 1,
    },
  ];
  const url = "/api/weekly-plans/9102/assistant-review-batches";
  expect(
    (
      await browserRequest(
        page,
        url,
        "POST",
        { decisions },
        "csrf-negative",
        false,
      )
    ).status,
  ).toBe(419);
  const v2 = await browserRequest(
    page,
    "/api/weekly-plans/9105/assistant-review-batches",
    "POST",
    { decisions },
    "v2-negative",
  );
  expect(v2.status).toBe(409);
  expect(v2.body.code).toBe("INVALID_WORKFLOW_VERSION");
  for (const action of [
    "wrong-organization",
    "revoke-membership",
    "revoke-permission",
  ]) {
    if (action !== "wrong-organization") await loginProbe();
    securityFixture(action);
    try {
      expect(
        (
          await browserRequest(
            page,
            url,
            "POST",
            { decisions },
            `blocked-${action}`,
          )
        ).status,
      ).toBe(action === "revoke-permission" ? 403 : 401);
    } finally {
      securityFixture("restore");
    }
  }
  expect((await browserRequest(page, "/api/auth/me")).status).toBe(200);
  securityFixture("invalidate-session");
  expect((await browserRequest(page, "/api/auth/me")).status).toBe(401);
  await page.goto("/weekly-plans");
  await expect(page).toHaveURL(/\/login$/);

  const observerContext = await browser.newContext();
  const observer = await observerContext.newPage();
  await login(observer, "employee-c");
  expect(
    (
      await browserRequest(
        observer,
        url,
        "POST",
        { decisions },
        "unauthorized-employee",
      )
    ).status,
  ).toBe(403);
  await observer.goto("/weekly-plans/9102/review");
  await expect(observer.locator('[data-workspace="v3-reviewer"]')).toHaveCount(
    0,
  );
  await observerContext.close();
  expect(stateOf(9102).counts.weekly_plan_v3_review_batches).toBe(0);
  expect(stateOf(9102).counts.tasks).toBe(0);
  expect(stateOf(9105).plan.workflow_version).toBe(2);
});

test("Assistant correction observer opens read-only review workspace", async ({
  page,
}) => {
  test.setTimeout(180_000);
  await login(page, "assistant");
  await page.goto("/weekly-plans");
  const link = page.locator('a[href="/weekly-plans/9104/review"]');
  await expect(link).toContainText("Mở không gian xét duyệt");
  await link.click();
  await expect(page.locator('[data-workspace="v3-reviewer"]')).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Chấp nhận", exact: true }),
  ).toHaveCount(0);
  expect(
    (await browserRequest(page, "/api/weekly-plans/9104")).body.data
      .employee_workflows[0].actions.can_accept,
  ).toBe(false);
  const workflow = stateOf(9104).workflows[0];
  expect(
    (
      await browserRequest(
        page,
        "/api/weekly-plans/9104/assistant-review-batches",
        "POST",
        {
          decisions: [
            {
              assignee_id: workflow.assignee_id,
              decision: "ACCEPTED",
              expected_state_version: 1,
              expected_content_version: 1,
            },
          ],
        },
        "readonly-review-rejected",
      )
    ).status,
  ).toBe(409);
  expect(stateOf(9104).counts.weekly_plan_v3_review_batches).toBe(0);
});

test("503 and invalid JSON after commit preserve one request identity until reconciliation", async ({
  page,
}) => {
  test.setTimeout(180_000);
  await login(page, "assistant");
  await page.goto("/weekly-plans/9102/review");
  await selectDecision(page, "Employee A", "Chấp nhận");
  let attempts = 0;
  const statuses: number[] = [];
  page.on("response", (response) => {
    if (
      response.url().endsWith("/9102/assistant-review-batches") &&
      response.request().method() === "POST"
    )
      statuses.push(response.status());
  });
  const requests: { key: string | undefined; payload: string | null }[] = [];
  await page.route("**/9102/assistant-review-batches", async (route) => {
    attempts++;
    requests.push({
      key: route.request().headers()["idempotency-key"],
      payload: route.request().postData(),
    });
    if (attempts === 1)
      await route.fulfill({
        status: 503,
        contentType: "application/json",
        headers: {
          "Access-Control-Allow-Origin": "http://127.0.0.1:5174",
          "Access-Control-Allow-Credentials": "true",
        },
        body: '{"message":"Synthetic unavailable response"}',
      });
    else if (attempts === 2) {
      const committed = await route.fetch({ timeout: 60_000 });
      expect(committed.status()).toBe(201);
      await route.fulfill({
        response: committed,
        contentType: "application/json",
        body: "{invalid-json",
      });
    } else await route.continue();
  });
  await page.getByRole("button", { name: "Xem lại lô (1)" }).click();
  await page.getByRole("button", { name: "Xác nhận lô", exact: true }).click();
  await expect(
    page.getByText(/Chưa thể xác định yêu cầu trước/).first(),
  ).toBeVisible();
  const pending = await pendingOf(page, 9102);
  expect(stateOf(9102).counts.weekly_plan_v3_review_batches).toBe(0);
  await page
    .getByRole("alert")
    .getByRole("button", { name: "Thử lại và đối soát" })
    .click();
  await expect.poll(() => attempts).toBe(2);
  await expect(
    page
      .getByRole("alert")
      .getByRole("button", { name: "Thử lại và đối soát" }),
  ).toBeEnabled();
  expect(await pendingOf(page, 9102)).toEqual(pending);
  expect(stateOf(9102).counts.weekly_plan_v3_review_batches).toBe(1);
  await page
    .getByRole("alert")
    .getByRole("button", { name: "Thử lại và đối soát" })
    .click();
  await expect.poll(() => pendingOf(page, 9102)).toBeNull();
  expect(requests).toHaveLength(3);
  expect(statuses).toEqual([503, 201, 200]);
  expect(
    requests.every(
      (request) => JSON.stringify(request) === JSON.stringify(requests[0]),
    ),
  ).toBe(true);
  expect(stateOf(9102).counts.weekly_plan_v3_review_batches).toBe(1);
  expect(stateOf(9102).counts.tasks).toBe(0);
});
