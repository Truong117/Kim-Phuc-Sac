import assert from "node:assert/strict";
import { before, after, test } from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { MemoryRouter } from "react-router";
import { createServer } from "vite";
let vite, Employee;
before(async () => {
  vite = await createServer({
    appType: "custom",
    logLevel: "silent",
    server: { middlewareMode: true, watch: { ignored: ["**/backend/**"] } },
  });
  await vite.ssrLoadModule("/src/i18n/index.ts");
  Employee = (
    await vite.ssrLoadModule("/src/components/ai/DailyDocumentEmployee.tsx")
  ).default;
});
after(async () => {
  await vite?.close();
});
const source = {
  report_id: 7,
  employee_id: 42,
  date: "2026-11-02",
  is_makeup: true,
  submitted_at: "2026-11-03T00:00:00Z",
  next_day_plan: ["Làm 30 video"],
  items: [
    {
      item_id: 9,
      status: "COMPLETED",
      content: "<script>bad()</script>",
      result: "Đã đăng 2 video",
      note: "Ghi chú cũ",
      issue: "Chờ duyệt",
      evidence_links: ["https://example.com/post", "javascript:bad()"],
      evidence_status: "unverified",
    },
    {
      item_id: 10,
      status: "IN_PROGRESS",
      content: "Dựng video",
      result: "Đang dựng",
      issue: "",
      note: "",
      evidence_links: [],
      evidence_status: "unverified",
    },
  ],
};
function render(document, changed = false) {
  return renderToStaticMarkup(
    React.createElement(
      MemoryRouter,
      null,
      React.createElement(Employee, {
        row: {
          report_id: 7,
          employee_name: "Nhân viên thử",
          source,
          document,
          source_changed: changed,
        },
        busy: false,
        onRequest() {},
        onRetry() {},
      }),
    ),
  );
}
const doc = (patch = {}) => ({
  id: 3,
  report_id: 7,
  status: "partial",
  source,
  mode: "fake",
  model: null,
  captured_at: "2026-11-03T00:00:00Z",
  completed_units: 1,
  total_units: 2,
  result: null,
  units: [
    { id: 1, part: 0, status: "completed", error_code: null, can_retry: false },
    {
      id: 2,
      part: 1,
      status: "failed",
      error_code: "AI_WORKER_INTERRUPTED",
      can_retry: true,
    },
  ],
  ...patch,
});
test("inventory is complete before and after a partial AI failure; data is escaped and unsafe links excluded", () => {
  for (const document of [null, doc()]) {
    const html = render(document);
    for (const text of [
      "Đã đăng 2 video",
      "Dựng video",
      "Làm 30 video",
      "Chờ duyệt",
      "Ghi chú cũ",
      "Báo cáo bù",
    ])
      assert.ok(html.includes(text), text);
    assert.ok(html.includes("&lt;script&gt;bad()&lt;/script&gt;"));
    assert.ok(!html.includes("<script>"));
    assert.ok(!html.includes("javascript:"));
    assert.ok(html.includes('href="https://example.com/post"'));
    assert.ok(html.includes("chưa xác minh"));
  }
});
test("fake/partial state, progress and retry are clear without untranslated placeholders", () => {
  const html = render(doc());
  for (const text of [
    "Bản thử nghiệm dữ liệu",
    "chưa gọi AI thật",
    "Hoàn tất một phần",
    "1/2 phần",
    "Thử lại phần lỗi",
    "AI_WORKER_INTERRUPTED",
  ])
    assert.ok(html.includes(text), text);
  assert.ok(
    !html.includes("{count}") &&
      !html.includes("{part}") &&
      !html.includes("{total}"),
  );
});
test("frozen source stays distinct from newer source and is not replaced by current report text", () => {
  const document = doc({
    source: {
      ...source,
      items: [{ ...source.items[0], content: "Nguồn đã chốt" }],
    },
  });
  const html = render(document, true);
  assert.ok(html.includes("Nguồn đã chốt"));
  assert.ok(!html.includes("Dựng video"));
  assert.ok(html.includes("Nguồn hiện tại đã khác"));
});
test("uncertain totals are never displayed as a number and grounded review follows the inventory", () => {
  const html = render(
    doc({
      result: {
        work_totals: [
          {
            metric: "Video đăng",
            total: null,
            unit: "video",
            status: "needs_confirmation",
          },
        ],
        comments: [
          { item_id: 9, text: "Cần làm rõ việc duyệt", quote: "Chờ duyệt" },
        ],
        recommendations: [],
      },
    }),
  );
  assert.ok(html.includes("Cần xác nhận"));
  assert.ok(!html.includes("null video"));
  assert.ok(
    html.indexOf("Đã đăng 2 video") < html.indexOf("Cần làm rõ việc duyệt"),
  );
  assert.ok(html.includes("Đầu việc #9"));
});
