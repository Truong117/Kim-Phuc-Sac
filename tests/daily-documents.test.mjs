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

test("employee cards start collapsed, show source counts and do not duplicate self-reported text", () => {
  for (const document of [null, doc()]) {
    const html = render(document);
    assert.match(html, /<details class="group">/);
    assert.ok(!html.includes("<details open"));
    for (const text of [
      "Nhân viên thử",
      "Xem kết quả",
      "Báo cáo bù",
      "Báo cáo #7",
      'href="/reports/7"',
      "Công việc đã hoàn thành: 1",
      "Công việc đang thực hiện: 1",
    ])
      assert.ok(html.includes(text), text);
    for (const text of [
      "Đã đăng 2 video",
      "Dựng video",
      "Làm 30 video",
      "Chờ duyệt",
      "Ghi chú cũ",
      "javascript:",
      "<script>",
    ])
      assert.ok(!html.includes(text), text);
  }
});
test("partial errors retain explicit retry, while exhausted retries have a visible explanation", () => {
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
  const exhausted = render(
    doc({
      units: [
        {
          id: 2,
          part: 0,
          status: "failed",
          error_code: "AI_AUTHENTICATION_FAILED",
          can_retry: false,
        },
      ],
    }),
  );
  assert.ok(exhausted.includes("Đã hết lượt thử lại cho bản này"));
  assert.ok(!exhausted.includes(">Thử lại phần lỗi<"));
  assert.ok(
    exhausted.indexOf("Đã hết lượt") >
      exhausted.indexOf("</details></details>"),
  );
  const changed = render(
    doc({
      requires_new_document: true,
      units: [
        {
          id: 2,
          part: 0,
          status: "failed",
          error_code: "AI_AUTHENTICATION_FAILED",
          can_retry: false,
        },
      ],
    }),
  );
  assert.ok(changed.includes("Bản ngày dùng cấu hình cũ"));
  assert.ok(!changed.includes("Đã hết lượt"));
});
test("source link and counts refer to the frozen report instead of changed current content", () => {
  const document = doc({
    source: {
      ...source,
      report_id: 8,
      items: [{ ...source.items[0], status: "BLOCKED" }],
    },
  });
  const html = render(document, true);
  assert.ok(html.includes('href="/reports/8"'));
  assert.ok(html.includes("Công việc bị vướng mắc: 1"));
  assert.ok(html.includes("Công việc đang thực hiện: 0"));
  assert.ok(html.includes("Nguồn hiện tại đã khác"));
});
test("AI grouped work is escaped and shown before metrics and assessment, with source references", () => {
  const html = render(
    doc({
      status: "completed",
      mode: "openai",
      model: "gpt-6-luna",
      units: [],
      result: {
        work_groups: [
          {
            title: "Nội dung truyền thông",
            text: "<script>AI text</script>",
            evidence: [{ report_id: 7, item_id: 9, quote: "Đã đăng 2 video" }],
          },
        ],
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
  assert.ok(html.includes("bg-success-50"));
  assert.ok(html.includes("&lt;script&gt;AI text&lt;/script&gt;"));
  assert.ok(!html.includes("<script>"));
  assert.ok(html.includes("Cần xác nhận"));
  assert.ok(!html.includes("null video"));
  assert.ok(
    html.indexOf("Nội dung truyền thông") < html.indexOf("Số liệu có căn cứ"),
  );
  assert.ok(
    html.indexOf("Số liệu có căn cứ") < html.indexOf("Cần làm rõ việc duyệt"),
  );
  assert.ok(html.includes('href="/reports/7#work-item-9"'));
  assert.ok(!html.includes("Đã hết lượt"));
});
