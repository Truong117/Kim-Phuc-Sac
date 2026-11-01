import { expect, test } from "@playwright/test";
import { browserRequest, login, stateOf } from "./helpers";

test("V2 remains default and preserves submission, review, approval and publishing UI", async ({
  page,
  browser,
}) => {
  test.setTimeout(180_000);
  await login(page, "hr");
  await page.goto("/weekly-plans/new");
  await expect(page.locator("#weekly-plan-workflow-version")).toHaveValue("2");
  const assigneeId = (await browserRequest(page, "/api/weekly-plans/9105")).body
    .data.entries[0].assignee.id;
  expect(
    (
      await browserRequest(
        page,
        "/api/weekly-plans/9105/correction-batches",
        "POST",
        {
          employees: [
            {
              assignee_id: assigneeId,
              expected_state_version: 1,
              expected_content_version: 1,
            },
          ],
        },
        "v2-correction-rejected",
      )
    ).status,
  ).toBe(409);
  await page.goto("/weekly-plans/9105/work");
  await expect(
    page.getByText("Synthetic V2 regression work", { exact: true }).first(),
  ).toBeVisible();
  await page.goto("/weekly-plans/9105/submit");
  const submitted = page.waitForResponse(
    (r) => r.url().endsWith("/9105/submit") && r.request().method() === "POST",
  );
  await page
    .getByRole("button", { name: "Gửi Trợ lý Giám đốc xét duyệt" })
    .click();
  expect((await submitted).status()).toBe(200);
  expect(stateOf(9105).plan.status).toBe("ASSISTANT_REVIEW");

  const assistantContext = await browser.newContext();
  const assistant = await assistantContext.newPage();
  await login(assistant, "assistant");
  await assistant.goto("/weekly-plans");
  await assistant.locator('a[href="/weekly-plans/9105/review"]').click();
  await assistant
    .getByRole("button", { name: "Chấp nhận", exact: true })
    .click();
  await assistant.getByRole("button", { name: "Xác nhận chấp nhận" }).click();
  await assistant
    .getByRole("button", { name: "Chuyển OWNER xét duyệt", exact: true })
    .click();
  await assistant
    .getByRole("dialog")
    .getByRole("button", { name: "Chuyển OWNER xét duyệt", exact: true })
    .click();
  await expect.poll(() => stateOf(9105).plan.status).toBe("OWNER_REVIEW");
  await assistantContext.close();

  const ownerContext = await browser.newContext();
  const owner = await ownerContext.newPage();
  await login(owner, "owner");
  const rejectedOwner = await browserRequest(
    owner,
    "/api/weekly-plans/9105/owner-review-batches",
    "POST",
    {
      decisions: [
        {
          assignee_id: assigneeId,
          decision: "ACCEPTED",
          expected_state_version: 1,
          expected_content_version: 1,
        },
      ],
    },
    "v2-owner-rejected",
  );
  expect(rejectedOwner.status).toBe(409);
  await owner.goto("/weekly-plans/9105/review");
  await owner.getByRole("button", { name: "Chấp nhận", exact: true }).click();
  await owner.getByRole("button", { name: "Xác nhận chấp nhận" }).click();
  await owner
    .getByRole("button", { name: "Phê duyệt kế hoạch", exact: true })
    .click();
  await owner
    .getByRole("dialog")
    .getByRole("button", { name: /Phê duyệt/ })
    .click();
  await expect.poll(() => stateOf(9105).plan.status).toBe("APPROVED");
  await owner
    .getByRole("button", { name: "Phát hành công việc", exact: true })
    .click();
  await owner
    .getByRole("dialog")
    .getByRole("button", { name: /Phát hành/ })
    .click();
  await expect.poll(() => stateOf(9105).plan.status).toBe("PUBLISHED");
  const state = stateOf(9105);
  expect(state.plan.workflow_version).toBe(2);
  expect(state.counts.weekly_plan_v3_review_batches).toBe(0);
  expect(state.counts.tasks).toBe(1);
  expect(state.counts.user_notifications).toBe(1);
  await ownerContext.close();
});
