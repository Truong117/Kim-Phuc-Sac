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
