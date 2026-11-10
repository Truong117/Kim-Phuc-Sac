import { expect, test } from "@playwright/test";
import { browserRequest, login } from "./helpers";

test("work-report analysis: create, employees, source item, shared history, reload, 7/30 days", async ({
  page,
  browser,
}) => {
  test.setTimeout(240_000);
  await login(page, "owner");
  let creates = 0;
  page.on("request", (request) => {
    if (
      request.method() === "POST" &&
      /\/api\/ai\/work-report-analyses\/?$/.test(request.url())
    )
      creates++;
  });
  await page.goto("/ai/insights?days=1");
  await expect(
    page.getByText(
      "Nhận xét dựa trên nội dung báo cáo; chưa đối chiếu với kế hoạch tuần hoặc kết quả nghiệm thu.",
      { exact: true },
    ),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Tạo / mở phân tích", exact: true }),
  ).toBeEnabled();
  await expect(
    page.getByText("Có dữ liệu, chưa phân tích", { exact: true }).first(),
  ).toBeVisible();
  const createdResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      /\/api\/ai\/work-report-analyses\/?$/.test(response.url()),
  );
  await page
    .getByRole("button", { name: "Tạo / mở phân tích", exact: true })
    .click();
  const created = await (await createdResponse).json();
  if (process.env.WORK_REPORT_AI_QUEUE_CONNECTION === "work_report_ai") {
    expect(["pending", "running"]).toContain(created.data.status);
  } else {
    expect(created.data.status).toBe("completed");
  }
  await expect(
    page.getByText("Dữ liệu phân tích mô phỏng — chưa phải AI thật"),
  ).toBeVisible();
  await expect(page.getByText(/Hoàn tất · Lần phân tích/)).toBeVisible();
  await expect(page.getByText("Chi phí ước tính: Chưa xác định")).toBeVisible();
  const firstResponse = await browserRequest(
    page,
    "/api/ai/work-report-analyses/history",
  );
  expect(firstResponse.status).toBe(200);
  const id = firstResponse.body.data[0].id;
  const first = await browserRequest(
    page,
    `/api/ai/work-report-analyses/${id}`,
  );
  expect(first.body.data.work_counts.reports).toBe(1);
  const source = first.body.data.source_reports[0];
  const item = source.item_ids[0];

  await page.getByRole("tab", { name: "Theo nhân viên", exact: true }).click();
  await page
    .getByRole("navigation", { name: "Danh sách nhân viên", exact: true })
    .getByRole("button", { name: /Employee A/ })
    .click();
  const person = page.getByRole("region", {
    name: "Báo cáo AI của Employee A",
    exact: true,
  });
  await expect(
    person.getByRole("heading", { name: "Tổng hợp từ báo cáo", exact: true }),
  ).toBeVisible();
  await expect(
    person.getByRole("heading", { name: "Nhận xét", exact: true }),
  ).toBeVisible();
  await expect(
    person.getByText(/Ghi nhận từ nguồn: E2E/).first(),
  ).toBeVisible();
  await person.getByText("Nguồn báo cáo và đối chiếu", { exact: true }).click();
  await person
    .getByRole("link", { name: new RegExp(`Đầu việc #${item}$`) })
    .first()
    .click();
  await expect(page).toHaveURL(
    new RegExp(`/reports/${source.id}#work-item-${item}$`),
  );
  await expect(page.locator(`#work-item-${item}`)).toBeInViewport();
  await page.goto("/ai/insights?days=1");
  await page
    .getByRole("tab", { name: "Lịch sử phân tích", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Xem kết quả", exact: true })
    .first()
    .click();
  await expect(page).toHaveURL(new RegExp(`analysis=${id}`));
  await page.reload();
  await expect(page.getByText(/Hoàn tất · Lần phân tích/)).toBeVisible();
  await page.getByRole("tab", { name: "Báo cáo nguồn", exact: true }).click();
  await expect(
    page.getByRole("link", { name: new RegExp(`Báo cáo #${source.id}$`) }),
  ).toBeVisible();
  expect(creates).toBe(1);

  await page.getByRole("button", { name: "Xem dữ liệu hiện tại" }).click();
  await page
    .getByRole("link", { name: "Theo kỳ / báo cáo tuần", exact: true })
    .click();
  for (const [days, expected] of [
    [7, 2],
    [30, 3],
  ]) {
    const filters = page
      .locator("details")
      .filter({ has: page.locator("#analysis-period") });
    if ((await filters.getAttribute("open")) === null)
      await filters.locator("summary").click();
    await page.locator("#analysis-period").selectOption(String(days));
    await expect(
      page.getByRole("button", { name: "Tạo / mở phân tích", exact: true }),
    ).toBeEnabled();
    await page
      .getByRole("button", { name: "Tạo / mở phân tích", exact: true })
      .click();
    await expect(page.getByText(/Hoàn tất · Lần phân tích/)).toBeVisible();
    const runs = await browserRequest(
      page,
      "/api/ai/work-report-analyses/history",
    );
    const details = await browserRequest(
      page,
      `/api/ai/work-report-analyses/${runs.body.data[0].id}`,
    );
    expect(details.body.data.period.days).toBe(days);
    expect(details.body.data.work_counts.reports).toBe(expected);
  }
  // A separate real browser session reads the same saved run under current assistant RBAC.
  const assistant = await browser.newPage();
  await login(assistant, "assistant");
  const shared = await browserRequest(
    assistant,
    `/api/ai/work-report-analyses/${id}`,
  );
  expect(shared.status).toBe(200);
  expect(shared.body.data.id).toBe(id);
  const cached = await browserRequest(
    assistant,
    "/api/ai/work-report-analyses",
    "POST",
    {
      end_date: first.body.data.period.end_date,
      days: 1,
    },
  );
  expect(cached.status).toBe(202);
  expect(cached.body.data.id).toBe(id);
  const simultaneous = await Promise.all(
    [page, assistant].map((session) =>
      browserRequest(session, "/api/ai/work-report-analyses", "POST", {
        end_date: first.body.data.period.end_date,
        days: 1,
      }),
    ),
  );
  for (const response of simultaneous) {
    expect(response.status).toBe(202);
    expect(response.body.data.id).toBe(id);
  }
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page.locator("main").locator("..")).toHaveCSS(
    "margin-inline-start",
    "0px",
  );
  await expect
    .poll(async () => (await page.locator("main").boundingBox())?.width ?? 0)
    .toBeGreaterThanOrEqual(350);
  await page.screenshot({
    path: "test-results/work-report-analysis-mobile.png",
    fullPage: true,
    animations: "disabled",
  });
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  await assistant.close();
});

test("staff cannot open analysis APIs by ID or source endpoint", async ({
  page,
}) => {
  await login(page, "employee-a");
  for (const path of ["history", "1", "1/sources"]) {
    expect(
      (await browserRequest(page, `/api/ai/work-report-analyses/${path}`))
        .status,
    ).toBe(403);
  }
});

test("regenerate frozen analysis with feedback, double click, lost response recovery and old version", async ({
  page,
  browser,
}) => {
  test.setTimeout(240_000);
  // A separate reviewer keeps this scenario independent of the first case's per-user mutation limit.
  await login(page, "multi-reviewer");
  await page.goto("/ai/insights?days=1");
  const history = await browserRequest(
    page,
    "/api/ai/work-report-analyses/history",
  );
  let source = history.body.data.find(
    (run: { period: { days: number }; status: string }) =>
      run.period.days === 1 && run.status === "completed",
  );
  if (!source) {
    const response = page.waitForResponse(
      (res) =>
        res.request().method() === "POST" &&
        /\/api\/ai\/work-report-analyses\/?$/.test(res.url()),
    );
    await page
      .getByRole("button", { name: "Tạo / mở phân tích", exact: true })
      .click();
    source = (await (await response).json()).data;
    await expect(page.getByText(/Hoàn tất · Lần phân tích/)).toBeVisible();
  }
  expect(source).toBeTruthy();
  const sourceUrl = `/ai/insights?analysis=${source.id}&days=1&end=${source.period.end_date}`;
  await page.goto(sourceUrl);
  await page
    .getByRole("button", { name: "Tạo bản phân tích mới", exact: true })
    .click();
  const dialog = page.getByRole("dialog");
  await expect(dialog.getByText(/Bản cũ vẫn được giữ/)).toBeVisible();
  await expect(dialog.getByText(/phát sinh lượt gọi mới/)).toBeVisible();
  const feedback = dialog.getByLabel("Góp ý tùy chọn (tối đa 1.000 ký tự)");
  await feedback.fill("ắ".repeat(1001));
  const confirm = dialog.getByRole("button", {
    name: "Xác nhận tạo bản mới",
    exact: true,
  });
  await expect(confirm).toBeDisabled();
  await feedback.fill(
    "Làm rõ vướng mắc và nhu cầu hỗ trợ theo từng nhân viên.",
  );
  let posts = 0;
  page.on("request", (request) => {
    if (request.method() === "POST" && request.url().endsWith("/regenerate"))
      posts++;
  });
  const response = page.waitForResponse(
    (res) =>
      res.request().method() === "POST" && res.url().endsWith("/regenerate"),
  );
  await confirm.evaluate((button: HTMLButtonElement) => {
    button.click();
    button.click();
  });
  const created = (await (await response).json()).data;
  expect(created.id).not.toBe(source.id);
  expect(created.revision.parent_id).toBe(source.id);
  await expect(page).toHaveURL(new RegExp(`analysis=${created.id}(?:&|$)`));
  await expect(page.getByText(/Hoàn tất · Lần phân tích/)).toBeVisible();
  expect(posts).toBe(1);
  await page
    .getByRole("link", { name: `Mở bản nguồn #${source.id}`, exact: true })
    .click();
  await expect(page).toHaveURL(new RegExp(`analysis=${source.id}(?:&|$)`));
  await expect(page.getByText(/Hoàn tất · Lần phân tích/)).toBeVisible();
  // Persist server-side operation, then lose only its HTTP response. Reload must use GET lookup.
  let lostId: number | null = null;
  await page.route(
    "**/api/ai/work-report-analyses/*/regenerate",
    async (route) => {
      const result = await route.fetch();
      expect(result.status()).toBe(202);
      lostId = (await result.json()).data.id;
      await route.abort("failed");
    },
  );
  await page
    .getByRole("button", { name: "Tạo bản phân tích mới", exact: true })
    .click();
  await dialog
    .getByLabel("Góp ý tùy chọn (tối đa 1.000 ký tự)")
    .fill("Tổng hợp chi tiết hơn.");
  await dialog
    .getByRole("button", { name: "Xác nhận tạo bản mới", exact: true })
    .click();
  await expect(dialog.getByRole("alert")).toContainText("Chưa xác định");
  const pointers = await page.evaluate(() =>
    Object.keys(sessionStorage)
      .filter((k) => k.startsWith("kps-analysis-regeneration:"))
      .map((k) => JSON.parse(sessionStorage.getItem(k)!)),
  );
  expect(pointers).toHaveLength(1);
  expect(Object.keys(pointers[0]).sort()).toEqual([
    "key",
    "sourceId",
    "summaryOnly",
  ]);
  expect(pointers[0].summaryOnly).toBe(false);
  await page.reload();
  await expect(page).toHaveURL(new RegExp(`analysis=${lostId}(?:&|$)`));
  await expect(page.getByText(/Hoàn tất · Lần phân tích/)).toBeVisible();
  expect(posts).toBe(2);
  await page
    .getByRole("link", { name: `Mở bản nguồn #${source.id}`, exact: true })
    .click();
  await expect(page.getByText(/Hoàn tất · Lần phân tích/)).toBeVisible();
  await page
    .getByRole("tab", { name: "Lịch sử phân tích", exact: true })
    .click();
  await expect(
    page.locator("article").filter({ hasText: `Lần phân tích #${created.id}` }),
  ).toContainText(`Mở bản nguồn #${source.id}`);
  await page.screenshot({
    path: "test-results/work-report-regeneration-history.png",
    fullPage: true,
    animations: "disabled",
  });
  await page.unroute("**/api/ai/work-report-analyses/*/regenerate");
  await page.goto(sourceUrl);
  await page
    .getByRole("button", { name: "Tạo lại riêng tổng hợp", exact: true })
    .click();
  await expect(
    dialog.getByText(/Dùng lại các phần nhân viên đã thành công/),
  ).toBeVisible();
  const summaryResponse = page.waitForResponse(
    (res) =>
      res.request().method() === "POST" && res.url().endsWith("/regenerate"),
  );
  await dialog
    .getByRole("button", { name: "Xác nhận tạo bản mới", exact: true })
    .click();
  const summaryResult = await summaryResponse;
  expect(summaryResult.status()).toBe(202);
  expect(summaryResult.request().postDataJSON().summary_only).toBe(true);
  const summaryRun = (await summaryResult.json()).data;
  expect(summaryRun.reused_employee_units).toBe(1);
  expect(summaryRun.revision.parent_id).toBe(source.id);
  await expect(page).toHaveURL(new RegExp(`analysis=${summaryRun.id}(?:&|$)`));
  await expect(page.getByText(/Hoàn tất · Lần phân tích/)).toBeVisible();
  const assistant = await browser.newPage();
  await login(assistant, "assistant");
  const key = await assistant.evaluate(() => crypto.randomUUID());
  const simultaneous = await Promise.all(
    [1, 2].map(() =>
      browserRequest(
        assistant,
        `/api/ai/work-report-analyses/${source.id}/regenerate`,
        "POST",
        {
          idempotency_key: key,
          review_feedback: "Đối chiếu căn cứ rõ hơn.",
        },
      ),
    ),
  );
  expect(simultaneous.map((result) => result.status)).toEqual([202, 202]);
  expect(simultaneous[0].body.data.id).toBe(simultaneous[1].body.data.id);
  await assistant.close();
});
