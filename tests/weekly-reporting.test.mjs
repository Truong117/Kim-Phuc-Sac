import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const source = await readFile(
  new URL("../src/utils/weeklyReporting.ts", import.meta.url),
  "utf8",
);
const javascript = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.ES2022,
    target: ts.ScriptTarget.ES2022,
  },
}).outputText;
const { filterWeeklyReporters } = await import(
  `data:text/javascript;base64,${Buffer.from(javascript).toString("base64")}`
);

const rows = [
  {
    employee: { id: 1, name: "Đỗ Trường" },
    department: { id: 3, name: "Marketing" },
    missing_dates: ["2026-11-23"],
  },
  {
    employee: { id: 2, name: "Nguyễn An" },
    department: { id: 4, name: "Kinh doanh" },
    missing_dates: [],
  },
  {
    employee: { id: 3, name: "Trần Bình" },
    department: null,
    missing_dates: ["2026-11-24"],
  },
];

test("search accepts Vietnamese names with or without accents and ignores case and surrounding spaces", () => {
  assert.deepEqual(filterWeeklyReporters(rows, "  DO TRUONG ", "", false), [
    rows[0],
  ]);
  assert.deepEqual(filterWeeklyReporters(rows, "Nguyễn", "", false), [rows[1]]);
});

test("department and missing filters compose, including unassigned people and an empty result", () => {
  assert.deepEqual(filterWeeklyReporters(rows, "", "", true), [
    rows[0],
    rows[2],
  ]);
  assert.deepEqual(filterWeeklyReporters(rows, "", "3", true), [rows[0]]);
  assert.deepEqual(filterWeeklyReporters(rows, "", "unassigned", true), [
    rows[2],
  ]);
  assert.deepEqual(filterWeeklyReporters(rows, "", "4", true), []);
  assert.deepEqual(filterWeeklyReporters([], "", "", false), []);
  assert.equal(rows.length, 3);
});
