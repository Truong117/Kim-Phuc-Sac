import { expect, test } from "@playwright/test";
import { readFile } from "node:fs/promises";
import { browserRequest, login, stateOf } from "./helpers";

test("disabled V3 exposes only default V2 creation and rejects V3 mutation", async ({
  page,
}) => {
  test.skip(
    process.env.KPS_V3_E2E_SCENARIO !== "v3-disabled",
    "Runs in the dedicated disabled local process profile.",
  );
  await login(page, "hr");
  await page.goto("/weekly-plans/new");
  await expect(page.locator("#weekly-plan-file")).toBeVisible();
  await expect(page.locator("#weekly-plan-workflow-version")).toHaveCount(0);
  const download = page.waitForEvent("download");
  await page.getByRole("button", { name: "Tải file Excel mẫu" }).click();
  const path = await (await download).path();
  await page.locator("#weekly-plan-file").setInputFiles({
    name: "synthetic-disabled-v2.xlsx",
    mimeType:
      "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    buffer: await readFile(path!),
  });
  const imported = page.waitForResponse(
    (r) =>
      r.url().endsWith("/api/weekly-plans/import") &&
      r.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Tải lên và kiểm tra" }).click();
  const response = await imported;
  expect(response.status()).toBe(201);
  const id = (await response.json()).data.id;
  expect(stateOf(id).plan.workflow_version).toBe(2);
  await browserRequest(page, "/api/auth/logout", "POST");
  await login(page, "assistant");
  const result = await browserRequest(
    page,
    "/api/weekly-plans/9101/assistant-review-batches",
    "POST",
    {
      decisions: [
        {
          assignee_id: stateOf(9101).workflows[0].assignee_id,
          decision: "ACCEPTED",
          expected_state_version: 1,
          expected_content_version: 1,
        },
      ],
    },
    "disabled-negative",
  );
  expect(result.status).toBe(409);
  expect(result.body.code).toBe("V3_WORKFLOW_DISABLED");
  expect(stateOf(9101).counts.weekly_plan_v3_review_batches).toBe(0);
});
