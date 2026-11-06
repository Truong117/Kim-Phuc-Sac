import { expect, test, type Page } from "@playwright/test";

async function setup(
  page: Page,
  options: {
    canView?: boolean;
    canConfigure?: boolean;
    uncertain?: boolean;
    invalid?: boolean;
  } = {},
) {
  const canView = options.canView ?? true;
  const canConfigure = options.canConfigure ?? true;
  const keys: string[] = [];
  const history: Record<string, unknown>[] = [];
  let mailReads = 0;
  await page.route("**/api/**", async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path === "/api/auth/me")
      return route.fulfill({
        json: {
          id: 1,
          name: "Người gửi",
          email: "sender@example.test",
          organization: { id: 1, name: "KPS" },
          role: { name: canConfigure ? "Admin" : "Trợ lý" },
          department: null,
          location: null,
        },
      });
    if (path === "/api/navigation")
      return route.fulfill({ json: { items: ["integrations"] } });
    if (path === "/api/integrations/mail/capabilities")
      return route.fulfill({
        json: { can_view: canView, can_configure: canConfigure },
      });
    if (path === "/api/integrations/mail/recipients")
      return route.fulfill({
        json: {
          data: [
            {
              id: "2",
              name: "Nguyễn Văn An",
              email: "recipient@example.test",
              department: "Nội bộ",
              test_allowed: true,
            },
            {
              id: "3",
              name: "Nhân viên chưa được phép",
              email: "outside@example.test",
              department: null,
              test_allowed: false,
            },
          ],
        },
      });
    if (path === "/api/integrations/mail/test") {
      const payload = request.postDataJSON();
      keys.push(payload.request_key);
      expect(Object.keys(payload).sort()).toEqual([
        "recipient_user_id",
        "request_key",
      ]);
      if (options.invalid)
        return route.fulfill({
          status: 422,
          json: { errors: { recipient_user_id: ["Invalid recipient"] } },
        });
      const entry = {
        id: "mail-1",
        recipient_user_id: "2",
        recipient_email: "recipient@example.test",
        created_by: "1",
        kind: "test",
        status: options.uncertain ? "unknown" : "accepted",
        error_code: options.uncertain ? "PROVIDER_RESULT_UNKNOWN" : null,
        created_at: "2026-11-06T04:00:00Z",
        accepted_at: null,
      };
      if (!history.length) history.push(entry);
      if (options.uncertain && keys.length === 1) return route.abort("failed");
      return route.fulfill({ status: 202, json: { data: entry } });
    }
    if (path === "/api/integrations/mail/connection") {
      expect(canConfigure).toBe(true);
      return route.fulfill({ status: 204 });
    }
    if (path === "/api/integrations/mail") {
      mailReads++;
      return route.fulfill({
        json: {
          data: {
            enabled: true,
            configured: true,
            schema_ready: true,
            send_enabled: true,
            expected_email: "kimphucsac@gmail.com",
            status: "connected",
            email: "kimphucsac@gmail.com",
            connected_at: null,
            history,
          },
          actions: { can_configure: canConfigure },
        },
      });
    }
    if (path === "/api/integrations/references")
      return route.fulfill({ json: { data: [] } });
    if (path === "/api/integrations")
      return route.fulfill({
        json: { data: [], actions: { can_manage: false } },
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
  await expect(page.getByRole("tab", { name: /Drive/ })).toBeVisible();
  if (canView) {
    await page.getByRole("tab", { name: "Mail", exact: true }).click();
    await expect(page.getByLabel("Nhân viên nhận thư thử")).toBeEnabled();
  }
  return { keys, mailReads: () => mailReads };
}

async function preview(page: Page) {
  await page.getByLabel("Nhân viên nhận thư thử").selectOption("2");
  await page
    .getByRole("button", { name: "Xem trước thư thử", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toContainText("Nguyễn Văn An");
  await expect(page.getByRole("dialog")).toContainText("Chào Nguyễn Văn An,");
  await expect(page.getByRole("dialog")).not.toContainText("{name}");
}

test("mail is hidden for other roles and never loads protected mail data", async ({
  page,
}) => {
  const api = await setup(page, { canView: false });
  await expect(
    page.getByRole("tab", { name: "Mail", exact: true }),
  ).toHaveCount(0);
  expect(api.mailReads()).toBe(0);
});

test("admin previews a named employee and sees provider acceptance in history", async ({
  page,
}) => {
  const api = await setup(page);
  await expect(
    page.getByRole("button", { name: "Kết nối lại Gmail", exact: true }),
  ).toBeVisible();
  await expect(page.locator('option[value="3"]')).toBeDisabled();
  await preview(page);
  await page.getByRole("button", { name: "Gửi thư thử", exact: true }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(
    page.getByText("Gmail đã tiếp nhận", { exact: true }),
  ).toBeVisible();
  expect(api.keys).toHaveLength(1);
});

test("assistant can send but cannot connect or disconnect Gmail", async ({
  page,
}) => {
  await setup(page, { canConfigure: false });
  await expect(
    page.getByRole("button", { name: /Kết nối.*Gmail|Ngắt kết nối Gmail/ }),
  ).toHaveCount(0);
  await preview(page);
  await page.getByRole("button", { name: "Gửi thư thử", exact: true }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(
    page.getByText("Gmail đã tiếp nhận", { exact: true }),
  ).toBeVisible();
});

test("a network error preserves the same request key across a page reload", async ({
  page,
}) => {
  const api = await setup(page, { uncertain: true });
  await preview(page);
  await page.getByRole("button", { name: "Gửi thư thử", exact: true }).click();
  await expect(page.getByRole("dialog").getByRole("alert")).toContainText(
    "Chưa xác nhận được kết quả",
  );
  await page.reload();
  await page.getByRole("tab", { name: "Mail", exact: true }).click();
  await preview(page);
  await page.getByRole("button", { name: "Gửi thư thử", exact: true }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(
    page.getByText("Chưa xác định kết quả", { exact: true }),
  ).toBeVisible();
  expect(api.keys).toHaveLength(2);
  expect(api.keys[1]).toBe(api.keys[0]);
});

for (const width of [320, 390]) {
  for (const dark of [false, true]) {
    test(`${width}px ${dark ? "dark" : "light"}: mail tabs, preview and invalid recipient stay within the viewport`, async ({
      page,
    }) => {
      await page.setViewportSize({ width, height: 844 });
      await setup(page, { canConfigure: false, invalid: true });
      await page.evaluate(
        (enabled) => document.documentElement.classList.toggle("dark", enabled),
        dark,
      );
      const expectNoPageOverflow = async () => {
        const dimensions = await page.evaluate(() => ({
          viewport: innerWidth,
          page: document.documentElement.scrollWidth,
          overflowing: Array.from(document.querySelectorAll("main *"))
            .filter(
              (element) =>
                element.getBoundingClientRect().right > innerWidth + 1,
            )
            .map((element) => ({
              tag: element.tagName,
              role: element.getAttribute("role"),
              className: element.className,
            })),
        }));
        expect(dimensions.page, JSON.stringify(dimensions)).toBeLessThanOrEqual(
          dimensions.viewport,
        );
      };
      await expectNoPageOverflow();
      const mail = page.getByRole("tab", { name: "Mail", exact: true });
      const drive = page.getByRole("tab", { name: /Drive/ });
      await mail.focus();
      await mail.press("Home");
      await expect(drive).toBeFocused();
      await drive.press("End");
      await expect(mail).toBeFocused();
      await expect(page.getByLabel("Nhân viên nhận thư thử")).toBeEnabled();
      await expectNoPageOverflow();
      await preview(page);
      await expectNoPageOverflow();
      await page
        .getByRole("button", { name: "Gửi thư thử", exact: true })
        .click();
      await expect(page.getByRole("dialog").getByRole("alert")).toContainText(
        "Người nhận không còn hợp lệ",
      );
      await page.getByRole("button", { name: "Hủy", exact: true }).click();
      await expect(page.getByLabel("Nhân viên nhận thư thử")).toHaveValue("2");
      await expect(page.getByLabel("Nhân viên nhận thư thử")).toHaveAttribute(
        "aria-invalid",
        "true",
      );
      await expectNoPageOverflow();
    });
  }
}
