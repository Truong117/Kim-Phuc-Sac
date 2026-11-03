import assert from "node:assert/strict";
import { after, before, test } from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { MemoryRouter } from "react-router";
import { createServer } from "vite";

let vite, Quick, Detailed, Plan, Details, evidence, syncTextAreas;
before(async () => {
  vite = await createServer({
    appType: "custom",
    logLevel: "silent",
    server: { middlewareMode: true, watch: { ignored: ["**/backend/**"] } },
  });
  await vite.ssrLoadModule("/src/i18n/index.ts");
  [Quick, Detailed, Plan, Details] = await Promise.all(
    [
      "QuickReportEditor",
      "WorkItemList",
      "ReportNextDayPlan",
      "ReportWorkItemDetails",
    ].map(
      async (name) =>
        (await vite.ssrLoadModule(`/src/components/reports/${name}.tsx`))
          .default,
    ),
  );
  evidence = await vite.ssrLoadModule("/src/utils/reportEvidence.ts");
  syncTextAreas = (
    await vite.ssrLoadModule("/src/utils/syncReportTextAreas.ts")
  ).syncReportTextAreas;
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

test("quick and detailed modes show four sections without duplicated results in order and preserve optional item fields", () => {
  const props = {
    items: [item],
    drafts: { COMPLETED: "", IN_PROGRESS: "", BLOCKED: "" },
    errors: {},
    disabled: false,
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
      "2. Công việc đang thực hiện",
      "3. Kế hoạch ngày mai",
      "4. Công việc có vấn đề / cần hỗ trợ",
    ];
    assert.ok(!html.includes("Kết quả đạt được"));
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

test("completed tasks require results in both editors while unfinished tasks remain optional", () => {
  for (const component of [Quick, Detailed]) {
    for (const status of ["COMPLETED", "IN_PROGRESS", "BLOCKED"]) {
      const html = render(component, {
        items: [{ ...item, status, result: "" }],
        drafts: { COMPLETED: "", IN_PROGRESS: "", BLOCKED: "" },
        errors: {
          a: { result: "Vui lòng nhập kết quả của công việc đã hoàn thành." },
        },
        onItemChange() {},
        onItemBlur() {},
        onItemDelete() {},
        onItemAdd() {},
        onDraftChange() {},
        onAddBatch() {},
      });
      const textarea = html.match(
        /<textarea[^>]*id="(?:quick-)?result-a"[^>]*>/,
      )[0];
      assert.equal(/required=""/.test(textarea), status === "COMPLETED");
      assert.ok(html.includes("Vui lòng nhập kết quả"));
    }
  }
});

test("resizing synchronizes either direction within a row without feedback, value changes or leaking into other rows", () => {
  const original = globalThis.ResizeObserver;
  const instances = [];
  globalThis.ResizeObserver = class {
    observed = [];
    constructor(callback) {
      this.callback = callback;
      instances.push(this);
    }
    observe(field) {
      this.observed.push(field);
    }
    disconnect() {
      this.disconnected = true;
    }
  };
  try {
    const a = { style: { height: "" }, value: "Nội dung" };
    const b = { style: { height: "" }, value: "Kết quả" };
    const c = { style: { height: "" }, value: "Minh chứng" };
    const stop = syncTextAreas({ querySelectorAll: () => [a, b] });
    const stopOther = syncTextAreas({ querySelectorAll: () => [c] });
    const observer = instances[0];
    observer.callback([{ target: a }]); // Width/layout change: leave initial rows alone.
    assert.equal(b.style.height, "");
    a.style.height = "180px";
    observer.callback([{ target: a }]);
    assert.equal(b.style.height, "180px");
    observer.callback([{ target: b }]); // Synchronized observer event does not loop.
    b.style.height = "90px";
    observer.callback([{ target: b }]);
    assert.equal(a.style.height, "90px");
    assert.equal(c.style.height, "");
    assert.deepEqual([a.value, b.value], ["Nội dung", "Kết quả"]);
    stop();
    stopOther();
    assert.ok(instances.every((entry) => entry.disconnected));
  } finally {
    globalThis.ResizeObserver = original;
  }
});
