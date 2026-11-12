import { expect, test, type Page } from "@playwright/test";
import type { ReportMailStatus } from "../src/services/reportMailService";

const root = "/api/account/report-mail";
const token = "a".repeat(64);
const initial: ReportMailStatus = {
  schema_ready: true,
  email: "alice.report@gmail.com",
  eligibility: "unverified",
  verified: false,
  weekly_enabled: false,
  auto_enabled: false,
  can_request: true,
  verification_status: null,
  request_after: null,
  verification_expires_at: null,
};

async function setup(
  page: Page,
  options: {
    placeholder?: boolean;
    confirmed?: boolean;
    invalidToken?: boolean;
    admin?: boolean;
    fragment?: boolean;
  } = {},
) {
  let status: ReportMailStatus = {
    ...initial,
    ...(options.placeholder
      ? {
          email: "alice@kps.local",
          eligibility: "placeholder" as const,
          can_request: false,
        }
      : {}),
    ...(options.confirmed
      ? { verified: true, weekly_enabled: true, eligibility: "ready" as const }
      : {}),
  };
  const writes: { path: string; body: Record<string, unknown> }[] = [];
  await page.route("**/api/**", async (route) => {
    const request = route.request(),
      path = new URL(request.url()).pathname;
    if (path === "/api/auth/me")
      return route.fulfill({
        json: {
          id: 1,
          name: "Alice",
          email: status.email,
          organization: { id: 1, name: "KPS" },
          role: { name: options.admin ? "Admin" : "Office Staff" },
          department: null,
          location: null,
        },
      });
    if (path === "/api/workspaces")
      return route.fulfill({
        json: { workspaces: [{ id: "internal", home: "/", sections: [] }] },
      });
    if (path === "/api/navigation")
      return route.fulfill({
        json: {
          items: [
            "reports.history",
            ...(options.admin ? ["integrations"] : []),
          ],
        },
      });
    if (path.startsWith(root)) {
      if (request.method() !== "GET") {
        const body = request.postDataJSON();
        writes.push({ path, body });
        if (path.endsWith("/confirm")) {
          if (options.invalidToken)
            return route.fulfill({
              status: 422,
              json: { errors: { token: ["Invalid"] } },
            });
          status = {
            ...status,
            verified: true,
            weekly_enabled: true,
            eligibility: "ready",
          };
        } else if (path.endsWith("/verification"))
          status = { ...status, verification_status: "accepted" };
        else
          status = {
            ...status,
            weekly_enabled: body.weekly_enabled,
            eligibility: body.weekly_enabled ? "ready" : "opted_out",
          };
      }
      return route.fulfill({
        status:
          request.method() === "POST" && path.endsWith("/verification")
            ? 202
            : 200,
        json: { data: status },
      });
    }
    if (path === "/api/integrations/mail/capabilities")
      return route.fulfill({ json: { can_view: true, can_configure: true } });
    if (path === "/api/integrations/mail")
      return route.fulfill({
        json: {
          data: {
            enabled: true,
            configured: true,
            schema_ready: true,
            send_enabled: true,
            status: "connected",
            email: "sender@gmail.com",
            from_name: "KPS",
            history: [],
          },
          actions: { can_configure: true },
        },
      });
    if (path === "/api/integrations/mail/personal-weekly-recipients")
      return route.fulfill({
        json: {
          data: {
            schema_ready: true,
            auto_enabled: false,
            recipients: [
              {
                user_id: 1,
                name: "Alice",
                email: "alice@kps.local",
                department: "Marketing",
                eligibility: "placeholder",
                delivery_status: "skipped",
                error_code: null,
              },
              {
                user_id: 2,
                name: "Bob",
                email: "bob.report@gmail.com",
                department: "Marketing",
                eligibility: "ready",
                delivery_status: "accepted",
                error_code: null,
              },
            ],
            total: 2,
            page: 1,
            last_page: 1,
          },
        },
      });
    if (path === "/api/integrations")
      return route.fulfill({
        json: { data: [], actions: { can_manage: true } },
      });
    return route.fulfill({
      json: {
        data: [],
        unread_count: 0,
        meta: { total: 0, current_page: 1, last_page: 1 },
      },
    });
  });
  await page.goto(
    options.admin
      ? "/integrations"
      : "/account" + (options.fragment ? "#report-email-confirm=" + token : ""),
  );
  return writes;
}

test("temporary login emails do not force account changes or offer a verification send", async ({
  page,
}) => {
  const writes = await setup(page, { placeholder: true });
  await expect(
    page.getByRole("heading", { name: "Nhận báo cáo tuần qua email" }),
  ).toBeVisible();
  await expect(page.getByText(/Email cấp tạm: chưa gửi báo cáo/)).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Gửi thư xác nhận email", exact: true }),
  ).toHaveCount(0);
  expect(writes).toHaveLength(0);
});

test("a user explicitly requests only a verification email to their current address", async ({
  page,
}) => {
  const writes = await setup(page);
  await page
    .getByRole("button", { name: "Gửi thư xác nhận email", exact: true })
    .click();
  await expect(
    page.getByText(/Hãy mở hộp thư và làm theo liên kết/),
  ).toBeVisible();
  expect(writes).toHaveLength(1);
  expect(writes[0].body).toMatchObject({
    expected_email: "alice.report@gmail.com",
  });
  expect(Object.keys(writes[0].body).sort()).toEqual([
    "expected_email",
    "request_key",
  ]);
  await expect(
    page.getByRole("button", { name: "Tắt nhận báo cáo tuần", exact: true }),
  ).toHaveCount(0);
});

test("the emailed fragment is consumed without automatic confirmation or storing the token", async ({
  page,
}) => {
  const writes = await setup(page, { fragment: true });
  await expect(
    page.getByRole("button", {
      name: "Xác nhận và nhận báo cáo tuần",
      exact: true,
    }),
  ).toBeVisible();
  expect(writes).toHaveLength(0);
  await expect(page).toHaveURL(/\/account$/);
  await page
    .getByRole("button", { name: "Xác nhận và nhận báo cáo tuần", exact: true })
    .click();
  await expect(
    page.getByText(
      "Xác nhận thành công. Đăng ký có hiệu lực từ kỳ gửi tiếp theo.",
      {
        exact: true,
      },
    ),
  ).toBeVisible();
  expect(writes[0]).toEqual({ path: root + "/confirm", body: { token } });
  expect(
    await page.evaluate(
      (value) =>
        Object.values(localStorage).some((entry) => entry.includes(value)),
      token,
    ),
  ).toBe(false);
});

test("the wrong or expired confirmation does not enable subscription", async ({
  page,
}) => {
  await setup(page, { fragment: true, invalidToken: true });
  await page
    .getByRole("button", { name: "Xác nhận và nhận báo cáo tuần", exact: true })
    .click();
  await expect(page.getByRole("alert")).toContainText(
    "Liên kết không khớp tài khoản",
  );
  await expect(
    page.getByRole("button", { name: "Tắt nhận báo cáo tuần", exact: true }),
  ).toHaveCount(0);
});

test("verified staff can opt out without using the mail administration screen", async ({
  page,
}) => {
  const writes = await setup(page, { confirmed: true });
  await page
    .getByRole("button", { name: "Tắt nhận báo cáo tuần", exact: true })
    .click();
  await expect(
    page.getByText("Đã tắt nhận báo cáo tuần qua email.", { exact: true }),
  ).toBeVisible();
  expect(writes).toEqual([{ path: root, body: { weekly_enabled: false } }]);
  await expect(
    page.getByRole("button", { name: "Bật nhận báo cáo tuần", exact: true }),
  ).toBeVisible();
});

test("admin reviews real and temporary email eligibility without sending or confirming for others", async ({
  page,
}) => {
  const writes = await setup(page, { admin: true });
  await page.getByRole("tab", { name: "Mail", exact: true }).click();
  await page
    .locator("summary")
    .filter({ hasText: "Gửi báo cáo tuần cá nhân" })
    .click();
  await expect(
    page.getByText("Email cấp tạm — không gửi báo cáo", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Đủ điều kiện nhận báo cáo tuần", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText(/Đã gửi qua Gmail; chưa xác nhận đã nhận hoặc đọc/),
  ).toBeVisible();
  expect(writes).toHaveLength(0);
});

test("the personal mail section remains readable on a mobile dark account screen", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.addInitScript(() => localStorage.setItem("theme", "dark"));
  await setup(page);
  await expect(
    page.getByRole("heading", { name: "Nhận báo cáo tuần qua email" }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Gửi thư xác nhận email", exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
});
