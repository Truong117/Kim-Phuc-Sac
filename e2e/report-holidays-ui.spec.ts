import { expect, test, type Page } from "@playwright/test";
import type { ReportHoliday } from "../src/types/reportHolidays";

const endpoint = "/api/settings/report-holidays";
const initialRows: ReportHoliday[] = [
  {
    id: 1,
    name: "Sinh hoạt nội bộ",
    start_date: "2026-11-30",
    end_date: "2026-11-30",
    note: "Hoạt động toàn công ty",
    is_active: true,
    version: 1,
    reporting_days: 1,
    created_at: "2026-11-25T10:00:00Z",
    updated_at: "2026-11-25T10:00:00Z",
  },
  {
    id: 2,
    name: "Nghỉ cuối năm",
    start_date: "2026-12-30",
    end_date: "2026-12-31",
    note: null,
    is_active: true,
    version: 1,
    reporting_days: 2,
    created_at: "2026-11-25T10:00:00Z",
    updated_at: "2026-11-25T10:00:00Z",
  },
];

async function setup(
  page: Page,
  options: {
    canManage?: boolean;
    failLoad?: boolean;
    staleEdit?: boolean;
    path?: string;
  } = {},
) {
  let rows = structuredClone(initialRows);
  let failing = options.failLoad ?? false;
  let stale = options.staleEdit ?? false;
  const mutations: { method: string; body: Record<string, unknown> }[] = [];
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.clock.setFixedTime(new Date("2026-11-25T10:00:00Z"));
  await page.route("**/api/**", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname === endpoint && request.method() === "GET") {
      return failing
        ? route.fulfill({ status: 503, json: { message: "Unavailable" } })
        : route.fulfill({
            json: {
              data: rows,
              meta: {
                business_date: "2026-11-25",
                working_days: [1, 2, 3, 4, 5, 6],
                can_manage: options.canManage ?? true,
              },
            },
          });
    }
    if (
      url.pathname.startsWith(endpoint) &&
      ["POST", "PATCH"].includes(request.method())
    ) {
      const body = request.postDataJSON();
      mutations.push({ method: request.method(), body });
      if (body.name === "Lỗi máy chủ")
        return route.fulfill({
          status: 422,
          json: {
            message: "Kiểm tra dữ liệu.",
            errors: {
              start_date: ["Khoảng ngày này trùng với lịch mới trên máy chủ."],
            },
          },
        });
      if (stale) {
        stale = false;
        rows[0].version++;
        return route.fulfill({
          status: 409,
          json: {
            message:
              "Lịch nghỉ đã được thay đổi. Hãy tải lại danh sách trước khi lưu.",
          },
        });
      }
      const previous = rows.find(
        (row) => String(row.id) === url.pathname.split("/").pop(),
      );
      const next = {
        ...(previous ?? {
          id: 3,
          note: null,
          is_active: true,
          version: 0,
          created_at: "2026-11-25T10:00:00Z",
        }),
        ...body,
        version: (previous?.version ?? 0) + 1,
        updated_at: "2026-11-25T10:00:00Z",
        reporting_days: body.start_date === body.end_date ? 1 : 2,
      } as ReportHoliday;
      rows = [...rows.filter((row) => row.id !== next.id), next].sort((a, b) =>
        a.start_date.localeCompare(b.start_date),
      );
      return route.fulfill({
        status: previous ? 200 : 201,
        json: { data: next },
      });
    }
    const json =
      url.pathname === "/api/auth/me"
        ? {
            id: 10001,
            name: "Quản trị kiểm thử",
            email: "holidays@example.test",
            role: { name: "Quản trị viên" },
            organization: { id: 1, name: "KPS Test" },
            department: null,
            location: null,
          }
        : url.pathname === "/api/navigation"
          ? { items: ["settings", "reports.new", "reports.history"] }
          : url.pathname === "/api/workspaces"
            ? { workspaces: [{ id: "internal", home: "/", sections: [] }] }
            : url.pathname === "/api/reports/today"
              ? {
                  data: {
                    business_date: "2026-11-25",
                    cutoff_at: "2026-11-25T23:00:00+07:00",
                    is_working_day: false,
                    is_window_closed: true,
                    reporting_required: false,
                    report: null,
                    holiday: {
                      name: "Nghỉ nội bộ",
                      note: "Công ty nghỉ sinh hoạt.",
                    },
                    actions: { can_create: false },
                  },
                }
              : url.pathname === "/api/reports/makeup"
                ? {
                    data: {
                      business_date: "2026-11-25",
                      period_start: "2026-11-23",
                      period_end: "2026-11-28",
                      days: [
                        {
                          date: "2026-11-24",
                          can_submit: true,
                          deadline_at: "2026-11-27T23:59:59+07:00",
                        },
                      ],
                    },
                  }
                : url.pathname === "/api/reports/makeup/state"
                  ? {
                      data: {
                        business_date: "2026-11-24",
                        cutoff_at: "2026-11-27T23:59:59+07:00",
                        is_working_day: true,
                        is_window_closed: false,
                        reporting_required: true,
                        report: null,
                        actions: { can_create: true },
                      },
                    }
                  : {
                      data: [],
                      unread_count: 0,
                      meta: { total: 0, current_page: 1, last_page: 1 },
                    };
    return route.fulfill({ json });
  });
  await page.goto(options.path ?? "/settings");
  return {
    mutations,
    errors,
    recover: () => {
      failing = false;
    },
  };
}

async function selectDate(page: Page, fieldId: string, date: string) {
  await page.locator(`#${fieldId}`).click();
  await page.locator(`button[data-calendar-date="${date}"]`).click();
}

test("owner creates a manual date, then toggles the persisted holiday with its current version", async ({
  page,
}) => {
  const state = await setup(page);
  await page
    .getByRole("button", { name: "Thêm ngày nghỉ", exact: true })
    .click();
  await page
    .getByLabel("Tên ngày nghỉ", { exact: false })
    .fill("Nghỉ nội bộ mới");
  await selectDate(page, "holiday-start", "2026-11-27");
  await page
    .getByRole("button", { name: "Lưu ngày nghỉ", exact: true })
    .click();
  const row = page.getByRole("row").filter({ hasText: "Nghỉ nội bộ mới" });
  await expect(row).toContainText("27/11/2026");
  await expect(row).toContainText("1 ngày");
  expect(state.mutations[0]).toEqual({
    method: "POST",
    body: {
      name: "Nghỉ nội bộ mới",
      start_date: "2026-11-27",
      end_date: "2026-11-27",
      note: null,
    },
  });
  await row.getByRole("switch").click();
  await expect(row).toContainText("Tạm tắt");
  expect(state.mutations[1]).toEqual({
    method: "PATCH",
    body: { is_active: false, expected_version: 1 },
  });
  expect(state.errors).toEqual([]);
});

test("date ranges count workdays and prevent overlaps before sending a request", async ({
  page,
}) => {
  const state = await setup(page);
  await page
    .getByRole("button", { name: "Thêm ngày nghỉ", exact: true })
    .click();
  await page
    .getByLabel("Tên ngày nghỉ", { exact: false })
    .fill("Nghỉ nhiều ngày");
  await page.getByRole("button", { name: "Khoảng ngày", exact: true }).click();
  await selectDate(page, "holiday-start", "2026-11-27");
  await selectDate(page, "holiday-end", "2026-11-30");
  await expect(
    page.getByText(/Tạm dừng báo cáo cho 3 ngày làm việc/),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Lưu ngày nghỉ", exact: true })
    .click();
  await expect(page.getByRole("alert")).toContainText("trùng");
  expect(state.mutations).toHaveLength(0);
  await selectDate(page, "holiday-end", "2026-11-28");
  await page
    .getByRole("button", { name: "Lưu ngày nghỉ", exact: true })
    .click();
  await expect(
    page.getByRole("row").filter({ hasText: "Nghỉ nhiều ngày" }),
  ).toContainText("2 ngày");
});

test("assistant reads the schedule without mutation controls", async ({
  page,
}) => {
  const state = await setup(page, { canManage: false });
  await expect(
    page.getByRole("heading", { name: "Ngày nghỉ & ngày lễ", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Sinh hoạt nội bộ", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Thêm ngày nghỉ", exact: true }),
  ).toHaveCount(0);
  await expect(page.getByRole("switch")).toHaveCount(0);
  expect(state.mutations).toHaveLength(0);
});

test("server validation preserves the form and stale edits refresh instead of overwriting", async ({
  page,
}) => {
  await setup(page);
  await page
    .getByRole("button", { name: "Thêm ngày nghỉ", exact: true })
    .click();
  await page.getByLabel("Tên ngày nghỉ", { exact: false }).fill("Lỗi máy chủ");
  await page
    .getByRole("button", { name: "Lưu ngày nghỉ", exact: true })
    .click();
  await expect(page.getByRole("alert")).toContainText("lịch mới trên máy chủ");
  await expect(page.getByLabel("Tên ngày nghỉ", { exact: false })).toHaveValue(
    "Lỗi máy chủ",
  );

  await setup(page, { staleEdit: true });
  await page
    .getByRole("button", { name: "Sửa Sinh hoạt nội bộ", exact: true })
    .click();
  await page.getByLabel("Tên ngày nghỉ", { exact: false }).fill("Chỉnh sửa cũ");
  await page
    .getByRole("button", { name: "Lưu ngày nghỉ", exact: true })
    .click();
  await expect(
    page.getByRole("status").filter({ hasText: "Lịch nghỉ đã được thay đổi" }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Thêm ngày nghỉ", exact: true }),
  ).toBeEnabled();
  await expect(
    page.getByText("Sinh hoạt nội bộ", { exact: true }),
  ).toBeVisible();
});

test("load failures can be retried without exposing edit actions", async ({
  page,
}) => {
  const state = await setup(page, { failLoad: true });
  await expect(page.getByRole("alert")).toContainText(
    "Không thể tải lịch nghỉ",
  );
  await expect(
    page.getByRole("button", { name: "Thêm ngày nghỉ", exact: true }),
  ).toHaveCount(0);
  state.recover();
  await page.getByRole("button", { name: "Thử lại", exact: true }).click();
  await expect(
    page.getByText("Sinh hoạt nội bộ", { exact: true }),
  ).toBeVisible();
});

test("a holiday closes today's form but the makeup link still opens an eligible previous date", async ({
  page,
}) => {
  const state = await setup(page, { path: "/reports/new" });
  await expect(
    page.getByRole("heading", { name: "Ngày nghỉ: Nghỉ nội bộ", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Công ty nghỉ sinh hoạt.", { exact: true }),
  ).toBeVisible();
  await expect(page.locator("#quick-draft-completed")).toHaveCount(0);
  await page.getByRole("link", { name: "Nộp báo cáo bù", exact: true }).click();
  await expect(page.locator("#quick-draft-completed")).toBeVisible();
  await expect(page.locator("#makeup-report-date")).toHaveValue("2026-11-24");
  expect(state.mutations).toHaveLength(0);
  expect(state.errors).toEqual([]);
});

test("the manual editor fits a mobile screen and preserves the date controls", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await setup(page);
  await page
    .getByRole("button", { name: "Thêm ngày nghỉ", exact: true })
    .click();
  await page.getByRole("button", { name: "Khoảng ngày", exact: true }).click();
  await expect(page.locator("#holiday-start")).toBeVisible();
  await expect(page.locator("#holiday-end")).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth > window.innerWidth,
    ),
  ).toBe(false);
});
