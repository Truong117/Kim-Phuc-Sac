import { expect, test, type Page } from "@playwright/test";
import {
  dailyMailFixture,
  weeklyMailFixture,
} from "../tests/fixtures/mail-ai.mjs";

async function setup(
  page: Page,
  options: {
    queueUncertain?: boolean;
    processUncertain?: boolean;
    invalid?: boolean;
    aiDenied?: boolean;
    staleAi?: boolean;
  } = {},
) {
  const history: Record<string, unknown>[] = [];
  const submissions: {
    recipients: { recipient_user_id: number; request_key: string }[];
    subject: string;
    body: string;
  }[] = [];
  const processed: string[] = [];
  const aiReads: string[] = [];
  await page.route("**/api/**", async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path.startsWith("/api/ai/")) {
      expect(request.method()).toBe("GET");
      aiReads.push(request.url());
      if (options.aiDenied)
        return route.fulfill({ status: 403, json: { message: "Forbidden" } });
      if (path === "/api/ai/daily-documents") {
        const params = new URL(request.url()).searchParams;
        const pageNumber = Number(params.get("page") || 1);
        const row = structuredClone(dailyMailFixture);
        if (options.staleAi && aiReads.length > 1) row.source_changed = true;
        if (pageNumber === 2) {
          row.employee_name = "Nhân viên trang hai";
          row.document.id = 102;
        }
        return route.fulfill({
          json: {
            data: {
              enabled: true,
              date: params.get("date"),
              page: pageNumber,
              last_page: 2,
              total: 4,
              reports: [
                row,
                {
                  ...row,
                  employee_name: "AI chưa hoàn tất",
                  document: { ...row.document, id: 110, status: "running" },
                },
                {
                  ...row,
                  employee_name: "Dữ liệu đã đổi",
                  source_changed: true,
                  document: { ...row.document, id: 111 },
                },
              ],
            },
          },
        });
      }
      if (path === "/api/ai/work-report-analyses/history")
        return route.fulfill({
          json: {
            data: [
              { ...weeklyMailFixture, result: null },
              {
                ...weeklyMailFixture,
                id: 202,
                period: { ...weeklyMailFixture.period, days: 1 },
              },
              { ...weeklyMailFixture, id: 203, status: "partial" },
              { ...weeklyMailFixture, id: 204, source_changed: true },
            ],
          },
        });
      if (path === "/api/ai/work-report-analyses/201")
        return route.fulfill({
          json: {
            data: {
              ...weeklyMailFixture,
              source_changed: options.staleAi || false,
            },
          },
        });
      throw new Error(`Unexpected AI read: ${path}`);
    }
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
  return { submissions, processed, history, aiReads };
}

for (const [value, title] of [
  ["weekly_plan", "Kế hoạch tuần"],
  ["monthly_plan", "Kế hoạch tháng"],
  ["issue", "Có vấn đề cần xử lý"],
]) {
  test(`preset ${value}: explicit replacement preserves recipients and remains editable without sending`, async ({
    page,
  }) => {
    const api = await setup(page);
    await page.getByRole("checkbox", { name: /Nguyễn Văn An/ }).check();
    await page
      .getByLabel("Tiêu đề", { exact: true })
      .fill("Nội dung đang soạn");
    await page.getByLabel("Nội dung", { exact: true }).fill("Giữ lại nếu hủy");
    const picker = page.getByRole("combobox", {
      name: "Mẫu mail",
      exact: true,
    });
    await expect(picker.getByRole("option")).toHaveCount(6);
    await picker.selectOption(value);
    await page
      .getByRole("button", { name: "Xem trước mẫu", exact: true })
      .click();
    await expect(page.getByRole("dialog")).toContainText(
      "Áp dụng sẽ thay toàn bộ tiêu đề",
    );
    await expect(page.getByRole("dialog")).toContainText(
      "Kim Phục Sắc - Nội bộ",
    );
    await page
      .getByRole("dialog")
      .getByRole("button", { name: "Hủy", exact: true })
      .click();
    await expect(page.getByLabel("Nội dung", { exact: true })).toHaveValue(
      "Giữ lại nếu hủy",
    );
    await page
      .getByRole("button", { name: "Xem trước mẫu", exact: true })
      .click();
    await page
      .getByRole("button", { name: "Dùng nội dung này", exact: true })
      .click();
    await expect(page.getByLabel("Tiêu đề", { exact: true })).toHaveValue(
      /^\[KPS\]/,
    );
    await expect(page.getByLabel("Nội dung", { exact: true })).not.toHaveValue(
      "Giữ lại nếu hủy",
    );
    await expect(page.getByLabel("Nội dung", { exact: true })).toBeEnabled();
    await expect(
      page.getByRole("checkbox", { name: /Nguyễn Văn An/ }),
    ).toBeChecked();
    await expect(picker.locator("option:checked")).toHaveText(title);
    expect(api.aiReads).toHaveLength(0);
    expect(api.submissions).toHaveLength(0);
  });
}

test("daily AI template uses a completed employee report, refreshes the source and keeps evidence and uncertain figures", async ({
  page,
}) => {
  const api = await setup(page);
  await page
    .getByRole("combobox", { name: "Mẫu mail", exact: true })
    .selectOption("daily");
  await page
    .getByRole("button", { name: "Tải danh sách báo cáo AI", exact: true })
    .click();
  const source = page.getByRole("combobox", {
    name: "Báo cáo AI nguồn",
    exact: true,
  });
  await expect(source.getByRole("option")).toHaveCount(2);
  await source.selectOption("101");
  await page
    .getByRole("button", { name: "Xem trước mẫu", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toContainText(
    "Đã bàn giao 3 mẫu được duyệt.",
  );
  await expect(page.getByRole("dialog")).toContainText(
    "Doanh thu (đồng): Cần xác nhận",
  );
  await expect(page.getByRole("dialog")).not.toContainText("9000");
  await page
    .getByRole("button", { name: "Dùng nội dung này", exact: true })
    .click();
  await expect(page.getByLabel("Tiêu đề", { exact: true })).toHaveValue(
    "[KPS] Báo cáo ngày 05/11/2026 - Nguyễn Văn An",
  );
  await expect(page.getByLabel("Nội dung", { exact: true })).toHaveValue(
    /Căn cứ báo cáo #12, đầu việc #4/,
  );
  expect(api.aiReads).toHaveLength(2);
  expect(api.submissions).toHaveLength(0);
  await page.getByRole("button", { name: "Trang sau", exact: true }).click();
  await expect(
    source.getByRole("option", { name: /Nhân viên trang hai/ }),
  ).toBeAttached();
  expect(new URL(api.aiReads.at(-1)!).searchParams.get("page")).toBe("2");
});

test("weekly AI template selects only complete seven-day runs and uses the saved summary and precise report link", async ({
  page,
}) => {
  const api = await setup(page);
  await page
    .getByRole("combobox", { name: "Mẫu mail", exact: true })
    .selectOption("weekly");
  await page
    .getByRole("button", { name: "Tải danh sách báo cáo AI", exact: true })
    .click();
  const source = page.getByRole("combobox", {
    name: "Báo cáo AI nguồn",
    exact: true,
  });
  await expect(source.getByRole("option")).toHaveCount(2);
  await source.selectOption("201");
  await page
    .getByRole("button", { name: "Xem trước mẫu", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toContainText(
    "Chưa đủ dữ liệu để kết luận doanh thu.",
  );
  await expect(page.getByRole("dialog")).toContainText(
    "Bản tổng hợp AI #201, phiên bản 2",
  );
  await page
    .getByRole("button", { name: "Dùng nội dung này", exact: true })
    .click();
  await expect(page.getByLabel("Nội dung", { exact: true })).toHaveValue(
    /analysis=201&end=2026-11-05&days=7/,
  );
  expect(api.aiReads).toHaveLength(2);
  expect(api.submissions).toHaveLength(0);
});

for (const failure of ["aiDenied", "staleAi"] as const) {
  test(`${failure}: an unavailable AI source never replaces the existing draft`, async ({
    page,
  }) => {
    const api = await setup(page, { [failure]: true });
    await page.getByLabel("Tiêu đề", { exact: true }).fill("Giữ tiêu đề");
    await page.getByLabel("Nội dung", { exact: true }).fill("Giữ nội dung");
    await page
      .getByRole("combobox", { name: "Mẫu mail", exact: true })
      .selectOption("weekly");
    await page
      .getByRole("button", { name: "Tải danh sách báo cáo AI", exact: true })
      .click();
    if (failure === "staleAi") {
      await page
        .getByRole("combobox", { name: "Báo cáo AI nguồn", exact: true })
        .selectOption("201");
      await page
        .getByRole("button", { name: "Xem trước mẫu", exact: true })
        .click();
    }
    await expect(page.getByRole("alert")).toBeVisible();
    await expect(page.getByLabel("Tiêu đề", { exact: true })).toHaveValue(
      "Giữ tiêu đề",
    );
    await expect(page.getByLabel("Nội dung", { exact: true })).toHaveValue(
      "Giữ nội dung",
    );
    await expect(page.getByRole("dialog")).toHaveCount(0);
    expect(api.submissions).toHaveLength(0);
  });
}

for (const dark of [false, true]) {
  test(`320px ${dark ? "dark" : "light"}: template preview stays in the viewport and cancels without replacing the draft`, async ({
    page,
  }) => {
    await page.setViewportSize({ width: 320, height: 844 });
    await setup(page);
    await page.evaluate(
      (enabled) => document.documentElement.classList.toggle("dark", enabled),
      dark,
    );
    await page
      .getByLabel("Nội dung", { exact: true })
      .fill("Bản đang soạn trên điện thoại");
    await page
      .getByRole("combobox", { name: "Mẫu mail", exact: true })
      .selectOption("monthly_plan");
    await page
      .getByRole("button", { name: "Xem trước mẫu", exact: true })
      .click();
    await expect(page.getByRole("dialog")).toBeVisible();
    const sizes = await page.evaluate(() => ({
      page: document.documentElement.scrollWidth,
      viewport: innerWidth,
      modal: document.querySelector('[role="dialog"]')!.scrollWidth,
    }));
    expect(sizes.page).toBeLessThanOrEqual(sizes.viewport);
    expect(sizes.modal).toBeLessThanOrEqual(sizes.viewport);
    await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await expect(page.getByLabel("Nội dung", { exact: true })).toHaveValue(
      "Bản đang soạn trên điện thoại",
    );
    await expect(page.getByLabel("Nội dung", { exact: true })).toBeEnabled();
  });
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
