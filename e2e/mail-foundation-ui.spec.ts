import { expect, test, type Page } from "@playwright/test";

async function setup(
  page: Page,
  options: {
    canView?: boolean;
    canConfigure?: boolean;
    uncertain?: boolean;
    invalid?: boolean;
    mixedFailure?: boolean;
  } = {},
) {
  const canView = options.canView ?? true;
  const canConfigure = options.canConfigure ?? true;
  const keys: string[] = [];
  const history: Record<string, unknown>[] = [];
  let mailReads = 0;
  let submissions = 0;
  const processCalls: string[] = [];
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
              id: "4",
              name: "Trần Thị Bình",
              email: "colleague@example.test",
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
    if (path === "/api/integrations/mail/tests") {
      const payload = request.postDataJSON();
      expect(Object.keys(payload)).toEqual(["recipients"]);
      submissions++;
      for (const recipient of payload.recipients) {
        keys.push(recipient.request_key);
        expect(Object.keys(recipient).sort()).toEqual([
          "recipient_user_id",
          "request_key",
        ]);
      }
      if (options.invalid)
        return route.fulfill({
          status: 422,
          json: { errors: { recipient_user_id: ["Invalid recipient"] } },
        });
      const data = payload.recipients.map(
        (recipient: { recipient_user_id: number }) => {
          let entry = history.find(
            (item) =>
              item.recipient_user_id === String(recipient.recipient_user_id),
          );
          if (!entry) {
            entry = {
              id: `mail-${recipient.recipient_user_id}`,
              recipient_user_id: String(recipient.recipient_user_id),
              recipient_email:
                recipient.recipient_user_id === 2
                  ? "recipient@example.test"
                  : "colleague@example.test",
              created_by: "1",
              kind: "test",
              status: "queued",
              error_code: null,
              created_at: "2026-11-06T04:00:00Z",
              accepted_at: null,
            };
            history.push(entry);
          }
          return entry;
        },
      );
      if (options.uncertain && submissions === 1) return route.abort("failed");
      return route.fulfill({ status: 202, json: { data } });
    }
    if (/^\/api\/integrations\/mail\/tests\/[^/]+\/process$/.test(path)) {
      const id = path.split("/").at(-2)!;
      processCalls.push(id);
      if (
        options.mixedFailure &&
        id === "mail-4" &&
        processCalls.filter((value) => value === id).length === 1
      )
        return route.abort("failed");
      const entry = history.find((item) => item.id === id)!;
      entry.status = options.uncertain ? "unknown" : "accepted";
      entry.error_code = options.uncertain ? "PROVIDER_RESULT_UNKNOWN" : null;
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
            from_name: "Kim Phục Sắc - Nội bộ",
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
    await expect(
      page.getByRole("group", { name: "Nhân viên nhận thư thử" }),
    ).toBeEnabled();
  }
  return { keys, processCalls, mailReads: () => mailReads };
}

async function preview(page: Page) {
  await page.getByRole("checkbox", { name: /Nguyễn Văn An/ }).check();
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
  await expect(
    page.getByRole("checkbox", { name: /Nhân viên chưa được phép/ }),
  ).toBeDisabled();
  await expect(
    page.getByText("Tên người gửi: Kim Phục Sắc - Nội bộ", { exact: true }),
  ).toBeVisible();
  await preview(page);
  await page.getByRole("button", { name: "Gửi thư thử", exact: true }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(
    page.getByText("Đã gửi qua Gmail", { exact: true }).first(),
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
    page.getByText("Đã gửi qua Gmail", { exact: true }).first(),
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
  await page
    .getByRole("button", { name: "Tiếp tục / cập nhật lượt gửi", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(
    page.getByText("Chưa xác định kết quả", { exact: true }).first(),
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
      await expect(
        page.getByRole("group", { name: "Nhân viên nhận thư thử" }),
      ).toBeEnabled();
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
      await expect(
        page.getByRole("checkbox", { name: /Nguyễn Văn An/ }),
      ).toBeChecked();
      await expect(
        page.getByRole("group", { name: "Nhân viên nhận thư thử" }),
      ).toHaveAttribute("aria-invalid", "true");
      await expectNoPageOverflow();
    });
  }
}

test("assistant selects multiple employees and safely resumes a partially completed batch", async ({
  page,
}) => {
  const api = await setup(page, { canConfigure: false, mixedFailure: true });
  await page.getByRole("checkbox", { name: /Trần Thị Bình/ }).check();
  await preview(page);
  await expect(page.getByRole("dialog")).toContainText(
    "Gửi thư riêng cho 2 nhân viên",
  );
  await page.getByRole("button", { name: "Gửi thư thử", exact: true }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(
    page.getByRole("button", {
      name: "Tiếp tục / cập nhật lượt gửi",
      exact: true,
    }),
  ).toBeEnabled();
  await page.reload();
  await page.getByRole("tab", { name: "Mail", exact: true }).click();
  await page
    .getByRole("button", { name: "Tiếp tục / cập nhật lượt gửi", exact: true })
    .click();
  await expect(
    page.getByRole("button", { name: "Tạo lượt gửi mới", exact: true }),
  ).toBeVisible();
  expect(api.processCalls.filter((id) => id === "mail-2")).toHaveLength(1);
  expect(api.processCalls.filter((id) => id === "mail-4")).toHaveLength(2);
  expect(api.keys.slice(2).sort()).toEqual(api.keys.slice(0, 2).sort());
  await page
    .getByRole("button", { name: "Tạo lượt gửi mới", exact: true })
    .click();
  await expect(
    page.getByRole("checkbox", { name: /Nguyễn Văn An/ }),
  ).not.toBeChecked();
});

test("recipient search and select-all include only allowed employees", async ({
  page,
}) => {
  await setup(page);
  await page
    .getByRole("textbox", { name: "Tìm theo tên, email hoặc phòng ban" })
    .fill("Nội bộ");
  await page
    .getByRole("button", { name: "Chọn các kết quả được phép", exact: true })
    .click();
  await expect(
    page.getByText("Đã chọn 2/50 người nhận", { exact: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Bỏ chọn tất cả", exact: true })
    .click();
  await expect(
    page.getByRole("button", { name: "Xem trước thư thử", exact: true }),
  ).toBeDisabled();
});

test("sent status explains delivery on hover and keyboard focus, and Escape dismisses it", async ({
  page,
}) => {
  await setup(page);
  await preview(page);
  await page.getByRole("button", { name: "Gửi thư thử", exact: true }).click();
  const badge = page.getByText("Đã gửi qua Gmail", { exact: true }).first();
  const info = page
    .getByRole("button", { name: "Thông tin trạng thái gửi", exact: true })
    .first();
  await expect(badge).toBeVisible();
  await expect(page.getByRole("tooltip")).toHaveCount(0);
  await info.hover();
  await expect(page.getByRole("tooltip")).toHaveText(
    "Chưa xác nhận đã nhận/đọc",
  );
  await page.keyboard.press("Escape");
  await expect(page.getByRole("tooltip")).toHaveCount(0);
  await page.getByRole("heading", { name: "Mail KPS", exact: true }).hover();
  await info.focus();
  await expect(info).toHaveAttribute("aria-describedby", /.+/);
  await expect(page.getByRole("tooltip")).toHaveText(
    "Chưa xác nhận đã nhận/đọc",
  );
  await info.press("Shift+Tab");
  await expect(page.getByRole("tooltip")).toHaveCount(0);
});

test.describe("sent tooltip on touch screens", () => {
  test.use({ hasTouch: true });
  for (const dark of [false, true]) {
    test(`mobile ${dark ? "dark" : "light"}: tap opens sent explanation without horizontal overflow`, async ({
      page,
    }) => {
      await page.setViewportSize({ width: 320, height: 844 });
      await setup(page, { canConfigure: false });
      await page.evaluate(
        (enabled) => document.documentElement.classList.toggle("dark", enabled),
        dark,
      );
      await preview(page);
      await page
        .getByRole("button", { name: "Gửi thư thử", exact: true })
        .click();
      const info = page
        .getByRole("button", { name: "Thông tin trạng thái gửi", exact: true })
        .first();
      await info.tap();
      await expect(page.getByRole("tooltip")).toHaveText(
        "Chưa xác nhận đã nhận/đọc",
      );
      const bounds = await page.getByRole("tooltip").boundingBox();
      expect(bounds).not.toBeNull();
      expect(bounds!.x).toBeGreaterThanOrEqual(0);
      expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(320);
      expect(
        await page.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        ),
      ).toBe(false);
      await page
        .getByRole("heading", { name: "Gửi thư thử", exact: true })
        .tap();
      await expect(page.getByRole("tooltip")).toHaveCount(0);
    });
  }
});
