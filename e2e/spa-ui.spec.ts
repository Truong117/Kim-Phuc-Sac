import { expect, test, type Page } from "@playwright/test";

const previewEnabled = process.env.SPA_UI_TEST_ENABLED !== "false";

async function setup(
  page: Page,
  options: { settings?: boolean; authenticated?: boolean } = {},
) {
  const requests: { path: string; method: string }[] = [];
  await page.route("**/api/**", async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    requests.push({ path, method: request.method() });
    if (path === "/api/auth/me")
      return route.fulfill({
        status: options.authenticated === false ? 401 : 200,
        json:
          options.authenticated === false
            ? {}
            : {
                id: "spa-ui-reviewer",
                name: "Người xem UI",
                email: "spa-ui@example.test",
                organization: { id: 1, name: "KPS" },
                department: null,
                location: { id: 1, name: "Cơ sở mẫu" },
                role: { name: "Người xem" },
              },
      });
    if (path === "/api/workspaces")
      return route.fulfill({
        json: {
          workspaces:
            options.settings === false
              ? [{ id: "internal", home: "/", sections: [] }]
              : [
                  { id: "internal", home: "/", sections: [] },
                  {
                    id: "spa",
                    home: "/spa",
                    sections: [
                      "overview",
                      "appointments",
                      "reception",
                      "customers",
                      "services",
                      "treatments",
                      "cashier",
                      "technician",
                      "rooms",
                      "inventory",
                      "care",
                      "reports",
                      "settings",
                    ],
                  },
                ],
        },
      });
    if (path === "/api/navigation")
      return route.fulfill({
        json: { items: options.settings === false ? [] : ["settings"] },
      });
    if (path === "/api/notifications")
      return route.fulfill({
        json: {
          data: [],
          unread_count: 0,
          meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
        },
      });
    return route.fulfill({
      status: 404,
      json: { message: `Unexpected request: ${path}` },
    });
  });
  return requests;
}

test.describe("spa UI preview", () => {
  test.skip(!previewEnabled, "Preview is disabled for this run");

  test("requires KPS login and backend Spa workspace access", async ({
    page,
  }) => {
    await setup(page, { authenticated: false });
    await page.goto("/spa/technician");
    await expect(page).toHaveURL(/\/login$/);
    await page.unroute("**/api/**");
    await setup(page, { settings: false });
    await page.goto("/spa");
    await expect(
      page.getByRole("heading", { name: "Không có quyền truy cập" }),
    ).toBeVisible();
    await expect(page.getByRole("link", { name: "Spa · Bản mẫu" })).toHaveCount(
      0,
    );
    await expect(page.getByText("Nguyễn Minh Anh")).toHaveCount(0);
  });

  test("opens all base screens without spa API calls or writes", async ({
    page,
  }) => {
    const requests = await setup(page);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.goto("/spa");
    await expect(
      page.getByRole("heading", { name: "Không gian vận hành spa" }),
    ).toBeVisible();
    await expect(
      page.getByRole("complementary", { name: "KPS · Spa" }),
    ).toBeVisible();
    await expect(
      page.getByRole("link", { name: "Báo cáo công việc", exact: true }),
    ).toHaveCount(0);
    await expect(
      page
        .getByText("Lịch hẹn trong ngày", { exact: true })
        .first()
        .locator(".."),
    ).toContainText("5");
    const nav = page.getByRole("navigation", { name: "Chức năng spa" });
    const links = await nav.getByRole("link").evaluateAll((items) =>
      items.map((item) => ({
        href: item.getAttribute("href")!,
        name: item.textContent!.trim(),
      })),
    );
    expect(links).toHaveLength(15);
    for (const link of links) {
      await nav.getByRole("link", { name: link.name, exact: true }).click();
      await expect(page).toHaveURL(link.href);
      await expect(
        page.getByRole("heading", { name: link.name, exact: true, level: 2 }),
      ).toBeVisible();
    }
    expect(errors).toEqual([]);
    expect(requests.every((request) => request.method === "GET")).toBe(true);
    expect(
      requests.some((request) => request.path.startsWith("/api/spa")),
    ).toBe(false);
  });

  test("filters appointments by unaccented name and appointment status", async ({
    page,
  }) => {
    await setup(page);
    await page.goto("/spa/appointments");
    await page
      .getByRole("button", { name: "Danh sách lịch", exact: true })
      .click();
    await page.getByLabel("Tìm khách hoặc mã lịch").fill("minh anh");
    await expect(
      page
        .getByRole("region", { name: "Danh sách lịch hẹn mẫu", exact: true })
        .getByText("Nguyễn Minh Anh", { exact: true }),
    ).toBeVisible();
    await expect(page.getByText("Trần Ngọc Linh", { exact: true })).toHaveCount(
      0,
    );
    await page.getByLabel("Trạng thái lịch hẹn").selectOption("cancelled");
    await expect(
      page.getByText("Không có lịch hẹn phù hợp với bộ lọc."),
    ).toBeVisible();
    await page.getByLabel("Tìm khách hoặc mã lịch").fill("");
    await expect(page.getByText("Đỗ Mỹ Duyên", { exact: true })).toBeVisible();
    await expect(
      page.getByText("1 lịch hẹn phù hợp", { exact: true }),
    ).toBeVisible();
  });

  test("phone uses task cards, full detail and back navigation", async ({
    page,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await setup(page);
    await page.goto("/spa");
    await page
      .getByRole("button", { name: "Đóng hoặc mở thanh điều hướng" })
      .click();
    await page
      .getByRole("navigation", { name: "Chức năng spa" })
      .getByRole("link", { name: "Công việc KTV", exact: true })
      .click();
    const tasks = page.getByRole("region", { name: "Danh sách công việc" });
    await expect(tasks.getByRole("button")).toHaveCount(2);
    await expect(tasks.getByText("Trần Ngọc Linh")).toHaveCount(0);
    await tasks.getByRole("button", { name: /Nguyễn Minh Anh/ }).click();
    const detail = page.getByRole("article", { name: "Chi tiết buổi dịch vụ" });
    await expect(detail).toBeVisible();
    await expect(tasks).toBeHidden();
    await expect(
      detail.getByText("Dịch vụ yêu cầu ảnh tình trạng da"),
    ).toBeVisible();
    await expect(detail).not.toContainText(/điện thoại|công nợ|doanh thu/i);
    await expect(page.locator("main input[type=file]")).toHaveCount(2);
    await expect(
      page.locator("main input[type=file][capture]"),
    ).toHaveAttribute("capture", "environment");
    await detail.getByRole("button", { name: "Quay lại danh sách" }).click();
    await expect(tasks).toBeVisible();
    await page.getByRole("button", { name: "Đã làm", exact: true }).click();
    await expect(tasks.getByText("Võ Thu Hương")).toBeVisible();
    await page.getByRole("button", { name: "Bàn giao", exact: true }).click();
    await expect(page.getByText(/Chưa có yêu cầu bàn giao/)).toBeVisible();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBe(true);
  });

  test("tablet shows list and detail in portrait, landscape and dark mode", async ({
    page,
  }) => {
    await setup(page);
    await page.addInitScript(() => localStorage.setItem("theme", "dark"));
    for (const viewport of [
      { width: 820, height: 1180 },
      { width: 1180, height: 820 },
    ]) {
      await page.setViewportSize(viewport);
      await page.goto("/spa/technician");
      await expect(page.locator("html")).toHaveClass(/dark/);
      const list = page.getByRole("region", { name: "Danh sách công việc" });
      const detail = page.getByRole("article", {
        name: "Chi tiết buổi dịch vụ",
      });
      await expect(list).toBeVisible();
      await expect(detail).toBeVisible();
      const a = (await list.boundingBox())!;
      const b = (await detail.boundingBox())!;
      expect(b.x).toBeGreaterThanOrEqual(a.x + a.width);
      const buttons = await list
        .getByRole("button")
        .evaluateAll((items) =>
          items.map((item) => item.getBoundingClientRect().height),
        );
      expect(buttons.every((height) => height >= 44)).toBe(true);
      expect(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
      ).toBe(true);
    }
  });
});

test("spa UI is absent when preview is disabled", async ({ page }) => {
  test.skip(previewEnabled, "Run separately with SPA_UI_TEST_ENABLED=false");
  await setup(page);
  await page.goto("/settings");
  await expect(page.getByRole("link", { name: "Spa · Bản mẫu" })).toHaveCount(
    0,
  );
  await page.goto("/spa");
  await expect(
    page.getByRole("heading", { name: "LỖI 404", exact: true }),
  ).toBeVisible();
  await expect(page.getByText("Nguyễn Minh Anh")).toHaveCount(0);
});

const internalWorkspace = { id: "internal", home: "/", sections: [] };
const technicianWorkspace = {
  id: "spa",
  home: "/spa/technician",
  sections: ["technician"],
};

async function setupWorkspaces(
  page: Page,
  workspaces: unknown[],
  signedIn = true,
) {
  await setup(page);
  let authenticated = signedIn;
  const user = {
    id: "workspace-reviewer",
    name: "Nhân viên mẫu",
    email: "workspace@example.test",
    organization: { id: 1, name: "KPS" },
    department: null,
    location: null,
    role: { name: "Tên vai trò không dùng điều hướng" },
  };
  await page.route("**/sanctum/csrf-cookie", (route) =>
    route.fulfill({ status: 204 }),
  );
  await page.route("**/api/auth/me", (route) =>
    route.fulfill({
      status: authenticated ? 200 : 401,
      json: authenticated ? user : { message: "Unauthenticated." },
    }),
  );
  await page.route("**/api/auth/login", (route) => {
    authenticated = true;
    return route.fulfill({ json: user });
  });
  await page.route("**/api/auth/logout", (route) => {
    authenticated = false;
    return route.fulfill({ status: 204 });
  });
  await page.route("**/api/workspaces", (route) =>
    route.fulfill({ json: { workspaces } }),
  );
}
async function signIn(page: Page) {
  await page
    .getByLabel("Email", { exact: true })
    .fill("workspace@example.test");
  await page.getByLabel("Mật khẩu", { exact: true }).fill("test-password");
  await page.getByRole("button", { name: "Đăng nhập", exact: true }).click();
}

test.describe("workspace routing and switching", () => {
  test.skip(!previewEnabled, "Preview is disabled for this run");
  test("spa technician login goes straight to own work, hides switch and rejects management links", async ({
    page,
  }) => {
    await setupWorkspaces(page, [technicianWorkspace], false);
    await page.goto("/login");
    await signIn(page);
    await expect(page).toHaveURL("/spa/technician");
    await page.getByRole("button", { name: "Tài khoản Nhân viên mẫu" }).click();
    await expect(
      page.getByRole("link", { name: /Chuyển sang hệ thống/ }),
    ).toHaveCount(0);
    await page.goto("/spa/reports");
    await expect(
      page.getByRole("heading", { name: "Không có quyền truy cập" }),
    ).toBeVisible();
    await page.goto("/employees");
    await expect(
      page.getByRole("heading", { name: "Không có quyền truy cập" }),
    ).toBeVisible();
  });
  test("dual access switches without login, keeps Spa profile and remembers last workspace", async ({
    page,
  }) => {
    await setupWorkspaces(page, [internalWorkspace, technicianWorkspace]);
    await page.goto("/account");
    await page.getByRole("button", { name: "Tài khoản Nhân viên mẫu" }).click();
    await page.getByRole("link", { name: "Chuyển sang hệ thống Spa" }).click();
    await expect(page).toHaveURL("/spa/technician");
    await page.getByRole("button", { name: "Tài khoản Nhân viên mẫu" }).click();
    await page.getByRole("link", { name: "Chỉnh sửa hồ sơ" }).click();
    await expect(page).toHaveURL("/spa/profile");
    await expect(
      page.getByRole("complementary", { name: "KPS · Spa" }),
    ).toBeVisible();
    await page.goto("/login");
    await expect(page).toHaveURL("/spa/technician");
    await page.getByRole("button", { name: "Tài khoản Nhân viên mẫu" }).click();
    await page
      .getByRole("link", { name: "Chuyển sang hệ thống nội bộ" })
      .click();
    await expect(page).toHaveURL("/");
    await expect(
      page.getByRole("navigation", { name: "Chức năng spa" }),
    ).toHaveCount(0);
  });
  test("authorized deep link survives login including query and fragment", async ({
    page,
  }) => {
    await setupWorkspaces(page, [technicianWorkspace], false);
    await page.goto("/spa/technician?day=today#task");
    await expect(page).toHaveURL(/\/login$/);
    await signIn(page);
    await expect(page).toHaveURL("/spa/technician?day=today#task");
  });
  test("stale Spa preference cannot grant office user access or show switch", async ({
    page,
  }) => {
    await setupWorkspaces(page, [internalWorkspace]);
    await page.addInitScript(() =>
      localStorage.setItem("kps.workspace.workspace-reviewer", "spa"),
    );
    await page.goto("/login");
    await expect(page).toHaveURL("/");
    await page.getByRole("button", { name: "Tài khoản Nhân viên mẫu" }).click();
    await expect(
      page.getByRole("link", { name: "Chuyển sang hệ thống Spa" }),
    ).toHaveCount(0);
    await page.getByRole("button", { name: "Đăng xuất", exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
    await page.goto("/spa/technician");
    await expect(page).toHaveURL(/\/login$/);
  });
});

async function bookExistingCustomer(page: Page, time = "14:30") {
  await page
    .getByRole("button", { name: "Tạo lịch hẹn mẫu", exact: true })
    .click();
  const dialog = page.getByRole("dialog", {
    name: "Tạo lịch hẹn mẫu",
    exact: true,
  });
  await dialog
    .getByLabel("Chọn khách hàng", { exact: true })
    .selectOption("customer-6");
  await dialog.getByLabel("Giờ hẹn", { exact: true }).fill(time);
  await dialog
    .getByLabel("Dịch vụ 1", { exact: true })
    .selectOption("relaxation");
  await dialog
    .getByRole("button", { name: "Lưu lịch hẹn mẫu", exact: true })
    .click();
  await expect(dialog).toHaveCount(0);
}
async function receiveNewBooking(page: Page) {
  const row = page.getByRole("listitem", {
    name: "SPA-007 · Đỗ Mỹ Duyên",
    exact: true,
  });
  await row.getByRole("link", { name: "Mở tiếp nhận", exact: true }).click();
  const card = page.getByRole("article", {
    name: "SPA-007 · Đỗ Mỹ Duyên",
    exact: true,
  });
  await card
    .getByRole("button", { name: "Xác nhận khách đến", exact: true })
    .click();
  await card
    .getByRole("button", { name: "Phân công dịch vụ", exact: true })
    .click();
  return page.getByRole("dialog", { name: "Phân công dịch vụ", exact: true });
}

test.describe("spa reception workflow preview", () => {
  test.skip(!previewEnabled, "Preview is disabled for this run");
  test("new customer through booking, reception, assignment and KTV uses the same in-memory session", async ({
    page,
  }) => {
    const requests = await setup(page);
    await page.goto("/spa/customers");
    await page
      .getByRole("button", { name: "Thêm khách hàng mẫu", exact: true })
      .click();
    const customerDialog = page.getByRole("dialog", {
      name: "Thêm khách hàng mẫu",
      exact: true,
    });
    await customerDialog
      .getByLabel("Tên khách hàng", { exact: true })
      .fill("Khách UI thử");
    await customerDialog
      .getByLabel("Số điện thoại mẫu", { exact: true })
      .fill("0000000088");
    await customerDialog
      .getByRole("button", { name: "Lưu khách hàng mẫu", exact: true })
      .click();
    await page
      .getByRole("article", { name: "Khách UI thử", exact: true })
      .getByRole("button", { name: "Đặt lịch", exact: true })
      .click();
    const bookingDialog = page.getByRole("dialog", {
      name: "Tạo lịch hẹn mẫu",
      exact: true,
    });
    await bookingDialog.getByLabel("Giờ hẹn", { exact: true }).fill("14:30");
    await bookingDialog
      .getByLabel("Dịch vụ 1", { exact: true })
      .selectOption("skinRecovery");
    await bookingDialog
      .getByRole("button", { name: "Lưu lịch hẹn mẫu", exact: true })
      .click();
    await expect(page).toHaveURL(/\/spa\/appointments\?/);
    const booking = page.getByRole("listitem", {
      name: "SPA-007 · Khách UI thử",
      exact: true,
    });
    await expect(booking).toContainText("Chưa phân công");
    await booking
      .getByRole("link", { name: "Mở tiếp nhận", exact: true })
      .click();
    const card = page.getByRole("article", {
      name: "SPA-007 · Khách UI thử",
      exact: true,
    });
    await card
      .getByRole("button", { name: "Xác nhận khách đến", exact: true })
      .click();
    await expect(card).toContainText("Tiếp nhận: Người xem UI");
    await card
      .getByRole("button", { name: "Phân công dịch vụ", exact: true })
      .click();
    const assignment = page.getByRole("dialog", {
      name: "Phân công dịch vụ",
      exact: true,
    });
    await assignment
      .getByLabel("Kỹ thuật viên", { exact: true })
      .selectOption("tech-1");
    await assignment
      .getByLabel("Phòng / giường", { exact: true })
      .selectOption("bed-02");
    await assignment
      .getByRole("button", { name: "Lưu phân công mẫu", exact: true })
      .click();
    await expect(card).toContainText("Lan Anh");
    await expect(card).toContainText("Điều phối: Người xem UI");
    await card
      .getByRole("button", { name: "Xem chi tiết", exact: true })
      .click();
    const detail = page.getByRole("dialog", {
      name: "Chi tiết lịch hẹn mẫu",
      exact: true,
    });
    await expect(detail).toContainText("Lịch sử thao tác trong phiên thử");
    await expect(detail).toContainText("Xác nhận khách đến");
    await detail
      .getByRole("button", { name: "Đóng biểu mẫu", exact: true })
      .click();
    await card
      .getByRole("button", { name: "Mở giao diện KTV", exact: true })
      .click();
    const tasks = page.getByRole("region", {
      name: "Danh sách công việc",
      exact: true,
    });
    await expect(
      tasks.getByRole("button", { name: /Khách UI thử/ }),
    ).toBeVisible();
    await tasks.getByRole("button", { name: /Khách UI thử/ }).click();
    await expect(
      page.getByRole("article", { name: "Chi tiết buổi dịch vụ" }),
    ).toContainText("Phòng / giường");
    expect(requests.every((request) => request.method === "GET")).toBe(true);
    expect(
      requests.some((request) => request.path.startsWith("/api/spa")),
    ).toBe(false);
    await page.reload();
    await expect(page.getByText("Khách UI thử", { exact: true })).toHaveCount(
      0,
    );
  });
  test("conflicting KTV and beds show errors without losing the form or creating an assignment", async ({
    page,
  }) => {
    await setup(page);
    await page.goto("/spa/appointments");
    await bookExistingCustomer(page, "09:00");
    const dialog = await receiveNewBooking(page);
    await dialog
      .getByLabel("Kỹ thuật viên", { exact: true })
      .selectOption("tech-1");
    await dialog
      .getByLabel("Phòng / giường", { exact: true })
      .selectOption("bed-03");
    await dialog
      .getByRole("button", { name: "Lưu phân công mẫu", exact: true })
      .click();
    await expect(dialog.getByRole("alert")).toContainText(
      "KTV đã có dịch vụ trùng giờ",
    );
    await expect(
      dialog.getByLabel("Kỹ thuật viên", { exact: true }),
    ).toHaveAttribute("aria-invalid", "true");
    await dialog
      .getByLabel("Kỹ thuật viên", { exact: true })
      .selectOption("tech-3");
    await dialog
      .getByLabel("Phòng / giường", { exact: true })
      .selectOption("bed-01");
    await dialog
      .getByRole("button", { name: "Lưu phân công mẫu", exact: true })
      .click();
    await expect(dialog.getByRole("alert")).toContainText(
      "Giường đã có dịch vụ trùng giờ",
    );
    await dialog
      .getByLabel("Phòng / giường", { exact: true })
      .selectOption("bed-03");
    await dialog
      .getByRole("button", { name: "Lưu phân công mẫu", exact: true })
      .click();
    await expect(dialog).toHaveCount(0);
    await expect(
      page.getByRole("article", { name: "SPA-007 · Đỗ Mỹ Duyên", exact: true }),
    ).toContainText("Bích Ngọc");
  });
  test("validates, protects dirty forms, edits bookings and cancels with a reason", async ({
    page,
  }) => {
    await setup(page);
    await page.goto("/spa/appointments");
    await page
      .getByRole("button", { name: "Tạo lịch hẹn mẫu", exact: true })
      .click();
    let dialog = page.getByRole("dialog", {
      name: "Tạo lịch hẹn mẫu",
      exact: true,
    });
    await dialog
      .getByRole("button", { name: "Lưu lịch hẹn mẫu", exact: true })
      .click();
    await expect(dialog.getByRole("alert")).toContainText(
      "Chọn khách hàng trước khi lưu lịch",
    );
    await expect(
      dialog.getByLabel("Chọn khách hàng", { exact: true }),
    ).toBeFocused();
    await dialog
      .getByLabel("Ghi chú lịch hẹn", { exact: true })
      .fill("Bản chưa lưu");
    page.once("dialog", (prompt) => prompt.dismiss());
    await dialog
      .getByRole("button", { name: "Đóng biểu mẫu", exact: true })
      .click();
    await expect(dialog).toBeVisible();
    page.once("dialog", (prompt) => prompt.accept());
    await dialog
      .getByRole("button", { name: "Đóng biểu mẫu", exact: true })
      .click();
    await bookExistingCustomer(page);
    const row = page.getByRole("listitem", {
      name: "SPA-007 · Đỗ Mỹ Duyên",
      exact: true,
    });
    await row
      .getByRole("button", { name: "Sửa lịch hẹn", exact: true })
      .click();
    dialog = page.getByRole("dialog", { name: "Sửa lịch hẹn", exact: true });
    await dialog.getByLabel("Giờ hẹn", { exact: true }).fill("16:00");
    await dialog
      .getByRole("button", { name: "Lưu lịch hẹn mẫu", exact: true })
      .click();
    await expect(row).toContainText("16:00");
    await row
      .getByRole("button", { name: "Hủy lịch hẹn", exact: true })
      .click();
    dialog = page.getByRole("dialog", { name: "Hủy lịch hẹn", exact: true });
    await dialog
      .getByRole("button", { name: "Xác nhận hủy lịch mẫu", exact: true })
      .click();
    await expect(dialog.getByRole("alert")).toContainText("Nhập lý do hủy");
    await dialog
      .getByLabel("Lý do hủy", { exact: true })
      .fill("Khách đổi kế hoạch");
    await dialog
      .getByRole("button", { name: "Xác nhận hủy lịch mẫu", exact: true })
      .click();
    await expect(row).toContainText("Đã hủy");
    await row
      .getByRole("button", { name: "Xem chi tiết", exact: true })
      .click();
    await expect(
      page.getByRole("dialog", { name: "Chi tiết lịch hẹn mẫu", exact: true }),
    ).toContainText("Khách đổi kế hoạch");
  });
  test("phone customer form stays within viewport and duplicate phones retain the entered data", async ({
    page,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await setup(page);
    await page.goto("/spa/customers");
    await page
      .getByRole("button", { name: "Thêm khách hàng mẫu", exact: true })
      .click();
    const dialog = page.getByRole("dialog", {
      name: "Thêm khách hàng mẫu",
      exact: true,
    });
    await dialog
      .getByLabel("Tên khách hàng", { exact: true })
      .fill("Khách trùng số");
    await dialog
      .getByLabel("Số điện thoại mẫu", { exact: true })
      .fill("0000000001");
    await dialog
      .getByRole("button", { name: "Lưu khách hàng mẫu", exact: true })
      .click();
    await expect(dialog.getByRole("alert")).toContainText(
      "Số điện thoại đã có",
    );
    await expect(
      dialog.getByLabel("Tên khách hàng", { exact: true }),
    ).toHaveValue("Khách trùng số");
    await expect(
      dialog.getByLabel("Số điện thoại mẫu", { exact: true }),
    ).toBeFocused();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
  });
});

test.describe("Spa concept one interactive modules", () => {
  test.skip(!previewEnabled, "SPA UI preview flag disabled");
  const nav = (page: Page) =>
    page.getByRole("navigation", { name: "Chức năng spa" });
  const openSpa = async (page: Page, name: string) => {
    await nav(page).getByRole("link", { name, exact: true }).click();
    await expect(
      page.getByRole("heading", { name, exact: true, level: 2 }),
    ).toBeVisible();
  };
  test("completes a service, collects partially and reconciles the receipt in cashbook/report", async ({
    page,
  }) => {
    const requests = await setup(page);
    await page.goto("/spa/technician");
    await page.getByLabel("Chọn KTV để xem dữ liệu mẫu").selectOption("tech-2");
    await page
      .getByRole("button", { name: "Bắt đầu dịch vụ", exact: true })
      .click();
    await page
      .getByLabel("Kết quả thực hiện", { exact: true })
      .fill("Đã chăm sóc da, hướng dẫn tại nhà theo mẫu.");
    await expect(
      page.getByRole("status").filter({ hasText: "Đã lưu nháp trong phiên" }),
    ).toBeVisible();
    await openSpa(page, "Thu ngân");
    await openSpa(page, "Công việc KTV");
    await expect(
      page.getByLabel("Kết quả thực hiện", { exact: true }),
    ).toHaveValue("Đã chăm sóc da, hướng dẫn tại nhà theo mẫu.");
    await page
      .getByRole("button", { name: "Hoàn tất dịch vụ", exact: true })
      .click();
    await page
      .getByRole("dialog")
      .getByRole("button", { name: "Lưu mẫu", exact: true })
      .click();
    await openSpa(page, "Thu ngân");
    const row = page.getByRole("row").filter({ hasText: "INV-SPA-002" });
    await expect(row).toContainText("450.000");
    await row.getByRole("button", { name: "Thu tiền", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("Số tiền (₫)", { exact: true }).fill("450001");
    await dialog.getByRole("button", { name: "Lưu mẫu", exact: true }).click();
    await expect(dialog.getByRole("alert")).toBeVisible();
    await expect(
      dialog.getByLabel("Số tiền (₫)", { exact: true }),
    ).toBeFocused();
    await dialog.getByLabel("Số tiền (₫)", { exact: true }).fill("150000");
    await dialog.getByRole("button", { name: "Lưu mẫu", exact: true }).click();
    await expect(row).toContainText("300.000");
    await openSpa(page, "Sổ quỹ");
    await expect(
      page.getByRole("row").filter({ hasText: "INV-SPA-002" }),
    ).toContainText("150.000");
    await expect(
      page.getByText("Thực thu", { exact: true }).locator(".."),
    ).toContainText("400.000");
    await openSpa(page, "Báo cáo spa");
    await expect(
      page.getByText("Thực thu", { exact: true }).first().locator(".."),
    ).toContainText("400.000");
    const download = page.waitForEvent("download");
    await page.getByRole("button", { name: "Xuất CSV mẫu" }).click();
    expect((await download).suggestedFilename()).toContain("bao-cao");
    expect(requests.every((r) => r.method === "GET")).toBe(true);
  });
  test("package booking retains balance; handover requires recipient acceptance before one completion", async ({
    page,
  }) => {
    await setup(page);
    await page.goto("/spa/treatments");
    const pack = page.getByRole("article").filter({ hasText: "PKG-001" });
    await expect(pack).toContainText("3/10 buổi");
    await pack.getByRole("button", { name: "Đặt buổi tiếp theo" }).click();
    const booking = page.getByRole("dialog");
    await booking.getByLabel("Giờ hẹn", { exact: true }).fill("16:00");
    await booking.getByRole("button", { name: "Lưu lịch hẹn mẫu" }).click();
    await expect(pack).toContainText("3/10 buổi");
    await openSpa(page, "Công việc KTV");
    await page
      .getByRole("button", { name: "Bàn giao KTV", exact: true })
      .click();
    const handover = page.getByRole("dialog");
    await handover.getByLabel("KTV nhận bàn giao").selectOption("tech-3");
    await handover.getByLabel("Lý do / diễn giải").fill("Đổi ca mẫu");
    await handover
      .getByRole("button", { name: "Lưu mẫu", exact: true })
      .click();
    await expect(
      page.getByText("Đang chờ Bích Ngọc nhận bàn giao"),
    ).toBeVisible();
    await expect(
      page.getByText("KTV chịu trách nhiệm: Lan Anh", { exact: false }),
    ).toBeVisible();
    await page.getByLabel("Chọn KTV để xem dữ liệu mẫu").selectOption("tech-3");
    await page.getByRole("button", { name: "Bàn giao", exact: true }).click();
    await page
      .getByRole("button", { name: "Nhận bàn giao", exact: true })
      .click();
    await page.getByRole("button", { name: "Hôm nay", exact: true }).click();
    await page
      .getByLabel("Kết quả thực hiện", { exact: true })
      .fill("Đã nhận ca và hoàn tất chăm sóc mẫu.");
    await page
      .getByRole("button", { name: "Hoàn tất dịch vụ", exact: true })
      .click();
    await page
      .getByRole("dialog")
      .getByRole("button", { name: "Lưu mẫu", exact: true })
      .click();
    await openSpa(page, "Liệu trình");
    await expect(pack).toContainText("4/10 buổi");
    await openSpa(page, "Thu ngân");
    await page.getByRole("button", { name: "Tất cả", exact: true }).click();
    await expect(
      page.getByRole("row").filter({ hasText: "INV-SPA-001" }),
    ).toHaveCount(0);
  });
  test("catalog and inventory share retail stock, with no automatic cash receipt", async ({
    page,
  }) => {
    const requests = await setup(page);
    await page.goto("/spa/services");
    await page
      .getByRole("button", { name: "Thêm dịch vụ", exact: true })
      .click();
    const dialog = page.getByRole("dialog");
    await dialog
      .getByLabel("Tên dịch vụ", { exact: true })
      .fill("Chăm sóc mới mẫu");
    await dialog.getByLabel("Đơn giá (₫)", { exact: true }).fill("120000");
    await dialog.getByRole("button", { name: "Lưu mẫu", exact: true }).click();
    await expect(
      page.getByRole("heading", { name: "Chăm sóc mới mẫu", exact: true }),
    ).toBeVisible();
    await page
      .getByRole("button", { name: "Sản phẩm bán lẻ", exact: true })
      .click();
    await page
      .getByRole("article")
      .filter({ hasText: "Serum phục hồi" })
      .getByRole("button", { name: "Bán sản phẩm" })
      .click();
    await dialog
      .getByLabel("Khách hàng", { exact: true })
      .selectOption("customer-2");
    await dialog.getByLabel("Số lượng", { exact: true }).fill("2");
    await dialog.getByRole("button", { name: "Lưu mẫu", exact: true }).click();
    await openSpa(page, "Kho");
    const product = page
      .getByRole("row")
      .filter({ hasText: "Serum phục hồi" })
      .first();
    await expect(product.getByRole("cell").nth(2)).toHaveText("16");
    await product
      .getByRole("button", { name: "Xuất kho", exact: true })
      .click();
    await dialog.getByLabel("Số lượng", { exact: true }).fill("17");
    await dialog.getByLabel("Lý do / diễn giải").fill("Dùng nội bộ mẫu");
    await dialog.getByRole("button", { name: "Lưu mẫu", exact: true }).click();
    await expect(dialog.getByRole("alert")).toContainText("Không đủ tồn kho");
    await dialog.getByLabel("Số lượng", { exact: true }).fill("1");
    await dialog.getByRole("button", { name: "Lưu mẫu", exact: true }).click();
    await expect(product.getByRole("cell").nth(2)).toHaveText("15");
    await openSpa(page, "Thu ngân");
    await expect(
      page.getByRole("row").filter({ hasText: "Bán lẻ" }),
    ).toContainText("840.000");
    expect(requests.every((r) => r.method === "GET")).toBe(true);
  });
  test("phone supports care forms and manager settings without page overflow or permission writes", async ({
    page,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const requests = await setup(page);
    await page.goto("/spa/care");
    await page
      .getByRole("button", { name: "Tạo việc chăm sóc", exact: true })
      .click();
    const dialog = page.getByRole("dialog");
    await dialog
      .getByLabel("Khách hàng", { exact: true })
      .selectOption("customer-6");
    await dialog
      .getByLabel("Nội dung chăm sóc", { exact: true })
      .fill("Hỏi thăm khách mẫu");
    await dialog
      .getByLabel("Người phụ trách", { exact: true })
      .selectOption("sample-reception");
    await dialog.getByRole("button", { name: "Lưu mẫu", exact: true }).click();
    const job = page
      .getByRole("article")
      .filter({ hasText: "Hỏi thăm khách mẫu" });
    await job
      .getByRole("button", { name: "Ghi nhận kết quả", exact: true })
      .click();
    await dialog
      .getByLabel("Kết quả thực hiện")
      .fill("Khách đã phản hồi theo mẫu.");
    await dialog.getByRole("button", { name: "Lưu mẫu", exact: true }).click();
    await page
      .getByRole("button", { name: "Đã hoàn thành", exact: true })
      .click();
    await expect(job).toContainText("Khách đã phản hồi");
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBe(true);
    await page
      .getByRole("button", { name: "Đóng hoặc mở thanh điều hướng" })
      .click();
    await openSpa(page, "Thiết lập spa");
    await page
      .getByRole("button", { name: "Chỉnh sửa cấu hình", exact: true })
      .click();
    await dialog
      .getByLabel("Tên cơ sở", { exact: true })
      .fill("KPS Spa thử nghiệm");
    await dialog
      .getByLabel("Tỷ lệ hoa hồng mẫu (%)", { exact: true })
      .fill("8");
    await dialog.getByRole("button", { name: "Lưu mẫu", exact: true }).click();
    await expect(
      page.getByText("KPS Spa thử nghiệm", { exact: true }),
    ).toBeVisible();
    await expect(
      page.getByText("Quyền xem hiện tại", { exact: true }).first(),
    ).toBeVisible();
    await page
      .getByRole("button", { name: "Đóng hoặc mở thanh điều hướng" })
      .click();
    await openSpa(page, "Công việc KTV");
    await page
      .getByRole("region", { name: "Danh sách công việc" })
      .getByRole("button", { name: /Nguyễn Minh Anh/ })
      .click();
    await page
      .getByLabel("Kết quả thực hiện", { exact: true })
      .fill("Bản nháp cần xóa khi khôi phục mẫu.");
    page.once("dialog", (dialog) => dialog.accept());
    await page
      .getByRole("button", { name: "Khôi phục dữ liệu mẫu", exact: true })
      .click();
    await page
      .getByRole("region", { name: "Danh sách công việc" })
      .getByRole("button", { name: /Nguyễn Minh Anh/ })
      .click();
    await expect(
      page.getByLabel("Kết quả thực hiện", { exact: true }),
    ).toHaveValue("");
    expect(requests.every((r) => r.method === "GET")).toBe(true);
  });
});

const beforePng = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAmUlEQVR4nO3PMQ2AUBTFUOyiAAEowAkK0IaB/wam0qTJHTvcs93nvtxzHcv9rd/+digAfSgAfSgAfegzwHJ06gPQfQC6D0D3foDl6NQHoPsAdB+A7v0Ay9GpD0D3Aeg+AN37AZajUx+A7gPQfQC69wMsR6c+AN0HoPsAdO8HWI5OfQC6D0D3AejeD7AcnfoAdB+A7gPQvR7wAuP0EZaHDwA5AAAAAElFTkSuQmCC",
  "base64",
);
const afterPng = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAmUlEQVR4nO3PMQ2AUBTFUPwvKMABChCBAsRg4L+BqTRpcscO92z3uS/3XMdyf+u3vx0KQB8KQB8KQB/6DLAcnfoAdB+A7gPQvR9gOTr1Aeg+AN0HoHs/wHJ06gPQfQC6D0D3foDl6NQHoPsAdB+A7v0Ay9GpD0D3Aeg+AN37AZajUx+A7gPQfQC69wMsR6c+AN0HoPsAdK8HvAXQ4fA0zIORAAAAAElFTkSuQmCC",
  "base64",
);

test.describe("Spa inline booking and skin photos", () => {
  test.skip(!previewEnabled, "SPA UI preview flag disabled");
  const navigate = async (page: Page, name: string) => {
    await page
      .getByRole("navigation", { name: "Chức năng spa" })
      .getByRole("link", { name, exact: true })
      .click();
    await expect(
      page.getByRole("heading", { name, exact: true, level: 2 }),
    ).toBeVisible();
  };
  test("phone creates new customer in the booking form and resolves duplicate phone without losing schedule", async ({
    page,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const requests = await setup(page);
    await page.goto("/spa/appointments");
    await page
      .getByRole("button", { name: "Tạo lịch hẹn mẫu", exact: true })
      .click();
    const form = page.getByRole("dialog");
    await form.getByLabel("Giờ hẹn", { exact: true }).fill("16:00");
    await form
      .getByLabel("Dịch vụ 1", { exact: true })
      .selectOption("skinRecovery");
    await form
      .getByRole("button", { name: "Khách hàng mới", exact: true })
      .click();
    await form
      .getByLabel("Tên khách hàng", { exact: true })
      .fill("Khách tại form mẫu");
    await form
      .getByLabel("Số điện thoại mẫu", { exact: true })
      .fill("0000000001");
    await form
      .getByRole("button", { name: "Tạo khách và lưu lịch mẫu" })
      .click();
    await expect(form.getByRole("alert")).toContainText(
      "đã có trong danh sách",
    );
    await expect(
      form.getByLabel("Số điện thoại mẫu", { exact: true }),
    ).toBeFocused();
    await form.getByRole("button", { name: "Dùng khách hàng này" }).click();
    await expect(
      form.getByLabel("Chọn khách hàng", { exact: true }),
    ).toHaveValue("customer-1");
    await expect(form.getByLabel("Giờ hẹn", { exact: true })).toHaveValue(
      "16:00",
    );
    await expect(form.getByLabel("Dịch vụ 1", { exact: true })).toHaveValue(
      "skinRecovery",
    );
    await form
      .getByRole("button", { name: "Khách hàng mới", exact: true })
      .click();
    await form
      .getByLabel("Số điện thoại mẫu", { exact: true })
      .fill("0900000129");
    await form.getByLabel("Giờ hẹn", { exact: true }).fill("23:30");
    await form
      .getByRole("button", { name: "Tạo khách và lưu lịch mẫu" })
      .click();
    await expect(form.getByRole("alert")).toBeVisible();
    await expect(
      form.getByRole("button", { name: "Dùng khách hàng này" }),
    ).toHaveCount(0);
    await form.getByLabel("Giờ hẹn", { exact: true }).fill("16:00");
    await form
      .getByRole("button", { name: "Tạo khách và lưu lịch mẫu" })
      .click();
    await expect(form).toBeHidden();
    await expect(
      page.getByRole("listitem").filter({ hasText: "Khách tại form mẫu" }),
    ).toHaveCount(1);
    await page
      .getByRole("button", { name: "Đóng hoặc mở thanh điều hướng" })
      .click();
    await navigate(page, "Khách hàng");
    await page
      .getByLabel("Tìm khách theo tên hoặc số điện thoại")
      .fill("0900000129");
    await expect(
      page.getByRole("article", { name: "Khách tại form mẫu" }),
    ).toHaveCount(1);
    expect(requests.every((r) => r.method === "GET")).toBe(true);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
  });
  test("photos decode locally, compare, survive navigation and revoke preview URLs after removal/reset", async ({
    page,
  }) => {
    const requests = await setup(page);
    await page.goto("/spa/technician");
    const section = page.getByRole("region", {
      name: "Ảnh tình trạng da",
      exact: true,
    });
    await section.getByLabel("Ảnh từ thiết bị", { exact: true }).setInputFiles({
      name: "invalid.svg",
      mimeType: "image/svg+xml",
      buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"/>'),
    });
    await expect(section.getByRole("alert")).toContainText("JPEG");
    await section
      .getByLabel("Ghi chú ảnh (không bắt buộc)", { exact: true })
      .fill("Ảnh trước buổi mẫu");
    await section.getByLabel("Ảnh từ thiết bị", { exact: true }).setInputFiles({
      name: "skin-before.png",
      mimeType: "image/png",
      buffer: beforePng,
    });
    await expect(section.locator("img")).toHaveCount(1);
    await section
      .getByRole("button", { name: "Sau dịch vụ", exact: true })
      .click();
    await section.getByLabel("Ảnh từ camera", { exact: true }).setInputFiles({
      name: "skin-after.png",
      mimeType: "image/png",
      buffer: afterPng,
    });
    await expect(section.locator("img")).toHaveCount(2);
    await expect(section).toContainText("Người xem UI");
    const url = await section.locator("img").first().getAttribute("src");
    expect(url).toMatch(/^blob:/);
    await section
      .getByRole("button", { name: "Đối chiếu ảnh", exact: true })
      .click();
    const viewer = page.getByRole("region", {
      name: "Đối chiếu ảnh tình trạng da",
      exact: true,
    });
    await expect(viewer.locator("img")).toHaveCount(2);
    await viewer
      .getByRole("button", { name: "Xem lớn", exact: true })
      .first()
      .click();
    await expect(viewer.locator("img")).toHaveCount(1);
    await viewer
      .getByRole("button", { name: "Quay lại đối chiếu", exact: true })
      .click();
    await viewer
      .getByRole("button", { name: "Thu gọn ảnh", exact: true })
      .click();
    await navigate(page, "Khách hàng");
    await page
      .getByRole("article", { name: "Nguyễn Minh Anh", exact: true })
      .getByRole("button", { name: "Hồ sơ khách hàng", exact: true })
      .click();
    await page
      .locator("aside")
      .filter({ hasText: "Ảnh tình trạng da" })
      .getByRole("button", { name: "Đối chiếu ảnh", exact: true })
      .click();
    await expect(viewer.locator("img")).toHaveCount(2);
    await navigate(page, "Công việc KTV");
    await expect(section.locator("img")).toHaveCount(2);
    await section
      .getByRole("button", { name: "Gỡ ảnh", exact: true })
      .first()
      .click();
    await section
      .getByRole("button", { name: "Xác nhận gỡ ảnh", exact: true })
      .click();
    await expect(section.getByRole("alert")).toBeVisible();
    await section
      .getByLabel("Lý do gỡ ảnh", { exact: true })
      .fill("Ảnh thử cần thay góc");
    await section
      .getByRole("button", { name: "Xác nhận gỡ ảnh", exact: true })
      .click();
    await expect(section.locator("img")).toHaveCount(1);
    expect(
      await page.evaluate(async (url) => {
        try {
          await fetch(url!);
          return false;
        } catch {
          return true;
        }
      }, url),
    ).toBe(true);
    page.once("dialog", (dialog) => dialog.accept());
    await page
      .getByRole("button", { name: "Khôi phục dữ liệu mẫu", exact: true })
      .click();
    await expect(section.locator("img")).toHaveCount(0);
    expect(requests.every((r) => r.method === "GET")).toBe(true);
  });
  test("KTV phone keeps photo history after completion and locks removal of completed records", async ({
    page,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await setupWorkspaces(page, [technicianWorkspace]);
    await page.goto("/spa/technician");
    await page
      .getByRole("region", { name: "Danh sách công việc" })
      .getByRole("button", { name: /Nguyễn Minh Anh/ })
      .click();
    const section = page.getByRole("region", {
      name: "Ảnh tình trạng da",
      exact: true,
    });
    await section.getByLabel("Ảnh từ thiết bị", { exact: true }).setInputFiles({
      name: "before.png",
      mimeType: "image/png",
      buffer: beforePng,
    });
    await expect(section.locator("img")).toHaveCount(1);
    await section
      .getByRole("button", { name: "Sau dịch vụ", exact: true })
      .click();
    await section.getByLabel("Ảnh từ thiết bị", { exact: true }).setInputFiles({
      name: "after.png",
      mimeType: "image/png",
      buffer: afterPng,
    });
    await expect(section.locator("img")).toHaveCount(2);
    await page
      .getByLabel("Kết quả thực hiện", { exact: true })
      .fill("Hoàn tất buổi chăm sóc mẫu.");
    await page
      .getByRole("button", { name: "Hoàn tất dịch vụ", exact: true })
      .click();
    await page
      .getByRole("dialog")
      .getByRole("button", { name: "Lưu mẫu", exact: true })
      .click();
    await page.getByRole("button", { name: "Đã làm", exact: true }).click();
    await page
      .getByRole("region", { name: "Danh sách công việc" })
      .getByRole("button", { name: /Nguyễn Minh Anh/ })
      .click();
    await expect(section.locator("img")).toHaveCount(2);
    await expect(
      section.getByRole("button", { name: "Gỡ ảnh", exact: true }),
    ).toHaveCount(0);
    await expect(
      section.getByRole("button", { name: "Trước dịch vụ", exact: true }),
    ).toHaveCount(0);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
    await page.reload();
    await page
      .getByRole("region", { name: "Danh sách công việc" })
      .getByRole("button", { name: /Nguyễn Minh Anh/ })
      .click();
    await expect(section.locator("img")).toHaveCount(0);
  });
});

test.describe("Spa concept one layout", () => {
  test.skip(!previewEnabled, "SPA UI preview flag disabled");
  test("overview opens booking in place and header search finds an existing customer", async ({
    page,
  }) => {
    const requests = await setup(page);
    await page.goto("/spa");
    await expect(
      page.getByRole("img", { name: "Biểu đồ số lượt hẹn theo giờ" }),
    ).toBeVisible();
    await expect(
      page.getByRole("heading", { name: "Không gian phục vụ", exact: true }),
    ).toBeVisible();
    await page
      .getByRole("button", { name: "Tạo lịch hẹn mẫu", exact: true })
      .click();
    await expect(page.getByRole("dialog")).toBeVisible();
    await page
      .getByRole("button", { name: "Đóng biểu mẫu", exact: true })
      .click();
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await page
      .getByRole("searchbox", {
        name: "Tìm khách, số điện thoại…",
        exact: true,
      })
      .fill("0000000002");
    await page
      .getByRole("searchbox", {
        name: "Tìm khách, số điện thoại…",
        exact: true,
      })
      .press("Enter");
    await expect(
      page.getByRole("heading", { name: "Khách hàng", exact: true, level: 2 }),
    ).toBeVisible();
    await expect(
      page.getByRole("article", { name: "Trần Ngọc Linh", exact: true }),
    ).toBeVisible();
    await expect(
      page.getByRole("article", { name: "Nguyễn Minh Anh", exact: true }),
    ).toHaveCount(0);
    expect(requests.every((request) => request.method === "GET")).toBe(true);
  });
  test("timeline opens the correct visit and reception moves it between board columns", async ({
    page,
  }) => {
    await setup(page);
    await page.goto("/spa/appointments");
    const timeline = page.getByRole("region", {
      name: "Lịch phục vụ theo kỹ thuật viên",
      exact: true,
    });
    await expect(timeline).toBeVisible();
    const event = timeline.getByRole("button", {
      name: "09:00 – 10:00 · Nguyễn Minh Anh · Chăm sóc phục hồi da",
      exact: true,
    });
    await event.click();
    await expect(page.getByRole("dialog")).toContainText(
      "Nguyễn Minh Anh · SPA-001",
    );
    await page
      .getByRole("button", { name: "Đóng biểu mẫu", exact: true })
      .click();
    await page
      .getByRole("navigation", { name: "Chức năng spa" })
      .getByRole("link", { name: "Tiếp nhận và điều phối", exact: true })
      .click();
    const booked = page.getByRole("region", {
      name: "Đã đặt lịch",
      exact: true,
    });
    const waiting = page.getByRole("region", {
      name: "Chờ phục vụ",
      exact: true,
    });
    await expect(booked.getByRole("article")).toHaveCount(1);
    await expect(waiting.getByRole("article")).toHaveCount(2);
    await booked
      .getByRole("button", { name: "Xác nhận khách đến", exact: true })
      .click();
    await expect(booked.getByRole("article")).toHaveCount(0);
    await expect(waiting.getByRole("article")).toHaveCount(3);
    await expect(
      waiting.getByRole("article", {
        name: "SPA-004 · Phạm Bảo Ngọc",
        exact: true,
      }),
    ).toContainText("Tiếp nhận: Người xem UI");
  });
  test("phone keeps timeline scrolling inside the calendar and customer cards inside the viewport", async ({
    page,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await setup(page);
    await page.goto("/spa/appointments");
    const grid = page.getByRole("region", {
      name: "Lưới giờ và nhân viên, có thể cuộn",
      exact: true,
    });
    await expect(grid).toBeVisible();
    expect(
      await grid.evaluate(
        (element) => element.scrollWidth > element.clientWidth,
      ),
    ).toBe(true);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
    await page
      .getByRole("button", { name: "Đóng hoặc mở thanh điều hướng" })
      .click();
    await page
      .getByRole("navigation", { name: "Chức năng spa" })
      .getByRole("link", { name: "Khách hàng", exact: true })
      .click();
    await expect(
      page.getByRole("article", { name: "Nguyễn Minh Anh", exact: true }),
    ).toBeVisible();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
  });
});
