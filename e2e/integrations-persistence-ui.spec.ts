import { expect, test, type Page } from "@playwright/test";

async function setup(page: Page, canManage = true) {
  const rows: Record<string, unknown>[] = [];
  let conflict = false;
  let writes = 0;
  await page.route("**/api/**", async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const method = request.method();
    if (path === "/api/auth/me")
      return route.fulfill({
        json: {
          id: 1,
          name: "Admin test",
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
      return route.fulfill({
        json: { data: [{ id: "1", name: "Nhân viên KPS" }] },
      });
    if (path.startsWith("/api/integrations")) {
      if (method === "GET")
        return route.fulfill({
          json: { data: rows, actions: { can_manage: canManage } },
        });
      writes++;
      if (conflict)
        return route.fulfill({ status: 409, json: { message: "Conflict" } });
      const payload = request.postDataJSON();
      if (method === "POST") {
        const row = {
          ...payload,
          id: "1",
          version: 1,
          owner: "Nhân viên KPS",
          connection_status: "not_connected",
        };
        rows.push(row);
        return route.fulfill({ status: 201, json: { data: row } });
      }
      if (method === "PATCH") {
        const row = {
          ...rows[0],
          ...payload,
          version: Number(payload.version) + 1,
        };
        rows[0] = row;
        return route.fulfill({ json: { data: row } });
      }
      rows.splice(0);
      return route.fulfill({ status: 204 });
    }
    return route.fulfill({
      json: {
        data: [],
        unread_count: 0,
        meta: { total: 0, current_page: 1, last_page: 1 },
      },
    });
  });
  await page.goto("/integrations");
  await expect(
    page.getByRole("button", { name: "Tải lại danh sách", exact: true }),
  ).toBeEnabled();
  return {
    rows,
    writes: () => writes,
    conflict: () => {
      conflict = true;
    },
  };
}

async function addFolder(page: Page) {
  await page.getByRole("button", { name: "Thêm thư mục", exact: true }).click();
  await page
    .getByLabel("Tên thư mục", { exact: false })
    .fill("Kế hoạch Marketing");
  await page
    .getByLabel("Đường dẫn", { exact: false })
    .fill("https://drive.google.com/drive/folders/AbCdE123");
  await page.getByLabel("Người phụ trách", { exact: false }).selectOption("1");
  await page
    .getByRole("button", { name: "Lưu thông tin", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
}

test("shared API CRUD persists after reload and failures preserve form input", async ({
  page,
}) => {
  const api = await setup(page);
  await addFolder(page);
  expect(api.writes()).toBe(1);
  await page.reload();
  await expect(
    page.getByRole("cell", { name: "Kế hoạch Marketing", exact: false }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Chỉnh sửa: Kế hoạch Marketing", exact: true })
    .click();
  await page.getByLabel("Tên thư mục", { exact: false }).fill("Kế hoạch mới");
  await page
    .getByRole("button", { name: "Lưu thông tin", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  expect(api.rows[0].version).toBe(2);
  await page
    .getByRole("button", { name: "Xóa: Kế hoạch mới", exact: true })
    .click();
  await page.getByRole("button", { name: "Hủy", exact: true }).click();
  expect(api.rows).toHaveLength(1);
  await page
    .getByRole("button", { name: "Xóa: Kế hoạch mới", exact: true })
    .click();
  await page.getByRole("button", { name: "Xóa", exact: true }).click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  expect(api.rows).toHaveLength(0);
});

test("view-only access hides all write actions", async ({ page }) => {
  const api = await setup(page, false);
  await expect(
    page.getByRole("button", { name: "Thêm thư mục", exact: true }),
  ).toHaveCount(0);
  await page.getByRole("tab", { name: /MXH/ }).click();
  await expect(
    page.getByRole("button", { name: "Thêm trang/kênh", exact: true }),
  ).toHaveCount(0);
  expect(api.writes()).toBe(0);
});

test("invalid inputs focus the field; stale updates preserve input and show recovery", async ({
  page,
}) => {
  const api = await setup(page);
  await page.getByRole("button", { name: "Thêm thư mục", exact: true }).click();
  await page
    .getByRole("button", { name: "Lưu thông tin", exact: true })
    .click();
  await expect(page.locator("#integration-name")).toBeFocused();
  await expect(page.locator("#integration-name")).toHaveAttribute(
    "aria-invalid",
    "true",
  );
  expect(api.writes()).toBe(0);
  await page.getByRole("button", { name: "Hủy", exact: true }).click();
  await addFolder(page);
  api.conflict();
  await page
    .getByRole("button", { name: "Chỉnh sửa: Kế hoạch Marketing", exact: true })
    .click();
  await page.getByLabel("Tên thư mục", { exact: false }).fill("Chưa lưu");
  await page
    .getByRole("button", { name: "Lưu thông tin", exact: true })
    .click();
  await expect(page.getByRole("alert")).toContainText(
    "đã được người khác thay đổi",
  );
  await expect(page.locator("#integration-name")).toHaveValue("Chưa lưu");
  expect(api.rows[0].name).toBe("Kế hoạch Marketing");
});

test("mobile uses the same API and keeps form actions accessible", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await setup(page);
  await addFolder(page);
  await page
    .getByRole("button", { name: "Chi tiết: Kế hoạch Marketing", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await expect(
    page.getByRole("dialog").getByText("Nhân viên KPS", { exact: true }),
  ).toBeVisible();
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Đóng", exact: true })
    .last()
    .click();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth > window.innerWidth,
  );
  expect(overflow).toBe(false);
});
