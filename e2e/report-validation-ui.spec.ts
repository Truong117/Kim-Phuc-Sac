import { expect, test, type Page } from "@playwright/test";

async function setup(page: Page, makeup: boolean) {
  let submissions = 0;
  let errors: Record<string, string[]> = {};
  const user = {
    id: 10001,
    name: "Nhân viên kiểm thử",
    email: "report-ui@example.test",
    role: { name: "Nhân viên" },
    department: null,
    organization: { id: 1, name: "KPS Test" },
    location: null,
  };
  const state = {
    business_date: makeup ? "2026-11-04" : "2026-11-05",
    cutoff_at: "2099-11-05T23:00:00+07:00",
    is_working_day: true,
    is_window_closed: false,
    reporting_required: true,
    report: null,
    actions: { can_create: true },
  };
  await page.route("**/api/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (
      route.request().method() === "POST" &&
      ["/api/reports", "/api/reports/makeup"].includes(path)
    ) {
      submissions++;
      return route.fulfill({
        status: 422,
        json: { message: "Dữ liệu chưa hợp lệ", errors },
      });
    }
    const json =
      path === "/api/auth/me"
        ? user
        : path === "/api/navigation"
          ? { items: ["reports.new", "reports.history"] }
          : path === "/api/reports/today" ||
              path === "/api/reports/makeup/state"
            ? { data: state }
            : path === "/api/reports/makeup"
              ? {
                  data: {
                    period_start: "2026-11-02",
                    period_end: "2026-11-07",
                    days: [
                      {
                        date: "2026-11-04",
                        can_submit: true,
                        deadline_at: "2099-11-07T23:59:59+07:00",
                      },
                    ],
                  },
                }
              : {
                  data: [],
                  unread_count: 0,
                  meta: { total: 0, current_page: 1, last_page: 1 },
                };
    await route.fulfill({ json });
  });
  await page.goto(makeup ? "/reports/makeup/new" : "/reports/new");
  await expect(page.locator("#quick-draft-completed")).toBeVisible();
  return {
    count: () => submissions,
    setErrors: (value: Record<string, string[]>) => {
      errors = value;
    },
  };
}

for (const makeup of [false, true]) {
  const kind = makeup ? "makeup" : "daily";
  test(`${kind}: missing fields scroll into view, turn red and keep pending items once; API 422 maps back to fields`, async ({
    page,
  }) => {
    const api = await setup(page, makeup);
    await page.locator("#quick-draft-completed").fill("Đăng video");
    const save = page.locator('form button[type="submit"]');
    await save.click();
    const result = page.locator('textarea[id^="quick-result-"]');
    await expect(result).toBeFocused();
    await expect(result).toHaveAttribute("aria-invalid", "true");
    await expect(result).toHaveClass(/border-error-500/);
    await expect(page.locator("#next-day-plan")).toHaveAttribute(
      "aria-invalid",
      "true",
    );
    await expect(page.locator("[data-report-error-summary]")).toContainText(
      "tô đỏ",
    );
    await expect(result).toBeInViewport();
    expect(api.count()).toBe(0);
    await save.click();
    await expect(result).toHaveCount(1);
    await result.fill("Đã đăng 2 video");
    await expect(result).not.toHaveAttribute("aria-invalid", "true");
    await save.click();
    await expect(page.locator("#next-day-plan")).toBeFocused();
    await page.locator("#next-day-plan").fill("Làm video ngày mai");
    api.setErrors({
      "items.0.evidence_links.0": ["Link minh chứng chưa hợp lệ."],
      next_day_plan: ["Kế hoạch cần bổ sung."],
    });
    const evidence = page.locator('textarea[id^="evidence-"]');
    await evidence.fill("https://example.com/video");
    // Repeated modal open/close cycles must not steal focus back from API errors.
    for (let attempt = 1; attempt <= 3; attempt++) {
      await save.click();
      await expect(evidence).toBeFocused();
      await expect(evidence).toHaveAttribute("aria-invalid", "true");
      await expect(evidence).toBeInViewport();
      await expect(
        page.getByText("Link minh chứng chưa hợp lệ.", { exact: true }),
      ).toBeVisible();
      await expect(page.locator("#next-day-plan")).toHaveAttribute(
        "aria-invalid",
        "true",
      );
      expect(api.count()).toBe(attempt);
    }
    await evidence.fill("https://example.com/fixed");
    await expect(evidence).not.toHaveAttribute("aria-invalid", "true");
    await expect(page.locator("#next-day-plan")).toHaveAttribute(
      "aria-invalid",
      "true",
    );
    await page.locator("#next-day-plan").fill("Kế hoạch đã bổ sung");
    await expect(page.locator("#next-day-plan")).not.toHaveAttribute(
      "aria-invalid",
      "true",
    );
    await expect(result).toHaveValue("Đã đăng 2 video");
  });

  test(`${kind}: oversized pending quick text focuses its red input without a save request`, async ({
    page,
  }) => {
    const api = await setup(page, makeup);
    const draft = page.locator("#quick-draft-completed");
    await draft.fill("x".repeat(5001));
    await page.locator('form button[type="submit"]').click();
    await expect(draft).toBeFocused();
    await expect(draft).toHaveAttribute("aria-invalid", "true");
    await expect(draft).toHaveClass(/border-error-500/);
    expect(api.count()).toBe(0);
  });

  test(`${kind}: detailed fields all show errors and the first visible invalid field gets focus`, async ({
    page,
  }) => {
    const api = await setup(page, makeup);
    await page.locator("#quick-draft-completed").fill("Việc cần làm");
    await page
      .getByRole("tab", { name: "Báo cáo chi tiết", exact: true })
      .click();
    const content = page.locator('textarea[id^="content-"]');
    const result = page.locator('textarea[id^="result-"]');
    await content.fill("");
    await page.locator('form button[type="submit"]').click();
    await expect(content).toBeFocused();
    await expect(content).toHaveClass(/border-error-500/);
    await expect(result).toHaveAttribute("aria-invalid", "true");
    await expect(page.locator("#next-day-plan")).toHaveAttribute(
      "aria-invalid",
      "true",
    );
    expect(api.count()).toBe(0);
  });
}

for (const makeup of [false, true]) {
  const kind = makeup ? "makeup" : "daily";
  test(`${kind}: share links are checked before save and warnings never block submission`, async ({
    page,
  }) => {
    const api = await setup(page, makeup);
    let status = "matched";
    await page.route("**/api/reports/check-links", async (route) => {
      const { urls } = route.request().postDataJSON();
      await route.fulfill({
        json: {
          data: urls.map((url: string) => ({
            url,
            status,
            page_name: "Kim Phục Sắc",
          })),
        },
      });
    });
    await page.locator("#quick-draft-completed").fill("Đăng reel → Đã đăng");
    const save = page.locator('form button[type="submit"]');
    await save.click();
    await page.locator("#next-day-plan").fill("Chuẩn bị bài ngày mai");
    const evidence = page.locator('textarea[id^="evidence-"]');
    const share = "https://www.facebook.com/share/r/1HCg7qFhTp/";
    await evidence.fill(share);
    const checks = page.locator("[data-evidence-link-checks]");
    await expect(checks).toContainText("Đã đối chiếu với Page Kim Phục Sắc");
    await expect(evidence).toHaveValue(share);
    status = "unverified";
    await evidence.fill("https://facebook.com/share/p/Unknown/");
    await expect(checks).not.toContainText("Đã đối chiếu");
    await expect(checks).toContainText("Chưa xác minh được");
    await save.click();
    expect(api.count()).toBe(1);
    await expect(evidence).toHaveValue("https://facebook.com/share/p/Unknown/");
    await expect(page.locator('textarea[id^="quick-result-"]')).toHaveValue(
      "Đã đăng",
    );
  });
}

test("link checks handle rate limits and malformed input without losing the draft", async ({
  page,
}) => {
  const api = await setup(page, false);
  let checks = 0;
  await page.route("**/api/reports/check-links", async (route) => {
    checks++;
    await route.fulfill({
      status: 429,
      json: { message: "raw-provider-secret" },
    });
  });
  await page.locator("#quick-draft-completed").fill("Đăng bài → Hoàn thành");
  const save = page.locator('form button[type="submit"]');
  await save.click();
  await page.locator("#next-day-plan").fill("Kế hoạch ngày mai");
  const evidence = page.locator('textarea[id^="evidence-"]');
  await evidence.fill("https://drive.google.com/file/d/example/view");
  await expect(page.locator("[data-evidence-link-checks]")).toHaveCount(0);
  await evidence.fill("ftp://facebook.com/reel/555");
  await expect(page.locator("[data-evidence-link-checks]")).toContainText(
    "Đường dẫn không hợp lệ",
  );
  await save.click();
  await expect(evidence).toHaveAttribute("aria-invalid", "true");
  expect(api.count()).toBe(0);
  expect(checks).toBe(0);
  await evidence.fill("https://facebook.com/share/r/RateLimited");
  await expect(page.locator("[data-evidence-link-checks]")).toContainText(
    "Chờ khoảng một phút",
  );
  await expect(page.locator("body")).not.toContainText("raw-provider-secret");
  await save.click();
  expect(api.count()).toBe(1);
});

test("an old link response cannot mark the replacement link as matched", async ({
  page,
}) => {
  await setup(page, false);
  let releaseOld!: () => void;
  const oldGate = new Promise<void>((resolve) => {
    releaseOld = resolve;
  });
  await page.route("**/api/reports/check-links", async (route) => {
    const { urls } = route.request().postDataJSON();
    const old = urls[0].endsWith("/Old");
    if (old) await oldGate;
    await route.fulfill({
      json: {
        data: urls.map((url: string) => ({
          url,
          status: old ? "matched" : "unverified",
          page_name: "OLD PAGE",
        })),
      },
    });
  });
  await page.locator("#quick-draft-completed").fill("Đăng bài → Hoàn thành");
  await page.locator('form button[type="submit"]').click();
  const evidence = page.locator('textarea[id^="evidence-"]');
  const request = page.waitForRequest("**/api/reports/check-links");
  await evidence.fill("https://facebook.com/share/r/Old");
  await request;
  await evidence.fill("https://facebook.com/share/r/New");
  releaseOld();
  await expect(page.locator("[data-evidence-link-checks]")).toContainText(
    "Chưa xác minh được",
  );
  await expect(page.locator("[data-evidence-link-checks]")).not.toContainText(
    "OLD PAGE",
  );
});

test("identified Facebook links distinguish connection errors from an unfinished identity check", async ({
  page,
}) => {
  await setup(page, false);
  let reason = "SOCIAL_RECONNECT_REQUIRED";
  await page.route("**/api/reports/check-links", async (route) => {
    const { urls } = route.request().postDataJSON();
    await route.fulfill({
      json: {
        data: urls.map((url: string) => ({
          url,
          status: "identified",
          reason_code: reason,
        })),
      },
    });
  });
  await page.locator("#quick-draft-completed").fill("Đăng bài → Đã đăng");
  await page.locator('form button[type="submit"]').click();
  const evidence = page.locator('textarea[id^="evidence-"]');
  const share = "https://facebook.com/share/p/Example";
  await evidence.fill(share);
  const checks = page.locator("[data-evidence-link-checks]");
  await expect(checks).toContainText("kết nối Page cần cấp quyền lại");
  await expect(checks).not.toContainText("300 bài");
  reason = "SOCIAL_CHECK_PENDING";
  await checks.getByRole("button", { name: "Kiểm tra lại đường dẫn" }).click();
  await expect(checks).toContainText("cần đối chiếu thêm mã bài");
  await expect(checks).not.toContainText("cần cấp quyền lại");
  await expect(evidence).toHaveValue(share);
});
