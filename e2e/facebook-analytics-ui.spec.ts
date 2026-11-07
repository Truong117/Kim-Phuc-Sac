import { expect, test, type Page } from "@playwright/test";

async function setup(
  page: Page,
  options: { empty?: boolean; delayed?: boolean } = {},
) {
  await page.clock.setFixedTime(new Date("2026-11-07T03:00:00Z"));
  const requests: string[] = [];
  const posts = [
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
        { date: "2026-11-06", likes: 10, comments: 2, media_views: null },
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
  let analysis = false;
  let sync = false;
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
              status: "idle",
              interrupted: false,
              sampled_at: sync
                ? "2026-11-07T03:00:00Z"
                : "2026-11-06T03:00:00Z",
              catalogued_at: "2026-11-06T03:00:00Z",
              catalogue_complete: false,
              catalogue_from: "2026-08-10",
              catalogue_to: "2026-11-07",
              error_code: null,
              pending_samples: 1,
              catalogue_posts: 2,
            },
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
    page.getByText("Danh mục đang được tải từng phần.", { exact: false }),
  ).toBeVisible();
  await expect(page.locator('input[type="date"]')).toHaveCount(0);
  await page.getByRole("button", { name: "28 ngày", exact: true }).click();
  await expect
    .poll(() => requests.some((r) => r.includes("from_date=2026-10-11")))
    .toBeTruthy();
  await page.getByRole("tab", { name: "Nội dung", exact: true }).click();
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
  await page.getByRole("tab", { name: "Nhận xét AI", exact: true }).click();
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
  await page.getByRole("tab", { name: "Nội dung", exact: true }).click();
  await expect(
    page.getByText("Không có bài phù hợp.", { exact: false }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: /Routine tối giản/ }),
  ).toHaveCount(0);
});
