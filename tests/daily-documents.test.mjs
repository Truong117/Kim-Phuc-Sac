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
        onRebuild() {},
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
  assert.ok(changed.includes("Bản lỗi dùng cấu hình AI cũ"));
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
test("daily AI result uses four merged sections, with all sources in the footer", () => {
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
          {
            title: "Tuyển dụng",
            text: "Đang tìm ứng viên.",
            evidence: [{ report_id: 7, item_id: 10, quote: "Đang dựng" }],
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
          {
            item_id: 9,
            text: "Cần làm rõ việc duyệt.",
            quote: "Đã đăng 2 video",
          },
          {
            item_id: 10,
            text: "Công việc tuyển dụng đang tiếp tục.",
            quote: "Đang dựng",
          },
        ],
        recommendations: [
          {
            item_id: 9,
            text: "Đề nghị người phụ trách xác nhận nội dung.",
            quote: "Chờ duyệt",
          },
        ],
      },
    }),
  );
  assert.deepEqual(
    [...html.matchAll(/<h3[^>]*>(.*?)<\/h3>/g)].map(([, title]) => title),
    ["Tổng hợp từ báo cáo", "Cần làm rõ", "Nhận xét", "Đề xuất hỗ trợ"],
  );
  assert.equal((html.match(/<article\b/g) ?? []).length, 1);
  assert.ok(!html.includes("<h4"));
  assert.ok(!html.includes("Nội dung truyền thông"));
  assert.ok(
    html.includes("&lt;script&gt;AI text&lt;/script&gt; Đang tìm ứng viên."),
  );
  assert.ok(!html.includes("<script>"));
  assert.ok(
    html.includes("Cần làm rõ việc duyệt. Công việc tuyển dụng đang tiếp tục."),
  );
  assert.ok(html.includes("Đề nghị người phụ trách xác nhận nội dung."));
  assert.ok(!html.includes("null video"));
  assert.ok(html.indexOf('href="/reports/7"') > html.lastIndexOf("</section>"));
  assert.ok(
    html.indexOf("Đối chiếu nguồn báo cáo") > html.lastIndexOf("</section>"),
  );
  // Repeated citations are consolidated, while a distinct quotation is retained.
  assert.equal(
    (html.match(/href="\/reports\/7#work-item-9"/g) ?? []).length,
    2,
  );
  assert.equal(
    (html.match(/href="\/reports\/7#work-item-10"/g) ?? []).length,
    1,
  );
  assert.ok(!html.includes("Đã hết lượt"));
});

function renderTotals(work_totals) {
  return render(
    doc({
      status: "completed",
      units: [],
      result: { work_totals, comments: [], recommendations: [] },
    }),
  );
}
function sections(html) {
  return [...html.matchAll(/<section\b[^>]*>[\s\S]*?<\/section>/g)].map(
    ([section]) => section,
  );
}

test("report quantities stay in the summary, while unresolved metrics stay in clarification", () => {
  const html = renderTotals([
    { metric: "Video đã đăng", total: 2, unit: "video", status: "confirmed" },
    { metric: "Khách đã tư vấn", total: 0, unit: "khách", status: "confirmed" },
    {
      metric: "Doanh số lũy kế",
      total: null,
      unit: "đồng",
      status: "needs_confirmation",
    },
  ]);
  const [summary, clarification] = sections(html);
  assert.ok(
    summary.includes("Video đã đăng: 2 video; Khách đã tư vấn: 0 khách"),
  );
  assert.ok(!summary.includes("Doanh số lũy kế"));
  assert.ok(clarification.includes("Doanh số lũy kế"));
  assert.ok(clarification.includes("bg-warning-50"));
  assert.ok(!clarification.includes("Video đã đăng"));
  assert.ok(!html.includes("Số liệu có căn cứ"));
});

test("numeric values never promote unresolved or inconsistent metrics into the summary", () => {
  const html = renderTotals([
    {
      metric: "Doanh số cần làm rõ",
      total: 999,
      unit: "đồng",
      status: "needs_confirmation",
    },
    {
      metric: "Chưa đủ dữ liệu",
      total: null,
      unit: "bài",
      status: "confirmed",
    },
    { metric: "Không hợp lệ", total: -1, unit: "bài", status: "confirmed" },
  ]);
  const [summary, clarification] = sections(html);
  assert.ok(!summary.includes("Số liệu ghi nhận"));
  for (const metric of [
    "Doanh số cần làm rõ",
    "Chưa đủ dữ liệu",
    "Không hợp lệ",
  ])
    assert.ok(clarification.includes(metric));
  assert.ok(!html.includes("999 đồng"));
  assert.ok(!html.includes("null bài"));
});

test("empty AI fields retain all four sections without fabricating review or support", () => {
  const html = renderTotals([]);
  assert.equal(sections(html).length, 4);
  assert.ok(html.includes("Bản này chưa có nội dung tổng hợp công việc"));
  assert.ok(html.includes("Chưa ghi nhận số liệu cần làm rõ."));
  assert.ok(html.includes("Chưa có nhận xét AI cho bản tổng hợp này."));
  assert.ok(html.includes("Chưa có đề xuất hỗ trợ AI cho bản tổng hợp này."));
  assert.ok(!html.includes("Số liệu ghi nhận"));
  const quantified = renderTotals([
    { metric: "Bài đã đăng", total: 1, unit: "bài", status: "confirmed" },
  ]);
  assert.ok(sections(quantified)[0].includes("Bài đã đăng: 1 bài"));
  assert.ok(
    sections(quantified)[1].includes("Chưa ghi nhận số liệu cần làm rõ."),
  );
});

test("rebuild is offered only for failed older contracts, never for exhausted current or completed documents", () => {
  const old = render(
    doc({
      requires_new_document: true,
      status: "failed",
      units: [
        {
          id: 2,
          part: 0,
          status: "failed",
          error_code: "AI_INVALID_EVIDENCE",
          can_retry: false,
        },
      ],
    }),
  );
  assert.ok(old.includes(">Tạo lại bằng bản sửa<"));
  const current = render(
    doc({
      requires_new_document: false,
      status: "failed",
      units: [
        {
          id: 2,
          part: 0,
          status: "failed",
          error_code: "AI_INVALID_EVIDENCE",
          can_retry: false,
        },
      ],
    }),
  );
  assert.ok(!current.includes(">Tạo lại bằng bản sửa<"));
  assert.ok(current.includes("Đã hết lượt"));
  const completed = render(
    doc({ requires_new_document: true, status: "completed", units: [] }),
  );
  assert.ok(!completed.includes(">Tạo lại bằng bản sửa<"));
  assert.ok(!completed.includes("Bản lỗi dùng cấu hình"));
});
