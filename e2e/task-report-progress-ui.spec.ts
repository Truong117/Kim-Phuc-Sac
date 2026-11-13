import { test, expect, type Page } from "@playwright/test";

async function setup(
  page: Page,
  options: {
    makeup?: boolean;
    schema?: boolean;
    unit?: "video" | "khách hàng" | "lượt chăm sóc";
  } = {},
) {
  const task = {
    id: 51,
    title: "Sản xuất 5 video",
    description: "Bàn giao video hoàn thiện",
    status: "IN_PROGRESS",
    priority: "MEDIUM",
    due_date: "2026-11-14",
    start_date: "2026-11-09",
    is_overdue: false,
    completed_at: null,
    creator: { id: 1, name: "Admin" },
    assignee: { id: 7, name: "Nhân viên" },
    department: null,
    location: null,
    actions: { can_manage: false, can_update_status: true },
    target_quantity: 5,
    quantity_unit: options.unit ?? "video",
    progress: {
      revision: 3,
      target: 5,
      unit: options.unit ?? "video",
      completed: 0,
      counts: {},
      percent: 0,
      outputs: [],
    },
  };
  const date = options.makeup ? "2026-11-11" : "2026-11-12";
  const submissions: Record<string, unknown>[] = [];
  await page.route("**/api/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (
      route.request().method() === "POST" &&
      ["/api/reports", "/api/reports/makeup"].includes(path)
    ) {
      submissions.push(route.request().postDataJSON());
      return route.fulfill({
        status: 422,
        json: {
          message: "Công việc đã thay đổi",
          errors: {
            "items.0.task_progress": [
              "Tải lại danh sách công việc, kiểm tra rồi áp dụng lại",
            ],
          },
        },
      });
    }
    if (path.endsWith("/care-customers"))
      return route.fulfill({
        json: {
          data: [
            {
              id: 701,
              name: "Khách A",
              code: "KH-701",
              contacts: [{ channel: "Zalo", value: "0901234567" }],
              task_output_id: null,
              counted: false,
            },
            {
              id: 702,
              name: "Khách B",
              code: "KH-702",
              contacts: [
                {
                  channel: "Messenger",
                  value: "https://facebook.com/customer.b",
                },
              ],
              task_output_id: null,
              counted: false,
            },
          ],
          meta: { current_page: 1, last_page: 1 },
        },
      });
    const json =
      path === "/api/workspaces"
        ? { workspaces: [{ id: "internal", home: "/", sections: [] }] }
        : path === "/api/auth/me"
          ? {
              id: 7,
              name: "Nhân viên",
              email: "report@example.test",
              role: { name: "Nhân viên" },
              department: null,
              organization: { id: 1, name: "KPS" },
              location: null,
            }
          : path === "/api/navigation"
            ? { items: ["reports.new", "reports.history", "tasks"] }
            : path === "/api/reports/today" ||
                path === "/api/reports/makeup/state"
              ? {
                  data: {
                    business_date: date,
                    cutoff_at: "2099-11-12T23:00:00+07:00",
                    is_working_day: true,
                    is_window_closed: false,
                    reporting_required: true,
                    report: null,
                    actions: { can_create: true },
                  },
                }
              : path === "/api/reports/makeup"
                ? {
                    data: {
                      period_start: "2026-11-09",
                      period_end: "2026-11-14",
                      days: [
                        {
                          date,
                          can_submit: true,
                          deadline_at: "2099-11-14T23:59:59+07:00",
                        },
                      ],
                    },
                  }
                : path === "/api/reports/assigned-tasks"
                  ? {
                      data: options.schema === false ? [] : [task],
                      schema_ready: options.schema !== false,
                      can_sync: !options.makeup,
                      meta: { current_page: 1, last_page: 1 },
                    }
                  : path === "/api/tasks/51"
                    ? { data: task }
                    : {
                        data: [],
                        unread_count: 0,
                        meta: { total: 0, current_page: 1, last_page: 1 },
                      };
    await route.fulfill({ json });
  });
  await page.goto(options.makeup ? "/reports/makeup/new" : "/reports/new");
  await expect(page.locator("#quick-draft-completed")).toBeVisible();
  return submissions;
}
async function fillUpdate(page: Page) {
  await page
    .getByRole("button", { name: "Cập nhật tiến độ", exact: true })
    .click();
  await page
    .locator("#task-report-result-51")
    .fill("Đã hoàn thành video “ABC” và video “DEF”");
  await page.locator("#task-report-status-51").selectOption("COMPLETED");
  await page
    .locator('textarea[id^="evidence-"]')
    .fill("https://example.test/videos");
  await page.getByRole("button", { name: /Gợi ý tên video/ }).click();
  await expect(
    page.getByRole("textbox", { name: "Tên sản phẩm 1", exact: true }),
  ).toHaveValue("ABC");
}

test("apply creates one linked preview, keeps manual input, and submits products with KEEP even on a server conflict", async ({
  page,
}) => {
  const submissions = await setup(page);
  await page
    .locator("#quick-draft-completed")
    .fill("Công việc nhập tay → Đã xong");
  await fillUpdate(page);
  await page
    .getByRole("button", { name: "Áp dụng vào báo cáo", exact: true })
    .click();
  await expect(page.locator("#linked-task-51")).toHaveCount(1);
  await expect(page.getByRole("progressbar")).toHaveAttribute(
    "aria-valuenow",
    "40",
  );
  await expect(page.locator("#quick-draft-completed")).toHaveValue(
    "Công việc nhập tay → Đã xong",
  );
  await page.locator("#quick-draft-completed").fill("");
  await page.locator("#next-day-plan").fill("Tiếp tục video còn lại");
  await page.locator('form button[type="submit"]').click();
  await expect.poll(() => submissions.length).toBe(1);
  const items = submissions[0].items as {
    task_id: number;
    task_progress: { status: string; outputs: unknown[] };
  }[];
  expect(items).toHaveLength(1);
  expect(items[0].task_id).toBe(51);
  expect(items[0].task_progress.status).toBe("KEEP");
  expect(items[0].task_progress.outputs).toHaveLength(2);
  await expect(page.locator("#linked-task-51")).toContainText("ABC");
  await expect(page.locator("#next-day-plan")).toHaveValue(
    "Tiếp tục video còn lại",
  );
});
test("editing an applied update replaces its preview instead of adding another report item", async ({
  page,
}) => {
  await setup(page);
  await fillUpdate(page);
  await page
    .getByRole("button", { name: "Áp dụng vào báo cáo", exact: true })
    .click();
  await page
    .locator("#linked-task-51")
    .getByRole("button", { name: /Sửa/ })
    .click();
  await page
    .locator("#task-report-result-51")
    .fill("Đã chỉnh nội dung video ABC và DEF");
  await page
    .getByRole("button", { name: "Áp dụng vào báo cáo", exact: true })
    .click();
  await expect(page.locator("#linked-task-51")).toHaveCount(1);
  await expect(page.locator("#linked-task-51")).toContainText(
    "Đã chỉnh nội dung",
  );
  await expect(page.getByRole("progressbar")).toHaveAttribute(
    "aria-valuenow",
    "40",
  );
});
test("unapplied inline work blocks final save and is recoverable after reload", async ({
  page,
}) => {
  const submissions = await setup(page);
  await fillUpdate(page);
  await page.locator("#next-day-plan").fill("Làm phần tiếp theo");
  await page.locator('form button[type="submit"]').click();
  expect(submissions).toHaveLength(0);
  await expect(page.getByText(/chưa áp dụng/i)).toBeVisible();
  await expect
    .poll(() =>
      page.evaluate(() =>
        Object.values(localStorage).some((v) => v.includes("“ABC”")),
      ),
    )
    .toBe(true);
  await page.reload();
  await page.getByRole("button", { name: /Khôi phục bản nháp/ }).click();
  await expect(page.locator("#task-report-result-51")).toHaveValue(
    "Đã hoàn thành video “ABC” và video “DEF”",
  );
});
test("makeup preserves the report update but disables live whole-task status changes", async ({
  page,
}) => {
  await setup(page, { makeup: true });
  await page
    .getByRole("button", { name: "Cập nhật tiến độ", exact: true })
    .click();
  await expect(page.locator("#task-report-whole-51")).toBeDisabled();
  await expect(page.locator("#task-report-whole-51")).toHaveValue("KEEP");
});
test("before the migration the existing manual daily form stays usable", async ({
  page,
}) => {
  await setup(page, { schema: false });
  await expect(
    page.getByRole("button", { name: "Cập nhật tiến độ", exact: true }),
  ).toHaveCount(0);
  await page.locator("#quick-draft-completed").fill("Công việc nhập tay");
  await expect(page.locator("#quick-draft-completed")).toHaveValue(
    "Công việc nhập tay",
  );
});
test("inline products fit a mobile viewport and dark mode", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await setup(page);
  await fillUpdate(page);
  await page.evaluate(() => document.documentElement.classList.add("dark"));
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  await expect(page.locator("#task-report-result-51")).toBeVisible();
});

test("a restored update remains readable and cancellable when task access was revoked", async ({
  page,
}) => {
  await setup(page);
  await fillUpdate(page);
  await expect
    .poll(() =>
      page.evaluate(() =>
        Object.values(localStorage).some((value) => value.includes("“ABC”")),
      ),
    )
    .toBe(true);
  await page.route("**/api/navigation", (route) =>
    route.fulfill({ json: { items: ["reports.new", "reports.history"] } }),
  );
  await page.route("**/api/reports/assigned-tasks?**", (route) =>
    route.fulfill({ status: 403, json: { message: "Forbidden" } }),
  );
  await page.route("**/api/tasks/51", (route) =>
    route.fulfill({ status: 403, json: { message: "Forbidden" } }),
  );
  await page.reload();
  await page
    .getByRole("button", { name: "Khôi phục bản nháp", exact: true })
    .click();
  await expect(
    page.getByText("Đã hoàn thành video “ABC” và video “DEF”", { exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Hủy thay đổi", exact: true }).click();
  await expect(
    page.getByText("Đã hoàn thành video “ABC” và video “DEF”", { exact: true }),
  ).toHaveCount(0);
  await expect(page.locator("#quick-draft-completed")).toBeVisible();
});

test("daily customer form saves individual activities and canonical identities without manual reference codes", async ({
  page,
}) => {
  const submissions = await setup(page, { unit: "khách hàng" });
  await page
    .getByRole("button", { name: "Cập nhật tiến độ", exact: true })
    .click();
  await expect(page.locator("#task-report-result-51")).toHaveCount(0);
  await page.getByLabel("Số lượng khách", { exact: true }).fill("2");
  await page
    .getByRole("button", { name: "Cập nhật danh sách", exact: true })
    .click();
  const selects = page.locator('select[id^="care-51-"][id$="-customer"]');
  await selects.nth(0).selectOption("701");
  await selects.nth(1).selectOption("702");
  await page
    .getByLabel("Dịch vụ / việc hỗ trợ", { exact: true })
    .nth(0)
    .fill("Hướng dẫn chăm sóc da tại nhà");
  await page
    .getByRole("textbox", { name: "Kết quả / hẹn theo dõi", exact: true })
    .nth(0)
    .fill("Đã hướng dẫn quy trình");
  await page
    .getByLabel("Dịch vụ / việc hỗ trợ", { exact: true })
    .nth(1)
    .fill("Tư vấn sản phẩm");
  await page
    .getByRole("textbox", { name: "Kết quả / hẹn theo dõi", exact: true })
    .nth(1)
    .fill("Khách theo dõi thêm");
  await page
    .getByRole("button", { name: "Áp dụng vào báo cáo", exact: true })
    .click();
  await expect(page.getByRole("progressbar")).toHaveAttribute(
    "aria-valuenow",
    "40",
  );
  await expect(page.locator("#linked-task-51")).toContainText(
    "Khách B — Tư vấn sản phẩm: Khách theo dõi thêm",
  );
  await page.locator("#next-day-plan").fill("Theo dõi phản hồi");
  await page.locator('form button[type="submit"]').click();
  await expect.poll(() => submissions.length).toBe(1);
  const items = submissions[0].items as {
    task_progress: {
      unit: string;
      care_entries: {
        customer_id: number;
        activities: { service: string; result: string }[];
      }[];
    };
  }[];
  expect(items[0].task_progress.unit).toBe("khách hàng");
  expect(items[0].task_progress.care_entries.map((e) => e.customer_id)).toEqual(
    [701, 702],
  );
  expect(items[0].task_progress.care_entries[1].activities[0].service).toBe(
    "Tư vấn sản phẩm",
  );
});

test("customer count reduction confirms before discarding entered rows and blocks duplicate customers", async ({
  page,
}) => {
  await setup(page, { unit: "khách hàng" });
  await page
    .getByRole("button", { name: "Cập nhật tiến độ", exact: true })
    .click();
  await page.getByLabel("Số lượng khách", { exact: true }).fill("2");
  await page
    .getByRole("button", { name: "Cập nhật danh sách", exact: true })
    .click();
  const selects = page.locator('select[id^="care-51-"][id$="-customer"]');
  await selects.nth(0).selectOption("701");
  await selects.nth(1).selectOption("701");
  await expect(
    page.getByText("Có khách bị chọn trùng.", { exact: false }),
  ).toBeVisible();
  await page.getByLabel("Số lượng khách", { exact: true }).fill("1");
  await page
    .getByRole("button", { name: "Cập nhật danh sách", exact: true })
    .click();
  await expect(selects).toHaveCount(2);
  await page
    .getByRole("button", { name: "Giữ danh sách", exact: true })
    .click();
  await expect(page.getByLabel("Số lượng khách", { exact: true })).toHaveValue(
    "2",
  );
  await page.getByLabel("Số lượng khách", { exact: true }).fill("1");
  await page
    .getByRole("button", { name: "Cập nhật danh sách", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Bỏ các dòng cuối", exact: true })
    .click();
  await expect(selects).toHaveCount(1);
});

test("quick-create customer accepts contact entry and multiple activities inside the daily report", async ({
  page,
}) => {
  await setup(page, { unit: "khách hàng" });
  await page
    .getByRole("button", { name: "Cập nhật tiến độ", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Áp dụng vào báo cáo", exact: true })
    .click();
  await expect(
    page.locator("[data-report-error-summary]").first(),
  ).toBeFocused();
  await page
    .locator('select[id^="care-51-"][id$="-customer"]')
    .selectOption("new");
  await page
    .getByRole("textbox", { name: "Tên khách hàng", exact: true })
    .fill("Chị An");
  await page
    .getByRole("textbox", {
      name: "Số điện thoại / liên kết hồ sơ (không bắt buộc)",
      exact: true,
    })
    .fill("0901234567");
  await page
    .getByLabel("Dịch vụ / việc hỗ trợ", { exact: true })
    .fill("Hướng dẫn chăm sóc da");
  await page
    .getByRole("textbox", { name: "Kết quả / hẹn theo dõi", exact: true })
    .fill("Đã hướng dẫn");
  await page
    .getByRole("button", {
      name: "+ Thêm việc hỗ trợ cho khách này",
      exact: true,
    })
    .click();
  await page
    .getByLabel("Dịch vụ / việc hỗ trợ", { exact: true })
    .nth(1)
    .fill("Tư vấn sản phẩm");
  await page
    .getByRole("textbox", { name: "Kết quả / hẹn theo dõi", exact: true })
    .nth(1)
    .fill("Hẹn phản hồi");
  await page
    .getByRole("button", { name: "Áp dụng vào báo cáo", exact: true })
    .click();
  await expect(page.getByRole("progressbar")).toHaveAttribute(
    "aria-valuenow",
    "20",
  );
  await expect(page.locator("#linked-task-51")).toContainText(
    "Chị An — Hướng dẫn chăm sóc da: Đã hướng dẫn; Tư vấn sản phẩm: Hẹn phản hồi",
  );
});
