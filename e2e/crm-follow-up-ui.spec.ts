import { expect, test } from "@playwright/test";

test("CRM uses the KPS calendar to validate and save a follow-up date", async ({
  page,
}) => {
  const customer = {
    id: 1,
    code: "KH-1",
    name: "Khách thử nghiệm",
    channel: "ZALO",
    owner_name: "Sales",
    contacts: [],
    opportunities: [],
    activities: [],
    can_update: true,
    can_assign: false,
    version: 0,
  };
  const responses: Record<string, unknown> = {
    "/api/workspaces": {
      workspaces: [{ id: "internal", home: "/", sections: [] }],
    },
    "/api/auth/me": {
      id: 7,
      name: "Sales",
      email: "sales@example.test",
      role: { name: "Nhân viên" },
      organization: { id: 1, name: "KPS" },
      department: null,
      location: null,
    },
    "/api/navigation": { items: ["customers"] },
    "/api/crm/capabilities": {
      data: {
        enabled: true,
        ready: true,
        can_create: true,
        business_date: "2026-11-30",
      },
    },
    "/api/crm/customers": {
      data: { data: [customer], total: 1, current_page: 1, last_page: 1 },
    },
    "/api/crm/customers/1": { data: customer },
  };
  const submissions: unknown[] = [];

  await page.route("**/api/**", async (route) => {
    const { pathname } = new URL(route.request().url());
    if (
      pathname === "/api/crm/customers/1/activities" &&
      route.request().method() === "POST"
    ) {
      submissions.push(route.request().postDataJSON());
      return route.fulfill({ status: 201, json: { data: { id: 2 } } });
    }
    return route.fulfill({
      json: responses[pathname] ?? { data: [], unread_count: 0 },
    });
  });

  await page.goto("/customers/1");
  await expect(
    page.getByRole("button", { name: "Ghi lượt tư vấn", exact: true }),
  ).toHaveCSS("background-color", "rgb(139, 99, 58)");
  await page.getByRole("button", { name: "Đặt lịch liên hệ lại" }).click();

  const dialog = page.getByRole("dialog", { name: "Đặt lịch liên hệ lại" });
  const date = dialog.getByRole("button", { name: /Ngày liên hệ lại/ });
  const save = dialog.getByRole("button", { name: "Lưu", exact: true });
  await expect(save).toHaveCSS("background-color", "rgb(139, 99, 58)");
  await dialog.getByLabel("Việc cần làm khi liên hệ lại").fill("Hỏi phản hồi");
  await save.click();
  await expect(date).toBeFocused();
  await expect(date).toHaveAttribute("aria-invalid", "true");
  expect(submissions).toHaveLength(0);

  await date.click();
  const calendar = dialog.getByRole("dialog", { name: "Lịch chọn ngày" });
  await expect(
    calendar.locator('[data-calendar-date="2026-11-29"]'),
  ).toBeDisabled();
  await expect(
    calendar.locator('[data-calendar-date="2026-11-30"]'),
  ).toBeEnabled();
  await calendar.getByRole("button", { name: "Tháng sau" }).click();
  await calendar.locator('[data-calendar-date="2026-12-01"]').click();
  await expect(date).toContainText("01/12/2026");
  await expect(date).not.toHaveAttribute("aria-invalid", "true");

  await save.click();
  await expect(dialog).toBeHidden();
  expect(submissions).toEqual([
    expect.objectContaining({
      kind: "FOLLOW_UP",
      title: "Hỏi phản hồi",
      due_on: "2026-12-01",
    }),
  ]);
});
