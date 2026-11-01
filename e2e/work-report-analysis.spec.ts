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
  await page.goto("/ai/insights");
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
  const person = page
    .locator("details")
    .filter({ has: page.locator("summary", { hasText: "Employee A" }) });
  await person.locator("summary").click();
  await expect(person.getByText(/Theo báo cáo: E2E/).first()).toBeVisible();
  await person
    .getByRole("link", { name: new RegExp(`Đầu việc #${item}$`) })
    .first()
    .click();
  await expect(page).toHaveURL(
    new RegExp(`/reports/${source.id}#work-item-${item}$`),
  );
  await expect(page.locator(`#work-item-${item}`)).toBeInViewport();
  await page.goto("/ai/insights");
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
  for (const [days, expected] of [
    [7, 2],
    [30, 3],
  ]) {
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
  await page.screenshot({
    path: "test-results/work-report-analysis-mobile.png",
    fullPage: true,
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
