import { expect, test, type Page } from "@playwright/test";
import type { DailyDocumentsPage } from "../src/types/dailyDocument";

type Row = DailyDocumentsPage["reports"][number];

function sampleRow(id: number, name: string): Row {
  const source: Row["source"] = {
    report_id: id,
    employee_id: id,
    date: "2026-11-02",
    is_makeup: id === 8,
    submitted_at: "2026-11-03T00:00:00Z",
    next_day_plan: ["Kế hoạch không hiển thị trong kết quả"],
    items: [
      {
        item_id: id * 10 + 1,
        status: "COMPLETED",
        content: "Thiết kế nội dung",
        result: "Đã thiết kế 2 ảnh",
        note: "",
        issue: "",
        evidence_links: [],
        evidence_status: "unverified",
      },
      {
        item_id: id * 10 + 2,
        status: "COMPLETED",
        content: "Đăng bài",
        result: "Đã đăng 1 bài",
        note: "",
        issue: "",
        evidence_links: [],
        evidence_status: "unverified",
      },
    ],
  };
  return {
    report_id: id,
    employee_name: name,
    source,
    source_changed: false,
    document: {
      id: id + 100,
      report_id: id,
      status: id === 8 ? "partial" : "completed",
      source,
      mode: "fake",
      model: null,
      captured_at: "2026-11-03T00:00:00Z",
      completed_units: id === 8 ? 1 : 2,
      total_units: 2,
      result: {
        work_groups: [
          {
            title: "Thiết kế nội dung",
            text: "Đã thiết kế 2 ảnh",
            evidence: [
              {
                report_id: id,
                item_id: id * 10 + 1,
                quote: "Đã thiết kế 2 ảnh",
              },
            ],
          },
          {
            title: "Đăng bài",
            text: "Đã đăng 1 bài",
            evidence: [
              { report_id: id, item_id: id * 10 + 2, quote: "Đã đăng 1 bài" },
            ],
          },
        ],
        comments: [],
        recommendations: [],
        work_totals: [
          { metric: "Ảnh đã tạo", total: 2, unit: "ảnh", status: "confirmed" },
        ],
      },
      units:
        id === 8
          ? [
              {
                id: 80,
                part: 1,
                status: "failed",
                error_code: "AI_WORKER_INTERRUPTED",
                can_retry: true,
              },
            ]
          : [],
    },
  };
}

async function setup(
  page: Page,
  options: { old?: boolean; failRetry?: boolean } = {},
) {
  const rows = [sampleRow(7, "Nhân viên A"), sampleRow(8, "Nhân viên B")];
  if (options.old) {
    rows[1].document!.requires_new_document = true;
    rows[1].document!.units[0].can_retry = false;
  }
  const posts: string[] = [];
  const user = {
    id: 10001,
    name: "Người nghiệm thu",
    email: "daily-reader@example.test",
    role: { name: "Owner" },
    department: null,
    organization: { id: 1, name: "KPS Test" },
    location: null,
  };
  await page.route("**/api/**", async (route) => {
    const request = route.request(),
      url = new URL(request.url()),
      path = url.pathname;
    if (
      request.method() === "POST" &&
      path.startsWith("/api/ai/daily-documents")
    ) {
      posts.push(path);
      if (path.endsWith("/batch")) {
        expect(request.postDataJSON()).toEqual({ date: "2026-11-02" });
        return route.fulfill({
          status: 202,
          json: {
            data: {
              date: "2026-11-02",
              total: 3,
              created: 0,
              reused: 3,
              failed: 0,
            },
          },
        });
      }
      if (path.endsWith("/retry") && options.failRetry) {
        return route.fulfill({
          status: 503,
          json: { message: "Không thể thử lại lúc này." },
        });
      }
      const row = rows.find(
        (entry) =>
          path.endsWith("/" + entry.document!.id + "/retry") ||
          path.endsWith("/reports/" + entry.report_id),
      );
      expect(row).toBeDefined();
      row!.document = {
        ...row!.document!,
        status: "completed",
        completed_units: 2,
        units: [],
        requires_new_document: false,
      };
      return route.fulfill({ status: 202, json: { data: row!.document } });
    }
    if (path === "/api/ai/daily-documents") {
      const current = Number(url.searchParams.get("page") ?? 1);
      return route.fulfill({
        json: {
          data: {
            enabled: true,
            company_scope: true,
            date: url.searchParams.get("date"),
            page: current,
            last_page: 2,
            total: 3,
            summary: {
              total: 3,
              completed: 2,
              processing: 0,
              failed: 1,
              not_started: 0,
            },
            reports: current === 1 ? rows : [sampleRow(9, "Nhân viên C")],
          },
        },
      });
    }
    const json =
      path === "/api/auth/me"
        ? user
        : path === "/api/navigation"
          ? { items: ["ai.insights", "reports.history"] }
          : {
              data: [],
              unread_count: 0,
              meta: { total: 0, current_page: 1, last_page: 1 },
            };
    await route.fulfill({ json });
  });
  await page.goto("/ai/insights?view=daily&date=2026-11-02&page=1");
  await expect(
    page.getByRole("heading", { name: "Nhân viên A", exact: true }),
  ).toBeVisible();
  return posts;
}

function panel(page: Page, employee: string) {
  return page.getByRole("region", {
    name: "Báo cáo AI của " + employee,
    exact: true,
  });
}
function employeeList(page: Page) {
  return page.getByRole("navigation", {
    name: "Danh sách nhân viên",
    exact: true,
  });
}

test("focused reader keeps separate AI work lines, selected employee on reload and correct report on pagination", async ({
  page,
}) => {
  const posts = await setup(page);
  const summary = panel(page, "Nhân viên A").getByRole("region", {
    name: "Tổng hợp từ báo cáo",
    exact: true,
  });
  await expect(summary.getByRole("listitem")).toHaveCount(2);
  await expect(summary.getByRole("listitem").nth(0)).toContainText(
    "Thiết kế nội dung: Đã thiết kế 2 ảnh",
  );
  await expect(summary.getByRole("listitem").nth(1)).toContainText(
    "Đăng bài: Đã đăng 1 bài",
  );
  await employeeList(page)
    .getByRole("button", { name: /Nhân viên B/ })
    .click();
  await expect(
    page.getByRole("heading", { name: "Nhân viên B", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Nhân viên A", exact: true }),
  ).toHaveCount(0);
  await expect(
    panel(page, "Nhân viên B").getByRole("link", { name: /Báo cáo #8/ }),
  ).toHaveAttribute("href", "/reports/8");
  await panel(page, "Nhân viên B")
    .getByText("Đối chiếu nguồn báo cáo", { exact: true })
    .click();
  await expect(
    panel(page, "Nhân viên B").getByRole("link", { name: /Đầu việc #81/ }),
  ).toHaveAttribute("href", "/reports/8#work-item-81");
  await page.getByRole("button", { name: "Tải lại", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Nhân viên B", exact: true }),
  ).toBeVisible();
  expect(posts).toHaveLength(0);
  await page.getByRole("button", { name: "Trang tiếp", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Nhân viên C", exact: true }),
  ).toBeVisible();
  await expect(
    panel(page, "Nhân viên C").getByRole("link", { name: /Báo cáo #9/ }),
  ).toHaveAttribute("href", "/reports/9");
});

test("mobile picker opens one employee without horizontal overflow or an AI request", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const posts = await setup(page);
  await expect(employeeList(page)).toBeHidden();
  await page.getByLabel("Chọn nhân viên", { exact: true }).selectOption("8");
  await expect(
    page.getByRole("heading", { name: "Nhân viên B", exact: true }),
  ).toBeVisible();
  await expect(
    panel(page, "Nhân viên B")
      .getByRole("region", { name: "Tổng hợp từ báo cáo", exact: true })
      .getByRole("listitem"),
  ).toHaveCount(2);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  expect(posts).toHaveLength(0);
});

test("retry and batch actions preserve the selected employee and target only the intended requests", async ({
  page,
}) => {
  const posts = await setup(page);
  await employeeList(page)
    .getByRole("button", { name: /Nhân viên B/ })
    .click();
  await panel(page, "Nhân viên B")
    .getByRole("button", { name: "Thử lại phần lỗi", exact: true })
    .click();
  await expect(
    panel(page, "Nhân viên B").getByRole("button", {
      name: "Thử lại phần lỗi",
      exact: true,
    }),
  ).toHaveCount(0);
  expect(posts).toEqual(["/api/ai/daily-documents/108/retry"]);
  await page
    .getByRole("button", { name: "Tổng hợp toàn công ty", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Nhân viên B", exact: true }),
  ).toBeVisible();
  expect(posts).toEqual([
    "/api/ai/daily-documents/108/retry",
    "/api/ai/daily-documents/batch",
  ]);
});

test("older failed analysis still requires explicit confirmation before rebuilding", async ({
  page,
}) => {
  const posts = await setup(page, { old: true });
  await employeeList(page)
    .getByRole("button", { name: /Nhân viên B/ })
    .click();
  await panel(page, "Nhân viên B")
    .getByRole("button", { name: "Tạo lại bằng bản sửa", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toBeVisible();
  expect(posts).toHaveLength(0);
  await page.getByRole("button", { name: "Hủy", exact: true }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await panel(page, "Nhân viên B")
    .getByRole("button", { name: "Tạo lại bằng bản sửa", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Xác nhận tạo bản mới", exact: true })
    .click();
  await expect(
    panel(page, "Nhân viên B").getByRole("button", {
      name: "Tạo lại bằng bản sửa",
      exact: true,
    }),
  ).toHaveCount(0);
  expect(posts).toEqual(["/api/ai/daily-documents/reports/8"]);
});

test("failed retry retains the selected report, source and error", async ({
  page,
}) => {
  const posts = await setup(page, { failRetry: true });
  await employeeList(page)
    .getByRole("button", { name: /Nhân viên B/ })
    .click();
  await panel(page, "Nhân viên B")
    .getByRole("button", { name: "Thử lại phần lỗi", exact: true })
    .click();
  await expect(page.getByRole("alert")).toContainText(
    "Không thể thử lại lúc này.",
  );
  await expect(
    page.getByRole("heading", { name: "Nhân viên B", exact: true }),
  ).toBeVisible();
  await expect(
    panel(page, "Nhân viên B").getByRole("link", { name: /Báo cáo #8/ }),
  ).toHaveAttribute("href", "/reports/8");
  expect(posts).toEqual(["/api/ai/daily-documents/108/retry"]);
});
