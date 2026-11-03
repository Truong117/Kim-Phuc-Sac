import { expect, test } from "@playwright/test";
import { login } from "./helpers";

test("daily inventory survives partial processing and retries only the failed part", async ({
  page,
}) => {
  await login(page, "owner");
  let created = false;
  let retried = false;
  let requests = 0;
  const source = {
    report_id: 7,
    employee_id: 42,
    date: "2026-11-02",
    is_makeup: true,
    submitted_at: "2026-11-03T00:00:00Z",
    next_day_plan: ["Dự kiến đăng 30 video"],
    items: [
      {
        item_id: 9,
        status: "COMPLETED",
        content: "Biên tập video minh họa",
        result: "Đã dựng 2 video",
        note: "",
        issue: "Chờ duyệt",
        evidence_links: ["https://example.com/video"],
        evidence_status: "unverified",
      },
    ],
  };
  const document = () => ({
    id: 3,
    report_id: 7,
    status: retried ? "completed" : "partial",
    source,
    mode: "fake",
    model: null,
    captured_at: "2026-11-03T00:00:00Z",
    completed_units: retried ? 2 : 1,
    total_units: 2,
    result: { comments: [], recommendations: [], work_totals: [] },
    units: [
      {
        id: 1,
        part: 0,
        status: "completed",
        error_code: null,
        can_retry: false,
      },
      {
        id: 2,
        part: 1,
        status: retried ? "completed" : "failed",
        error_code: retried ? null : "AI_WORKER_INTERRUPTED",
        can_retry: !retried,
      },
    ],
  });
  await page.route("**/api/ai/daily-documents**", async (route) => {
    if (route.request().method() === "POST") {
      requests++;
      if (route.request().url().endsWith("/retry")) retried = true;
      else created = true;
      await route.fulfill({ status: 202, json: { data: document() } });
    } else {
      await route.fulfill({
        json: {
          data: {
            enabled: true,
            date: "2026-11-02",
            page: 1,
            last_page: 1,
            total: 1,
            reports: [
              {
                report_id: 7,
                employee_name: "Nhân viên minh họa",
                source,
                source_changed: false,
                document: created ? document() : null,
              },
            ],
          },
        },
      });
    }
  });
  await page.goto("/ai/insights?view=daily&date=2026-11-02");
  await expect(
    page.getByText("Đã dựng 2 video", { exact: false }),
  ).toBeVisible();
  await expect(
    page.getByText("Minh chứng do nhân viên cung cấp — chưa xác minh"),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Tạo / mở tổng hợp ngày", exact: true })
    .click();
  await expect(
    page.getByText("Hoàn tất một phần", { exact: false }),
  ).toBeVisible();
  await expect(
    page.getByText("Đã dựng 2 video", { exact: false }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Thử lại phần lỗi", exact: true })
    .click();
  await expect(
    page.getByText("Đã xử lý đủ phần", { exact: false }),
  ).toBeVisible();
  expect(requests).toBe(2);
  await expect(
    page.getByRole("button", { name: "Thử lại phần lỗi", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByText("Dự kiến đăng 30 video", { exact: true }),
  ).toBeVisible();
  await page
    .getByRole("link", { name: "Theo kỳ / báo cáo tuần", exact: true })
    .click();
  await expect(
    page
      .getByRole("heading", {
        name: "Phân tích báo cáo công việc",
        exact: true,
      })
      .first(),
  ).toBeVisible();
});
