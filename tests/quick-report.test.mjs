import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const source = await readFile(
  new URL("../src/utils/quickReport.ts", import.meta.url),
  "utf8",
);
const pageSource = await readFile(
  new URL("../src/pages/Reports/NewReport.tsx", import.meta.url),
  "utf8",
);
const detailedFormSource = await readFile(
  new URL("../src/components/reports/WorkItemForm.tsx", import.meta.url),
  "utf8",
);
const reportDetailsSource = await readFile(
  new URL(
    "../src/components/reports/ReportWorkItemDetails.tsx",
    import.meta.url,
  ),
  "utf8",
);
const transpiled = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.ES2022,
    target: ts.ScriptTarget.ES2022,
  },
}).outputText;
const quickReport = await import(
  `data:text/javascript;base64,${Buffer.from(transpiled).toString("base64")}`
);

const item = (overrides = {}) => ({
  clientId: "existing",
  content: "Công việc đang có",
  result: "",
  status: "IN_PROGRESS",
  note: "Ghi chú được giữ nguyên",
  ...overrides,
});

const idFactory = () => {
  let id = 0;
  return () => `quick-${++id}`;
};

test("quick report parser supports optional Unicode and ASCII arrows", () => {
  assert.deepEqual(
    quickReport.parseQuickReportText(
      " abc \r\nabc → xyz\nabc →\n→ xyz\nabc → xyz → extra\nabc -> xyz\nabc -> xyz -> extra\n-> xyz\nabc - xyz\n\nabc",
    ),
    [
      { content: "abc", result: "" },
      { content: "abc", result: "xyz" },
      { content: "abc", result: "" },
      { content: "→ xyz", result: "" },
      { content: "abc", result: "xyz → extra" },
      { content: "abc", result: "xyz" },
      { content: "abc", result: "xyz -> extra" },
      { content: "-> xyz", result: "" },
      { content: "abc - xyz", result: "" },
      { content: "abc", result: "" },
    ],
  );
});

test("candidate builder preserves duplicate input lines and does not count blank placeholders", () => {
  const result = quickReport.buildCandidateItems(
    [
      item(),
      item({
        clientId: "placeholder",
        content: "",
        result: "",
        status: "",
        note: "",
      }),
    ],
    {
      COMPLETED: "Cùng nội dung\nCùng nội dung",
      IN_PROGRESS: "",
      BLOCKED: "",
    },
    idFactory(),
  );

  assert.equal(result.ok, true);
  assert.equal(result.items.length, 3);
  assert.equal(result.addedCount, 2);
  assert.equal(result.items[0].note, "Ghi chú được giữ nguyên");
  assert.deepEqual(
    result.items.slice(1).map(({ content, status }) => ({ content, status })),
    [
      { content: "Cùng nội dung", status: "COMPLETED" },
      { content: "Cùng nội dung", status: "COMPLETED" },
    ],
  );
});

test("flushed drafts do not duplicate items on repeated mode switches", () => {
  const first = quickReport.buildCandidateItems(
    [],
    {
      COMPLETED: "Việc A",
      IN_PROGRESS: "Việc B",
      BLOCKED: "",
    },
    idFactory(),
  );
  assert.equal(first.ok, true);

  const second = quickReport.buildCandidateItems(
    first.items,
    quickReport.createEmptyQuickReportDrafts(),
    idFactory(),
  );
  assert.equal(second.ok, true);
  assert.equal(second.items.length, 2);
  assert.equal(second.addedCount, 0);
});

test("detailed edits keep notes and move an item to its new quick group", () => {
  const edited = item({ status: "COMPLETED", result: "Đã xong" });
  const groups = quickReport.groupWorkItemsByStatus([edited]);

  assert.equal(groups.IN_PROGRESS.length, 0);
  assert.equal(groups.COMPLETED.length, 1);
  assert.equal(groups.COMPLETED[0].note, "Ghi chú được giữ nguyên");
  assert.equal(groups.COMPLETED[0].result, "Đã xong");
});

test("candidate builder atomically rejects too many or oversized quick items", () => {
  const existing = Array.from({ length: 49 }, (_, index) =>
    item({ clientId: `existing-${index}` }),
  );
  const overLimit = quickReport.buildCandidateItems(
    existing,
    {
      COMPLETED: "Việc 50\nViệc 51",
      IN_PROGRESS: "",
      BLOCKED: "",
    },
    idFactory(),
  );
  assert.deepEqual(overLimit, {
    ok: false,
    error: { code: "item_limit", limit: 50 },
  });
  assert.equal(existing.length, 49);

  const oversized = quickReport.buildCandidateItems(
    [],
    {
      COMPLETED: "x".repeat(5001),
      IN_PROGRESS: "",
      BLOCKED: "",
    },
    idFactory(),
  );
  assert.equal(oversized.ok, false);
  assert.equal(oversized.error.code, "content_too_long");
  assert.equal(oversized.error.line, 1);
  assert.equal(oversized.error.limit, 5000);
  assert.equal(quickReport.parseQuickReportText("x".repeat(5001)).length, 1);
});

test("pending quick text is included directly in the submit candidate", () => {
  const result = quickReport.buildCandidateItems(
    [item()],
    {
      COMPLETED: "Đã làm → Đã xong",
      IN_PROGRESS: "Đang làm",
      BLOCKED: "Vướng phê duyệt",
    },
    idFactory(),
  );

  assert.equal(result.ok, true);
  assert.equal(result.items.length, 4);
  assert.deepEqual(
    result.items.slice(1).map(({ content, result, status }) => ({
      content,
      result,
      status,
    })),
    [
      { content: "Đã làm", result: "Đã xong", status: "COMPLETED" },
      { content: "Đang làm", result: "", status: "IN_PROGRESS" },
      { content: "Vướng phê duyệt", result: "", status: "BLOCKED" },
    ],
  );
});

test("report submit builds a local candidate and only refreshes state on a cutoff conflict", () => {
  assert.match(
    pageSource,
    /const candidate = buildCandidateItems\(items, quickDrafts, createClientId\)/,
  );
  assert.match(pageSource, /items: candidateItems\.map/);
  assert.match(pageSource, /if \(submitError\.status === 409\)/);
  assert.match(pageSource, /setRecoveryText\(unsavedContent \|\| null\)/);

  const catchBlock = pageSource.slice(
    pageSource.indexOf("} catch (submitError)"),
    pageSource.indexOf(
      "} finally {",
      pageSource.indexOf("} catch (submitError)"),
    ),
  );
  assert.doesNotMatch(catchBlock, /setItems\(/);
  assert.doesNotMatch(catchBlock, /setQuickDrafts\(/);
});

test("result is optional in the detailed editor and hidden when empty in read-only detail", () => {
  const resultLabel = detailedFormSource.slice(
    detailedFormSource.indexOf("result.label"),
    detailedFormSource.indexOf(
      "</Label>",
      detailedFormSource.indexOf("result.label"),
    ),
  );

  assert.doesNotMatch(resultLabel, /text-error-500/);
  assert.match(detailedFormSource, /errors\?\.result/);
  assert.match(reportDetailsSource, /item\.result\.trim\(\) &&/);
});
