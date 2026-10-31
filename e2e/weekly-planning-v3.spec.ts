import { execFileSync } from "node:child_process";
import { mkdir } from "node:fs/promises";
import { resolve } from "node:path";
import { expect, test } from "@playwright/test";

const backendRoot = resolve("backend");

type State = {
  plan: {
    id: number;
    workflow_version: number;
    status: string;
    parse_status: string;
  } | null;
  counts: Record<string, number>;
  workflows: { assignee_id: number; state: string }[];
};

const stateOf = (planId: number): State =>
  JSON.parse(
    execFileSync(
      "php",
      ["tests/Infrastructure/weekly-plan-v3-e2e-state.php", String(planId)],
      { cwd: backendRoot, encoding: "utf8" },
    ),
  ) as State;

test("synthetic HR, Assistant, and OWNER complete mixed review with partial publishing", async ({
  browser,
  page,
}) => {
  test.setTimeout(420_000);
  await page.goto("/login");
  await page.locator("#email").fill("hr@weekly-plan-v3-e2e.example.test");
  await page.locator("#password").fill("Kps-V3-E2E-Only!2026");
  await page.locator('form button[type="submit"]').click();
  await expect(page).not.toHaveURL(/\/login$/);

  await page.goto("/weekly-plans/new");
  await expect(page.locator("#weekly-plan-workflow-version")).toBeVisible();
  const downloadPromise = page.waitForEvent("download");
  await page.getByRole("button", { name: "Tải file Excel mẫu" }).click();
  const download = await downloadPromise;
  const templatePath = await download.path();
  expect(templatePath).not.toBeNull();

  await page.locator("#weekly-plan-workflow-version").selectOption("3");
  await page.locator("#weekly-plan-file").setInputFiles({
    name: "synthetic-weekly-plan.xlsx",
    mimeType:
      "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    buffer: await (await import("node:fs/promises")).readFile(templatePath!),
  });

  const imported = page.waitForResponse(
    (response) =>
      response.url().endsWith("/api/weekly-plans/import") &&
      response.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Tải lên và kiểm tra" }).click();
  const response = await imported;
  expect(response.status()).toBe(201);
  const body = (await response.json()) as {
    data: { id: number; workflow_version: number; parse_status: string };
  };
  expect(body.data.workflow_version).toBe(3);
  await expect(page).toHaveURL(new RegExp(`/weekly-plans/${body.data.id}/`));

  const state = stateOf(body.data.id);
  expect(state.plan?.workflow_version).toBe(3);
  expect(state.plan?.status).toBe("DRAFT");
  expect(state.counts.tasks).toBe(0);
  expect(state.counts.weekly_plan_v3_review_batches).toBe(0);

  await page.goto(`/weekly-plans/${body.data.id}/work`);
  await page.getByRole("button", { name: "Thêm công việc" }).click();
  const assigneeOptions = await page
    .locator("#weekly-entry-assignee option")
    .evaluateAll((options) =>
      options.map((option) => ({
        value: (option as HTMLOptionElement).value,
        label: option.textContent ?? "",
      })),
    );
  const employeeA = assigneeOptions.find((option) =>
    option.label.includes("Employee A (Fake)"),
  );
  expect(employeeA).toBeDefined();
  await page.locator("#weekly-entry-assignee").selectOption(employeeA!.value);
  await page.locator("#weekly-entry-title").fill("Prepare synthetic campaign");
  await page.locator("#weekly-entry-result").fill("Campaign approved");
  await page.getByRole("button", { name: "Thêm đầu mục" }).click();
  await page.locator("#weekly-entry-item-title-0").fill("Draft outline");
  const entryCreated = page.waitForResponse(
    (response) =>
      response.url().endsWith(`/api/weekly-plans/${body.data.id}/entries`) &&
      response.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Lưu công việc" }).click();
  expect((await entryCreated).status()).toBe(201);

  await page.goto(`/weekly-plans/${body.data.id}/submit`);
  await expect(page.getByText("V3 E2E Employee A (Fake)")).toBeVisible();
  await page.getByRole("checkbox", { name: /Employee B \(Fake\)/ }).check();
  await page.getByRole("checkbox", { name: /Zero Work Employee/ }).check();
  const rosterSaved = page.waitForResponse(
    (response) =>
      response.url().endsWith(`/api/weekly-plans/${body.data.id}/v3-roster`) &&
      response.request().method() === "PUT",
  );
  await page.getByRole("button", { name: "Lưu danh sách" }).click();
  expect((await rosterSaved).status()).toBe(200);
  const submitted = page.waitForResponse(
    (response) =>
      response.url().endsWith(`/api/weekly-plans/${body.data.id}/submit`) &&
      response.request().method() === "POST",
  );
  await page
    .getByRole("button", { name: "Gửi Trợ lý Giám đốc xét duyệt" })
    .click();
  expect((await submitted).status()).toBe(200);
  const submittedState = stateOf(body.data.id);
  expect(submittedState.plan?.status).toBe("IN_REVIEW");
  expect(submittedState.counts.weekly_plan_employee_workflows).toBe(3);
  expect(submittedState.counts.tasks).toBe(0);

  const assistantContext = await browser.newContext();
  const assistant = await assistantContext.newPage();
  await assistant.goto("/login");
  await assistant
    .locator("#email")
    .fill("assistant@weekly-plan-v3-e2e.example.test");
  await assistant.locator("#password").fill("Kps-V3-E2E-Only!2026");
  await assistant.locator('form button[type="submit"]').click();
  await expect(assistant).not.toHaveURL(/\/login$/);
  await assistant.goto("/weekly-plans");
  const reviewLink = assistant.locator(
    `a[href="/weekly-plans/${body.data.id}/review"]`,
  );
  await expect(reviewLink).toBeVisible({ timeout: 45_000 });
  await reviewLink.click();
  await expect(
    assistant.locator('[data-workspace="v3-reviewer"]'),
  ).toBeVisible();

  const decide = async (name: string, decision: string) => {
    const toggle = assistant.getByRole("button", { name: new RegExp(name) });
    if ((await toggle.getAttribute("aria-expanded")) !== "true") {
      await toggle.click();
    }
    const region = assistant.locator(
      `#${await toggle.getAttribute("aria-controls")}`,
    );
    await region.getByRole("button", { name: decision }).click();
  };
  await decide("Employee A", "Chấp nhận");
  await decide("Employee B", "Yêu cầu chỉnh sửa");
  await decide("Zero Work Employee", "Chấp nhận");
  await assistant.getByRole("button", { name: "Xem lại lô (3)" }).click();
  let assistantAttempts = 0;
  let committedStatus: number | null = null;
  let originalAssistantKey: string | undefined;
  let originalAssistantPayload: string | null = null;
  let retryAssistantKey: string | undefined;
  let retryAssistantPayload: string | null = null;
  await assistant.route("**/assistant-review-batches", async (route) => {
    assistantAttempts += 1;
    if (assistantAttempts === 1) {
      originalAssistantKey = route.request().headers()["idempotency-key"];
      originalAssistantPayload = route.request().postData();
      const committed = await route.fetch();
      committedStatus = committed.status();
      await route.abort("failed");
    } else {
      retryAssistantKey = route.request().headers()["idempotency-key"];
      retryAssistantPayload = route.request().postData();
      await route.continue();
    }
  });
  await assistant.getByRole("button", { name: "Xác nhận lô" }).click();
  await expect(
    assistant.getByText(/Chưa thể xác định yêu cầu trước/).first(),
  ).toBeVisible();
  expect(committedStatus).toBe(201);
  expect(assistantAttempts).toBe(1);
  expect(stateOf(body.data.id).counts.weekly_plan_v3_review_batches).toBe(1);

  await assistant.getByRole("tab", { name: "Tổng quan" }).click();
  await assistant.getByRole("tab", { name: "Hàng đợi Trợ lý" }).click();
  await expect(
    assistant
      .locator('[id^="weekly-plan-v3-employee-"]')
      .first()
      .getByRole("button", { name: "Chấp nhận" }),
  ).toBeDisabled();
  let linkDialogSeen = false;
  assistant.once("dialog", (dialog) => {
    linkDialogSeen = true;
    void dialog.dismiss();
  });
  await assistant.locator('a[href="/weekly-plans"]').last().click();
  await assistant.waitForTimeout(500);
  expect(linkDialogSeen).toBe(true);
  await expect(
    assistant.locator('[data-workspace="v3-reviewer"]'),
  ).toBeVisible();
  await expect(assistant).toHaveURL(
    new RegExp(`/weekly-plans/${body.data.id}/review`),
  );
  expect(assistantAttempts).toBe(1);
  assistant.once("dialog", (dialog) => void dialog.accept());
  await assistant.reload();
  await expect(
    assistant.locator('[data-workspace="v3-reviewer"]'),
  ).toBeVisible();
  await assistant.getByRole("tab", { name: "Hàng đợi Trợ lý" }).click();
  expect(assistantAttempts).toBe(1);
  await assistant
    .getByRole("button", { name: "Thử lại và đối soát" })
    .first()
    .click();
  const retryDialog = assistant.getByRole("dialog");
  await expect(retryDialog).toContainText(
    "3 nhân viên · 2 chấp nhận · 1 yêu cầu chỉnh sửa",
  );
  const originalDecisions = (
    JSON.parse(originalAssistantPayload!) as {
      decisions: { assignee_id: number }[];
    }
  ).decisions;
  for (const decision of originalDecisions) {
    await expect(retryDialog).toContainText(`#${decision.assignee_id}`);
  }
  const assistantBatch = assistant.waitForResponse(
    (response) =>
      response
        .url()
        .endsWith(
          `/api/weekly-plans/${body.data.id}/assistant-review-batches`,
        ) && response.request().method() === "POST",
  );
  await retryDialog
    .getByRole("button", { name: "Thử lại và đối soát" })
    .click();
  expect((await assistantBatch).status()).toBe(200);
  expect(assistantAttempts).toBe(2);
  expect(retryAssistantKey).toBe(originalAssistantKey);
  expect(retryAssistantPayload).toBe(originalAssistantPayload);
  await assistant.unroute("**/assistant-review-batches");
  const reviewedState = stateOf(body.data.id);
  expect(reviewedState.counts.weekly_plan_v3_review_batches).toBe(1);
  expect(reviewedState.counts.tasks).toBe(0);
  expect(
    reviewedState.workflows.map((workflow) => workflow.state).sort(),
  ).toEqual(["HR_CORRECTION", "STAGED_FOR_OWNER", "STAGED_FOR_OWNER"]);
  await assistantContext.close();

  const ownerContext = await browser.newContext();
  const owner = await ownerContext.newPage();
  await owner.goto("/login");
  await owner.locator("#email").fill("owner@weekly-plan-v3-e2e.example.test");
  await owner.locator("#password").fill("Kps-V3-E2E-Only!2026");
  await owner.locator('form button[type="submit"]').click();
  await expect(owner).not.toHaveURL(/\/login$/);
  await owner.goto(`/weekly-plans/${body.data.id}/review`);
  await expect(owner.locator('[data-workspace="v3-reviewer"]')).toBeVisible();

  const decideOwner = async (name: string, decision: string) => {
    const toggle = owner.getByRole("button", { name: new RegExp(name) });
    if ((await toggle.getAttribute("aria-expanded")) !== "true") {
      await toggle.click();
    }
    const region = owner.locator(
      `#${await toggle.getAttribute("aria-controls")}`,
    );
    await region.getByRole("button", { name: decision }).click();
  };
  await decideOwner("Employee A", "Phê duyệt và tạo Task");
  await decideOwner("Zero Work Employee", "Duyệt không có công việc");
  await owner.getByRole("button", { name: "Xem lại lô OWNER (2)" }).click();
  const ownerBatch = owner.waitForResponse(
    (response) =>
      response
        .url()
        .endsWith(`/api/weekly-plans/${body.data.id}/owner-review-batches`) &&
      response.request().method() === "POST",
  );
  await owner.getByRole("button", { name: "Xác nhận và phát hành" }).click();
  const firstOwnerResponse = await ownerBatch;
  expect(firstOwnerResponse.status()).toBe(201);
  const firstOwnerBatchId = (await firstOwnerResponse.json()).data.batch
    .id as number;
  const originalOwnerKey = firstOwnerResponse.request().headers()[
    "idempotency-key"
  ];
  const originalOwnerPayload = firstOwnerResponse.request().postData();
  expect(originalOwnerKey).toBeTruthy();
  expect(originalOwnerPayload).toBeTruthy();
  const publishedState = stateOf(body.data.id);
  expect(publishedState.plan?.status).toBe("IN_REVIEW");
  expect(publishedState.counts.tasks).toBe(1);
  expect(publishedState.counts.user_notifications).toBe(1);
  expect(
    publishedState.workflows.map((workflow) => workflow.state).sort(),
  ).toEqual(["HR_CORRECTION", "NO_WORK_APPROVED", "PUBLISHED"]);

  const postOwnerBatch = async (key: string, payload: string) =>
    owner.evaluate(
      async ({ planId, requestKey, requestBody }) => {
        const csrfCookie = document.cookie
          .split(";")
          .map((part) => part.trim())
          .find((part) => part.startsWith("XSRF-TOKEN="));
        const csrf = csrfCookie
          ? decodeURIComponent(csrfCookie.slice("XSRF-TOKEN=".length))
          : "";
        const response = await fetch(
          `http://127.0.0.1:8001/api/weekly-plans/${planId}/owner-review-batches`,
          {
            method: "POST",
            credentials: "include",
            headers: {
              Accept: "application/json",
              "Content-Type": "application/json",
              "Idempotency-Key": requestKey,
              "X-XSRF-TOKEN": csrf,
            },
            body: requestBody,
          },
        );
        return { status: response.status, body: await response.json() };
      },
      { planId: body.data.id, requestKey: key, requestBody: payload },
    );

  const replay = await postOwnerBatch(originalOwnerKey!, originalOwnerPayload!);
  expect(replay.status).toBe(200);
  expect(replay.body.data.batch.id).toBe(firstOwnerBatchId);
  expect(stateOf(body.data.id).counts.tasks).toBe(1);
  expect(stateOf(body.data.id).counts.user_notifications).toBe(1);

  const stale = await postOwnerBatch(
    `stale-${crypto.randomUUID()}`,
    originalOwnerPayload!,
  );
  expect(stale.status).toBe(409);
  const invalid = await postOwnerBatch(
    `invalid-${crypto.randomUUID()}`,
    JSON.stringify({ decisions: [] }),
  );
  expect(invalid.status).toBe(422);
  expect(stateOf(body.data.id).counts.tasks).toBe(1);
  expect(stateOf(body.data.id).counts.weekly_plan_v3_review_batches).toBe(2);
  await ownerContext.close();

  await page.goto(`/weekly-plans/${body.data.id}/submit`);
  const correctionWorkspace = page.locator(
    '[data-workspace="v3-hr-corrections"]',
  );
  await expect(correctionWorkspace).toBeVisible();
  await expect(
    correctionWorkspace.getByRole("heading", {
      name: "V3 E2E Employee B (Fake)",
    }),
  ).toBeVisible();
  await expect(
    correctionWorkspace.getByRole("heading", {
      name: "V3 E2E Employee A (Fake)",
    }),
  ).toHaveCount(0);
  await correctionWorkspace
    .getByRole("button", { name: "Thêm công việc" })
    .click();
  await page
    .locator("#weekly-entry-title")
    .fill("Corrected employee B campaign");
  await page.locator("#weekly-entry-result").fill("Correction accepted");
  await page.getByRole("button", { name: "Thêm đầu mục" }).click();
  await page
    .locator("#weekly-entry-item-title-0")
    .fill("Prepare revised outline");
  const correctionSaved = page.waitForResponse(
    (response) =>
      response
        .url()
        .includes(`/api/weekly-plans/${body.data.id}/v3-corrections/`) &&
      response.url().endsWith("/entries") &&
      response.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Lưu công việc" }).click();
  expect((await correctionSaved).status()).toBe(201);
  await correctionWorkspace
    .getByRole("checkbox", { name: "Chọn gửi lại" })
    .check();
  await correctionWorkspace
    .getByRole("button", { name: "Gửi lại 1 nhân viên" })
    .click();
  const correctionBatch = page.waitForResponse(
    (response) =>
      response
        .url()
        .endsWith(`/api/weekly-plans/${body.data.id}/correction-batches`) &&
      response.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Xác nhận gửi lại" }).click();
  expect((await correctionBatch).status()).toBe(201);
  const correctedState = stateOf(body.data.id);
  expect(correctedState.counts.tasks).toBe(1);
  expect(
    correctedState.workflows.map((workflow) => workflow.state).sort(),
  ).toEqual(["AWAITING_ASSISTANT", "NO_WORK_APPROVED", "PUBLISHED"]);

  const assistantReturnContext = await browser.newContext();
  const assistantReturn = await assistantReturnContext.newPage();
  await assistantReturn.goto("/login");
  await assistantReturn
    .locator("#email")
    .fill("assistant@weekly-plan-v3-e2e.example.test");
  await assistantReturn.locator("#password").fill("Kps-V3-E2E-Only!2026");
  await assistantReturn.locator('form button[type="submit"]').click();
  await expect(assistantReturn).not.toHaveURL(/\/login$/);
  await assistantReturn.goto(`/weekly-plans/${body.data.id}/review`);
  const correctedToggle = assistantReturn.getByRole("button", {
    name: /Employee B/,
  });
  if ((await correctedToggle.getAttribute("aria-expanded")) !== "true") {
    await correctedToggle.click();
  }
  await assistantReturn
    .locator(`#${await correctedToggle.getAttribute("aria-controls")}`)
    .getByRole("button", { name: "Chấp nhận" })
    .click();
  await assistantReturn.getByRole("button", { name: "Xem lại lô (1)" }).click();
  const secondAssistantBatch = assistantReturn.waitForResponse(
    (response) =>
      response
        .url()
        .endsWith(
          `/api/weekly-plans/${body.data.id}/assistant-review-batches`,
        ) && response.request().method() === "POST",
  );
  await assistantReturn.getByRole("button", { name: "Xác nhận lô" }).click();
  expect((await secondAssistantBatch).status()).toBe(201);
  expect(stateOf(body.data.id).counts.tasks).toBe(1);
  await assistantReturnContext.close();

  const ownerReturnContext = await browser.newContext();
  const ownerReturn = await ownerReturnContext.newPage();
  await ownerReturn.goto("/login");
  await ownerReturn
    .locator("#email")
    .fill("owner@weekly-plan-v3-e2e.example.test");
  await ownerReturn.locator("#password").fill("Kps-V3-E2E-Only!2026");
  await ownerReturn.locator('form button[type="submit"]').click();
  await expect(ownerReturn).not.toHaveURL(/\/login$/);
  await ownerReturn.goto(`/weekly-plans/${body.data.id}/review`);
  const finalToggle = ownerReturn.getByRole("button", { name: /Employee B/ });
  if ((await finalToggle.getAttribute("aria-expanded")) !== "true") {
    await finalToggle.click();
  }
  await ownerReturn
    .locator(`#${await finalToggle.getAttribute("aria-controls")}`)
    .getByRole("button", { name: "Phê duyệt và tạo Task" })
    .click();
  await ownerReturn
    .getByRole("button", { name: "Xem lại lô OWNER (1)" })
    .click();
  const finalOwnerBatch = ownerReturn.waitForResponse(
    (response) =>
      response
        .url()
        .endsWith(`/api/weekly-plans/${body.data.id}/owner-review-batches`) &&
      response.request().method() === "POST",
  );
  await ownerReturn
    .getByRole("button", { name: "Xác nhận và phát hành" })
    .click();
  expect((await finalOwnerBatch).status()).toBe(201);
  const finalState = stateOf(body.data.id);
  expect(finalState.plan?.status).toBe("PUBLISHED");
  expect(finalState.counts.tasks).toBe(2);
  expect(finalState.counts.user_notifications).toBe(2);
  expect(finalState.workflows.map((workflow) => workflow.state).sort()).toEqual(
    ["NO_WORK_APPROVED", "PUBLISHED", "PUBLISHED"],
  );
  await mkdir("test-results/evidence", { recursive: true });
  await ownerReturn.screenshot({
    path: "test-results/evidence/v3-published-synthetic.png",
    fullPage: true,
  });
  await ownerReturnContext.close();
});
