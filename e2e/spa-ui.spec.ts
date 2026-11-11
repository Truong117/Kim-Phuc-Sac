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

  test("requires KPS login and the existing settings permission", async ({
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
      page.getByRole("link", { name: "Spa · Bản mẫu" }),
    ).toBeVisible();
    await expect(
      page.getByText("Lịch hẹn còn hiệu lực", { exact: true }).locator(".."),
    ).toContainText("5");
    const nav = page.getByRole("navigation", { name: "Chức năng spa" });
    const links = await nav.getByRole("link").evaluateAll((items) =>
      items.map((item) => ({
        href: item.getAttribute("href")!,
        name: item.textContent!.trim(),
      })),
    );
    expect(links).toHaveLength(13);
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
    await page.getByLabel("Tìm khách hoặc mã lịch").fill("minh anh");
    await expect(
      page.getByText("Nguyễn Minh Anh", { exact: true }),
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
      .getByRole("combobox", { name: "Chức năng spa", exact: true })
      .selectOption("/spa/technician");
    const tasks = page.getByRole("region", { name: "Danh sách công việc" });
    await expect(tasks.getByRole("button")).toHaveCount(3);
    await expect(tasks.getByText("Trần Ngọc Linh")).toHaveCount(0);
    await tasks.getByRole("button", { name: /Nguyễn Minh Anh/ }).click();
    const detail = page.getByRole("article", { name: "Chi tiết buổi dịch vụ" });
    await expect(detail).toBeVisible();
    await expect(tasks).toBeHidden();
    await expect(
      detail.getByText("Dịch vụ yêu cầu ảnh tình trạng da"),
    ).toBeVisible();
    await expect(detail).not.toContainText(/điện thoại|công nợ|doanh thu/i);
    await expect(page.locator("main input[type=file]")).toHaveCount(0);
    await detail.getByRole("button", { name: "Quay lại danh sách" }).click();
    await expect(tasks).toBeVisible();
    await page.getByRole("button", { name: "Đã làm", exact: true }).click();
    await expect(tasks.getByText("Võ Thu Hương")).toBeVisible();
    await page.getByRole("button", { name: "Bàn giao", exact: true }).click();
    await expect(page.getByText(/Chưa có yêu cầu bàn giao mẫu/)).toBeVisible();
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
