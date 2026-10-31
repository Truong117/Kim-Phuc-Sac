import { expect, test, type Page } from "@playwright/test";
import { mkdir, writeFile } from "node:fs/promises";
import { login, pendingOf, selectDecision, stateOf } from "./helpers";

const workspace = (page: Page) =>
  page.locator('[data-workspace="v3-reviewer"]');

test("Assistant committed batch: Back Forward Link unload and reload preserve exact retry", async ({
  page,
}, testInfo) => {
  test.setTimeout(240_000);
  await login(page, "assistant");
  await page.goto("/weekly-plans");
  await page.locator('a[href="/weekly-plans/9101/review"]').click();
  // Make genuine backward and forward destinations without synthetic pushState.
  await page.locator('a[href="/weekly-plans"]').last().click();
  await expect(page).toHaveURL(/\/weekly-plans$/);
  await page.evaluate(() => history.back());
  await expect(workspace(page)).toBeVisible();
  const index = await page.evaluate(() => history.state.idx);
  const length = await page.evaluate(() => history.length);
  await selectDecision(page, "Employee A", "Chấp nhận");
  let mutations = 0;
  let originalKey: string | undefined;
  let originalPayload: string | null = null;
  let retryKey: string | undefined;
  let retryPayload: string | null = null;
  await page.route("**/9101/assistant-review-batches", async (route) => {
    mutations++;
    if (mutations === 1) {
      originalKey = route.request().headers()["idempotency-key"];
      originalPayload = route.request().postData();
      const response = await route.fetch({ timeout: 60_000 });
      expect(response.status()).toBe(201);
      await route.abort("failed");
    } else {
      retryKey = route.request().headers()["idempotency-key"];
      retryPayload = route.request().postData();
      await route.continue();
    }
  });
  await page.getByRole("button", { name: "Xem lại lô (1)" }).click();
  await page.getByRole("button", { name: "Xác nhận lô", exact: true }).click();
  await expect(
    page.getByText(/Chưa thể xác định yêu cầu trước/).first(),
  ).toBeVisible();
  const before = await pendingOf(page, 9101);
  expect(before.assistantPending.requestId).toBe(originalKey);
  let prompts = 0;
  let leave = false;
  const promptTypes: string[] = [];
  page.on("dialog", async (dialog) => {
    prompts++;
    promptTypes.push(dialog.type());
    if (leave) await dialog.accept();
    else await dialog.dismiss();
  });
  const unchanged = async () => {
    await expect(page).toHaveURL(/\/weekly-plans\/9101\/review$/);
    await expect(workspace(page)).toBeVisible();
    await expect.poll(() => page.evaluate(() => history.state.idx)).toBe(index);
    expect(await page.evaluate(() => history.length)).toBe(length);
    expect(await pendingOf(page, 9101)).toEqual(before);
    expect(mutations).toBe(1);
  };
  for (const direction of [-1, 1, -1, 1]) {
    const prior = prompts;
    await page.evaluate((delta) => history.go(delta), direction);
    await expect.poll(() => prompts).toBe(prior + 1);
    await unchanged();
  }
  let prior = prompts;
  await page.locator('a[href="/weekly-plans"]').last().click();
  expect(prompts).toBe(prior + 1);
  await unchanged();

  // Native beforeunload: Stay cancels reload; Leave reloads and restores storage.
  prior = prompts;
  await page.evaluate(() => {
    setTimeout(() => location.reload(), 0);
  });
  await expect.poll(() => prompts).toBe(prior + 1);
  expect(promptTypes.at(-1)).toBe("beforeunload");
  await unchanged();
  await mkdir("test-results/evidence", { recursive: true });
  await page.screenshot({
    path: "test-results/evidence/navigation-stay-after-fix.png",
  });
  leave = true;
  prior = prompts;
  const reloaded = page.waitForEvent("domcontentloaded");
  await page.evaluate(() => {
    setTimeout(() => location.reload(), 0);
  });
  await reloaded;
  expect(prompts).toBe(prior + 1);
  await unchanged();

  // Forward Leave, Back return; Back Leave, Forward return. Recovery never retries.
  for (const direction of [1, -1]) {
    prior = prompts;
    await page.evaluate((delta) => history.go(delta), direction);
    await expect(page).toHaveURL(/\/weekly-plans$/);
    expect(prompts).toBe(prior + 1);
    await page.evaluate((delta) => history.go(-delta), direction);
    await unchanged();
  }
  prior = prompts;
  await page.locator('a[href="/weekly-plans"]').last().click();
  await expect(page).toHaveURL(/\/weekly-plans$/);
  expect(prompts).toBe(prior + 1);
  await page.evaluate(() => history.back());
  await expect(workspace(page)).toBeVisible();
  expect(await pendingOf(page, 9101)).toEqual(before);
  expect(mutations).toBe(1);

  await page.getByRole("tab", { name: "Hàng đợi Trợ lý" }).click();
  await page
    .getByRole("button", { name: "Thử lại và đối soát" })
    .first()
    .click();
  const confirmation = page.getByRole("dialog");
  await expect(confirmation).toContainText("1 nhân viên · 1 chấp nhận");
  const response = page.waitForResponse(
    (r) =>
      r.url().endsWith("/9101/assistant-review-batches") &&
      r.request().method() === "POST",
  );
  await confirmation
    .getByRole("button", { name: "Thử lại và đối soát" })
    .click();
  expect((await response).status()).toBe(200);
  expect(retryKey).toBe(originalKey);
  expect(retryPayload).toBe(originalPayload);
  await expect.poll(() => pendingOf(page, 9101)).toBeNull();
  const count = prompts;
  await page.evaluate(() => history.forward());
  await expect(page).toHaveURL(/\/weekly-plans$/);
  expect(prompts).toBe(count);
  expect(mutations).toBe(2);
  const state = stateOf(9101);
  expect(state.counts.weekly_plan_v3_review_batches).toBe(1);
  expect(state.counts.tasks).toBe(0);
  const evidence = JSON.stringify({
    prompts,
    promptTypes,
    mutations,
    browser: page.context().browser()?.version(),
    batchCount: state.counts.weekly_plan_v3_review_batches,
    batches: state.batches,
    tasks: state.counts.tasks,
    notifications: state.counts.user_notifications,
  });
  await writeFile("test-results/evidence/navigation-after-fix.json", evidence);
  await testInfo.attach("navigation-evidence", {
    body: evidence,
    contentType: "application/json",
  });
});

test("multi-role Assistant and OWNER unresolved requests stay independent", async ({
  page,
}) => {
  test.setTimeout(180_000);
  await login(page, "multi-reviewer");
  await page.goto("/weekly-plans");
  await page.locator('a[href="/weekly-plans/9103/review"]').click();
  const attempts = { assistant: 0, owner: 0 };
  await page.route("**/9103/*-review-batches", async (route) => {
    const stage = route.request().url().includes("assistant-review")
      ? "assistant"
      : "owner";
    attempts[stage]++;
    if (attempts[stage] === 1) {
      expect((await route.fetch({ timeout: 60_000 })).status()).toBe(201);
      await route.abort("failed");
    } else await route.continue();
  });
  await page.getByRole("tab", { name: "Hàng đợi Trợ lý" }).click();
  await selectDecision(page, "Employee A", "Chấp nhận");
  await page.getByRole("button", { name: "Xem lại lô (1)" }).click();
  await page.getByRole("button", { name: "Xác nhận lô", exact: true }).click();
  await expect(
    page.getByText(/Chưa thể xác định yêu cầu trước/).first(),
  ).toBeVisible();
  await page.getByRole("tab", { name: "Hàng đợi OWNER" }).click();
  await selectDecision(page, "Employee B", "Duyệt không có công việc");
  await page.getByRole("button", { name: "Xem lại lô OWNER (1)" }).click();
  await page.getByRole("button", { name: "Xác nhận và phát hành" }).click();
  await expect(
    page
      .getByText(/Chưa thể xác định lô trước/)
      .filter({ visible: true })
      .first(),
  ).toBeVisible();
  const both = await pendingOf(page, 9103);
  expect(both.ownerPending.requestId).not.toBe(both.assistantPending.requestId);
  let prompts = 0;
  page.on("dialog", async (dialog) => {
    prompts++;
    await dialog.dismiss();
  });
  const protectedIndex = await page.evaluate(() => history.state.idx);
  await page.evaluate(() => history.back());
  await expect.poll(() => prompts).toBe(1);
  await expect
    .poll(() => page.evaluate(() => history.state.idx))
    .toBe(protectedIndex);
  await expect(page).toHaveURL(/\/9103\/review$/);
  expect(await pendingOf(page, 9103)).toEqual(both);
  expect(attempts).toEqual({ assistant: 1, owner: 1 });
  await page
    .getByRole("alert")
    .getByRole("button", { name: "Thử lại và đối soát" })
    .click();
  await expect
    .poll(async () => (await pendingOf(page, 9103))?.ownerPending)
    .toBeNull();
  expect((await pendingOf(page, 9103)).assistantPending).toEqual(
    both.assistantPending,
  );
  await page.getByRole("tab", { name: "Hàng đợi Trợ lý" }).click();
  await page
    .getByRole("alert")
    .getByRole("button", { name: "Thử lại và đối soát" })
    .click();
  await expect.poll(() => pendingOf(page, 9103)).toBeNull();
  expect(attempts).toEqual({ assistant: 2, owner: 2 });
  expect(stateOf(9103).counts.weekly_plan_v3_review_batches).toBe(3);
  expect(stateOf(9103).counts.tasks).toBe(0);
});
