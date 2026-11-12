import { expect, test, type Page } from "@playwright/test";
async function setup(
  page: Page,
  manage: boolean,
  options: {
    status?: string;
    errorCode?: string | null;
    connectStatus?: number;
    connectCode?: string;
  } = {},
) {
  let connectionStatus = options.status ?? "connected";
  let errorCode = options.errorCode ?? null;
  let token: string | null = null;
  await page.route("**/api/**", async (route) => {
    if (new URL(route.request().url()).pathname === "/api/workspaces")
      return route.fulfill({ json: { workspaces: [{ id: "internal", home: "/", sections: [] }] } });
    const path = new URL(route.request().url()).pathname;
    if (path === "/api/auth/me")
      return route.fulfill({
        json: {
          id: 1,
          name: "Test",
          email: "test@example.test",
          organization: { id: 1, name: "KPS" },
          role: { name: "Admin" },
          department: null,
          location: null,
        },
      });
    if (path === "/api/navigation")
      return route.fulfill({ json: { items: ["integrations"] } });
    if (path === "/api/integrations/references")
      return route.fulfill({ json: { data: [{ id: "1", name: "Test" }] } });
    if (path === "/api/integrations")
      return route.fulfill({
        json: {
          data: [
            {
              id: "1",
              kind: "social",
              platform: "facebook",
              name: "KPS Page",
              url: "https://facebook.com/kps",
              responsible_user_id: "1",
              owner: "Test",
              version: 1,
              note: "",
              enabled: true,
            },
          ],
          actions: { can_manage: manage },
        },
      });
    if (path.endsWith("/social/connect")) {
      if (route.request().method() === "DELETE") {
        connectionStatus = "disconnected";
        return route.fulfill({ status: 204 });
      }
      token = route.request().postDataJSON().access_token;
      if (options.connectStatus)
        return route.fulfill({
          status: options.connectStatus,
          json: {
            message: "test-only unsafe provider detail: " + token,
            code: options.connectCode,
          },
        });
      connectionStatus = "connected";
      errorCode = null;
      return route.fulfill({ json: { message: "OK" } });
    }
    if (path.endsWith("/social"))
      return route.fulfill({
        json: {
          data: {
            enabled: true,
            connection: {
              page_id: "100",
              page_name: "KPS Page",
              status: connectionStatus,
              synced_at: null,
              error_code: errorCode,
              sync_counts: null,
            },
            posts: [
              {
                post_id: 1,
                url: "https://facebook.com/kps/posts/555",
                report_ids: [12],
                sample_count: 2,
                observed_from: "2026-11-05 03:00:00",
                observed_to: "2026-11-05 04:00:00",
                current: { likes: 15, comments: 2, media_views: null },
                observed_delta: { likes: 5, comments: 1, media_views: null },
                coverage: "observed_interval_only",
              },
            ],
          },
        },
      });
    return route.fulfill({
      json: {
        data: [],
        unread_count: 0,
        meta: { total: 0, current_page: 1, last_page: 1 },
      },
    });
  });
  await page.goto("/integrations");
  await page.getByRole("tab", { name: /MXH/ }).click();
  await page
    .getByRole("button", { name: "Chi tiết: KPS Page", exact: true })
    .click();
  await expect(
    page.getByRole("link", { name: "Mở bài 1", exact: true }),
  ).toBeVisible();
  return () => token;
}

test("authorized viewer sees observed deltas and missing values without credential controls", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await setup(page, false);
  await expect(page.getByText("Δ +5", { exact: true })).toBeVisible();
  await expect(
    page.getByRole("link", { name: "Xem báo cáo 1", exact: true }),
  ).toHaveAttribute("href", "/reports/12");
  await expect(page.locator("#social-token")).toHaveCount(0);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth > window.innerWidth,
    ),
  ).toBe(false);
});

test("manager connection form sends the token once and clears it after submission", async ({
  page,
}) => {
  const captured = await setup(page, true);
  await expect(page.locator("#social-token")).toHaveCount(0);
  await page
    .getByRole("button", { name: "Thiết lập kết nối", exact: true })
    .click();
  await expect(page.locator("#social-page-id")).toHaveValue("100");
  await expect(page.locator("#social-token")).toHaveAttribute(
    "autocomplete",
    "new-password",
  );
  await page.locator("#social-token").fill("test-only-fake-token-123456");
  await expect(page.locator("#social-token")).toHaveAttribute(
    "type",
    "password",
  );
  await page
    .getByRole("button", { name: "Xác minh và lưu kết nối", exact: true })
    .click();
  await expect(page.locator("#social-token")).toHaveCount(0);
  expect(captured()).toBe("test-only-fake-token-123456");
  await expect(
    page.getByText("Đã cập nhật kết nối.", { exact: true }),
  ).toBeVisible();
});

test("reconnect feedback retains the Page identity and resolves after a successful update", async ({
  page,
}) => {
  await setup(page, true, {
    status: "reconnect_required",
    errorCode: "SOCIAL_RECONNECT_REQUIRED",
  });
  await expect(page.getByRole("alert")).toContainText(
    "Token Facebook không còn hợp lệ",
  );
  await page
    .getByRole("button", { name: "Thiết lập kết nối", exact: true })
    .click();
  await expect(page.locator("#social-page-id")).toHaveValue("100");
  await page.locator("#social-token").fill("test-only-new-page-token-1234");
  await page
    .getByRole("button", { name: "Xác minh và lưu kết nối", exact: true })
    .click();
  await expect(page.getByRole("alert")).toHaveCount(0);
  await expect(page.locator("#social-token")).toHaveCount(0);
  await expect(page.getByText("KPS Page · Đã xác minh Page")).toBeVisible();
});

test("rate limits explain the wait and clear credentials without echoing provider text", async ({
  page,
}) => {
  const captured = await setup(page, true, { connectStatus: 429 });
  await page
    .getByRole("button", { name: "Thiết lập kết nối", exact: true })
    .click();
  await page.locator("#social-token").fill("test-only-rate-limited-token");
  await page
    .getByRole("button", { name: "Xác minh và lưu kết nối", exact: true })
    .click();
  await expect(page.getByRole("alert")).toContainText("Chờ khoảng một phút");
  await expect(page.locator("#social-token")).toHaveValue("");
  await expect(page.locator("#social-page-id")).toHaveValue("100");
  await expect(
    page.getByText("test-only unsafe provider detail", { exact: false }),
  ).toHaveCount(0);
  expect(captured()).toBe("test-only-rate-limited-token");
  await page
    .getByRole("button", { name: "Đóng form kết nối", exact: true })
    .click();
  await expect(page.locator("#social-token")).toHaveCount(0);
});

test("a login email accidentally placed in Page ID cannot be submitted", async ({
  page,
}) => {
  const captured = await setup(page, true);
  await page
    .getByRole("button", { name: "Thiết lập kết nối", exact: true })
    .click();
  await page.locator("#social-page-id").fill("admin@example.test");
  await page.locator("#social-token").fill("test-only-not-a-login-password");
  await page
    .getByRole("button", { name: "Xác minh và lưu kết nối", exact: true })
    .click();
  expect(
    await page
      .locator("#social-page-id")
      .evaluate((input: HTMLInputElement) => input.validity.patternMismatch),
  ).toBe(true);
  expect(captured()).toBeNull();
});

test("disconnecting does not display a previous token error as a current sync failure", async ({
  page,
}) => {
  await setup(page, true, {
    status: "reconnect_required",
    errorCode: "SOCIAL_RECONNECT_REQUIRED",
  });
  await expect(page.getByRole("alert")).toContainText(
    "Token Facebook không còn hợp lệ",
  );
  await page.getByRole("button", { name: "Ngắt kết nối", exact: true }).click();
  await expect(
    page.getByText("Đã ngắt kết nối Page.", { exact: true }),
  ).toBeVisible();
  await expect(page.getByRole("alert")).toHaveCount(0);
  await expect(page.getByText("KPS Page · Đã ngắt kết nối")).toBeVisible();
});
