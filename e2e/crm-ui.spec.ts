import { expect, test } from "@playwright/test";

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
const emptyResponse = { data: [], unread_count: 0 };

test("CRM uses the KPS calendar to validate and save a follow-up date", async ({
  page,
}) => {
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
      json: responses[pathname] ?? emptyResponse,
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

test("CRM balances the list and detail while paginating a full customer list", async ({
  page,
}) => {
  const customers = Array.from({ length: 26 }, (_, index) => ({
    ...customer,
    id: index + 1,
    code: "KH-" + (index + 1),
  }));
  await page.setViewportSize({ width: 1864, height: 848 });
  await page.route("**/api/**", async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === "/api/crm/customers") {
      const pageNumber = Number(url.searchParams.get("page") ?? "1");
      return route.fulfill({
        json: {
          data: {
            data: customers.slice((pageNumber - 1) * 25, pageNumber * 25),
            total: customers.length,
            current_page: pageNumber,
            last_page: 2,
          },
        },
      });
    }
    return route.fulfill({
      json: responses[url.pathname] ?? emptyResponse,
    });
  });

  await page.goto("/customers/1");
  const list = page.getByRole("list", { name: "Danh sách khách hàng" });
  const search = page.getByRole("textbox", {
    name: "Tìm theo tên, mã hoặc liên hệ",
  });
  const listCard = page.locator(".rounded-2xl").filter({
    has: page.getByRole("heading", { name: "Danh sách khách hàng" }),
  });
  const historyCard = page.locator(".rounded-2xl").filter({
    has: page.getByRole("heading", { name: "Lịch sử CRM" }),
  });
  const differenceBetweenPanelBottoms = async () => {
    const [left, right] = await Promise.all([
      listCard.boundingBox(),
      historyCard.boundingBox(),
    ]);
    if (!left || !right) return Infinity;
    return Math.abs(left.y + left.height - right.y - right.height);
  };

  await expect(list.getByRole("listitem")).toHaveCount(25);
  await expect.poll(differenceBetweenPanelBottoms).toBeLessThan(1);
  await expect
    .poll(() => search.evaluate((input) => input.getBoundingClientRect().width))
    .toBeGreaterThan(290);
  await expect
    .poll(() =>
      list.evaluate((element) => element.scrollHeight > element.clientHeight),
    )
    .toBe(true);

  const nextPage = page.getByRole("button", { name: "Trang sau" });
  await expect(nextPage).toBeInViewport();
  await nextPage.click();
  await expect(list.getByRole("listitem")).toHaveCount(1);
  await expect.poll(differenceBetweenPanelBottoms).toBeLessThan(1);
});
