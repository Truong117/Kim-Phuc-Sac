import { expect, test } from "@playwright/test";
import { browserRequest, login } from "./helpers";

test("daily report V3 preserves all five sections through draft reload, mode switch and a failed save", async ({
  page,
}) => {
  await login(page, "employee-b");
  const today = (await browserRequest(page, "/api/reports/today")).body.data;
  test.skip(
    !today.is_working_day || today.is_window_closed || Boolean(today.report),
    "Requires an open working day and the clean, isolated synthetic fixture",
  );
  await page.goto("/reports/new");
  for (const name of [
    "1. Công việc đã hoàn thành",
    "2. Kết quả đạt được",
    "3. Công việc đang thực hiện",
    "4. Kế hoạch ngày mai",
    "5. Công việc có vấn đề / cần hỗ trợ",
  ]) {
    await expect(
      page.getByRole("heading", { name, exact: true }),
    ).toBeVisible();
  }
  await page
    .locator("#quick-draft-completed")
    .fill("Đăng video thử nghiệm → 2 video");
  const completed = page.locator("section").filter({
    has: page.getByRole("heading", {
      name: "1. Công việc đã hoàn thành",
      exact: true,
    }),
  });
  await completed
    .getByRole("button", { name: "Thêm vào báo cáo", exact: true })
    .click();
  await page.locator('textarea[id^="issue-"]').fill("Chờ phản hồi duyệt");
  await page
    .locator('textarea[id^="evidence-"]')
    .fill("https://example.com/video/1\nhttps://example.com/video/2");
  await page.locator("#next-day-plan").fill("Chuẩn bị video ngày mai");
  await expect
    .poll(() =>
      page.evaluate(() =>
        Object.keys(localStorage)
          .filter((key) => key.startsWith("kps:work-report:draft:v2:"))
          .some(
            (key) =>
              JSON.parse(localStorage.getItem(key)!).data.nextDayPlan ===
              "Chuẩn bị video ngày mai",
          ),
      ),
    )
    .toBe(true);
  await page.reload();
  await page
    .getByRole("button", { name: "Khôi phục bản nháp", exact: true })
    .click();
  await expect(page.locator("#next-day-plan")).toHaveValue(
    "Chuẩn bị video ngày mai",
  );
  await page
    .getByRole("tab", { name: "Báo cáo chi tiết", exact: true })
    .click();
  await expect(page.locator('textarea[id^="issue-"]')).toHaveValue(
    "Chờ phản hồi duyệt",
  );
  await expect(page.locator('textarea[id^="evidence-"]')).toHaveValue(
    "https://example.com/video/1\nhttps://example.com/video/2",
  );
  await page.route("**/api/reports", async (route) => {
    if (route.request().method() === "POST") await route.abort("failed");
    else await route.continue();
  });
  await page.locator('form button[type="submit"]').click();
  await expect(page.getByRole("alert").first()).toBeVisible();
  await expect(page.locator("#next-day-plan")).toHaveValue(
    "Chuẩn bị video ngày mai",
  );
  await page.unroute("**/api/reports");
  const response = page.waitForResponse(
    (r) => r.url().endsWith("/api/reports") && r.request().method() === "POST",
  );
  await page.locator('form button[type="submit"]').click();
  const saved = await response;
  expect(saved.status()).toBe(201);
  const data = (await saved.json()).data;
  expect(data.next_day_plan).toBe("Chuẩn bị video ngày mai");
  expect(data.items[0].issue).toBe("Chờ phản hồi duyệt");
  expect(data.items[0].evidence_links).toEqual([
    "https://example.com/video/1",
    "https://example.com/video/2",
  ]);
  await page.goto(`/reports/${data.id}`);
  await expect(
    page.getByText("Chuẩn bị video ngày mai", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Chưa xác minh nội dung đường dẫn.", { exact: true }),
  ).toBeVisible();
});
