import { test, expect, type Page } from "@playwright/test";

async function setup(
  page: Page,
  options: {
    care?: boolean;
    forbidden?: boolean;
    conflict?: boolean;
    pages?: boolean;
  } = {},
) {
  await page.clock.install({ time: new Date("2026-11-13T08:00:00+07:00") });
  let imported = false;
  const submissions: Record<string, unknown>[] = [];
  const task = () => ({
    id: 51,
    title: options.care ? "Chăm sóc 80 khách/tháng" : "Sản xuất 5 video",
    description: "Công việc được giao trong tháng",
    status: "IN_PROGRESS",
    priority: "MEDIUM",
    due_date: "2026-11-30",
    start_date: "2026-11-01",
    is_overdue: false,
    completed_at: null,
    created_at: "2026-11-01T00:00:00Z",
    updated_at: "2026-11-13T00:00:00Z",
    creator: { id: 1, name: "Admin" },
    assignee: { id: 7, name: "Nhân viên" },
    department: null,
    location: null,
    actions: { can_manage: false, can_update_status: true },
    target_quantity: options.care ? 80 : 5,
    quantity_unit: options.care ? "khách hàng" : "video",
    progress: {
      revision: imported ? 4 : 3,
      target: options.care ? 80 : 5,
      unit: options.care ? "khách hàng" : "video",
      completed: imported ? 2 : 0,
      counts: {},
      percent: imported ? (options.care ? 2.5 : 40) : 0,
      outputs: [],
    },
  });
  await page.route("**/api/**", async (route) => {
    const url = new URL(route.request().url()),
      path = url.pathname;
    if (path === "/api/tasks/51/progress/reconciliation") {
      if (route.request().method() === "POST") {
        submissions.push(route.request().postDataJSON());
        if (options.conflict)
          return route.fulfill({
            status: 422,
            json: {
              message: "Dữ liệu thay đổi",
              errors: {
                expected_revision: [
                  "Công việc đã thay đổi. Tải lại tiến độ và kiểm tra trước khi xác nhận.",
                ],
              },
            },
          });
        imported = true;
        return route.fulfill({
          json: {
            data: {
              created: 2,
              imported_sources:
                submissions[0].entries instanceof Array
                  ? submissions[0].entries.length
                  : 1,
              progress: task().progress,
            },
          },
        });
      }
      if (options.forbidden)
        return route.fulfill({ status: 403, json: { message: "Forbidden" } });
      const second = url.searchParams.get("page") === "2";
      return route.fulfill({
        json: {
          data: [
            {
              report_item_id: second ? 202 : 201,
              report_id: 100,
              report_date: second ? "2026-11-11" : "2026-11-02",
              content: options.care
                ? "Chăm sóc khách hàng qua Facebook và Zalo"
                : "Hoàn thiện video ABC và DEF",
              result: options.care
                ? "Đã chăm sóc hai khách"
                : "Đã hoàn thành video “ABC” và video “DEF”",
              source_hash: "a".repeat(64),
              evidence_links: ["https://example.test/proof"],
              already_recorded: imported,
              linked_elsewhere: false,
              suggested_outputs: options.care
                ? []
                : [
                    { name: "ABC", completed: true },
                    { name: "DEF", completed: true },
                  ],
            },
          ],
          meta: {
            current_page: second ? 2 : 1,
            last_page: options.pages ? 2 : 1,
          },
          expected_revision: imported ? 4 : 3,
        },
      });
    }
    const json =
      path === "/api/workspaces"
        ? { workspaces: [{ id: "internal", home: "/", sections: [] }] }
        : path === "/api/auth/me"
          ? {
              id: 7,
              name: "Nhân viên",
              email: "staff@example.test",
              role: { name: "Nhân viên" },
              department: null,
              organization: { id: 1, name: "KPS" },
              location: null,
            }
          : path === "/api/navigation"
            ? { items: ["tasks", "reports.new", "reports.history"] }
            : path === "/api/tasks/51"
              ? { data: task() }
              : path === "/api/tasks/references"
                ? {
                    data: {
                      actions: { can_create: false },
                      employees: { visible: false, options: [] },
                      departments: { visible: false, options: [] },
                      locations: { visible: false, options: [] },
                      assignable_employees: [],
                    },
                  }
                : {
                    data: [],
                    unread_count: 0,
                    meta: { total: 0, current_page: 1, last_page: 1 },
                  };
    await route.fulfill({ json });
  });
  await page.goto("/tasks/51");
  await page
    .getByRole("button", { name: "Chọn báo cáo cũ để đối soát", exact: true })
    .click();
  return submissions;
}
async function selectSource(page: Page, id = 201) {
  await page
    .getByRole("checkbox", { name: `Chọn đầu việc #${id}`, exact: true })
    .check();
}
async function confirm(page: Page) {
  await page
    .locator("#reconcile-note")
    .fill("Đã đối chiếu danh sách với báo cáo nguồn");
  await page
    .getByRole("checkbox", { name: /Tôi đã đối chiếu đúng công việc/ })
    .check();
}

test("reviewing historical videos updates progress and retains report/date context", async ({
  page,
}) => {
  const submissions = await setup(page);
  await selectSource(page);
  await expect(
    page.getByRole("textbox", { name: "Tên sản phẩm 1", exact: true }),
  ).toHaveValue("ABC");
  await expect(
    page.getByText(/không tính thành kết quả mới hôm nay/i),
  ).toBeVisible();
  await confirm(page);
  await page
    .getByRole("button", { name: "Xác nhận ghi nhận kết quả cũ", exact: true })
    .click();
  await expect.poll(() => submissions.length).toBe(1);
  const entries = submissions[0].entries as {
    report_item_id: number;
    outputs: { name: string }[];
  }[];
  expect(entries[0].report_item_id).toBe(201);
  expect(entries[0].outputs.map((output) => output.name)).toEqual([
    "ABC",
    "DEF",
  ]);
  await expect(page.getByRole("progressbar")).toHaveAttribute(
    "aria-valuenow",
    "40",
  );
  await expect(
    page.getByRole("checkbox", { name: "Chọn đầu việc #201", exact: true }),
  ).toBeDisabled();
});
test("customer reconciliation requires distinct customer keys even if display names are identical", async ({
  page,
}) => {
  const submissions = await setup(page, { care: true });
  await selectSource(page);
  await page
    .getByRole("button", { name: "+ Thêm khách / lượt chăm sóc", exact: true })
    .click();
  await page
    .getByRole("textbox", {
      name: "Khách hàng / nội dung chăm sóc 1",
      exact: true,
    })
    .fill("Chị An");
  await confirm(page);
  await page
    .getByRole("button", { name: "Xác nhận ghi nhận kết quả cũ", exact: true })
    .click();
  expect(submissions).toHaveLength(0);
  await expect(page.getByRole("alert")).toContainText("mã cần thiết");
  await page
    .getByRole("textbox", { name: "Mã khách hàng 1", exact: true })
    .fill("KH001");
  await page
    .getByRole("button", { name: "+ Thêm khách / lượt chăm sóc", exact: true })
    .click();
  await page
    .getByRole("textbox", {
      name: "Khách hàng / nội dung chăm sóc 2",
      exact: true,
    })
    .fill("Chị An");
  await page
    .getByRole("textbox", { name: "Mã khách hàng 2", exact: true })
    .fill("KH002");
  await confirm(page);
  await page
    .getByRole("button", { name: "Xác nhận ghi nhận kết quả cũ", exact: true })
    .click();
  await expect.poll(() => submissions.length).toBe(1);
  const entries = submissions[0].entries as {
    outputs: { reference: string }[];
  }[];
  expect(entries[0].outputs.map((row) => row.reference)).toEqual([
    "KH001",
    "KH002",
  ]);
});
test("stale task revisions keep selections and confirmation text for review", async ({
  page,
}) => {
  const submissions = await setup(page, { conflict: true });
  await selectSource(page);
  await confirm(page);
  await page
    .getByRole("button", { name: "Xác nhận ghi nhận kết quả cũ", exact: true })
    .click();
  await expect.poll(() => submissions.length).toBe(1);
  await expect(page.getByRole("alert")).toContainText("Công việc đã thay đổi");
  await expect(
    page.getByRole("textbox", { name: "Tên sản phẩm 1", exact: true }),
  ).toHaveValue("ABC");
  await expect(page.locator("#reconcile-note")).toHaveValue(
    "Đã đối chiếu danh sách với báo cáo nguồn",
  );
});
test("forbidden historical source access shows no private report content", async ({
  page,
}) => {
  await setup(page, { forbidden: true });
  await expect(page.getByRole("alert")).toContainText("không có quyền");
  await expect(
    page.getByText("Đã hoàn thành video “ABC” và video “DEF”", { exact: true }),
  ).toHaveCount(0);
});
test("selected sources remain selected across source pagination", async ({
  page,
}) => {
  const submissions = await setup(page, { pages: true });
  await selectSource(page);
  await page.getByRole("button", { name: "Trang sau", exact: true }).click();
  await selectSource(page, 202);
  await confirm(page);
  await page
    .getByRole("button", { name: "Xác nhận ghi nhận kết quả cũ", exact: true })
    .click();
  await expect.poll(() => submissions.length).toBe(1);
  expect(submissions[0].entries).toHaveLength(2);
});
test("historical reconciliation fits mobile and dark mode", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await setup(page, { care: true });
  await selectSource(page);
  await page
    .getByRole("button", { name: "+ Thêm khách / lượt chăm sóc", exact: true })
    .click();
  await page.evaluate(() => document.documentElement.classList.add("dark"));
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  await expect(
    page.getByRole("textbox", { name: "Mã khách hàng 1", exact: true }),
  ).toBeVisible();
});
