import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";
import vm from "node:vm";
const source = await readFile(
  new URL("../src/utils/facebookAnalytics.ts", import.meta.url),
  "utf8",
);
const options = {
  compilerOptions: {
    module: ts.ModuleKind.CommonJS,
    target: ts.ScriptTarget.ES2022,
  },
};
const dateExports = {};
vm.runInNewContext(
  ts.transpileModule(
    await readFile(
      new URL("../src/utils/dateOnly.ts", import.meta.url),
      "utf8",
    ),
    options,
  ).outputText,
  { exports: dateExports, Intl, Date },
);
const exports = {};
vm.runInNewContext(ts.transpileModule(source, options).outputText, {
  exports,
  Intl,
  Date,
  Map,
  require(name) {
    if (name === "./dateOnly") return dateExports;
    throw new Error("Unexpected import");
  },
});
test("CSV captions cannot execute spreadsheet formulas and numbers keep their sign", () => {
  for (const value of [
    "=SUM(A1:A2)",
    " +SUM(A1:A2)",
    "\t@import",
    "-cmd",
    "\0=SUM(A1:A2)",
  ])
    assert.match(exports.csvCell(value), /^"'/);
  assert.equal(exports.csvCell(-3), '"-3"');
  assert.equal(exports.csvCell(null), '""');
  assert.equal(exports.csvCell('a"b'), '"a""b"');
});
test("observation chart keeps missing days null and preserves zero and negative changes", () => {
  const rows = exports.analyticsSeries(
    [
      {
        trend: [
          { date: "2026-11-02", likes: 0, comments: null, media_views: null },
          { date: "2026-11-03", likes: -2, comments: 1, media_views: null },
        ],
      },
    ],
    "2026-11-01",
    "2026-11-03",
  );
  assert.equal(rows[0].likes, null);
  assert.equal(rows[1].likes, 0);
  assert.equal(rows[2].likes, -2);
  assert.equal(rows[2].media_views, null);
  assert.equal(
    exports.interactionGrowth({ observed_delta: { likes: 1, comments: null } }),
    null,
  );
  assert.equal(
    exports.interactionGrowth({ observed_delta: { likes: -2, comments: 0 } }),
    -2,
  );
});
