import { expect, test, type Page } from "@playwright/test";
import type {
  AnalysisEmployee,
  AnalysisResult,
  WorkReportAnalysis,
} from "../src/types/workReportAnalysis";

const root = "/api/ai/work-report-analyses";
const evidence = [{ report_id: 71, item_id: 711, quote: "Đã thiết kế 2 ảnh" }];
function result(legacy = false): AnalysisResult {
  return {
    overview: [
      {
        text: "Nhận xét có căn cứ: Đã có sản phẩm; chưa có số liệu hiệu quả quảng cáo.",
        evidence,
      },
    ],
    highlights: [],
    ongoing: [],
    attention: [],
    support: [],
    uncertainties: [],
    recommendations: [
      {
        text: "Đề xuất: Đối chiếu kết quả chiến dịch",
        evidence,
        ...(legacy
          ? {}
          : {
              priority: "high" as const,
              rationale: "Nguồn ghi nhận sản phẩm",
              impact: "Chưa đủ dữ kiện về chuyển đổi",
              action: "Đối chiếu chi phí và đơn hàng cùng kỳ",
              expected_outcome: "Có căn cứ quyết định ngân sách",
              verification: "Kiểm tra thống kê chiến dịch và đơn hàng",
              decision_needed: "Chọn kỳ đối chiếu",
            }),
      },
    ],
  };
}
function employee(id: number, name: string): AnalysisEmployee {
  return {
    employee_id: id,
    name,
    department_name: "Nội dung",
    status: "completed",
    completed_units: 1,
    total_units: 1,
    failed_units: 0,
    source_report_ids: [71],
    result: result(),
    work_groups: [
      { title: `Thiết kế của ${name}`, text: "Đã thiết kế 2 ảnh", evidence },
      {
        title: `Đăng bài của ${name}`,
        text: "Đã đăng 1 bài",
        evidence: [{ report_id: 71, item_id: 712, quote: "Đăng bài" }],
      },
    ],
    work_inventory: [
      {
        content: "Chi tiết gốc",
        observations: [
          {
            report_id: 71,
            item_id: 711,
            date: "2026-11-01",
            status: "COMPLETED",
            result: "Đã thiết kế 2 ảnh",
            note: null,
          },
        ],
      },
    ],
    work_totals: [
      {
        metric: "Ảnh đã tạo",
        total: 2,
        unit: "ảnh",
        status: "confirmed",
        entries: [],
      },
      {
        metric: "Doanh thu",
        total: null,
        unit: "đồng",
        status: "needs_confirmation",
        entries: [],
      },
    ],
  };
}
function sampleRun(
  options: {
    partial?: boolean;
    truncated?: boolean;
    changed?: boolean;
    legacy?: boolean;
    readable?: boolean;
    days?: 1 | 7 | 30;
  } = {},
): WorkReportAnalysis {
  const employees = [employee(7, "Nhân viên A"), employee(8, "Nhân viên B")];
  if (options.partial || options.truncated)
    employees[1] = {
      ...employees[1],
      status: "failed",
      result: null,
      work_groups: [],
      work_totals: [],
      completed_units: 0,
      failed_units: 1,
    };
  return {
    id: 41,
    status: options.partial ? "partial" : "completed",
    period: {
      start_date:
        options.days === 1
          ? "2026-11-01"
          : options.days === 30
            ? "2026-10-03"
            : "2026-10-26",
      end_date: "2026-11-01",
      days: options.days ?? 7,
      timezone: "Asia/Ho_Chi_Minh",
    },
    department_id: null,
    scope: { organization_id: 1, restrictions: [["organization", null]] },
    source_changed: Boolean(options.changed),
    config_changed: Boolean(options.legacy),
    requires_new_run: Boolean(options.legacy),
    captured_at: "2026-11-02T00:00:00Z",
    completed_at: "2026-11-02T00:01:00Z",
    provider: "fake",
    model: "deterministic-v3",
    is_mock: true,
    work_counts: {
      reports: 2,
      items: 4,
      completed: 4,
      in_progress: 0,
      blocked: 0,
    },
    progress: { finished: 3, total: 3 },
    result: options.readable
      ? {
          ...result(),
          ongoing: [
            {
              text: "Ghi nhận từ nguồn: Tiếp tục hoàn thiện catalogue; chưa xác nhận duyệt cuối.",
              evidence,
            },
          ],
          support: [
            { text: "Ghi nhận từ nguồn: Đang chờ báo giá lại.", evidence },
          ],
          recommendations: [
            {
              ...result().recommendations[0],
              priority: "normal",
              text: "Đề xuất: Đề xuất: Xác nhận phiên bản được duyệt",
            },
          ],
        }
      : result(options.legacy),
    employees,
    source_reports: [
      {
        id: 71,
        employee_id: 7,
        employee_name: "Nhân viên A",
        department_name: "Nội dung",
        date: "2026-11-01",
        item_ids: [711, 712],
      },
    ],
    errors: options.truncated
      ? [
          {
            unit_key: "employee:8:1",
            code: "AI_OUTPUT_TRUNCATED",
            max_output_tokens: 6000,
          },
        ]
      : options.partial
        ? [{ unit_key: "employee:8:1", code: "AI_INVALID_EVIDENCE" }]
        : [],
    can_retry: Boolean(options.partial) && !options.truncated,
    can_regenerate: false,
    created_at: "2026-11-02T00:00:00Z",
    requested_by_name: "Người kiểm tra",
    revision: { number: 1, root_id: 41, parent_id: null },
    usage: { input_tokens: null, output_tokens: null, cost_microusd: null },
  };
}
async function setup(
  page: Page,
  options: {
    partial?: boolean;
    truncated?: boolean;
    changed?: boolean;
    legacy?: boolean;
    readable?: boolean;
    days?: 1 | 7 | 30;
  } = {},
) {
  let run = sampleRun(options);
  const posts: { path: string; body: unknown }[] = [];
  await page.route("**/api/**", async (route) => {
    const request = route.request(),
      url = new URL(request.url()),
      path = url.pathname;
    if (path.startsWith(root) && request.method() === "POST") {
      posts.push({
        path,
        body: request.postData() ? request.postDataJSON() : null,
      });
      run = {
        ...sampleRun({
          ...options,
          partial: false,
          truncated: false,
          changed: false,
        }),
        id: options.changed ? 42 : 41,
        reused_employee_units: options.changed ? 1 : 0,
      };
      return route.fulfill({ status: 202, json: { data: run } });
    }
    if (path === root + "/preview") {
      const days = Number(url.searchParams.get("days")) as 1 | 7 | 30;
      if (days !== run.period.days) run = sampleRun({ ...options, days });
      return route.fulfill({
        json: {
          data: {
            enabled: true,
            mode: "fake",
            departments: [],
            max_days_back: 365,
            work_counts: run.work_counts,
            employees: run.employees,
            source_reports: run.source_reports,
            run,
            latest_attempt: null,
          },
        },
      });
    }
    if (path === root + "/history")
      return route.fulfill({ json: { data: [run] } });
    if (path === root + "/" + run.id)
      return route.fulfill({ json: { data: run } });
    const json =
      path === "/api/auth/me"
        ? {
            id: 10001,
            name: "Người kiểm tra",
            email: "weekly@example.test",
            role: { name: "Owner" },
            organization: { id: 1, name: "KPS Test" },
            department: null,
            location: null,
          }
        : path === "/api/navigation"
          ? { items: ["ai.insights", "reports.history"] }
          : {
              data: [],
              unread_count: 0,
              meta: { total: 0, current_page: 1, last_page: 1 },
            };
    await route.fulfill({ json });
  });
  await page.goto(`/ai/insights?end=2026-11-01&days=${options.days ?? 7}`);
  await expect(
    page.getByRole("heading", { name: "1. Kết quả trong kỳ", exact: true }),
  ).toBeVisible();
  return posts;
}
const employeePanel = (page: Page, name: string) =>
  page.getByRole("region", { name: "Báo cáo AI của " + name, exact: true });

test("company first, structured decisions and source footer; employee selection is local and survives reload and tab changes", async ({
  page,
}) => {
  const posts = await setup(page);
  await expect(
    page.getByRole("tab", { name: "Toàn công ty", exact: true }),
  ).toHaveAttribute("aria-selected", "true");
  await expect(
    page.getByLabel("Kỳ phân tích", { exact: true }),
  ).not.toBeVisible();
  await page
    .locator("summary")
    .filter({ hasText: "Đối chiếu kết quả chiến dịch" })
    .click();
  await expect(
    page.getByText("Có căn cứ quyết định ngân sách", { exact: true }),
  ).toBeVisible();
  await page.getByRole("tab", { name: "Theo nhân viên", exact: true }).click();
  await expect(
    employeePanel(page, "Nhân viên A").getByRole("heading", {
      name: "Thiết kế của Nhân viên A",
      exact: true,
    }),
  ).toBeVisible();
  await expect(
    page.getByText("Chi tiết gốc", { exact: true }),
  ).not.toBeVisible();
  await page
    .getByRole("navigation", { name: "Danh sách nhân viên", exact: true })
    .getByRole("button", { name: /Nhân viên B/ })
    .click();
  await expect(employeePanel(page, "Nhân viên B")).toBeVisible();
  await page.getByRole("button", { name: "Tải lại", exact: true }).click();
  await expect(employeePanel(page, "Nhân viên B")).toBeVisible();
  await page.getByRole("tab", { name: "Toàn công ty", exact: true }).click();
  await page.getByRole("tab", { name: "Theo nhân viên", exact: true }).click();
  const panel = employeePanel(page, "Nhân viên B");
  await expect(panel).toBeVisible();
  await panel.getByText("Xem căn cứ", { exact: true }).click();
  await panel
    .getByText("Chi tiết công việc theo ngày", { exact: true })
    .click();
  await expect(panel.getByText("Chi tiết gốc", { exact: true })).toBeVisible();
  expect(posts).toEqual([]);
});

test("mobile and dark reading keeps work lines and moves unconfirmed metrics into one clarification list", async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 812 });
  const posts = await setup(page);
  await page.evaluate(() => document.documentElement.classList.add("dark"));
  await page.getByRole("tab", { name: "Theo nhân viên", exact: true }).click();
  await page.getByLabel("Chọn nhân viên", { exact: true }).selectOption("8");
  const panel = employeePanel(page, "Nhân viên B");
  await expect(
    panel.getByRole("heading", {
      name: "Thiết kế của Nhân viên B",
      exact: true,
    }),
  ).toBeVisible();
  await expect(
    panel.getByRole("heading", {
      name: "Đăng bài của Nhân viên B",
      exact: true,
    }),
  ).toBeVisible();
  await expect(
    panel.getByText("Doanh thu (đồng)", { exact: true }),
  ).not.toBeVisible();
  await panel.getByText("Cần làm rõ · 1", { exact: true }).click();
  await expect(
    panel.getByText("Doanh thu (đồng)", { exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  expect(posts).toEqual([]);
});

test("explicit failed-part retry preserves selected employee and does not recreate the period", async ({
  page,
}) => {
  const posts = await setup(page, { partial: true });
  await page.getByRole("tab", { name: "Theo nhân viên", exact: true }).click();
  await page
    .getByRole("navigation", { name: "Danh sách nhân viên", exact: true })
    .getByRole("button", { name: /Nhân viên B/ })
    .click();
  await expect(
    employeePanel(page, "Nhân viên B").getByText(/Chưa có tổng hợp AI/),
  ).toBeVisible();
  await page
    .getByRole("button", {
      name: "Thử lại phần lỗi (có thể phát sinh phí)",
      exact: true,
    })
    .click();
  await expect(
    employeePanel(page, "Nhân viên B").getByRole("heading", {
      name: "Tổng hợp từ báo cáo",
      exact: true,
    }),
  ).toBeVisible();
  expect(posts.map((post) => post.path)).toEqual([root + "/41/retry"]);
});

test("changed source uses explicit update and reports employee reuse", async ({
  page,
}) => {
  const posts = await setup(page, { changed: true });
  await page
    .getByRole("button", { name: "Cập nhật theo dữ liệu mới", exact: true })
    .click();
  await expect(
    page.getByText("Đã dùng lại 1 phần nhân viên không thay đổi.", {
      exact: true,
    }),
  ).toBeVisible();
  expect(posts).toEqual([
    {
      path: root,
      body: { end_date: "2026-11-01", days: 7, department_id: null },
    },
  ]);
});

test("older result remains readable without fabricated decision fields or automatic AI calls", async ({
  page,
}) => {
  const posts = await setup(page, { legacy: true });
  await page
    .locator("summary")
    .filter({ hasText: "Đối chiếu kết quả chiến dịch" })
    .click();
  await expect(page.getByText(/Bản cũ chưa lưu chi tiết/)).toBeVisible();
  await expect(page.getByText("Cách kiểm chứng", { exact: true })).toHaveCount(
    0,
  );
  await page.getByRole("tab", { name: "Báo cáo nguồn", exact: true }).click();
  await expect(
    page.getByRole("link", { name: /Nhân viên A.*Báo cáo #71/ }),
  ).toBeVisible();
  expect(posts).toEqual([]);
});

for (const days of [1, 7] as const) {
  test(`${days}-day company report uses the approved form, plain decisions and expandable source evidence`, async ({
    page,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const posts = await setup(page, { readable: true, days });
    const headings = [
      "1. Kết quả trong kỳ",
      "2. Công việc đang tiếp tục",
      "3. Vướng mắc cần theo dõi",
      "4. Việc cần xem xét và quyết định",
    ];
    for (const name of headings)
      await expect(
        page.getByRole("heading", { name, exact: true }),
      ).toBeVisible();
    await expect(
      page.getByText(
        "Nhân viên A — Tiếp tục hoàn thiện catalogue; chưa xác nhận duyệt cuối.",
        { exact: true },
      ),
    ).toBeVisible();
    await expect(
      page
        .locator("summary")
        .filter({ hasText: "Xác nhận phiên bản được duyệt" }),
    ).toBeVisible();
    await expect(
      page.getByText(
        /Nhận xét có căn cứ:|Ghi nhận từ nguồn:|Đề xuất: Đề xuất:/,
      ),
    ).not.toBeVisible();
    const sources = page
      .locator("footer")
      .filter({ has: page.getByText("Xem căn cứ", { exact: true }) });
    await expect(
      sources.getByRole("link", { name: /Nhân viên A.*Báo cáo #71/ }),
    ).not.toBeVisible();
    await page.getByText("Xem căn cứ", { exact: true }).click();
    await expect(
      page.getByRole("link", { name: /Nhân viên A.*Báo cáo #71/ }),
    ).toBeVisible();
    await page
      .locator("summary")
      .filter({ hasText: "Xác nhận phiên bản được duyệt" })
      .click();
    await expect(
      page.getByText("Có căn cứ quyết định ngân sách", { exact: true }),
    ).toBeVisible();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
    expect(posts).toEqual([]);
  });
}

test("output truncation shows the saved limit without a misleading permission error or paid retry", async ({
  page,
}) => {
  const posts = await setup(page, { partial: true, truncated: true });
  await expect(
    page.getByText(/AI đã chạm giới hạn đầu ra 6000 token/),
  ).toBeVisible();
  await expect(
    page.getByText(/Kiểm tra quyền, cấu hình và nhật ký trạng thái/),
  ).not.toBeVisible();
  await expect(
    page.getByRole("button", {
      name: "Thử lại phần lỗi (có thể phát sinh phí)",
      exact: true,
    }),
  ).not.toBeVisible();
  await page.getByRole("tab", { name: "Theo nhân viên", exact: true }).click();
  await expect(
    employeePanel(page, "Nhân viên A").getByRole("heading", {
      name: "Tổng hợp từ báo cáo",
      exact: true,
    }),
  ).toBeVisible();
  expect(posts).toEqual([]);
});

test("daily tab reuses the whole analysis screen; switching modes preserves the date and removes period-one duplication", async ({
  page,
}) => {
  const posts = await setup(page, { days: 1 });
  const daily = page.getByRole("link", { name: "Theo ngày", exact: true });
  const periodic = page.getByRole("link", {
    name: "Theo kỳ / báo cáo tuần",
    exact: true,
  });
  await expect(daily).toHaveAttribute("aria-current", "page");
  await expect(
    page.getByRole("heading", { name: "Phân tích báo cáo ngày", exact: true }),
  ).toBeVisible();
  for (const name of [
    "Toàn công ty",
    "Theo nhân viên",
    "Lịch sử phân tích",
    "Báo cáo nguồn",
  ])
    await expect(page.getByRole("tab", { name, exact: true })).toBeVisible();
  await expect(page.locator("#analysis-period")).toHaveValue("1");
  await expect(page.locator("#analysis-period")).toBeDisabled();
  await periodic.click();
  await expect(periodic).toHaveAttribute("aria-current", "page");
  await expect(page.locator("#analysis-period option[value='1']")).toHaveCount(
    0,
  );
  await expect(page.locator("#analysis-period option[value='7']")).toHaveCount(
    1,
  );
  await expect(page.locator("#analysis-period option[value='30']")).toHaveCount(
    1,
  );
  const filters = page
    .locator("details")
    .filter({ has: page.locator("#analysis-period") });
  if ((await filters.getAttribute("open")) === null)
    await filters.locator("summary").click();
  await page.locator("#analysis-period").selectOption("30");
  await expect(page).toHaveURL(/end=2026-11-01&days=30/);
  await daily.click();
  await expect(daily).toHaveAttribute("aria-current", "page");
  await expect(page).toHaveURL(/view=daily&end=2026-11-01&days=1/);
  await page.goBack();
  await expect(periodic).toHaveAttribute("aria-current", "page");
  await expect(page.locator("#analysis-period")).toHaveValue("30");
  expect(posts).toEqual([]);
});

test("legacy daily date and saved one-day mail links open the same reader without creating AI work", async ({
  page,
}) => {
  const posts = await setup(page, { days: 1 });
  await page.goto("/ai/insights?view=daily&date=2026-11-01");
  await expect(
    page.getByRole("heading", { name: "1. Kết quả trong kỳ", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("link", { name: "Theo ngày", exact: true }),
  ).toHaveAttribute("aria-current", "page");
  await page.goto("/ai/insights?analysis=41&end=2026-11-01&days=1");
  await page.reload();
  await expect(
    page.getByRole("link", { name: "Theo ngày", exact: true }),
  ).toHaveAttribute("aria-current", "page");
  await expect(
    page.getByRole("heading", { name: "1. Kết quả trong kỳ", exact: true }),
  ).toBeVisible();
  expect(posts).toEqual([]);
});

test("a saved run owns its period when a historical link omits or carries the wrong mode", async ({
  page,
}) => {
  const posts = await setup(page, { days: 1 });
  await page.goto("/ai/insights?analysis=41");
  await expect(page).toHaveURL(/view=daily&analysis=41&end=2026-11-01&days=1/);
  await expect(
    page.getByRole("link", { name: "Theo ngày", exact: true }),
  ).toHaveAttribute("aria-current", "page");
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "1. Kết quả trong kỳ", exact: true }),
  ).toBeVisible();
  expect(posts).toEqual([]);
});

test("manual daily creation uses the one-day engine and does not request a daily-document batch", async ({
  page,
}) => {
  const posts = await setup(page, { days: 1 });
  await page
    .getByRole("button", { name: "Tạo / mở phân tích", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "1. Kết quả trong kỳ", exact: true }),
  ).toBeVisible();
  expect(posts).toEqual([
    {
      path: root,
      body: { end_date: "2026-11-01", days: 1, department_id: null },
    },
  ]);
});

test("selecting a weekly result from shared daily history activates the period tab and keeps that saved result", async ({
  page,
}) => {
  const posts = await setup(page, { days: 1 });
  const weekly = { ...sampleRun(), id: 42 };
  await page.route("**/api/ai/work-report-analyses/history", (route) =>
    route.fulfill({ json: { data: [weekly] } }),
  );
  await page.route("**/api/ai/work-report-analyses/42", (route) =>
    route.fulfill({ json: { data: weekly } }),
  );
  await page
    .getByRole("tab", { name: "Lịch sử phân tích", exact: true })
    .click();
  await page.getByRole("button", { name: "Xem kết quả", exact: true }).click();
  await expect(page).toHaveURL(/analysis=42&end=2026-11-01&days=7/);
  await expect(
    page.getByRole("link", { name: "Theo kỳ / báo cáo tuần", exact: true }),
  ).toHaveAttribute("aria-current", "page");
  await expect(
    page.getByRole("heading", { name: "1. Kết quả trong kỳ", exact: true }),
  ).toBeVisible();
  expect(posts).toEqual([]);
});
