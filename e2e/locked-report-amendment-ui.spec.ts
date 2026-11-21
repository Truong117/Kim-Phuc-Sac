import { expect, test, type Page } from "@playwright/test";
import type {
  DailyReport,
  LockedReportAmendmentInput,
} from "../src/types/reports";

const openName = "Hiệu chỉnh báo cáo đã khóa";
const saveName = "Lưu hiệu chỉnh";

function sampleReport(id: number, own = false): DailyReport {
  return {
    id,
    report_date: "2026-11-18",
    locked_at: "2026-11-18T23:00:00+07:00",
    is_locked: true,
    is_makeup: !own,
    overall_status: "COMPLETED",
    next_day_plan: "Kế hoạch ban đầu",
    employee: {
      id: own ? 7 : 8,
      name: own ? "Admin kiểm thử" : "Nhân viên gốc",
    },
    department: null,
    location: null,
    items: [
      {
        id: 11,
        content: "Việc nhập tay",
        result: "Kết quả ban đầu",
        status: "COMPLETED",
        note: null,
        sort_order: 0,
      },
      {
        id: 12,
        content: "Mục được bảo vệ",
        result: "Không thay đổi",
        status: "COMPLETED",
        note: null,
        sort_order: 1,
      },
    ],
    comments: [],
    comment_count: 0,
    unread_comment_count: 0,
    actions: { can_edit: false, can_comment: true },
    locked_amendment: {
      allowed: true,
      expected_revision: "original-revision",
      reason_required: !own,
      editable_item_ids: [11],
      can_add_items: true,
      deletable_item_ids: [11],
      can_edit_next_day_plan: own,
      history: [],
      ai_may_be_stale: false,
    },
    created_at: "2026-11-18T20:00:00+07:00",
    updated_at: "2026-11-18T20:00:00+07:00",
  };
}

async function setup(page: Page, own = false) {
  const recorded: { path: string; body: LockedReportAmendmentInput }[] = [];
  const initial = sampleReport(41, own);
  let report = initial;
  let stale = false;
  await page.route("**/api/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (
      path === "/api/reports/41/locked-amendment" &&
      route.request().method() === "PATCH"
    ) {
      const body = route.request().postDataJSON() as LockedReportAmendmentInput;
      recorded.push({ path, body });
      if (stale) {
        stale = false;
        report = {
          ...report,
          items: report.items.map((item) =>
            item.id === 11
              ? { ...item, result: "Kết quả từ người khác" }
              : item,
          ),
          locked_amendment: {
            ...report.locked_amendment!,
            expected_revision: "newer-revision",
          },
        };
        return route.fulfill({
          status: 409,
          json: { code: "LOCKED_AMENDMENT_STALE", message: "Stale revision" },
        });
      }
      report = {
        ...report,
        next_day_plan: body.next_day_plan ?? report.next_day_plan,
        items: [
          ...report.items
            .filter(({ id }) => !body.deleted_item_ids?.includes(id))
            .map((item) => {
              const fields = body.items?.find(({ id }) => id === item.id);
              return {
                ...item,
                ...fields,
                issue:
                  fields?.issue === null ? "" : (fields?.issue ?? item.issue),
              };
            }),
          ...(body.added_items ?? []).map((item, index) => ({
            ...item,
            id: 100 + index,
            result: item.result ?? "",
            note: item.note ?? null,
            sort_order: 2 + index,
          })),
        ],
        locked_amendment: {
          ...report.locked_amendment!,
          expected_revision: "saved-revision",
          ai_may_be_stale: !own,
          history: own
            ? []
            : [
                {
                  id: 1,
                  editor_name: "Trợ lý kiểm thử",
                  reason: body.reason!,
                  created_at: "2026-11-21T08:00:00+07:00",
                },
              ],
        },
      };
      return route.fulfill({ json: { data: report } });
    }
    const older = sampleReport(42);
    const json =
      path === "/api/workspaces"
        ? { workspaces: [{ id: "internal", home: "/", sections: [] }] }
        : path === "/api/auth/me"
          ? {
              id: 7,
              name: own ? "Admin kiểm thử" : "Trợ lý kiểm thử",
              email: "amendment@example.test",
              role: { name: own ? "Admin" : "Trợ lý" },
              organization: { id: 1, name: "KPS" },
              department: null,
              location: null,
            }
          : path === "/api/navigation"
            ? { items: ["reports.history"] }
            : path === "/api/reports/41"
              ? { data: report }
              : path === "/api/reports/42"
                ? {
                    data: {
                      ...older,
                      locked_amendment: {
                        ...older.locked_amendment!,
                        allowed: false,
                        expected_revision: null,
                      },
                    },
                  }
                : { data: [], unread_count: 0 };
    await route.fulfill({ json });
  });
  return {
    recorded,
    conflictNext: () => {
      stale = true;
    },
  };
}

test("amends an existing locked peer report with a required reason and visible history", async ({
  page,
}) => {
  const api = await setup(page);
  await page.goto("/reports/41");
  await page.getByRole("button", { name: openName }).click();
  const dialog = page.getByRole("dialog", { name: openName });
  await expect(dialog).toContainText("Nhân viên gốc");
  await expect(dialog.getByText("Công việc #2")).toHaveCount(0);
  await expect(dialog.getByLabel("Kế hoạch ngày tiếp theo")).toHaveCount(0);
  await dialog
    .getByLabel("Kết quả", { exact: true })
    .fill("Sửa lỗi nhập kết quả");
  await dialog.getByRole("button", { name: saveName }).click();
  await expect(dialog.getByLabel("Lý do hiệu chỉnh")).toBeFocused();
  expect(api.recorded).toHaveLength(0);

  await dialog
    .getByLabel("Lý do hiệu chỉnh")
    .fill("Nhân viên đã xác nhận kết quả");
  await dialog.getByRole("button", { name: saveName }).click();
  await expect(dialog).toBeHidden();
  expect(api.recorded).toEqual([
    {
      path: "/api/reports/41/locked-amendment",
      body: {
        expected_revision: "original-revision",
        reason: "Nhân viên đã xác nhận kết quả",
        items: [{ id: 11, result: "Sửa lỗi nhập kết quả" }],
      },
    },
  ]);
  await expect(
    page.getByText("Sửa lỗi nhập kết quả", { exact: true }),
  ).toBeVisible();
  await expect(page.getByText("Nhân viên gốc", { exact: true })).toBeVisible();
  await expect(page.getByText("Đã khóa", { exact: true })).toBeVisible();
  await expect(
    page.getByText("Mục được bảo vệ", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Trợ lý kiểm thử", { exact: true }).last(),
  ).toBeVisible();
  await expect(
    page.getByText("Nhân viên đã xác nhận kết quả", { exact: true }),
  ).toBeVisible();
  await expect(page.getByText(/Bản tổng hợp AI.*nội dung mới/)).toBeVisible();
});

test("only a server-eligible report can be amended; Admin self changes omit the public entry", async ({
  page,
}) => {
  const api = await setup(page, true);
  await page.goto("/reports/42");
  await expect(page.getByText("Nhân viên gốc", { exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: openName })).toHaveCount(0);

  await page.goto("/reports/41");
  await page.getByRole("button", { name: openName }).click();
  const dialog = page.getByRole("dialog", { name: openName });
  await expect(dialog.getByLabel("Lý do hiệu chỉnh")).toHaveCount(0);
  await dialog
    .getByLabel("Kế hoạch ngày tiếp theo")
    .fill("Kế hoạch điều chỉnh");
  await dialog.getByRole("button", { name: saveName }).click();
  await expect(dialog).toBeHidden();
  expect(api.recorded[0].body).toEqual({
    expected_revision: "original-revision",
    next_day_plan: "Kế hoạch điều chỉnh",
  });
  await expect(
    page.getByText("Lịch sử hiệu chỉnh", { exact: true }),
  ).toHaveCount(0);
  await expect(page.getByText(/Bản tổng hợp AI.*nội dung mới/)).toHaveCount(0);
});

test("conflicting amendment retains the typed draft and requires loading a new revision", async ({
  page,
}) => {
  const api = await setup(page);
  api.conflictNext();
  await page.goto("/reports/41");
  const open = page.getByRole("button", { name: openName });
  await open.click();
  const dialog = page.getByRole("dialog", { name: openName });
  await dialog.getByLabel("Kết quả", { exact: true }).fill("Kết quả đang nhập");
  await dialog
    .getByLabel("Lý do hiệu chỉnh")
    .fill("Lý do cần giữ cho đến khi tải lại");
  await dialog.getByRole("button", { name: saveName }).click();
  await expect(dialog.getByRole("alert")).toContainText("Báo cáo vừa thay đổi");
  await expect(dialog.getByLabel("Kết quả", { exact: true })).toHaveValue(
    "Kết quả đang nhập",
  );
  await expect(dialog.getByRole("button", { name: saveName })).toHaveCount(0);
  expect(api.recorded).toHaveLength(1);

  page.once("dialog", (confirm) => confirm.accept());
  await dialog.getByRole("button", { name: "Tải lại báo cáo" }).click();
  await expect(dialog).toBeHidden();
  await open.click();
  await expect(dialog.getByLabel("Kết quả", { exact: true })).toHaveValue(
    "Kết quả từ người khác",
  );
  await expect(dialog.getByLabel("Lý do hiệu chỉnh")).toHaveValue("");
  await dialog
    .getByLabel("Kết quả", { exact: true })
    .fill("Kết quả mới được xác nhận");
  await dialog.getByLabel("Lý do hiệu chỉnh").fill("Đã kiểm tra bản mới nhất");
  await dialog.getByRole("button", { name: saveName }).click();
  await expect(dialog).toBeHidden();
  expect(api.recorded[1].body.expected_revision).toBe("newer-revision");
});

test("changing report IDs closes a dirty amendment and waits for the new report", async ({
  page,
}) => {
  const api = await setup(page);
  await page.goto("/reports/41");
  const open = page.getByRole("button", { name: openName });
  await open.click();
  const dialog = page.getByRole("dialog", { name: openName });
  await dialog
    .getByLabel("Kết quả", { exact: true })
    .fill("Bản nháp của báo cáo 41");
  await expect(dialog.getByRole("button", { name: saveName })).toBeEnabled();

  let release = () => {};
  const later = new Promise<void>((resolve) => {
    release = resolve;
  });
  await page.route("**/api/reports/42", async (route) => {
    await later;
    const otherReport = sampleReport(42);
    await route.fulfill({
      json: {
        data: {
          ...otherReport,
          employee: { id: 90, name: "Nhân viên báo cáo 42" },
          locked_amendment: {
            ...otherReport.locked_amendment!,
            allowed: false,
            expected_revision: null,
          },
        },
      },
    });
  });
  page.once("dialog", (confirm) => confirm.accept());
  try {
    await page.evaluate(() => {
      const next = {
        ...history.state,
        idx: history.state.idx + 1,
        key: "second-report",
      };
      history.pushState(next, "", "/reports/42");
      window.dispatchEvent(new PopStateEvent("popstate", { state: next }));
    });
    await expect(page).toHaveURL(/\/reports\/42$/);
    await expect(dialog).toBeHidden();
    await expect(open).toHaveCount(0);
    await expect(page.getByText("Đang tải chi tiết báo cáo...")).toBeVisible();
  } finally {
    release();
  }
  await expect(
    page.getByText("Nhân viên báo cáo 42", { exact: true }),
  ).toBeVisible();
  await expect(open).toHaveCount(0);
  expect(api.recorded).toHaveLength(0);
});

test("queues a manual deletion with undo and saves a validated manual addition atomically", async ({
  page,
}) => {
  const api = await setup(page);
  await page.goto("/reports/41");
  await page.getByRole("button", { name: openName }).click();
  const dialog = page.getByRole("dialog", { name: openName });
  const original = dialog.getByRole("group", { name: "Công việc #1" });
  const remove = original.getByRole("button", {
    name: "Xóa đầu việc",
    exact: true,
  });
  await expect(
    dialog.getByRole("button", { name: "Xóa đầu việc", exact: true }),
  ).toHaveCount(1);
  await original
    .getByLabel("Kết quả", { exact: true })
    .fill("Bản nháp giữ khi hoàn tác");
  await remove.click();
  await expect(original.getByRole("status")).toContainText("sẽ bị xóa khi lưu");
  expect(api.recorded).toHaveLength(0);
  await original.getByRole("button", { name: "Hoàn tác xóa" }).click();
  await expect(original.getByLabel("Kết quả", { exact: true })).toHaveValue(
    "Bản nháp giữ khi hoàn tác",
  );
  await remove.click();

  const add = dialog.getByRole("button", {
    name: "Thêm đầu việc",
    exact: true,
  });
  await add.click();
  const added = dialog.getByRole("group", { name: "Đầu việc mới #1" });
  await added.getByLabel("Trạng thái").selectOption("COMPLETED");
  await added.getByLabel("Nội dung công việc").fill("Việc mới đã hoàn thành");
  await added.getByLabel("Kết quả", { exact: true }).fill("Có kết quả");
  await dialog
    .getByLabel("Lý do hiệu chỉnh")
    .fill("Cập nhật danh sách việc sau đối chiếu");
  const save = dialog.getByRole("button", { name: saveName });
  const evidence = added.getByLabel("Minh chứng", { exact: true });
  await save.click();
  await expect(evidence).toBeFocused();
  await expect(evidence).toHaveAttribute("aria-invalid", "true");
  expect(api.recorded).toHaveLength(0);
  await evidence.fill("https://example.test/proof");
  await save.click();

  await expect(dialog).toBeHidden();
  expect(api.recorded).toHaveLength(1);
  expect(api.recorded[0].body).toEqual({
    expected_revision: "original-revision",
    reason: "Cập nhật danh sách việc sau đối chiếu",
    added_items: [
      {
        status: "COMPLETED",
        content: "Việc mới đã hoàn thành",
        result: "Có kết quả",
        evidence_links: ["https://example.test/proof"],
      },
    ],
    deleted_item_ids: [11],
  });
  await expect(page.getByText("Việc nhập tay", { exact: true })).toHaveCount(0);
  await expect(
    page.getByText("Việc mới đã hoàn thành", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Mục được bảo vệ", { exact: true }),
  ).toBeVisible();
  await expect(page.getByText("Đã khóa", { exact: true })).toBeVisible();
});
