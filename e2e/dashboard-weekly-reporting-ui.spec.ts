import { expect, test, type Page } from "@playwright/test";
import type {
  DashboardData,
  DashboardWeeklyReporting,
  WeeklyReportingPerson,
} from "../src/types/dashboard";

const dates = Array.from({ length: 7 }, (_, index) => `2026-11-${23 + index}`);
const work = { total: 0, completed: 0, in_progress: 0, blocked: 0 };

function summary(weekStart = dates[0]): DashboardWeeklyReporting {
  const rows: WeeklyReportingPerson[] = Array.from(
    { length: 12 },
    (_, index) => ({
      employee: {
        id: index + 1,
        name: index === 0 ? "Đỗ Trường" : `Nhân viên ${index + 1}`,
      },
      department: {
        id: (index % 2) + 1,
        name: index % 2 ? "Kinh doanh" : "Marketing",
      },
      submitted: index % 2 ? 2 : 1,
      expected: 6,
      missing_dates: index % 2 ? [] : [dates[1]],
      days: dates.map((date, day) => ({
        date,
        status:
          day === 0 || (day === 1 && index % 2)
            ? "submitted"
            : day === 1
              ? "missing"
              : day === 2
                ? "pending"
                : day === 6
                  ? "non_working_day"
                  : "upcoming",
        report_id: day === 0 && index === 0 ? 71 : null,
      })),
    }),
  );
  return {
    week_start: weekStart,
    week_end: weekStart === dates[0] ? dates[6] : "2026-11-22",
    current_week_start: dates[0],
    business_date: dates[2],
    dates: dates.map((date, index) => ({
      date,
      is_working_day: index < 6,
      is_due: index < 2,
    })),
    totals: { people: 12, submitted: 18, people_missing: 6, missing_days: 6 },
    rows,
  };
}

async function setup(
  page: Page,
  mode: DashboardData["mode"] = "organization",
  failOnce = false,
) {
  let reads = 0;
  let failing = failOnce;
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.route("**/api/**", async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === "/api/dashboard/weekly-reporting") {
      reads++;
      if (failing)
        return route.fulfill({ status: 503, json: { message: "Unavailable" } });
      return route.fulfill({
        json: {
          data: summary(url.searchParams.get("week_start") ?? undefined),
        },
      });
    }
    const json =
      url.pathname === "/api/auth/me"
        ? {
            id: 10001,
            name: "Quản lý kiểm thử",
            email: "dashboard@example.test",
            role: { name: "Quản lý" },
            organization: { id: 1, name: "KPS Test" },
            department: null,
            location: null,
          }
        : url.pathname === "/api/navigation"
          ? { items: ["dashboard", "reports.history"] }
          : url.pathname === "/api/workspaces"
            ? {
                workspaces: [{ id: "internal", home: "/", sections: [] }],
              }
            : url.pathname === "/api/dashboard"
              ? {
                  data: {
                    mode,
                    business_date: dates[2],
                    cutoff_at: "2026-11-25T23:00:00+07:00",
                    is_working_day: true,
                    context: null,
                    reporting:
                      mode === "personal"
                        ? null
                        : { expected: 12, submitted: 0, missing: 12 },
                    work,
                    self_report: null,
                    group_breakdown: [],
                    people_progress: [],
                    attention_items: [],
                    recent_reports: [],
                  },
                }
              : { data: [], unread_count: 0 };
    return route.fulfill({ json });
  });
  await page.goto("/dashboard");
  return {
    reads: () => reads,
    errors,
    recover: () => {
      failing = false;
    },
  };
}

test("weekly overview filters names and departments, paginates, and preserves report permissions", async ({
  page,
}) => {
  const state = await setup(page);
  await expect(
    page.getByRole("heading", { name: "Báo cáo trong tuần" }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Tuần sau", exact: true }),
  ).toBeDisabled();
  const table = page.getByRole("table").first();
  await expect(table.getByRole("row")).toHaveCount(11);
  await expect(table.getByRole("link")).toHaveCount(1);
  await expect(table.getByRole("link")).toHaveAttribute("href", "/reports/71");
  await page.getByRole("button", { name: "Trang sau", exact: true }).click();
  await expect(table.getByRole("row")).toHaveCount(3);
  await page
    .getByRole("searchbox", { name: "Nhân viên", exact: true })
    .fill("do truong");
  await expect(table.getByRole("row")).toHaveCount(2);
  await expect(table.getByText("Đỗ Trường", { exact: true })).toBeVisible();
  await page.getByRole("searchbox").fill("");
  await page.getByRole("checkbox", { name: "Chỉ xem người còn thiếu" }).check();
  await expect(table.getByRole("row")).toHaveCount(7);
  await page
    .getByRole("combobox", { name: "Phòng ban", exact: true })
    .selectOption("2");
  await expect(
    table.getByText("Không có nhân viên phù hợp với bộ lọc."),
  ).toBeVisible();
  expect(state.errors).toEqual([]);
});

test("weeks can be changed and refreshed without leaving the overview", async ({
  page,
}) => {
  await setup(page);
  await expect(
    page.getByText("23/11/2026 – 29/11/2026", { exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Tuần trước", exact: true }).click();
  await expect(
    page.getByText("16/11/2026 – 22/11/2026", { exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Tuần sau", exact: true }).click();
  await expect(
    page.getByText("23/11/2026 – 29/11/2026", { exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Tuần này", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Tuần sau", exact: true }),
  ).toBeDisabled();
});

test("a weekly request error can be retried and does not remove the rest of the dashboard", async ({
  page,
}) => {
  const state = await setup(page, "organization", true);
  await expect(page.getByRole("alert")).toContainText(
    "Không thể tải thống kê báo cáo tuần",
  );
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
  state.recover();
  await page.getByRole("button", { name: "Thử lại", exact: true }).click();
  await expect(page.getByRole("searchbox")).toBeVisible();
  await expect(page.getByRole("alert")).toHaveCount(0);
});

test("personal dashboards never request the organization weekly summary", async ({
  page,
}) => {
  const state = await setup(page, "personal");
  await expect(
    page.getByRole("heading", { name: "Tổng quan cá nhân", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Báo cáo trong tuần" }),
  ).toHaveCount(0);
  expect(state.reads()).toBe(0);
});

test("mobile keeps the weekly grid inside a horizontally scrollable table", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await setup(page);
  await expect(page.getByRole("searchbox")).toBeVisible();
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth > window.innerWidth,
  );
  expect(overflow).toBe(false);
  const scrollable = page.getByRole("table").first().locator("..");
  expect(
    await scrollable.evaluate(
      (element) => element.scrollWidth > element.clientWidth,
    ),
  ).toBe(true);
});
