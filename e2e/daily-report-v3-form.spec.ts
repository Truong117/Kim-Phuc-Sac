import { expect, test } from "@playwright/test";
import { browserRequest, login } from "./helpers";

test("daily report V3 preserves four sections through draft reload, mode switch and a failed save", async ({
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
    "2. Công việc đang thực hiện",
    "3. Kế hoạch ngày mai",
    "4. Công việc có vấn đề / cần hỗ trợ",
  ]) {
    await expect(
      page.getByRole("heading", { name, exact: true }),
    ).toBeVisible();
  }
  await page.locator("#quick-draft-completed").fill("Đăng video thử nghiệm");
  const completed = page.locator("section").filter({
    has: page.getByRole("heading", {
      name: "1. Công việc đã hoàn thành",
      exact: true,
    }),
  });
  await completed
    .getByRole("button", { name: "Thêm vào báo cáo", exact: true })
    .click();
  const contentField = page.locator('textarea[id^="quick-content-"]');
  const resultField = page.locator('textarea[id^="quick-result-"]');
  await expect(resultField).toHaveAttribute("required", "");
  await contentField.evaluate((field) => {
    field.style.height = "180px";
  });
  await expect
    .poll(async () => Math.round((await resultField.boundingBox())!.height))
    .toBe(180);
  await resultField.evaluate((field) => {
    field.style.height = "100px";
  });
  await expect
    .poll(async () => Math.round((await contentField.boundingBox())!.height))
    .toBe(100);
  let creations = 0;
  page.on("request", (request) => {
    if (request.url().endsWith("/api/reports") && request.method() === "POST")
      creations++;
  });
  await page.locator("#next-day-plan").fill("Chuẩn bị video ngày mai");
  await resultField.fill("   ");
  await page.locator('form button[type="submit"]').click();
  await expect(
    page.getByText(
      "Vui lòng kiểm tra các trường được tô đỏ và hoàn thiện thông tin trước khi lưu.",
      {
        exact: true,
      },
    ),
  ).toBeVisible();
  expect(creations).toBe(0);
  await resultField.fill("2 video");
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

test("makeup submission uses an eligible missing date and is immediately locked", async ({
  page,
}) => {
  await login(page, "employee-b");
  const options = await browserRequest(page, "/api/reports/makeup");
  expect(options.status).toBe(200);
  const day = options.body.data.days.find(
    (entry: { can_submit: boolean }) => entry.can_submit,
  );
  test.skip(
    !day,
    "Requires a missing working day before the three-day/Saturday cutoff",
  );
  await page.goto("/reports/new");
  await page.getByRole("link", { name: "Nộp báo cáo bù", exact: true }).click();
  await page.locator("#makeup-report-date").selectOption(day.date);
  await page
    .locator("#quick-draft-completed")
    .fill("Đăng video bù → 2 video đã đăng");
  await page
    .locator("#next-day-plan")
    .fill("  Dựng video tiếp theo  \n\n  Thiết kế ảnh  ");
  const response = page.waitForResponse(
    (r) =>
      r.url().endsWith("/api/reports/makeup") &&
      r.request().method() === "POST",
  );
  await page
    .getByRole("button", { name: "Xác nhận gửi báo cáo bù", exact: true })
    .click();
  const saved = await response;
  expect(saved.status()).toBe(201);
  const data = (await saved.json()).data;
  expect(data.report_date).toBe(day.date);
  expect(data.is_makeup).toBe(true);
  expect(data.next_day_plan).toBe("Dựng video tiếp theo\nThiết kế ảnh");
  expect(data.is_locked).toBe(true);
  expect(data.actions.can_edit).toBe(false);
  await page.goto(`/reports/${data.id}`);
  await expect(page.getByText("Báo cáo bù", { exact: true })).toBeVisible();
  await expect(page.getByText(/Nộp thực tế:/)).toBeVisible();
});
