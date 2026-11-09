import { expect, test, type Page } from "@playwright/test";
import type { FacebookAnalyticsPost } from "../src/types/facebookAnalytics";

async function setup(
  page: Page,
  options: {
    empty?: boolean;
    delayed?: boolean;
    syncCycle?: boolean;
    sampling?: "waiting" | "overdue" | "off";
    sources?: boolean;
    milestones?: boolean;
  } = {},
) {
  await page.clock.setFixedTime(new Date("2026-11-07T03:00:00Z"));
  const requests: string[] = [];
  const posts: FacebookAnalyticsPost[] = [
    {
      id: 1,
      external_id: "100_555",
      url: "https://facebook.com/reel/555",
      message: "Routine tối giản cho làn da",
      picture: null,
      type: "reel",
      topic: "education",
      published_at: "2026-11-05T03:00:00Z",
      report_ids: [12],
      definition: "v27:like",
      sample_count: 2,
      observed_from: "2026-11-05 03:00:00",
      observed_to: "2026-11-06 03:00:00",
      current: { likes: 20, comments: 3, media_views: null },
      observed_delta: { likes: 10, comments: 2, media_views: null },
      trend: [
        {
          date: "2026-11-06",
          likes: 10,
          comments: 2,
          media_views: null,
          intervals: {
            likes: {
              from: "2026-11-05T03:00:00Z",
              to: "2026-11-06T03:00:00Z",
              pairs: 1,
              max_gap_seconds: 86400,
            },
          },
        },
      ],
    },
    {
      id: 2,
      external_id: "100_777",
      url: "https://facebook.com/kps/posts/777",
      message: "Giới thiệu sản phẩm mặt nạ",
      picture: null,
      type: "photo",
      topic: null,
      published_at: "2026-11-04T03:00:00Z",
      report_ids: [],
      definition: "v27:like",
      sample_count: 1,
      observed_from: "2026-11-05 03:00:00",
      observed_to: "2026-11-05 03:00:00",
      current: { likes: 0, comments: 0, media_views: null },
      observed_delta: { likes: null, comments: null, media_views: null },
      trend: [],
    },
  ];
  if (options.sources || options.milestones)
    posts[0].published_at = "2026-10-30T03:00:00Z";
  if (options.sources) {
    const interval = posts[0].trend[0].intervals?.likes;
    if (!interval) throw new Error("Missing source interval fixture");
    interval.from = "2026-11-04T03:00:00Z";
    interval.max_gap_seconds = 172800;
    posts.push({
      ...posts[1],
      id: 3,
      message: "Bài có mức giảm",
      trend: [
        { date: "2026-11-06", likes: -10, comments: null, media_views: null },
      ],
    });
    posts.push({
      ...posts[1],
      id: 4,
      message: "Bài không thay đổi",
      trend: [
        { date: "2026-11-06", likes: 0, comments: null, media_views: null },
      ],
    });
  }
  let analysis = false;
  let sync = false;
  let syncReads = 0;
  await page.route("**/api/**", async (route) => {
    const req = route.request(),
      url = new URL(req.url()),
      path = url.pathname;
    if (path.includes("/social-analytics/"))
      requests.push(req.method() + " " + url.pathname + url.search);
    if (path === "/api/auth/me")
      return route.fulfill({
        json: {
          id: 10001,
          name: "Test Admin",
          email: "test@example.test",
          role: { name: "Admin" },
          department: null,
          organization: { id: 1, name: "KPS" },
          location: null,
        },
      });
    if (path === "/api/navigation")
      return route.fulfill({
        json: { items: ["dashboard", "social.analytics", "integrations"] },
      });
    if (path.endsWith("/facebook/pages"))
      return route.fulfill({
        json: {
          data: options.empty
            ? []
            : [
                {
                  id: 1,
                  name: "KPS Page",
                  responsible_name: "Nhân viên mẫu",
                  status: "connected",
                },
                {
                  id: 2,
                  name: "Page thứ hai",
                  responsible_name: "Nhân viên hai",
                  status: "connected",
                },
              ],
          can_manage: true,
        },
      });
    if (path.endsWith("/sync")) {
      sync = true;
      return route.fulfill({ status: 202, json: { message: "Queued" } });
    }
    if (path.endsWith("/analyses")) {
      analysis = true;
      return route.fulfill({
        status: 202,
        json: { data: { id: "a", status: "queued" } },
      });
    }
    if (path.endsWith("/topic")) {
      posts[0].topic = req.postDataJSON().topic;
      return route.fulfill({ status: 204 });
    }
    if (/\/facebook\/\d+$/.test(path)) {
      if (options.delayed && path.endsWith("/1")) {
        await new Promise((resolve) => setTimeout(resolve, 650));
        requests.push("DELAY_RELEASED");
      }
      const isSecond = path.endsWith("/2");
      if (options.syncCycle && sync) syncReads++;
      const cycleDone = syncReads >= 2;
      return route.fulfill({
        json: {
          data: {
            entry_id: isSecond ? 2 : 1,
            page_name: isSecond ? "Page thứ hai" : "KPS Page",
            from_date: url.searchParams.get("from_date"),
            to_date: url.searchParams.get("to_date"),
            connection_status: "connected",
            principal_available: true,
            can_manage: true,
            ai_enabled: true,
            sync: {
              status:
                options.syncCycle && sync && !cycleDone ? "queued" : "idle",
              interrupted: false,
              sampled_at: sync
                ? "2026-11-07T03:00:00Z"
                : "2026-11-06T03:00:00Z",
              catalogued_at: "2026-11-06T03:00:00Z",
              catalogue_complete: !!(options.syncCycle && sync),
              catalogue_from: "2026-08-10",
              catalogue_to: "2026-11-07",
              error_code: null,
              pending_samples: 1,
              catalogue_posts: 2,
              progress:
                options.syncCycle && sync
                  ? {
                      phase: cycleDone ? "completed" : "samples",
                      catalogue_posts: 12,
                      ready_posts: cycleDone ? 12 : 10,
                      pending_posts: cycleDone ? 0 : 2,
                      batches_completed: cycleDone ? 2 : 1,
                      from_date: "2026-08-10",
                      to_date: "2026-11-07",
                      started_at: "2026-11-07T03:00:00Z",
                      finished_at: cycleDone ? "2026-11-07T03:00:20Z" : null,
                    }
                  : null,
            },
            sampling: options.sampling
              ? {
                  enabled: options.sampling !== "off",
                  status: options.sampling,
                  total_posts: 12,
                  due_posts: options.sampling === "overdue" ? 3 : 0,
                  overdue_posts: options.sampling === "overdue" ? 2 : 0,
                  never_sampled_posts: 1,
                  late_posts: 2,
                  last_checked_at: "2026-11-07T02:05:00Z",
                  last_sampled_at: "2026-11-07T02:10:00Z",
                  next_check_at: "2026-11-07T14:00:00Z",
                }
              : undefined,
            summary: {
              published: 2,
              previous_published: null,
              observed_posts: 2,
              growth: {
                likes: { value: 10, posts: 1, comparison: null },
                comments: { value: 2, posts: 1, comparison: null },
                media_views: { value: null, posts: 0, comparison: null },
              },
            },
            posts: isSecond ? [] : posts,
            analysis: analysis
              ? {
                  id: "a",
                  status: "completed",
                  error_code: null,
                  result: {
                    insights: [
                      {
                        kind: "highlight",
                        text: "Bài Reel có tăng bình luận trong khoảng quan sát.",
                        post_ids: [1],
                      },
                      {
                        kind: "limit",
                        text: "Mẫu dữ liệu còn ít.",
                        post_ids: [1],
                      },
                    ],
                  },
                  created_at: "2026-11-07T03:00:00Z",
                  finished_at: "2026-11-07T03:01:00Z",
                  stale: false,
                  interrupted: false,
                  selected_posts: 1,
                  candidate_posts: 1,
                }
              : null,
          },
        },
      });
    }
    return route.fulfill({
      json: { data: [], items: [], unread_count: 0, meta: { total: 0 } },
    });
  });
  await page.goto("/social-analytics/facebook");
  await expect(
    page.getByRole("heading", {
      name: "Phân tích Facebook",
      exact: true,
      level: 1,
    }),
  ).toBeVisible();
  return requests;
}

test("Facebook analytics uses real API state, shared calendar, filters, post evidence and scoped topic edits", async ({
  page,
}) => {
  const requests = await setup(page);
  await expect(
    page.getByText("Danh mục thu thập 90 ngày chưa tải hết.", { exact: false }),
  ).toBeVisible();
  await expect(page.locator('input[type="date"]')).toHaveCount(0);
  await expect(page.getByRole("button", { name: /^(7|28) ngày$/ })).toHaveCount(
    0,
  );
  await expect
    .poll(() =>
      requests.some((r) =>
        r.includes("from_date=2026-11-01&to_date=2026-11-07"),
      ),
    )
    .toBeTruthy();
  const initialReads = requests.filter((r) =>
    r.startsWith("GET /api/social-analytics/facebook/1?"),
  ).length;
  await page
    .getByRole("button", { name: "Từ ngày: 01/11/2026", exact: true })
    .click();
  const fromCalendar = page.getByRole("dialog", {
    name: "Từ ngày",
    exact: true,
  });
  await fromCalendar
    .getByRole("combobox", { name: "Chọn tháng", exact: true })
    .selectOption("9");
  await fromCalendar.locator('[data-calendar-date="2026-10-20"]').click();
  await page
    .getByRole("button", { name: "Đến ngày: 07/11/2026", exact: true })
    .click();
  await page
    .getByRole("dialog", { name: "Đến ngày", exact: true })
    .locator('[data-calendar-date="2026-11-06"]')
    .click();
  expect(
    requests.filter((r) =>
      r.startsWith("GET /api/social-analytics/facebook/1?"),
    ).length,
  ).toBe(initialReads);
  await page.getByRole("button", { name: "Áp dụng", exact: true }).click();
  await expect
    .poll(() =>
      requests.some((r) =>
        r.includes("from_date=2026-10-20&to_date=2026-11-06"),
      ),
    )
    .toBeTruthy();
  await page.getByRole("tab", { name: "Hiệu quả bài", exact: true }).click();
  await page
    .getByRole("textbox", { name: "Tìm trong nội dung bài…" })
    .fill("không tồn tại");
  await expect(
    page.getByText("Không có bài phù hợp.", { exact: false }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Xóa bộ lọc", exact: true }).click();
  await page
    .getByRole("combobox", { name: "Loại bài", exact: true })
    .selectOption("reel");
  await expect(
    page.getByRole("button", { name: /Giới thiệu sản phẩm/ }),
  ).toHaveCount(0);
  await page.getByRole("button", { name: /Routine tối giản/ }).click();
  const dialog = page.getByRole("dialog", {
    name: "Chi tiết nội dung Facebook",
  });
  await expect(dialog).toBeVisible();
  await expect(
    dialog.getByRole("link", { name: "Báo cáo #12" }),
  ).toHaveAttribute("href", "/reports/12");
  await expect(
    dialog.getByRole("link", { name: /Mở bài gốc/ }),
  ).toHaveAttribute("rel", "noopener noreferrer");
  await dialog
    .getByRole("combobox", { name: "Nhãn chủ đề" })
    .selectOption("product");
  await expect
    .poll(() => requests.some((r) => r.startsWith("PATCH ")))
    .toBeTruthy();
  await dialog.getByRole("button", { name: "Đóng", exact: true }).click();
  await expect(dialog).not.toBeVisible();
});

test("mobile analytics keeps navigation usable, missing metrics and AI evidence honest", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  const requests = await setup(page);
  await page.getByRole("button", { name: "Đồng bộ Page", exact: true }).click();
  await expect(
    page.getByRole("status").filter({ hasText: "Đã xếp hàng đồng bộ" }),
  ).toBeVisible();
  await page
    .getByRole("tab", { name: "Đánh giá & hành động", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Tạo / cập nhật nhận xét AI", exact: true })
    .click();
  await expect(
    page.getByText("Bài Reel có tăng bình luận trong khoảng quan sát."),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Bài #1 · Xem căn cứ", exact: true })
    .first()
    .click();
  const dialog = page.getByRole("dialog", {
    name: "Chi tiết nội dung Facebook",
  });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText("—", { exact: true }).first()).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(dialog).not.toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBeTruthy();
  expect(requests.some((r) => r.includes("/analyses"))).toBeTruthy();
  expect(errors).toEqual([]);
});

test("employees without assigned pages see an empty state and never request page metrics", async ({
  page,
}) => {
  const requests = await setup(page, { empty: true });
  await expect(
    page.getByText("Bạn chưa có Page Facebook được giao phụ trách", {
      exact: false,
    }),
  ).toBeVisible();
  expect(requests.filter((r) => /\/facebook\/\d/.test(r))).toEqual([]);
});

test("changing pages cannot display a delayed response from the previous page", async ({
  page,
}) => {
  const requests = await setup(page, { delayed: true });
  await expect
    .poll(() =>
      requests.some((r) =>
        r.startsWith("GET /api/social-analytics/facebook/1?"),
      ),
    )
    .toBeTruthy();
  await page
    .getByRole("combobox", { name: "Page đang phân tích" })
    .selectOption("2");
  await expect(page.getByText("Người phụ trách: Nhân viên hai")).toBeVisible();
  await expect.poll(() => requests.includes("DELAY_RELEASED")).toBeTruthy();
  await page.getByRole("tab", { name: "Hiệu quả bài", exact: true }).click();
  await expect(
    page.getByText("Không có bài phù hợp.", { exact: false }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: /Routine tối giản/ }),
  ).toHaveCount(0);
});

test("analytics layout keeps one title, translated sidebar and aligned date controls", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await setup(page);
  await expect(
    page.getByRole("heading", { name: "Phân tích Facebook", exact: true }),
  ).toHaveCount(1);
  const navLink = page.locator('aside a[href="/social-analytics/facebook"]');
  await expect(navLink).toHaveText("Phân tích MXH");
  await expect(navLink).toHaveAttribute("aria-current", "page");
  await expect(
    page.getByText("sidebar.items.socialAnalytics", { exact: true }),
  ).toHaveCount(0);
  const from = page.getByRole("button", { name: /^Từ ngày:/ });
  const to = page.getByRole("button", { name: /^Đến ngày:/ });
  const selector = page.getByRole("combobox", { name: "Page đang phân tích" });
  for (const control of [from, to]) await expect(control).toBeVisible();
  const boxes = await Promise.all(
    [selector, from, to].map((control) => control.boundingBox()),
  );
  expect(boxes.every(Boolean)).toBeTruthy();
  expect(Math.abs(boxes[0]!.y - boxes[1]!.y)).toBeLessThan(2);
  expect(Math.abs(boxes[1]!.y - boxes[2]!.y)).toBeLessThan(2);
  await expect(page.getByText("Từ ngày", { exact: true })).toBeVisible();
  await expect(page.getByText("Đến ngày", { exact: true })).toBeVisible();
  await from.click();
  await expect(
    page.getByRole("dialog", { name: "Từ ngày", exact: true }),
  ).toBeVisible();
  await page.keyboard.press("Escape");
  const toggle = page.getByRole("button", {
    name: "Đóng hoặc mở thanh điều hướng",
  });
  await toggle.click();
  await expect(navLink).toHaveAttribute("title", "Phân tích MXH");
  await toggle.click();
  await expect(navLink).toHaveText("Phân tích MXH");
});

test("analytics stays within the app shell on desktop, tablet and phone across all tabs", async ({
  page,
}) => {
  await setup(page);
  for (const width of [1440, 1280, 1024, 768, 390]) {
    await page.setViewportSize({ width, height: 900 });
    for (const name of [
      "Diễn biến Page",
      "Hiệu quả bài",
      "Đánh giá & hành động",
    ]) {
      await page.getByRole("tab", { name, exact: true }).click();
      await expect
        .poll(
          async () =>
            page.evaluate(() => ({
              document:
                document.documentElement.scrollWidth <= window.innerWidth,
              main:
                document.querySelector("main")!.getBoundingClientRect().right <=
                window.innerWidth + 1,
            })),
          {
            message: `${name} at ${width}px must not push the app outside the viewport`,
          },
        )
        .toEqual({ document: true, main: true });
    }
  }
  await page
    .getByRole("button", { name: "Đóng hoặc mở thanh điều hướng" })
    .click();
  await expect(
    page.getByRole("link", { name: "Phân tích MXH", exact: true }),
  ).toBeVisible();
});

test("one sync request follows progress until completion and distinguishes the selected week from the 90-day catalogue", async ({
  page,
}) => {
  const requests = await setup(page, { syncCycle: true });
  await expect(
    page.getByText("Kỳ đang chọn: 2 bài đăng đã tải", { exact: false }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Đồng bộ Page", exact: true }).click();
  await expect(
    page.getByText("Đã có mẫu 10/12 bài đã tải; còn 2 bài chờ lấy mẫu.", {
      exact: false,
    }),
  ).toBeVisible();
  await expect(
    page.getByText("Phạm vi thu thập: 10/08/2026–07/11/2026 (90 ngày)", {
      exact: false,
    }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Đang đồng bộ…", exact: true }),
  ).toBeDisabled();
  await expect(
    page.getByText("Đã hoàn tất đợt thu thập 90 ngày", { exact: true }),
  ).toBeVisible({ timeout: 15000 });
  await page
    .locator("summary")
    .filter({ hasText: "Đã hoàn tất đợt thu thập 90 ngày" })
    .click();
  await expect(
    page.getByRole("progressbar", {
      name: "Tiến độ lấy mẫu danh mục Facebook",
    }),
  ).toHaveAttribute("aria-valuenow", "12");
  await expect(
    page.getByRole("button", { name: "Đồng bộ Page", exact: true }),
  ).toBeEnabled();
  await expect(
    page.getByText("Đã xếp hàng đồng bộ.", { exact: false }),
  ).toHaveCount(0);
  expect(
    requests.filter((r) => r.startsWith("POST ") && r.endsWith("/sync")),
  ).toHaveLength(1);
});

test("automatic sampling shows real timestamps, cadence and data health", async ({
  page,
}) => {
  await setup(page, { sampling: "waiting" });
  const details = page
    .locator("details")
    .filter({ hasText: "Lịch lấy mẫu tự động" });
  await expect(details.locator("summary")).toContainText(
    "Đang chờ lịch tiếp theo",
  );
  await details.locator("summary").click();
  await expect(
    details.getByText("Bộ điều phối kiểm tra gần nhất", { exact: true }),
  ).toBeVisible();
  await expect(details.getByText("09:10", { exact: false })).toBeVisible();
  await expect(
    details
      .getByText("Lần kiểm tra dự kiến tiếp theo", { exact: true })
      .locator("..")
      .getByText("21:00", { exact: false }),
  ).toBeVisible();
  await expect(details.getByRole("status")).toContainText(
    "12 bài; 0 bài đến hạn",
  );
  await expect(details).toContainText(
    "Không tái tạo số liệu cho các thời điểm đã bỏ lỡ",
  );
});

test("overdue sampling opens its explanation and fits a phone viewport", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await setup(page, { sampling: "overdue" });
  const details = page
    .locator("details")
    .filter({ hasText: "Lịch lấy mẫu tự động" });
  await expect(details).toHaveAttribute("open", "");
  await expect(details.getByRole("status")).toContainText(
    "3 bài đến hạn, trong đó 2 bài quá hạn",
  );
  await expect(details).toContainText("Kiểm tra worker, hàng đợi");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBeTruthy();
});

test("disabled automatic sampling still permits manual sync", async ({
  page,
}) => {
  const requests = await setup(page, { sampling: "off" });
  const details = page
    .locator("details")
    .filter({ hasText: "Lịch lấy mẫu tự động" });
  await details.locator("summary").click();
  await expect(details).toContainText("Bạn vẫn có thể đồng bộ thủ công");
  await page.getByRole("button", { name: "Đồng bộ Page", exact: true }).click();
  await expect
    .poll(() =>
      requests.some((r) => r === "POST /api/social-analytics/facebook/1/sync"),
    )
    .toBeTruthy();
});

test("Page chart exposes contributors, zero net change and extended intervals without claiming daily activity", async ({
  page,
}) => {
  await setup(page, { sources: true });
  await page
    .getByRole("combobox", { name: "Ngày cần xem bài đóng góp" })
    .selectOption("2026-11-06");
  const sources = page.getByRole("region", {
    name: "Bài đóng góp vào thay đổi trong ngày",
  });
  await expect(sources).toContainText("3 bài có cặp mẫu");
  await expect(sources).toContainText("cách nhau hơn 24 giờ");
  await expect(sources).toContainText("Bài có mức giảm");
  await expect(sources).toContainText("-10");
  await expect(sources).toContainText("Bài không thay đổi");
  await sources
    .getByRole("button", { name: "Routine tối giản cho làn da", exact: true })
    .click();
  await expect(
    page.getByRole("dialog", { name: "Chi tiết nội dung Facebook" }),
  ).toBeVisible();
  await page.keyboard.press("Escape");
  await page
    .getByRole("combobox", { name: "Ngày cần xem bài đóng góp" })
    .selectOption("2026-11-05");
  await expect(sources).toContainText("Không thể coi là tăng 0");
});
test("milestone UI distinguishes waiting age and unclassified historical milestones without fake D7 scores", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await setup(page, { milestones: true });
  await page.getByRole("tab", { name: "Hiệu quả bài", exact: true }).click();
  await page
    .getByRole("button", { name: "Theo mốc D1 / D3 / D7", exact: true })
    .click();
  await expect(
    page.getByText("Các mốc D1/D3/D7 chưa được xác nhận", { exact: false }),
  ).toBeVisible();
  await expect(
    page
      .getByText("Mốc D7 chưa xác nhận", { exact: true })
      .filter({ visible: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Đăng trong kỳ", exact: true })
    .click();
  await expect(
    page
      .getByText("Chờ đến", { exact: false })
      .filter({ visible: true })
      .first(),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBeTruthy();
  await page
    .getByRole("tab", { name: "Đánh giá & hành động", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Báo cáo tháng", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Đánh giá tháng theo hai góc nhìn" }),
  ).toBeVisible();
  await expect(
    page.getByText("Hiện chưa có kết quả chấm điểm tháng", { exact: false }),
  ).toBeVisible();
});
