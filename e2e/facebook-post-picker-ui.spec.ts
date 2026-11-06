import { expect, test, type Page } from "@playwright/test";

async function setup(
  page: Page,
  makeup: boolean,
  assigned: boolean | "error" = true,
) {
  let submissions = 0;
  let submitted: { items: { facebook_selections: unknown }[] } | null = null;
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
    if (path === "/api/reports/facebook-pages" && assigned === "error") {
      return route.fulfill({ status: 503, json: { message: "Unavailable" } });
    }
    if (
      route.request().method() === "POST" &&
      ["/api/reports", "/api/reports/makeup"].includes(path)
    ) {
      submissions++;
      submitted = route.request().postDataJSON();
      return route.fulfill({
        status: 422,
        json: { message: "Dữ liệu chưa hợp lệ", errors },
      });
    }
    const json =
      path === "/api/auth/me"
        ? user
        : path === "/api/reports/facebook-pages"
          ? { data: assigned ? [{ id: 1, name: "KPS Page" }] : [] }
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
    submitted: () => submitted,
    setErrors: (value: Record<string, string[]>) => {
      errors = value;
    },
  };
}

const share = "https://www.facebook.com/share/p/TestShare/";
const makePost = (id: string, message: string, source?: string) => ({
  source_url: source ?? `https://facebook.com/kps/posts/${id}`,
  entry_id: 1,
  page_name: "KPS Page",
  external_id: `100_${id}`,
  post_url: `https://facebook.com/kps/posts/${id}`,
  message,
  created_time: `2026-11-0${id === "555" ? "4" : "3"}T03:00:00Z`,
  picture: null,
  type: "post",
  selection_token: `application-proof-${id}`,
});
async function mockPosts(page: Page) {
  await page.route("**/api/reports/facebook-posts?**", async (route) => {
    const url = new URL(route.request().url());
    const source = url.searchParams.get("source_url") ?? undefined;
    const cursor = url.searchParams.get("cursor");
    await route.fulfill({
      json: {
        data: cursor
          ? [makePost("888", "Bài cũ hơn", source)]
          : [
              makePost("555", "Bài mới nhất", source),
              makePost("777", "Reel hôm trước", source),
            ],
        pages: [{ id: 1, name: "KPS Page" }],
        entry_id: 1,
        next_cursor: cursor ? null : "opaque-cursor",
      },
    });
  });
}
async function addWork(page: Page) {
  await page.locator("#quick-draft-completed").fill("Đăng bài → Đã đăng");
  await page.locator('form button[type="submit"]').click();
  await page.locator("#next-day-plan").fill("Kế hoạch tiếp theo");
}

test("daily picker previews separately, confirms multiple posts and submits only application proofs", async ({
  page,
}) => {
  const api = await setup(page, false);
  await mockPosts(page);
  await addWork(page);
  const evidence = page.locator('textarea[id^="evidence-"]');
  await page
    .getByRole("button", { name: "Chọn bài từ Facebook", exact: true })
    .click();
  const dialog = page.getByRole("dialog", { name: "Chọn bài từ Facebook" });
  await expect(dialog.getByRole("checkbox")).toHaveCount(2);
  await dialog.getByRole("button", { name: "Xem trước: Bài mới nhất" }).hover();
  await expect(
    dialog.getByRole("region", { name: "Xem trước bài" }),
  ).toContainText("Bài mới nhất");
  await expect(
    dialog.getByRole("button", { name: "Xác nhận chọn 0 bài" }),
  ).toBeDisabled();
  await dialog
    .getByRole("checkbox", { name: "Chọn: Bài mới nhất", exact: true })
    .check();
  await expect(evidence).toHaveValue("");
  await dialog.getByRole("button", { name: "Hủy", exact: true }).click();
  await expect(evidence).toHaveValue("");
  await page
    .getByRole("button", { name: "Chọn bài từ Facebook", exact: true })
    .click();
  await dialog
    .getByRole("checkbox", { name: "Chọn: Bài mới nhất", exact: true })
    .check();
  await dialog
    .getByRole("checkbox", { name: "Chọn: Reel hôm trước", exact: true })
    .check();
  await dialog.getByRole("button", { name: "Xác nhận chọn 2 bài" }).click();
  await expect(dialog).not.toBeVisible();
  await expect(evidence).toHaveValue(
    "https://facebook.com/kps/posts/555\nhttps://facebook.com/kps/posts/777",
  );
  await expect(page.locator("[data-evidence-link-checks]")).toHaveCount(0);
  await expect(page.locator('textarea[id^="quick-result-"]')).toHaveValue(
    "Đã đăng",
  );
  await expect(page.locator("#next-day-plan")).toHaveValue(
    "Kế hoạch tiếp theo",
  );
  await page.locator('form button[type="submit"]').click();
  await expect.poll(api.count).toBe(1);
  const payload = api.submitted()!;
  expect(payload.items[0].facebook_selections).toEqual([
    {
      source_url: "https://facebook.com/kps/posts/555",
      selection_token: "application-proof-555",
    },
    {
      source_url: "https://facebook.com/kps/posts/777",
      selection_token: "application-proof-777",
    },
  ]);
});

test("makeup share choice keeps the original URL, selects one, stops checks and survives draft reload", async ({
  page,
}) => {
  await setup(page, true);
  await mockPosts(page);
  await page.route("**/api/reports/check-links", (route) =>
    route.fulfill({
      json: {
        data: [
          {
            url: share,
            status: "identified",
            reason_code: "SOCIAL_CHECK_PENDING",
          },
        ],
      },
    }),
  );
  await addWork(page);
  const evidence = page.locator('textarea[id^="evidence-"]');
  await evidence.fill(share);
  await page
    .getByRole("button", { name: "Chọn bài tương ứng", exact: true })
    .click();
  const dialog = page.getByRole("dialog", { name: "Chọn bài từ Facebook" });
  await dialog
    .getByRole("checkbox", { name: "Chọn: Bài mới nhất", exact: true })
    .check();
  await dialog
    .getByRole("checkbox", { name: "Chọn: Reel hôm trước", exact: true })
    .check();
  await expect(
    dialog.getByRole("checkbox", { name: "Chọn: Bài mới nhất", exact: true }),
  ).not.toBeChecked();
  await expect(
    dialog.getByText("Đã chọn 1/1 bài", { exact: true }),
  ).toBeVisible();
  await dialog.getByRole("button", { name: "Xác nhận đúng bài" }).click();
  await expect(evidence).toHaveValue(share);
  await expect(page.locator("[data-facebook-evidence-picker]")).toContainText(
    "nhân viên xác nhận",
  );
  await expect(page.locator("[data-evidence-link-checks]")).toHaveCount(0);
  await expect
    .poll(async () =>
      page.evaluate(() =>
        Object.keys(localStorage)
          .filter((key) => key.startsWith("kps:work-report:draft:"))
          .some((key) =>
            (localStorage.getItem(key) ?? "").includes("application-proof-777"),
          ),
      ),
    )
    .toBeTruthy();
  await page.reload();
  const restore = page.getByRole("button", {
    name: "Khôi phục bản nháp",
    exact: true,
  });
  await expect(restore).toBeVisible();
  await restore.click();
  await expect(page.locator("[data-facebook-evidence-picker]")).toContainText(
    "nhân viên xác nhận",
  );
  await evidence.fill("https://facebook.com/share/p/Changed/");
  await expect(
    page.locator("[data-facebook-evidence-picker]"),
  ).not.toContainText("nhân viên xác nhận");
});

test("mobile preview supports tap, pagination and focus return on cancel", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await setup(page, false);
  await mockPosts(page);
  await addWork(page);
  const opener = page.getByRole("button", {
    name: "Chọn bài từ Facebook",
    exact: true,
  });
  await opener.click();
  const dialog = page.getByRole("dialog", { name: "Chọn bài từ Facebook" });
  await dialog.getByRole("button", { name: "Xem trước: Bài mới nhất" }).click();
  await expect(
    dialog.getByRole("region", { name: "Xem trước bài" }),
  ).toBeInViewport();
  await dialog.getByRole("button", { name: "Tải thêm bài cũ hơn" }).click();
  await expect(dialog.getByRole("checkbox")).toHaveCount(3);
  await dialog.getByRole("button", { name: "Hủy", exact: true }).click();
  await expect(opener).toBeFocused();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBeTruthy();
});

for (const makeup of [false, true]) {
  test(`${makeup ? "makeup" : "daily"}: unassigned employee has no Facebook picker and can still use ordinary evidence`, async ({
    page,
  }) => {
    await setup(page, makeup, false);
    let catalogueRequests = 0;
    await page.route("**/api/reports/facebook-posts?**", async (route) => {
      catalogueRequests++;
      await route.fulfill({
        json: { data: [], pages: [], entry_id: null, next_cursor: null },
      });
    });
    await addWork(page);
    const evidence = page.locator('textarea[id^="evidence-"]');
    await evidence.fill("https://facebook.com/share/p/Test");
    await expect(page.locator("[data-facebook-evidence-picker]")).toHaveCount(
      0,
    );
    await expect(
      page.getByRole("button", { name: "Chọn bài từ Facebook", exact: true }),
    ).toHaveCount(0);
    await expect(
      page.getByRole("button", { name: "Chọn bài tương ứng", exact: true }),
    ).toHaveCount(0);
    await evidence.fill("https://drive.google.com/file/d/evidence/view");
    await expect(evidence).toHaveValue(
      "https://drive.google.com/file/d/evidence/view",
    );
    await page
      .getByRole("tab", { name: "Báo cáo chi tiết", exact: true })
      .click();
    await expect(page.locator("[data-facebook-evidence-picker]")).toHaveCount(
      0,
    );
    await expect(evidence).toHaveValue(
      "https://drive.google.com/file/d/evidence/view",
    );
    expect(catalogueRequests).toBe(0);
  });
}

test("assignment lookup failure hides picker controls without blocking the report editor", async ({
  page,
}) => {
  await setup(page, false, "error");
  await addWork(page);
  await expect(
    page.getByRole("button", { name: "Chọn bài từ Facebook", exact: true }),
  ).toHaveCount(0);
  await expect(page.locator('textarea[id^="quick-result-"]')).toHaveValue(
    "Đã đăng",
  );
});

test.describe("Facebook picker calendar", () => {
  test.use({ timezoneId: "America/Los_Angeles" });
  test("shared calendar shows Vietnamese dates, constrains the range and preserves ISO API dates across time zones", async ({
    page,
  }) => {
    await setup(page, false);
    await mockPosts(page);
    await addWork(page);
    await page
      .getByRole("button", { name: "Chọn bài từ Facebook", exact: true })
      .click();
    const picker = page.getByRole("dialog", {
      name: "Chọn bài từ Facebook",
      exact: true,
    });
    await expect(picker.getByRole("checkbox")).toHaveCount(2);
    await expect(picker.locator('input[type="date"]')).toHaveCount(0);
    const from = picker.getByRole("button", { name: /^Từ ngày:/ });
    const to = picker.getByRole("button", { name: /^Đến ngày:/ });
    await expect(from).toContainText("30/10/2026");
    await expect(to).toContainText("05/11/2026");
    await from.click();
    const fromCalendar = picker.getByRole("dialog", {
      name: "Từ ngày",
      exact: true,
    });
    await fromCalendar
      .getByRole("button", { name: "Tháng sau", exact: true })
      .click();
    await expect(
      fromCalendar.locator('[data-calendar-date="2026-11-06"]'),
    ).toBeDisabled();
    await fromCalendar.locator('[data-calendar-date="2026-11-01"]').click();
    await expect(from).toContainText("01/11/2026");
    await to.click();
    const toCalendar = picker.getByRole("dialog", {
      name: "Đến ngày",
      exact: true,
    });
    await expect(
      toCalendar.getByRole("button", { name: "Tháng trước", exact: true }),
    ).toBeDisabled();
    await toCalendar.locator('[data-calendar-date="2026-11-04"]').click();
    await expect(to).toContainText("04/11/2026");
    const request = page.waitForRequest((request) => {
      const url = new URL(request.url());
      return (
        url.pathname === "/api/reports/facebook-posts" &&
        url.searchParams.get("from_date") === "2026-11-01" &&
        url.searchParams.get("to_date") === "2026-11-04"
      );
    });
    await picker
      .getByRole("button", { name: "Áp dụng bộ lọc", exact: true })
      .click();
    await request;
    await from.click();
    await fromCalendar
      .locator('[data-calendar-date="2026-11-01"]')
      .press("Escape");
    await expect(fromCalendar).not.toBeVisible();
    await expect(picker).toBeVisible();
    await expect(from).toBeFocused();
    await from.click();
    await from.focus();
    await from.press("Escape");
    await expect(fromCalendar).not.toBeVisible();
    await expect(picker).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(picker).not.toBeVisible();
  });
});

for (const dark of [false, true]) {
  test(`${dark ? "dark mobile" : "light desktop"}: picker text, checkbox and confirmation use the KPS palette`, async ({
    page,
  }) => {
    if (dark) await page.setViewportSize({ width: 390, height: 844 });
    await setup(page, false);
    await mockPosts(page);
    await addWork(page);
    if (dark)
      await page.evaluate(() => document.documentElement.classList.add("dark"));
    await page
      .getByRole("button", { name: "Chọn bài từ Facebook", exact: true })
      .click();
    const picker = page.getByRole("dialog", {
      name: "Chọn bài từ Facebook",
      exact: true,
    });
    await expect(picker.getByRole("checkbox")).toHaveCount(2);
    const colors = await picker.evaluate((element, dark) => {
      const probe = document.createElement("span");
      element.append(probe);
      const resolve = (color: string) => {
        probe.style.color = color;
        return getComputedStyle(probe).color;
      };
      const values = {
        primary: resolve("var(--color-kps-primary)"),
        muted: resolve(
          "color-mix(in srgb, var(--color-kps-primary) 50%, white)",
        ),
        accent: resolve("var(--color-kps-primary-hover)"),
        link: resolve(
          dark
            ? "color-mix(in srgb, var(--color-kps-primary) 75%, white)"
            : "var(--color-kps-primary-hover)",
        ),
      };
      probe.remove();
      return values;
    }, dark);
    await expect(
      picker.getByRole("button", { name: "Xác nhận chọn 0 bài", exact: true }),
    ).toHaveCSS("background-color", colors.muted);
    await picker
      .getByRole("button", { name: "Xem trước: Bài mới nhất", exact: true })
      .click();
    await expect(
      picker.getByRole("link", { name: "Mở trên Facebook", exact: true }),
    ).toHaveCSS("color", colors.link);
    const check = picker.getByRole("checkbox", {
      name: "Chọn: Bài mới nhất",
      exact: true,
    });
    await expect(check).toHaveCSS("accent-color", colors.accent);
    await check.check();
    await expect(
      picker.getByRole("button", { name: "Xác nhận chọn 1 bài", exact: true }),
    ).toHaveCSS("background-color", colors.primary);
    await picker.getByRole("button", { name: /^Từ ngày:/ }).click();
    await expect(
      picker
        .getByRole("dialog", { name: "Từ ngày", exact: true })
        .locator('button[aria-pressed="true"]'),
    ).toHaveCSS("background-color", colors.primary);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBeTruthy();
  });
}

test.afterEach(async ({ page }, testInfo) => {
  if (testInfo.status !== testInfo.expectedStatus) {
    console.log(
      "Report picker failure context:",
      await page.locator("body").innerText(),
    );
  }
});
