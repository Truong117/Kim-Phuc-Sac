import { expect, test, type Page } from "@playwright/test";

async function setup(
  page: Page,
  options: {
    queueUncertain?: boolean;
    processUncertain?: boolean;
    invalid?: boolean;
  } = {},
) {
  const history: Record<string, unknown>[] = [];
  const submissions: {
    recipients: { recipient_user_id: number; request_key: string }[];
    subject: string;
    body: string;
  }[] = [];
  const processed: string[] = [];
  await page.route("**/api/**", async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path === "/api/auth/me")
      return route.fulfill({
        json: {
          id: 1,
          name: "Trợ lý",
          email: "assistant@example.test",
          organization: { id: 1, name: "KPS" },
          role: { name: "Trợ lý" },
          department: null,
          location: null,
        },
      });
    if (path === "/api/navigation")
      return route.fulfill({ json: { items: ["integrations"] } });
    if (path === "/api/integrations/mail/capabilities")
      return route.fulfill({ json: { can_view: true, can_configure: false } });
    if (path === "/api/integrations/mail/recipients")
      return route.fulfill({
        json: {
          data: [
            {
              id: "2",
              name: "Nguyễn Văn An",
              email: "an@example.test",
              department: "Nội bộ",
              test_allowed: false,
              send_allowed: true,
            },
            {
              id: "3",
              name: "Trần Thị Bình",
              email: "binh@example.test",
              department: "Nội bộ",
              test_allowed: false,
              send_allowed: true,
            },
            {
              id: "4",
              name: "Email chưa hợp lệ",
              email: "invalid-email",
              department: null,
              test_allowed: false,
              send_allowed: false,
            },
          ],
        },
      });
    if (path === "/api/integrations/mail/messages") {
      const input = request.postDataJSON();
      expect(Object.keys(input).sort()).toEqual([
        "body",
        "recipients",
        "subject",
      ]);
      submissions.push(input);
      if (options.invalid)
        return route.fulfill({
          status: 422,
          json: { errors: { subject: ["Invalid subject"] } },
        });
      const data = input.recipients.map(
        (recipient: { recipient_user_id: number; request_key: string }) => {
          expect(Object.keys(recipient).sort()).toEqual([
            "recipient_user_id",
            "request_key",
          ]);
          let entry = history.find(
            (message) => message.id === recipient.request_key,
          );
          if (!entry) {
            entry = {
              id: recipient.request_key,
              recipient_user_id: String(recipient.recipient_user_id),
              created_by: "1",
              recipient_email:
                recipient.recipient_user_id === 2
                  ? "an@example.test"
                  : "binh@example.test",
              subject: input.subject,
              kind: "internal",
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
      if (options.queueUncertain && submissions.length === 1)
        return route.abort("failed");
      return route.fulfill({ status: 202, json: { data } });
    }
    if (/^\/api\/integrations\/mail\/messages\/[^/]+\/process$/.test(path)) {
      const id = path.split("/").at(-2)!;
      processed.push(id);
      const message = history.find((entry) => entry.id === id)!;
      if (
        options.processUncertain &&
        message.recipient_user_id === "3" &&
        processed.filter((value) => value === id).length === 1
      )
        return route.abort("failed");
      message.status = "accepted";
      message.accepted_at = "2026-11-06T04:01:00Z";
      return route.fulfill({ status: 202, json: { data: message } });
    }
    if (path === "/api/integrations/mail")
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
          actions: { can_configure: false },
        },
      });
    if (
      path === "/api/integrations/mail/connect" ||
      path === "/api/integrations/mail/connection" ||
      path.startsWith("/api/integrations/mail/tests")
    )
      throw new Error(`Unexpected mail action: ${path}`);
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
  await page.getByRole("tab", { name: "Mail", exact: true }).click();
  await expect(
    page.getByRole("group", { name: "Nhân viên nhận mail", exact: true }),
  ).toBeEnabled();
  return { submissions, processed, history };
}

const subject = "Thông báo họp nội bộ";
const body =
  "Kính gửi anh chị,\n\nHọp lúc 9 giờ.\n<script>alert('mail')</script>\nKim Phục Sắc - Nội bộ";
async function compose(page: Page) {
  await page
    .getByRole("button", { name: "Chọn các kết quả được phép", exact: true })
    .click();
  await page.getByLabel("Tiêu đề", { exact: true }).fill(subject);
  await page.getByLabel("Nội dung", { exact: true }).fill(body);
  await page
    .getByRole("button", { name: "Xem trước mail", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toContainText(body);
}

test("assistant composes a private multi-recipient message without the test allowlist and previews before sending", async ({
  page,
}) => {
  const api = await setup(page);
  await expect(
    page.getByRole("button", { name: /Kết nối|Ngắt kết nối/ }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("checkbox", { name: /Email chưa hợp lệ/ }),
  ).toBeDisabled();
  await compose(page);
  expect(api.submissions).toHaveLength(0);
  await expect(page.getByRole("dialog")).toContainText(
    "Gửi thư riêng cho 2 nhân viên",
  );
  await page
    .getByRole("button", { name: "Quay lại chỉnh sửa", exact: true })
    .click();
  await expect(page.getByLabel("Nội dung", { exact: true })).toHaveValue(body);
  await page
    .getByRole("button", { name: "Xem trước mail", exact: true })
    .click();
  await page.getByRole("button", { name: "Gửi mail", exact: true }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(
    page.getByText(
      "Đã gửi mail qua Gmail đến tất cả người nhận trong lượt gửi.",
      { exact: true },
    ),
  ).toBeVisible();
  expect(api.submissions).toHaveLength(1);
  expect(api.submissions[0].subject).toBe(subject);
  expect(api.submissions[0].body).toBe(body);
  expect(api.submissions[0].recipients).toHaveLength(2);
  expect(api.processed).toHaveLength(2);
  await expect(page.getByLabel("Tiêu đề", { exact: true })).toBeDisabled();
  await page
    .getByRole("button", { name: "Soạn mail mới", exact: true })
    .click();
  await expect(page.getByLabel("Tiêu đề", { exact: true })).toHaveValue("");
});

for (const failure of ["queueUncertain", "processUncertain"] as const) {
  test(`${failure}: reload preserves frozen content and request keys and resumes only pending recipients`, async ({
    page,
  }) => {
    const api = await setup(page, { [failure]: true });
    await compose(page);
    await page.getByRole("button", { name: "Gửi mail", exact: true }).click();
    await expect(page.getByText(/Chưa xác nhận được kết quả/)).toBeVisible();
    await page.reload();
    await page.getByRole("tab", { name: "Mail", exact: true }).click();
    await expect(page.getByLabel("Tiêu đề", { exact: true })).toHaveValue(
      subject,
    );
    await expect(page.getByLabel("Nội dung", { exact: true })).toHaveValue(
      body,
    );
    await expect(page.getByLabel("Nội dung", { exact: true })).toBeDisabled();
    await page
      .getByRole("button", {
        name: "Tiếp tục / cập nhật lượt gửi",
        exact: true,
      })
      .click();
    await expect(
      page.getByText(
        "Đã gửi mail qua Gmail đến tất cả người nhận trong lượt gửi.",
        { exact: true },
      ),
    ).toBeVisible();
    expect(api.submissions).toHaveLength(2);
    expect(api.submissions[1]).toEqual(api.submissions[0]);
    expect(
      api.processed.filter(
        (id) => id === api.submissions[0].recipients[0].request_key,
      ),
    ).toHaveLength(1);
  });
}

test("invalid fields focus in form order and atomic server validation restores editable content", async ({
  page,
}) => {
  const api = await setup(page, { invalid: true });
  await page
    .getByRole("button", { name: "Xem trước mail", exact: true })
    .click();
  await expect(
    page.getByRole("group", { name: "Nhân viên nhận mail", exact: true }),
  ).toHaveAttribute("aria-invalid", "true");
  expect(api.submissions).toHaveLength(0);
  await page.getByRole("checkbox", { name: /Nguyễn Văn An/ }).check();
  await page
    .getByRole("button", { name: "Xem trước mail", exact: true })
    .click();
  await expect(page.getByLabel("Tiêu đề", { exact: true })).toBeFocused();
  await page.getByLabel("Tiêu đề", { exact: true }).fill(subject);
  await page
    .getByRole("button", { name: "Xem trước mail", exact: true })
    .click();
  await expect(page.getByLabel("Nội dung", { exact: true })).toBeFocused();
  await page.getByLabel("Nội dung", { exact: true }).fill(body);
  await page
    .getByRole("button", { name: "Xem trước mail", exact: true })
    .click();
  await page.getByRole("button", { name: "Gửi mail", exact: true }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(page.getByLabel("Tiêu đề", { exact: true })).toHaveAttribute(
    "aria-invalid",
    "true",
  );
  await expect(page.getByLabel("Tiêu đề", { exact: true })).toBeFocused();
  await expect(page.getByLabel("Nội dung", { exact: true })).toHaveValue(body);
  await expect(page.getByLabel("Nội dung", { exact: true })).toBeEnabled();
  expect(api.processed).toHaveLength(0);
});

for (const dark of [false, true]) {
  test(`320px ${dark ? "dark" : "light"}: compose and long preview fit without horizontal overflow`, async ({
    page,
  }) => {
    await page.setViewportSize({ width: 320, height: 844 });
    await setup(page);
    await page.evaluate(
      (enabled) => document.documentElement.classList.toggle("dark", enabled),
      dark,
    );
    await compose(page);
    await page
      .getByRole("button", { name: "Quay lại chỉnh sửa", exact: true })
      .click();
    await page.getByLabel("Tiêu đề", { exact: true }).fill("A".repeat(200));
    await page
      .getByLabel("Nội dung", { exact: true })
      .fill("https://example.test/" + "b".repeat(1000));
    await page
      .getByRole("button", { name: "Xem trước mail", exact: true })
      .click();
    await expect(page.getByRole("dialog")).toBeVisible();
    const size = await page.evaluate(() => ({
      page: document.documentElement.scrollWidth,
      viewport: innerWidth,
      dialog: document.querySelector('[role="dialog"]')!.scrollWidth,
    }));
    expect(size.page).toBeLessThanOrEqual(size.viewport);
    expect(size.dialog).toBeLessThanOrEqual(size.viewport);
    await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog")).toHaveCount(0);
  });
}
