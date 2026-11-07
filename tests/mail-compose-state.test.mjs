import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";
const source = (
  await readFile(
    new URL("../src/utils/mailComposeState.ts", import.meta.url),
    "utf8",
  )
).replace(
  /import \{ mailTemplateIds,[\s\S]*?from "\.\/mailTemplates";/,
  'const mailTemplateIds = ["daily", "weekly", "weekly_plan", "monthly_plan", "issue"];',
);
const js = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.ESNext,
    target: ts.ScriptTarget.ES2022,
  },
}).outputText;
const {
  parseMailComposeState,
  finishedMailRun,
  resumableMailRun,
  hasMailDraft,
} = await import(
  `data:text/javascript;base64,${Buffer.from(js).toString("base64")}`
);
const key = "d292ebcf-0c33-47be-9777-f616d5d3e39b";
const old = {
  keys: { 12: key },
  selectedIds: ["12"],
  subject: "Thư cũ",
  body: "Giữ nguyên nội dung",
};

test("legacy keys migrate into a frozen run while the composer starts empty", () => {
  const state = parseMailComposeState(null, JSON.stringify(old));
  assert.equal(hasMailDraft(state.draft), false);
  assert.equal(state.runs[0].keys[12], key);
  assert.equal(state.runs[0].subject, old.subject);
  assert.equal(state.runs[0].body, old.body);
  assert.equal(state.runs[0].checked, undefined);
  assert.equal(resumableMailRun(state.runs[0]), false);
});

test("draft metadata and manual edits survive without trusting saved send results", () => {
  const draft = {
    selectedIds: ["12"],
    subject: "Đã biên tập",
    body: "Bản nháp riêng",
    template: {
      kind: "weekly",
      date: "2026-11-07",
      page: 1,
      selected: "52",
      sourceLabel: "Báo cáo tuần",
      ready: true,
    },
  };
  const stored = {
    draft,
    runs: [
      { ...old, id: key, checked: true, results: [{ status: "accepted" }] },
    ],
  };
  const state = parseMailComposeState(JSON.stringify(stored), null);
  assert.deepEqual(state.draft, draft);
  assert.equal(state.runs[0].checked, undefined);
  assert.equal(state.runs[0].results, undefined);
  assert.equal(finishedMailRun(state.runs[0]), false);
});

test("only confirmed queued/missing requests resume; unknown and active sends cannot", () => {
  const run = {
    ...old,
    id: key,
    checked: true,
    missing: [],
    results: [{ status: "unknown" }],
  };
  assert.equal(resumableMailRun(run), false);
  assert.equal(
    resumableMailRun({ ...run, results: [{ status: "sending" }] }),
    false,
  );
  assert.equal(
    resumableMailRun({ ...run, results: [{ status: "queued" }] }),
    true,
  );
  assert.equal(resumableMailRun({ ...run, missing: [key], results: [] }), true);
  assert.equal(
    resumableMailRun({ ...run, readError: true, missing: [key] }),
    false,
  );
  for (const status of ["accepted", "failed", "cancelled"]) {
    assert.equal(finishedMailRun({ ...run, results: [{ status }] }), true);
    assert.equal(resumableMailRun({ ...run, results: [{ status }] }), false);
  }
  assert.equal(
    finishedMailRun({
      ...run,
      missing: [key],
      results: [{ status: "accepted" }],
    }),
    false,
  );
});

test("malformed snapshots fail closed rather than silently dropping live request keys", () => {
  assert.throws(() => parseMailComposeState("{", JSON.stringify(old)));
  assert.throws(() =>
    parseMailComposeState(
      JSON.stringify({ draft: {}, runs: [{ id: key, keys: {} }] }),
      null,
    ),
  );
  assert.deepEqual(parseMailComposeState(null, null).runs, []);
});
