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
  for (const text of ["Công việc đã báo cáo trong kỳ", "Bán 10 sản phẩm", "Bán 30 sản phẩm", "26/10/2026", "28/10/2026", "Đã giao khách"]) {
    assert.ok(html.includes(text), text);
  }
  assert.match(html, /<details[^>]*open/);
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
  assert.ok(html.indexOf("Bán 30 sản phẩm") < html.indexOf("Nội dung nhận xét sau tổng hợp"));
  const ambiguous = render([{ ...person, work_totals: [{ ...total, total: null, status: "needs_confirmation" }] }]);
  assert.ok(ambiguous.includes("Cần xác nhận cách cộng"));
  assert.ok(!ambiguous.includes("40 sản phẩm"));
  assert.ok(ambiguous.includes("chưa đại diện đầy đủ"));
});
