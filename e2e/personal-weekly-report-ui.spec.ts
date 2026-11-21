import { expect, test, type Page } from "@playwright/test";
import type { PersonalWeeklyReport } from "../src/types/personalWeeklyReport";

const endpoint = "/api/reports/my-weekly-summary";
const evidence = [{ report_id: 71, item_id: 711, quote: "Đã thiết kế 2 ảnh" }];
function sample(
  status: PersonalWeeklyReport["status"] = "completed",
): PersonalWeeklyReport {
  return {
    period: {
      start_date: "2026-11-01",
      end_date: "2026-11-07",
      days: 7,
      timezone: "Asia/Ho_Chi_Minh",
    },
    status,
    source_changed: false,
    updating: false,
    is_mock: false,
    captured_at: "2026-11-08T01:00:00Z",
    employee: {
      employee_id: 10001,
      name: "Nhân viên thử nghiệm",
      department_name: null,
      status,
      completed_units: status === "completed" ? 1 : 0,
      total_units: 1,
      failed_units: status === "failed" ? 1 : 0,
      source_report_ids: [71],
      result:
        status === "completed" || status === "partial"
          ? {
              overview: [
                { text: "Đã có sản phẩm để tiếp tục triển khai", evidence },
              ],
              highlights: [],
              ongoing: [],
              attention: [],
              support: [],
              uncertainties: [],
              recommendations: [],
            }
          : null,
      work_groups: [
        { title: "Thiết kế nội dung", text: "Đã thiết kế 2 ảnh", evidence },
      ],
      work_totals: [],
      work_inventory: [],
    },
    source_reports: [
      {
        id: 71,
        employee_id: 10001,
        employee_name: "Nhân viên thử nghiệm",
        department_name: null,
        date: "2026-11-04",
        item_ids: [711],
      },
    ],
  };
}

async function setup(
  page: Page,
  response: (
    week: string,
    attempt: number,
  ) => PersonalWeeklyReport | number = () => sample(),
  options: {
    periods?: PersonalWeeklyReport["period"][];
    path?: string;
    periodsError?: boolean;
  } = {},
) {
  await page.clock.setFixedTime(new Date("2026-11-11T02:00:00Z"));
  const requests: { path: string; method: string }[] = [];
  let reads = 0;
  await page.route("**/api/**", async (route) => {
    if (new URL(route.request().url()).pathname === "/api/workspaces")
      return route.fulfill({
        json: { workspaces: [{ id: "internal", home: "/", sections: [] }] },
      });
    const request = route.request(),
      url = new URL(request.url());
    requests.push({ path: url.pathname, method: request.method() });
    if (url.pathname === endpoint + "/periods") {
      return options.periodsError
        ? route.fulfill({ status: 503, json: { message: "Unavailable" } })
        : route.fulfill({ json: { data: options.periods ?? [] } });
    }
    if (url.pathname === endpoint) {
      const next = response(url.searchParams.get("end_date")!, ++reads);
      return typeof next === "number"
        ? route.fulfill({ status: next, json: { message: "Unavailable" } })
        : route.fulfill({ json: { data: next } });
    }
    const json =
      url.pathname === "/api/auth/me"
        ? {
            id: 10001,
            name: "Nhân viên thử nghiệm",
            email: "staff@example.test",
            role: { name: "Office Staff" },
            organization: { id: 1, name: "KPS Test" },
            department: null,
            location: null,
          }
        : url.pathname === "/api/navigation"
          ? { items: ["reports.history"] }
          : {
              data: [],
              unread_count: 0,
              meta: { total: 0, current_page: 1, last_page: 1 },
            };
    await route.fulfill({ json });
  });
  await page.goto(
    options.path ?? "/reports/my-weekly-summary?end_date=2026-11-07",
  );
  await expect(
    page.getByRole("heading", { name: "Báo cáo tuần của tôi", exact: true }),
  ).toBeVisible();
  return requests;
}

test("staff reads focused grouped work with sources at the end and GET requests only", async ({
  page,
}) => {
  const requests = await setup(page);
  await expect(
    page.getByRole("heading", { name: "Thiết kế nội dung" }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Nhận xét", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Đã có sản phẩm để tiếp tục triển khai"),
  ).toBeVisible();
  await expect(
    page.getByRole("textbox", { name: /nhân viên|phòng ban/i }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: /tạo.*phân tích|thử lại phần lỗi/i }),
  ).toHaveCount(0);
  const sources = page.locator("footer details").first();
  await expect(sources).not.toHaveAttribute("open");
  await sources.locator("summary").first().click();
  await expect(
    sources.getByRole("link", { name: /71/ }).first(),
  ).toHaveAttribute("href", /\/reports\/71/);
  await page.getByRole("button", { name: "Tải lại", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Tải lại", exact: true }),
  ).toBeEnabled();
  expect(requests.every((request) => request.method === "GET")).toBe(true);
  expect(requests.some((request) => request.path.startsWith("/api/ai/"))).toBe(
    false,
  );
  await expect(
    page.getByRole("link", { name: "Phân tích AI", exact: true }),
  ).toHaveCount(0);
});

test("the personal reader preserves repeated claims, complete work descriptions and decision order", async ({ page }) => {
  const report = sample();
  const employee = report.employee!;
  const group = employee.work_groups![0];
  group.text = "Ghi nhận từ nguồn: Bàn giao bản đầu.\nCòn hai bước đối chiếu chi tiết.";
  const review = { text: "Nhận xét có căn cứ: Lần ghi nhận này cần giữ lại.", evidence };
  employee.result!.overview = [review];
  employee.result!.highlights = [review];
  employee.result!.recommendations = [
    {text: "Đề xuất: Xác nhận bản đầu.", priority: "normal", evidence},
    {text: "Đề xuất: Duyệt bản tiếp theo.", priority: "high", evidence},
  ];
  await setup(page, () => report);
  await expect(page.getByText(group.text, { exact: true })).toBeVisible();
  await expect(page.getByText(review.text, { exact: true })).toHaveCount(2);
  await expect(page.locator("summary").filter({ hasText: /Đề xuất:/ })).toHaveText([
    /Đề xuất: Xác nhận bản đầu\./,
    /Đề xuất: Duyệt bản tiếp theo\./,
  ]);
});

test("personal weekly figures preserve work and keep uncertain quantities in clarification", async ({ page }) => {
  const report = sample();
  report.employee!.weekly_figures = [
    { label: "Ảnh và video/Reel đã đăng", text: "6 ảnh, 4 video/Reel (2 kênh)" },
  ];
  report.employee!.work_totals = [
    { metric: "Ảnh đã đăng trên Kênh A", total: 6, unit: "ảnh", status: "confirmed", entries: [] },
    { metric: "Bài SEO chưa rõ phạm vi", total: null, unit: "bài", status: "needs_confirmation", entries: [] },
  ];
  await setup(page, () => report);
  await expect(page.locator("dt").filter({ hasText: "Ảnh và video/Reel đã đăng" })).toBeVisible();
  await expect(page.locator("dd").filter({ hasText: "6 ảnh, 4 video/Reel (2 kênh)" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Thiết kế nội dung" })).toBeVisible();
  await expect(page.getByText("Đã thiết kế 2 ảnh", { exact: true })).toBeVisible();
  await expect(page.getByText("Ảnh đã đăng trên Kênh A:", { exact: false })).toHaveCount(0);
  await page.locator("summary").filter({ hasText: "Cần làm rõ" }).click();
  await expect(page.getByText("Bài SEO chưa rõ phạm vi (bài)", { exact: true })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Nhận xét", exact: true })).toBeVisible();
});

for (const hasFigures of [false, true]) {
  test(`personal quantity display respects ${hasFigures ? "an explicitly empty weekly figure list" : "legacy data"}`, async ({ page }) => {
    const report = sample();
    report.employee!.weekly_figures = hasFigures ? [] : undefined;
    report.employee!.weekly_revenue = { amount: 1200000, display: "1.200.000 đồng" };
    report.employee!.work_totals = [
      { metric: "Chỉ số thử nghiệm", total: 2, unit: "ảnh", status: "confirmed", entries: [] },
    ];
    await setup(page, () => report);
    await expect(page.getByText("1.200.000 đồng", { exact: true })).toBeVisible();
    const oldChip = page.getByText("Chỉ số thử nghiệm:", { exact: false });
    if (!hasFigures) await expect(oldChip).toBeVisible();
    else await expect(oldChip).toHaveCount(0);
    await expect(page.getByRole("heading", { name: "Thiết kế nội dung" })).toBeVisible();
  });
}

test("personal Word export uses the selected week and the displayed employee revision", async ({
  page,
}) => {
  const report = sample();
  report.export_version = "a".repeat(64);
  report.employee!.weekly_revenue = {
    amount: 1800000,
    display: "1.800.000 đồng",
  };
  await setup(page, () => report);
  await expect(page.getByText("1.800.000 đồng", { exact: true })).toBeVisible();
  await expect(
    page.getByRole("combobox", { name: "Nội dung file Word" }),
  ).toHaveCount(0);
  let url: URL | undefined;
  await page.route("**/api/reports/my-weekly-summary/word?**", (route) => {
    url = new URL(route.request().url());
    return route.fulfill({
      contentType:
        "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
      body: "word",
    });
  });
  const [file] = await Promise.all([
    page.waitForEvent("download"),
    page.getByRole("button", { name: "Xuất Word", exact: true }).click(),
  ]);
  expect(file.suggestedFilename()).toContain("2026-11-01_2026-11-07");
  expect(Object.fromEntries(url!.searchParams)).toEqual({
    end_date: "2026-11-07",
    version: report.export_version,
  });
});

test("week links and browser history select the correct week without stale results", async ({
  page,
}) => {
  await setup(page, (week) => ({
    ...sample(),
    employee: {
      ...sample().employee!,
      work_groups: [
        {
          title: `Nhóm công việc ${week}`,
          text: "Đã thiết kế 2 ảnh",
          evidence,
        },
      ],
    },
  }));
  await expect(
    page.getByRole("heading", { name: "Nhóm công việc 2026-11-07" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Tuần trước", exact: true }).click();
  await expect(page).toHaveURL(/end_date=2026-10-31/);
  await expect(
    page.getByRole("heading", { name: "Nhóm công việc 2026-10-31" }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Nhóm công việc 2026-11-07" }),
  ).toHaveCount(0);
  await page.goBack();
  await expect(
    page.getByRole("heading", { name: "Nhóm công việc 2026-11-07" }),
  ).toBeVisible();
});

test("in-flight personal parts poll for completion without requesting generation", async ({
  page,
}) => {
  await page.clock.install();
  let completed = false;
  const requests = await setup(page, () =>
    sample(completed ? "completed" : "running"),
  );
  await expect(
    page.getByText(/Phần báo cáo của bạn đang được phân tích/),
  ).toBeVisible();
  const beforePoll = requests.filter(
    (request) => request.path === endpoint,
  ).length;
  completed = true;
  await page.clock.fastForward(10_100);
  await expect(
    page.getByRole("heading", { name: "Thiết kế nội dung" }),
  ).toBeVisible();
  expect(
    requests.filter((request) => request.path === endpoint).length,
  ).toBeGreaterThan(beforePoll);
  expect(requests.every((request) => request.method === "GET")).toBe(true);
});

test("empty and failed weeks have useful messages and no generation button", async ({
  page,
}) => {
  for (const [status, message] of [
    ["not_analyzed", "Chưa có báo cáo tổng hợp cho tuần này."],
    ["no_data", "Không có báo cáo đã chốt trong tuần này."],
    ["failed", "Chưa phân tích được phần báo cáo của bạn."],
  ] as const) {
    await page.unrouteAll();
    await setup(page, () => ({
      ...sample(status),
      employee: null,
      source_reports: [],
    }));
    await expect(
      page.getByText(message, { exact: status !== "failed" }),
    ).toBeVisible();
    await expect(
      page.getByRole("heading", { name: "Thiết kế nội dung" }),
    ).toHaveCount(0);
  }
});

test("mobile dark reader remains readable and warns about updated sources", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.addInitScript(() => localStorage.setItem("theme", "dark"));
  await setup(page, () => ({
    ...sample(),
    source_changed: true,
    updating: true,
  }));
  await expect(page.getByText(/Bản mới đang được phân tích/)).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Thiết kế nội dung" }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
});

test("a read failure can be retried without triggering AI", async ({
  page,
}) => {
  let unavailable = true;
  const requests = await setup(page, () => (unavailable ? 503 : sample()));
  await expect(page.getByRole("alert")).toHaveText(
    "Chưa tải được báo cáo. Vui lòng thử tải lại.",
  );
  unavailable = false;
  await page.getByRole("button", { name: "Tải lại", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Thiết kế nội dung" }),
  ).toBeVisible();
  expect(requests.every((request) => request.method === "GET")).toBe(true);
});

test("a revoked read permission removes previously displayed report content", async ({
  page,
}) => {
  let allowed = true;
  await setup(page, () => (allowed ? sample() : 403));
  await expect(
    page.getByRole("heading", { name: "Thiết kế nội dung" }),
  ).toBeVisible();
  allowed = false;
  await page.getByRole("button", { name: "Tải lại", exact: true }).click();
  await expect(page.getByRole("alert")).toHaveText(
    "Bạn không có quyền xem báo cáo này.",
  );
  await expect(
    page.getByRole("heading", { name: "Thiết kế nội dung" }),
  ).toHaveCount(0);
});

test("the latest saved Sunday period opens by default and exact saved weeks stay distinct", async ({
  page,
}) => {
  const saturday = sample().period;
  const sunday = {
    ...saturday,
    start_date: "2026-11-02",
    end_date: "2026-11-08",
  };
  const requests = await setup(
    page,
    (end) => ({
      ...sample(),
      period: end === sunday.end_date ? sunday : saturday,
      employee: {
        ...sample().employee!,
        work_groups: [
          {
            title: `Nhóm công việc ${end}`,
            text: "Đã thiết kế 2 ảnh",
            evidence,
          },
        ],
      },
    }),
    { periods: [sunday, saturday], path: "/reports/my-weekly-summary" },
  );
  await expect(
    page.getByRole("heading", { name: "Nhóm công việc 2026-11-08" }),
  ).toBeVisible();
  await expect(
    page.locator("p").filter({ hasText: "Từ 02/11/2026 đến 08/11/2026" }),
  ).toBeVisible();
  const saved = page.getByRole("combobox", {
    name: "Các kỳ đã có bản phân tích",
  });
  await expect(saved).toHaveValue("2026-11-08");
  await saved.selectOption("2026-11-07");
  await expect(
    page.getByRole("heading", { name: "Nhóm công việc 2026-11-07" }),
  ).toBeVisible();
  await expect(page).toHaveURL(/end_date=2026-11-07/);
  await page.goBack();
  await expect(
    page.getByRole("heading", { name: "Nhóm công việc 2026-11-08" }),
  ).toBeVisible();
  expect(requests.every((request) => request.method === "GET")).toBe(true);
});

test("an explicit Sunday link retains its exact dates and the calendar permits Sunday", async ({
  page,
}) => {
  const period = {
    ...sample().period,
    start_date: "2026-11-02",
    end_date: "2026-11-08",
  };
  await setup(page, () => ({ ...sample(), period }), {
    periods: [period],
    path: "/reports/my-weekly-summary?end_date=2026-11-08",
  });
  await expect(
    page.getByRole("heading", { name: "Thiết kế nội dung" }),
  ).toBeVisible();
  await expect(
    page.locator("p").filter({ hasText: "Từ 02/11/2026 đến 08/11/2026" }),
  ).toBeVisible();
  await page
    .getByRole("button", {
      name: "Ngày kết thúc kỳ 7 ngày: 08/11/2026",
      exact: true,
    })
    .click();
  await expect(page.locator('[data-calendar-date="2026-11-08"]')).toBeEnabled();
});

test("failure to discover old periods still permits an exact date read", async ({
  page,
}) => {
  await setup(page, () => sample(), { periodsError: true });
  await expect(
    page.getByRole("heading", { name: "Thiết kế nội dung" }),
  ).toBeVisible();
  await expect(page.getByText(/Chưa tải được danh sách kỳ/)).toBeVisible();
});
