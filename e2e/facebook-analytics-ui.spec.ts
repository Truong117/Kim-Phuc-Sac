import { expect, test, type Page } from "@playwright/test";
import type {
  FacebookAnalyticsPost,
  FacebookMonthlyReportData,
} from "../src/types/facebookAnalytics";

async function setup(
  page: Page,
  options: {
    empty?: boolean;
    delayed?: boolean;
    syncCycle?: boolean;
    sampling?: "waiting" | "overdue" | "off";
    sources?: boolean;
    milestones?: boolean;
    age?: boolean;
    review?: "ready" | "blocked" | "conflict";
    contributionPages?: boolean;
    monthly?: boolean;
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
  if (options.sources || options.milestones || options.age || options.review)
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
  if (options.contributionPages)
    for (let i = 1; i <= 7; i++)
      posts.push({
        ...posts[0],
        id: 100 + i,
        message:
          `Tiêu đề ngắn ${i}\n` +
          "Nội dung dài cần giữ trong chi tiết. ".repeat(30),
        trend: [
          { date: "2026-11-06", likes: i, comments: null, media_views: null },
        ],
      });
  const agePoints = Object.fromEntries(
    ([1, 3, 7] as const).map((day) => {
      const due = new Date(
        new Date(posts[0].published_at).getTime() + day * 86400000,
      ).toISOString();
      return [
        `D${day}`,
        {
          day,
          due_at: due,
          status: day === 3 ? "missing" : "on_time",
          sampled_at: day === 3 ? null : due,
          delay_seconds: day === 3 ? null : 0,
          definition: "v27:like",
          source: "milestone",
          metrics: {
            likes: day === 1 ? 0 : day === 3 ? null : 20,
            comments: 2,
            media_views: null,
          },
        },
      ];
    }),
  ) as NonNullable<FacebookAnalyticsPost["milestones"]>;
  if (options.age || options.review)
    Object.assign(posts[0], {
      objective: "engagement",
      distribution: "organic",
      milestones: agePoints,
    });
  let reviewPlan = {
    version: 0,
    expected_posts: null as number | null,
    actions: [] as {
      id: string;
      title: string;
      expected_result: string;
      metric: string | null;
      due_date: string;
      review_date: string;
      status: string;
      result_note: string;
    }[],
    updated_at: null as string | null,
    source_changed: false,
  };
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
    if (path.endsWith("/review") && options.review) {
      if (req.method() === "PUT") {
        if (options.review === "conflict") {
          reviewPlan = { ...reviewPlan, version: 1, expected_posts: 15 };
          return route.fulfill({
            status: 409,
            json: { message: "Kế hoạch đã đổi" },
          });
        }
        const values = req.postDataJSON();
        reviewPlan = {
          ...reviewPlan,
          version: reviewPlan.version + 1,
          expected_posts: values.expected_posts,
          actions: values.actions,
          updated_at: "2026-11-07T03:00:00Z",
        };
      }
      const blocked = options.review === "blocked",
        selected =
          url.searchParams.get("metric") ??
          req.postDataJSON()?.metric ??
          "likes";
      return route.fulfill({
        json: {
          data: {
            entry_id: 1,
            entry_version: 1,
            from_date: "2026-11-01",
            to_date: "2026-11-07",
            metric: selected,
            previous_from: "2026-10-25",
            previous_to: "2026-10-31",
            as_of: "2026-11-07T03:00:00Z",
            source_hash: "a".repeat(64),
            catalogue_covered: !blocked,
            publication: { count: 12, covered: !blocked },
            quality: {
              total: 5,
              statuses: { on_time: 5, missing: 0, late: 0, due: 0, waiting: 0 },
            },
            policy: {
              version: 1,
              window_minutes: 30,
              minimum_posts: 5,
              minimum_coverage: 0.8,
            },
            owner: { id: 10001, name: "Người phụ trách kênh" },
            plan: reviewPlan,
            groups: [
              {
                id: "g1",
                identity: {
                  type: "reel",
                  topic: "education",
                  objective: "engagement",
                  distribution: "organic",
                  definition: "v27:like",
                },
                current: { median: 20, posts: 5, coverage: 1 },
                previous: { median: 10, posts: 5, coverage: 1 },
                current_total: 5,
                previous_total: 5,
                comparison: {
                  reason: blocked ? "catalogue_incomplete" : null,
                  difference: blocked ? null : 10,
                  percent: blocked ? null : 100,
                },
                status: blocked ? "insufficient" : "improved",
                evidence: blocked
                  ? []
                  : [
                      {
                        kind: "typical",
                        id: 1,
                        message: posts[0].message,
                        published_at: posts[0].published_at,
                        point: agePoints.D7,
                      },
                    ],
              },
            ],
          },
        },
      });
    }
    if (path.endsWith("/milestones") && options.age) {
      const statistic = {
        posts: 5,
        waiting: 0,
        metrics: {
          likes: { median: 20, posts: 5, coverage: 1 },
          comments: { median: 2, posts: 5, coverage: 1 },
          media_views: { median: null, posts: 0, coverage: 0 },
        },
      };
      return route.fulfill({
        json: {
          data: {
            basis: url.searchParams.get("basis"),
            from_date: url.searchParams.get("from_date"),
            to_date: url.searchParams.get("to_date"),
            previous_from: "2026-10-25",
            previous_to: "2026-10-31",
            as_of: "2026-11-07T03:00:00Z",
            catalogue_covered: true,
            policy: {
              version: 1,
              window_minutes: 30,
              minimum_posts: 5,
              minimum_coverage: 0.8,
            },
            posts: [
              { ...posts[0] },
              {
                ...posts[0],
                id: 99,
                message: "Bài D7 ngoài danh sách quan sát",
                milestones: {
                  ...agePoints,
                  D7: {
                    ...agePoints.D7,
                    status: "late",
                    delay_seconds: 2400,
                    sampled_at: "2026-11-06T03:40:00Z",
                  },
                },
              },
            ],
            groups: [
              {
                identity: {
                  type: "reel",
                  topic: "education",
                  objective: posts[0].objective,
                  distribution: posts[0].distribution,
                  definition: "v27:like",
                },
                current: statistic,
                previous: {
                  ...statistic,
                  metrics: {
                    ...statistic.metrics,
                    likes: { median: 10, posts: 5, coverage: 1 },
                  },
                },
                comparison: {
                  likes: { percent: 100, difference: 10, reason: null },
                  comments: {
                    percent: null,
                    difference: 0,
                    reason: "zero_baseline",
                  },
                  media_views: {
                    percent: null,
                    difference: null,
                    reason: "small_sample",
                  },
                },
              },
            ],
          },
        },
      });
    }
    if (/\/posts\/99$/.test(path) && options.age)
      return route.fulfill({
        json: {
          data: {
            ...posts[0],
            id: 99,
            message: "Bài D7 ngoài danh sách quan sát",
            milestones: agePoints,
          },
        },
      });
    if (path.endsWith("/classification") && options.age) {
      Object.assign(posts[0], req.postDataJSON());
      return route.fulfill({
        json: {
          data: {
            objective: posts[0].objective,
            distribution: posts[0].distribution,
          },
        },
      });
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
            milestones_enabled: !!(options.age || options.review),
            reviews_enabled: !!options.review,
            monthly_reports_enabled: !!options.monthly,
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

test("real age view shows actual windows, missing and late data, grouped medians and scoped classification", async ({
  page,
}) => {
  const requests = await setup(page, { age: true });
  await page.getByRole("tab", { name: "Hiệu quả bài", exact: true }).click();
  await page
    .getByRole("button", { name: "Theo mốc D1 / D3 / D7", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Trung vị D7 theo nhóm tương đồng" }),
  ).toBeVisible();
  await expect(
    page.getByText("Chênh lệch 10 · 100% so với kỳ trước"),
  ).toBeVisible();
  await expect(
    page.getByText("Lấy trễ, loại khỏi so sánh").filter({ visible: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Bỏ lỡ, không có mẫu").filter({ visible: true }).first(),
  ).toBeVisible();
  await page
    .getByRole("button", {
      name: "Bài D7 ngoài danh sách quan sát",
      exact: true,
    })
    .filter({ visible: true })
    .click();
  const detail = page.getByRole("dialog", {
    name: "Chi tiết nội dung Facebook",
  });
  await expect(detail).toBeVisible();
  await detail
    .getByRole("combobox", { name: "Mục tiêu nội dung", exact: true })
    .selectOption("traffic");
  await expect
    .poll(() =>
      requests.some(
        (r) =>
          r ===
          "PATCH /api/social-analytics/facebook/1/posts/99/classification",
      ),
    )
    .toBeTruthy();
  await expect(
    detail.getByRole("combobox", { name: "Mục tiêu nội dung", exact: true }),
  ).toHaveValue("traffic");
  await detail.getByRole("button", { name: "Đóng", exact: true }).click();
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(
    page.getByText("Lấy trễ, loại khỏi so sánh").filter({ visible: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBeTruthy();
});
test("age requests use cohort basis and filters without triggering provider sync", async ({
  page,
}) => {
  const requests = await setup(page, { age: true });
  await page.getByRole("tab", { name: "Hiệu quả bài", exact: true }).click();
  await page
    .getByRole("button", { name: "Theo mốc D1 / D3 / D7", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Trung vị D7 theo nhóm tương đồng" }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Đăng trong kỳ", exact: true })
    .click();
  await expect
    .poll(() =>
      requests.some(
        (r) => r.includes("/milestones?") && r.includes("basis=published"),
      ),
    )
    .toBeTruthy();
  await page
    .getByRole("combobox", { name: "Chỉ số", exact: true })
    .selectOption("media_views");
  await expect(
    page
      .getByText("Chưa đủ số bài có số liệu cho cả hai kỳ", { exact: false })
      .filter({ visible: true }),
  ).toBeVisible();
  expect(
    requests.some((r) => r.startsWith("POST") || r.includes("/analyses")),
  ).toBeFalsy();
});

test("late age response cannot replace a newer publication cohort", async ({
  page,
}) => {
  await setup(page, { age: true });
  await page.route("**/facebook/1/milestones?**", async (route) => {
    const url = new URL(route.request().url());
    const old = url.searchParams.get("basis") === "matured";
    if (old) await new Promise((resolve) => setTimeout(resolve, 500));
    await route.fulfill({
      json: {
        data: {
          basis: old ? "matured" : "published",
          from_date: "2026-11-01",
          to_date: "2026-11-07",
          previous_from: "2026-10-25",
          previous_to: "2026-10-31",
          as_of: "2026-11-07T03:00:00Z",
          catalogue_covered: !old,
          policy: {
            version: 1,
            window_minutes: 30,
            minimum_posts: 5,
            minimum_coverage: 0.8,
          },
          posts: [],
          groups: [],
        },
      },
    });
  });
  await page.getByRole("tab", { name: "Hiệu quả bài", exact: true }).click();
  await page
    .getByRole("button", { name: "Theo mốc D1 / D3 / D7", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Đăng trong kỳ", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Trung vị D7 theo nhóm tương đồng" }),
  ).toBeVisible();
  await page.waitForTimeout(650);
  await expect(
    page.getByText(
      "Danh mục chưa đủ phạm vi cả hai kỳ; chưa kết luận so sánh.",
      { exact: true },
    ),
  ).toHaveCount(0);
});

test("chart contributors paginate by five and keep full captions only in detail", async ({
  page,
}) => {
  await setup(page, { contributionPages: true });
  const sources = page.getByRole("region", {
    name: "Bài đóng góp vào thay đổi trong ngày",
  });
  await expect(sources.getByRole("article")).toHaveCount(5);
  await expect(sources).toContainText("8 bài có cặp mẫu");
  await expect(sources).not.toContainText("Nội dung dài cần giữ");
  await sources
    .getByRole("button", { name: "Tiêu đề ngắn 7", exact: true })
    .click();
  await expect(
    page.getByRole("dialog", { name: "Chi tiết nội dung Facebook" }),
  ).toContainText("Nội dung dài cần giữ");
  await page.keyboard.press("Escape");
  await sources.getByRole("button", { name: "Sau", exact: true }).click();
  await expect(sources.getByRole("article")).toHaveCount(3);
  await expect(sources).toContainText("Trang 2/2");
});

test("period review cites qualified D7 evidence and explicitly saves a shared action plan", async ({
  page,
}) => {
  const requests = await setup(page, { review: "ready" });
  await page
    .getByRole("tab", { name: "Đánh giá & hành động", exact: true })
    .click();
  await expect(
    page.getByRole("heading", {
      name: "Đánh giá theo kỳ và việc cần làm tiếp theo",
    }),
  ).toBeVisible();
  await expect(
    page.getByText("Trung vị D7 tăng trong nhóm này", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Bài gần trung vị · 20", { exact: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Thêm hành động", exact: true })
    .click();
  await page
    .getByRole("textbox", { name: "Việc cần làm", exact: true })
    .fill("Thử một cách mở đầu mới");
  await page
    .getByRole("textbox", {
      name: "Kết quả mong muốn / giả thuyết cần kiểm chứng",
      exact: true,
    })
    .fill("Đối chiếu LIKE D7 cùng loại và mục tiêu sau 7 ngày");
  await page
    .getByRole("spinbutton", { name: "Số bài dự kiến của kỳ đang xem" })
    .fill("14");
  await page.getByRole("button", { name: "Lưu kế hoạch", exact: true }).click();
  await expect(
    page.getByText("Đã lưu kế hoạch của kỳ và kênh này.", { exact: true }),
  ).toBeVisible();
  expect(
    requests.filter((r) => r.startsWith("PUT ") && r.endsWith("/review")),
  ).toHaveLength(1);
  expect(requests.some((r) => r.includes("/analyses"))).toBeFalsy();
  await page.reload();
  await page
    .getByRole("tab", { name: "Đánh giá & hành động", exact: true })
    .click();
  await expect(
    page.getByRole("textbox", { name: "Việc cần làm", exact: true }),
  ).toHaveValue("Thử một cách mở đầu mới");
});

test("review conflict keeps edits and does not overwrite another editor", async ({
  page,
}) => {
  await setup(page, { review: "conflict" });
  await page
    .getByRole("tab", { name: "Đánh giá & hành động", exact: true })
    .click();
  const target = page.getByRole("spinbutton", {
    name: "Số bài dự kiến của kỳ đang xem",
  });
  await target.fill("12");
  await page.getByRole("button", { name: "Lưu kế hoạch", exact: true }).click();
  await expect(
    page.getByText("Kênh, dữ liệu hoặc kế hoạch đã thay đổi.", {
      exact: false,
    }),
  ).toBeVisible();
  await expect(target).toHaveValue("12");
  await page
    .getByRole("button", { name: "Tải lại căn cứ", exact: true })
    .click();
  await expect(target).toHaveValue("12");
  await expect(
    page.getByRole("button", { name: "Lưu kế hoạch", exact: true }),
  ).toBeDisabled();
  page.once("dialog", (d) => d.accept());
  await page
    .getByRole("button", { name: "Dùng bản máy chủ", exact: true })
    .click();
  await expect(target).toHaveValue("15");
});

test("review data failure keeps an edited plan and warns before changing tabs", async ({
  page,
}) => {
  await setup(page, { review: "ready" });
  await page
    .getByRole("tab", { name: "Đánh giá & hành động", exact: true })
    .click();
  const target = page.getByRole("spinbutton", {
    name: "Số bài dự kiến của kỳ đang xem",
  });
  await target.fill("12");
  await page.route("**/facebook/1/review?**", (route) => route.abort());
  await page
    .getByRole("button", { name: "Tải lại căn cứ", exact: true })
    .click();
  await expect(
    page.getByText("Không tải được đánh giá.", { exact: false }).first(),
  ).toBeVisible();
  await expect(target).toHaveValue("12");
  page.once("dialog", (d) => d.dismiss());
  await page.getByRole("tab", { name: "Diễn biến Page", exact: true }).click();
  await expect(
    page.getByRole("tab", { name: "Đánh giá & hành động", exact: true }),
  ).toHaveAttribute("aria-selected", "true");
});

test("320px review shows insufficient data honestly and the plan fits the viewport", async ({
  page,
}) => {
  await page.setViewportSize({ width: 320, height: 844 });
  await setup(page, { review: "blocked" });
  await page
    .getByRole("tab", { name: "Đánh giá & hành động", exact: true })
    .click();
  await expect(
    page.getByText("Chưa có nhóm nào đủ điều kiện so sánh.", { exact: false }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Thêm hành động", exact: true })
    .click();
  await expect(
    page.getByRole("button", { name: /Hạn thực hiện:/ }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBeTruthy();
});

async function monthlySetup(
  page: Page,
  mode: "ready" | "waiting" | "conflict" = "ready",
) {
  const requests = await setup(page, { review: "ready", monthly: true });
  await page.clock.setFixedTime(
    new Date(
      mode === "waiting" ? "2026-11-01T03:00:00Z" : "2026-11-10T03:00:00Z",
    ),
  );
  let saved: FacebookMonthlyReportData | null = null;
  let displayed: FacebookMonthlyReportData;
  const review = {
    entry_id: 1,
    entry_version: 1,
    from_date: "2026-10-01",
    to_date: "2026-10-31",
    previous_from: "2026-09-01",
    previous_to: "2026-09-30",
    as_of: "2026-11-10T03:00:00Z",
    source_hash: "b".repeat(64),
    catalogue_covered: false,
    publication: { count: 12, covered: false },
    quality: {
      total: 12,
      statuses: {
        on_time: 5,
        missing: mode === "waiting" ? 4 : 5,
        late: 2,
        waiting: mode === "waiting" ? 1 : 0,
        due: 0,
      },
    },
    policy: {
      version: 1,
      window_minutes: 30,
      minimum_posts: 5,
      minimum_coverage: 0.8,
    },
    owner: { id: 10001, name: "Người phụ trách kênh" },
    plan: {
      version: 0,
      expected_posts: null,
      actions: [],
      updated_at: null,
      source_changed: false,
    },
    groups: [],
  };
  const observation = {
    value: -2,
    posts: 3,
    pairs: 4,
    observed_from: "2026-10-02T03:00:00Z",
    observed_to: "2026-10-20T03:00:00Z",
    max_gap_seconds: 172800,
  };
  await page.route("**/monthly-report**", async (route) => {
    const req = route.request(),
      url = new URL(req.url());
    requests.push(req.method() + " " + url.pathname);
    if (req.method() === "POST") {
      if (mode === "conflict")
        return route.fulfill({
          status: 409,
          json: { message: "Source changed" },
        });
      const values = req.postDataJSON();
      saved = {
        ...displayed,
        month: values.month,
        status: "closed",
        can_close: false,
        existing_snapshot: true,
        notes: values.notes,
        actions: values.actions,
        closed_at: "2026-11-10T03:00:00Z",
        closed_by_name: "Quản lý",
        snapshot_id: 1,
      };
      return route.fulfill({ json: { data: saved } });
    }
    const report: FacebookMonthlyReportData = {
      schema_version: 1,
      entry_id: 1,
      entry_version: 1,
      month: url.searchParams.get("month")!,
      page_name: "KPS Page",
      page_id: "100",
      status: "preview",
      as_of: "2026-11-10T03:00:00Z",
      close_available_at: "2026-11-07T17:30:00Z",
      can_close: mode !== "waiting" && !saved,
      existing_snapshot: !!saved,
      limited: true,
      source_hash: "b".repeat(64),
      reviews: {
        likes: { ...review, metric: "likes" },
        comments: { ...review, metric: "comments" },
        media_views: { ...review, metric: "media_views" },
      },
      activity: {
        definition: "v27:like",
        metrics: {
          likes: observation,
          comments: { ...observation, value: 0 },
          media_views: { ...observation, value: null, posts: 0, pairs: 0 },
        },
      },
      notes: "",
      actions: [],
      closed_at: null,
      closed_by_name: null,
    };
    displayed = report;
    return route.fulfill({
      json: {
        data: saved && url.searchParams.get("view") !== "live" ? saved : report,
      },
    });
  });
  await page
    .getByRole("tab", { name: "Đánh giá & hành động", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Báo cáo tháng", exact: true })
    .click();
  return requests;
}

test("monthly closure freezes the report, keeps limits honest and separates later live data", async ({
  page,
}) => {
  const requests = await monthlySetup(page);
  await expect(
    page.getByText("Đang xem toàn bộ tháng 2026-10.", { exact: false }),
  ).toBeVisible();
  await expect(
    page.getByText(
      "12 bài trong danh mục · 5 có D7 đúng cửa sổ · 5 thiếu · 2 trễ · 0 chưa qua cửa sổ",
      { exact: true },
    ),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", {
      name: "Đánh giá và chốt báo cáo tháng",
      exact: true,
    }),
  ).toBeVisible();
  await expect(
    page.getByText("1. Biến động quan sát trong tháng", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("2. Hiệu quả D7 của bài đăng trong tháng", { exact: true }),
  ).toBeVisible();
  await page
    .getByRole("textbox", {
      name: "Nhận xét của người chốt (không phải kết luận AI)",
      exact: true,
    })
    .fill("Chốt số liệu hiện có");
  await page.getByRole("checkbox").last().check();
  page.once("dialog", (d) => d.accept());
  await page
    .getByRole("button", { name: "Chốt và lưu báo cáo tháng", exact: true })
    .click();
  await expect(
    page.getByText("Đã chốt · bản lưu cố định", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Chốt số liệu hiện có", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", {
      name: "Chốt và lưu báo cáo tháng",
      exact: true,
    }),
  ).toHaveCount(0);
  await page
    .getByRole("button", { name: "Xem dữ liệu hiện tại", exact: true })
    .click();
  await expect(
    page.getByText("Đây là dữ liệu hiện tại để đối chiếu.", { exact: false }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", {
      name: "Chốt và lưu báo cáo tháng",
      exact: true,
    }),
  ).toHaveCount(0);
  await page
    .getByRole("button", { name: "Xem bản đã chốt", exact: true })
    .click();
  await expect(
    page.getByText("Chốt số liệu hiện có", { exact: true }),
  ).toBeVisible();
  expect(
    requests.filter(
      (r) => r.startsWith("POST ") && r.endsWith("/monthly-report"),
    ),
  ).toHaveLength(1);
  expect(
    requests.some((r) => r.includes("/analyses") || r.endsWith("/sync")),
  ).toBeFalsy();
});

test("monthly preliminary data and actions fit 320px and unsaved notes warn on leaving", async ({
  page,
}) => {
  await page.setViewportSize({ width: 320, height: 844 });
  await monthlySetup(page, "waiting");
  await expect(
    page.getByRole("button", {
      name: "Chốt và lưu báo cáo tháng",
      exact: true,
    }),
  ).toBeDisabled();
  await page
    .getByRole("button", { name: "Thêm hành động", exact: true })
    .click();
  await expect(
    page.getByRole("button", { name: /Hạn thực hiện:/ }),
  ).toBeVisible();
  page.once("dialog", (d) => d.dismiss());
  await page.getByRole("button", { name: "Tháng trước", exact: true }).click();
  await expect(
    page.getByText("Đang xem toàn bộ tháng 2026-10.", { exact: false }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBeTruthy();
});

test("monthly stale source preserves notes and requires refresh before another close", async ({
  page,
}) => {
  await monthlySetup(page, "conflict");
  const notes = page.getByRole("textbox", {
    name: "Nhận xét của người chốt (không phải kết luận AI)",
    exact: true,
  });
  await notes.fill("Giữ nguyên bản nháp");
  await page.getByRole("checkbox").last().check();
  page.once("dialog", (d) => d.accept());
  await page
    .getByRole("button", { name: "Chốt và lưu báo cáo tháng", exact: true })
    .click();
  await expect(
    page.getByText("Dữ liệu, phân công hoặc bản chốt đã thay đổi.", {
      exact: false,
    }),
  ).toBeVisible();
  await expect(notes).toHaveValue("Giữ nguyên bản nháp");
  await expect(
    page.getByRole("button", {
      name: "Chốt và lưu báo cáo tháng",
      exact: true,
    }),
  ).toBeDisabled();
  await page
    .getByRole("button", { name: "Tải lại báo cáo tháng", exact: true })
    .click();
  await expect(notes).toHaveValue("Giữ nguyên bản nháp");
  await expect(
    page.getByRole("button", {
      name: "Chốt và lưu báo cáo tháng",
      exact: true,
    }),
  ).toBeEnabled();
});
