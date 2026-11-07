import assert from "node:assert/strict";
import { after, before, test } from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { MemoryRouter } from "react-router";
import { createServer } from "vite";

let vite;
let Employees;
before(async () => {
  vite = await createServer({
    appType: "custom",
    logLevel: "silent",
    server: { middlewareMode: true, watch: { ignored: ["**/backend/**"] } },
  });
  await vite.ssrLoadModule("/src/i18n/index.ts");
  Employees = (await vite.ssrLoadModule("/src/components/ai/WorkAnalysisEmployees.tsx")).default;
});
after(async () => { await vite?.close(); });

const inventory = [{ content: "Bán hàng", observations: [
  { report_id: 1, item_id: 11, date: "2026-10-26", status: "COMPLETED", result: "Bán 10 sản phẩm", note: null },
  { report_id: 2, item_id: 22, date: "2026-10-28", status: "IN_PROGRESS", result: "Bán 30 sản phẩm", note: "Đã giao khách" },
] }];
const person = { employee_id: 7, name: "Sale A", department_name: "Kinh doanh", status: "running", completed_units: 0,
  total_units: 1, failed_units: 0, source_report_ids: [1, 2], result: null, work_inventory: inventory };
const render = (employees) => renderToStaticMarkup(React.createElement(MemoryRouter, null,
  React.createElement(Employees, { employees, sources: [] })));

test("weekly inventory is visible before AI completes and preserves both dates, results, statuses and links", () => {
  const html = render([person]);
  for (const text of ["Xem căn cứ", "Bán 10 sản phẩm", "Bán 30 sản phẩm", "26/10/2026", "28/10/2026", "Đã giao khách"]) {
    assert.ok(html.includes(text), text);
  }
  assert.doesNotMatch(html, /<details[^>]*open/);
  assert.ok(!html.includes("Tổng hợp từ báo cáo"));
  assert.match(html, /\/reports\/1#work-item-11/);
  assert.match(html, /\/reports\/2#work-item-22/);
  assert.ok(!html.includes("2. Nhận xét"));
});

test("confirmed totals appear before AI assessment, while ambiguous cumulative totals are not displayed as 40", () => {
  const result = Object.fromEntries(["overview", "highlights", "ongoing", "attention", "support", "uncertainties", "recommendations"].map(k => [k, []]));
  result.overview = [{ text: "Nhận xét có căn cứ: Nội dung nhận xét sau tổng hợp.", evidence: [{ report_id: 1, item_id: 11, quote: "Bán 10 sản phẩm" }] }];
  const total = { metric: "Sản phẩm đã bán", unit: "sản phẩm", total: 40, status: "confirmed", entries: [] };
  const html = render([{ ...person, status: "completed", result, work_totals: [total] }]);
  assert.ok(html.includes("40 sản phẩm"));
  assert.ok(html.indexOf("40 sản phẩm") < html.indexOf("Nội dung nhận xét sau tổng hợp"));
  assert.ok(html.indexOf("Bán 30 sản phẩm") > html.indexOf("Nội dung nhận xét sau tổng hợp"));
  const ambiguous = render([{ ...person, result, work_totals: [{ ...total, total: null, status: "needs_confirmation" }] }]);
  assert.ok(ambiguous.includes("Cần làm rõ"));
  assert.ok(ambiguous.includes("Sản phẩm đã bán"));
  assert.ok(!ambiguous.includes("Cần xác nhận cách cộng"));
  assert.ok(!ambiguous.includes("40 sản phẩm"));
  assert.ok(ambiguous.includes("chưa đại diện đầy đủ"));
});

test("thematic weekly groups precede totals and assessment; source detail remains expandable", () => {
  const result = Object.fromEntries(["overview", "highlights", "ongoing", "attention", "support", "uncertainties", "recommendations"].map(k => [k, []]));
  result.overview = [{ text: "Nhận xét có căn cứ: Kết quả cần đối chiếu", evidence: [{ report_id: 1, item_id: 11, quote: "Bán hàng" }] }];
  const html = render([{ ...person, status: "completed", result,
    work_groups: [{ title: "Kinh doanh trong tuần", text: "Đã bán sản phẩm và bàn giao cho khách", evidence: [{ report_id: 1, item_id: 11, quote: "Bán hàng" }, { report_id: 2, item_id: 22, quote: "Bán hàng" }] }],
  }]);
  assert.ok(html.indexOf("Kinh doanh trong tuần") < html.indexOf("Kết quả cần đối chiếu"));
  assert.ok(html.includes("Xem căn cứ"));
  assert.doesNotMatch(html, /<details[^>]*open/);
  assert.ok(html.includes("Chi tiết công việc theo ngày"));
  assert.ok(html.includes("Bán 30 sản phẩm"));
});

test("employee reader shows one person, moves evidence to the footer and never invents a completed review", () => {
  const html = render([person, { ...person, employee_id: 8, name: "Sale B", work_inventory: [{ content: "Nguồn riêng của B", observations: [] }] }]);
  assert.match(html, /aria-label="Danh sách nhân viên"/);
  assert.match(html, /aria-label="Báo cáo AI của Sale A"/);
  assert.ok(!html.includes("Nguồn riêng của B"));
  assert.ok(html.includes("Chưa có tổng hợp AI"));
  assert.ok(!html.includes("Kết quả mong đợi"));
});

test("structured proposals show action, expected outcome and verification without rewriting legacy proposals", async () => {
  const Result = (await vite.ssrLoadModule("/src/components/ai/WorkAnalysisResult.tsx")).default;
  const empty = Object.fromEntries(["overview", "highlights", "ongoing", "attention", "support", "uncertainties", "recommendations"].map(key => [key, []]));
  const evidence = [{ report_id: 1, item_id: 11, quote: "Bán 10 sản phẩm" }];
  const result = { ...empty, recommendations: [{ text: "Đề xuất: Đối chiếu kết quả", evidence, priority: "high", rationale: "Nguồn đã ghi nhận sản phẩm", impact: "Chưa đủ thông tin về ảnh hưởng", action: "Đối chiếu bàn giao", expected_outcome: "Làm rõ số đã giao", verification: "Kiểm tra biên nhận", decision_needed: "" }] };
  const show = value => renderToStaticMarkup(React.createElement(MemoryRouter, null, React.createElement(Result, { result: value, sources: [] })));
  const html = show(result);
  for (const text of ["Ưu tiên cao", "Căn cứ", "Hành động đề xuất", "Kết quả mong đợi", "Cách kiểm chứng"]) assert.ok(html.includes(text), text);
  assert.ok(html.indexOf("Đối chiếu kết quả") < html.indexOf("Xem căn cứ"));
  assert.doesNotMatch(html, /<details[^>]*open/);
  const legacy = show({ ...empty, recommendations: [{ text: "Đề xuất: Nội dung cũ", evidence }] });
  assert.ok(legacy.includes("Nội dung cũ"));
  assert.ok(!legacy.includes("Hành động đề xuất"));
  assert.ok(legacy.includes("Bản cũ chưa lưu"));
});

test("only confirmed finite nonnegative numeric totals enter the work summary", () => {
  const result = Object.fromEntries(["overview", "highlights", "ongoing", "attention", "support", "uncertainties", "recommendations"].map(key => [key, []]));
  const total = { metric: "Sản phẩm", unit: "sản phẩm", total: 40, status: "confirmed", entries: [] };
  const html = render([{ ...person, result, status: "completed", work_totals: [
    total, { ...total, total: -5, metric: "Số âm" }, { ...total, total: Infinity, metric: "Vô hạn" },
    { ...total, total: null, metric: "Chưa rõ" }, { ...total, total: 90, status: "needs_confirmation", metric: "Chưa duyệt cách tính" },
  ] }]);
  assert.ok(html.includes("40 sản phẩm"));
  for (const text of ["-5 sản phẩm", "90 sản phẩm", "Infinity sản phẩm"]) assert.ok(!html.includes(text));
  for (const text of ["Cần làm rõ", "Số âm", "Vô hạn", "Chưa rõ", "Chưa duyệt cách tính"]) assert.ok(html.includes(text));
});


test("company form separates results, ongoing work, obstacles and decisions while keeping legacy evidence collapsed", async () => {
  const Result = (await vite.ssrLoadModule("/src/components/ai/WorkAnalysisResult.tsx")).default;
  const empty = Object.fromEntries(["overview", "highlights", "ongoing", "attention", "support", "uncertainties", "recommendations"].map(key => [key, []]));
  const evidence = [{ report_id: 1, item_id: 11, quote: "Bán 10 sản phẩm" }];
  const result = { ...empty,
    overview: [{ text: "Nhận xét có căn cứ: Đã có bản trình duyệt, chưa xác nhận duyệt cuối.", evidence }],
    ongoing: [{ text: "Ghi nhận từ nguồn: Tiếp tục hoàn thiện catalogue.", evidence }],
    support: [{ text: "Ghi nhận từ nguồn: Đang chờ báo giá lại.", evidence }],
    recommendations: [{ text: "Đề xuất: Đề xuất: Xác nhận kết quả duyệt.", evidence, priority: "normal" }],
  };
  const before = structuredClone(result);
  const html = renderToStaticMarkup(React.createElement(MemoryRouter, null, React.createElement(Result, { result, sources: [{ id: 1, employee_name: "Phương Anh", item_ids: [11] }] })));
  const headings = ["1. Kết quả trong kỳ", "2. Công việc đang tiếp tục", "3. Vướng mắc cần theo dõi", "4. Việc cần xem xét và quyết định"];
  for (let i = 0; i < headings.length; i++) {
    assert.ok(html.includes(headings[i]));
    if (i) assert.ok(html.indexOf(headings[i-1]) < html.indexOf(headings[i]));
  }
  assert.ok(html.includes("Phương Anh — Đã có bản trình duyệt, chưa xác nhận duyệt cuối."));
  assert.doesNotMatch(html, /Nhận xét có căn cứ:|Ghi nhận từ nguồn:|Đề xuất:|<details[^>]*open/);
  assert.ok(html.includes("Bán 10 sản phẩm"));
  assert.ok(html.indexOf("Bán 10 sản phẩm") > html.indexOf("Xem căn cứ"));
  assert.deepEqual(result, before);
});
