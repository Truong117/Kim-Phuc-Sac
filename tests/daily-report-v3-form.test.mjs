import assert from "node:assert/strict";
import { after, before, test } from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { MemoryRouter } from "react-router";
import { createServer } from "vite";

let vite, Quick, Detailed, Results, Plan, Details, evidence;
before(async () => {
  vite = await createServer({
    appType: "custom",
    logLevel: "silent",
    server: { middlewareMode: true, watch: { ignored: ["**/backend/**"] } },
  });
  await vite.ssrLoadModule("/src/i18n/index.ts");
  [Quick, Detailed, Results, Plan, Details] = await Promise.all(
    [
      "QuickReportEditor",
      "WorkItemList",
      "ReportResultSummary",
      "ReportNextDayPlan",
      "ReportWorkItemDetails",
    ].map(
      async (name) =>
        (await vite.ssrLoadModule(`/src/components/reports/${name}.tsx`))
          .default,
    ),
  );
  evidence = await vite.ssrLoadModule("/src/utils/reportEvidence.ts");
});
after(async () => {
  await vite?.close();
});
const item = {
  clientId: "a",
  id: 42,
  content: "Đăng video",
  result: "2 video",
  status: "IN_PROGRESS",
  note: "Ghi chú cũ",
  issue: "Chờ duyệt",
  evidenceLinks: "https://example.com/video",
};
const render = (component, props) =>
  renderToStaticMarkup(
    React.createElement(
      MemoryRouter,
      null,
      React.createElement(component, props),
    ),
  );

test("quick and detailed modes show the five sections in order and preserve optional item fields", () => {
  const props = {
    items: [item],
    drafts: { COMPLETED: "", IN_PROGRESS: "", BLOCKED: "" },
    errors: {},
    disabled: false,
    resultsSection: React.createElement(Results, { items: [item] }),
    planSection: React.createElement(Plan, { value: "Dựng video ngày mai" }),
    onDraftChange() {},
    onAddBatch() {},
    onItemChange() {},
    onItemBlur() {},
    onItemDelete() {},
    onItemAdd() {},
  };
  for (const component of [Quick, Detailed]) {
    const html = render(component, props);
    const titles = [
      "1. Công việc đã hoàn thành",
      "2. Kết quả đạt được",
      "3. Công việc đang thực hiện",
      "4. Kế hoạch ngày mai",
      "5. Công việc có vấn đề / cần hỗ trợ",
    ];
    let previous = -1;
    for (const title of titles) {
      const at = html.indexOf(title);
      assert.ok(at > previous, title);
      previous = at;
    }
    for (const text of [
      "2 video",
      "Chờ duyệt",
      "https://example.com/video",
      "Dựng video ngày mai",
      "Chưa xác minh",
    ])
      assert.ok(html.includes(text), text);
  }
});

test("detail shows issue, untouched legacy note and unverified safe links; plan is separate from results", () => {
  const html = render(Details, {
    items: [
      {
        ...item,
        evidence_links: ["javascript:alert(1)", "https://example.com/video"],
        sort_order: 1,
      },
    ],
  });
  assert.ok(html.includes("Ghi chú cũ"));
  assert.ok(html.includes("Chờ duyệt"));
  assert.ok(html.includes('href="https://example.com/video"'));
  assert.ok(!html.includes('href="javascript:'));
  assert.ok(html.includes("Chưa xác minh"));
  assert.ok(
    render(Plan, { value: "", readOnly: true }).includes(
      "Chưa cung cấp kế hoạch",
    ),
  );
  const results = render(Results, { items: [{ ...item, result: "" }] });
  assert.ok(results.includes("Chưa nhập kết quả"));
});

test("evidence parser preserves distinct links but rejects executable, credential-bearing, duplicate and oversized input", () => {
  assert.deepEqual(
    evidence.parseEvidenceLinks(
      " https://example.com/1 \r\n\nhttps://example.com/2",
    ),
    ["https://example.com/1", "https://example.com/2"],
  );
  assert.equal(evidence.hasValidEvidenceLinks(""), true);
  for (const text of [
    "javascript:alert(1)",
    "https://user:secret@example.com/a",
    "https://example.com/a\nhttps://example.com/a",
    "https://example.com/" + "x".repeat(2050),
    Array.from({ length: 11 }, (_, i) => `https://example.com/${i}`).join("\n"),
  ])
    assert.equal(
      evidence.hasValidEvidenceLinks(text),
      false,
      text.slice(0, 50),
    );
});
