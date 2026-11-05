import { expect, test, type Page } from "@playwright/test";
async function setup(page: Page, manage: boolean) {
  let token: string | null = null;
  await page.route("**/api/**", async (route) => {
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
      token = route.request().postDataJSON().access_token;
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
              status: "connected",
              synced_at: null,
              error_code: null,
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
  await page.locator("#social-page-id").fill("100");
  await page.locator("#social-token").fill("test-only-fake-token-123456");
  await expect(page.locator("#social-token")).toHaveAttribute(
    "type",
    "password",
  );
  await page
    .getByRole("button", { name: "Xác minh và lưu kết nối", exact: true })
    .click();
  await expect(page.locator("#social-token")).toHaveValue("");
  expect(captured()).toBe("test-only-fake-token-123456");
  await expect(
    page.getByText("Đã cập nhật kết nối.", { exact: true }),
  ).toBeVisible();
});
