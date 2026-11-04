import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const source = await readFile(
  new URL("../src/utils/reportValidation.ts", import.meta.url),
  "utf8",
);
const code = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.ES2022,
    target: ts.ScriptTarget.ES2022,
  },
}).outputText;
const { mapReportFieldErrors, focusFirstReportError } = await import(
  `data:text/javascript;base64,${Buffer.from(code).toString("base64")}`
);

test("API field errors follow submitted identities and combine nested evidence errors without leaking to another item", () => {
  const result = mapReportFieldErrors(
    {
      "items.1.evidence_links.1": ["Link lặp"],
      "items.1.evidence_links.2": ["Link thứ hai lặp"],
      "items.0.result": ["Thiếu kết quả"],
      "items.0.status": ["Trạng thái sai"],
      next_day_plan: ["Thiếu kế hoạch"],
      "items.99.content": ["Không có phần tử này"],
      report_date: ["Ngày hết hạn"],
    },
    [{ clientId: "in-progress" }, { clientId: "completed" }],
  );
  assert.deepEqual(result, {
    items: {
      completed: { evidenceLinks: "Link lặp" },
      "in-progress": { result: "Thiếu kết quả", status: "Trạng thái sai" },
    },
    plan: "Thiếu kế hoạch",
  });
  assert.deepEqual(mapReportFieldErrors(undefined, []), { items: {} });
});

test("focus waits for the rendered errors, uses DOM order, and falls back to the error summary", () => {
  const previous = globalThis.requestAnimationFrame;
  let frame;
  globalThis.requestAnimationFrame = (callback) => {
    frame = callback;
  };
  try {
    const calls = [];
    const field = {
      focus: (options) => calls.push(["focus", options]),
      closest: () => null,
      scrollIntoView: (options) => calls.push(["scroll", options]),
    };
    let invalid = field;
    const root = {
      isConnected: true,
      querySelector: (selector) =>
        selector.includes("aria-invalid") ? invalid : field,
    };
    focusFirstReportError(root);
    assert.deepEqual(calls, []);
    frame();
    assert.deepEqual(calls, [
      ["focus", { preventScroll: true }],
      ["scroll", { behavior: "smooth", block: "center", inline: "nearest" }],
    ]);
    invalid = null;
    calls.length = 0;
    focusFirstReportError(root);
    frame();
    assert.equal(calls.length, 2);
    root.isConnected = false;
    calls.length = 0;
    focusFirstReportError(root);
    frame();
    assert.deepEqual(calls, []);
  } finally {
    globalThis.requestAnimationFrame = previous;
  }
});
