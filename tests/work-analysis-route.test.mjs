import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const moduleUrl = (code) =>
  `data:text/javascript;base64,${Buffer.from(ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText).toString("base64")}`;
const dateModule = moduleUrl(
  await readFile(new URL("../src/utils/dateOnly.ts", import.meta.url), "utf8"),
);
const { workAnalysisRoute, workAnalysisSearch } = await import(
  moduleUrl(
    (
      await readFile(
        new URL("../src/utils/workAnalysisRoute.ts", import.meta.url),
        "utf8",
      )
    ).replace('"./dateOnly"', JSON.stringify(dateModule)),
  )
);
const route = (query) =>
  workAnalysisRoute(new URLSearchParams(query), "2026-11-10");

test("existing period-one saved links open the daily analysis with the same run, date and department", () => {
  assert.deepEqual(route("analysis=301&days=1&end=2026-11-05&department=42"), {
    daily: true,
    historyId: 301,
    filters: { end_date: "2026-11-05", days: 1, department_id: 42 },
  });
});

test("old employee daily-mail links retain the work date and use the daily analysis route", () => {
  const old = route("view=daily&date=2026-11-05&page=2");
  assert.equal(old.daily, true);
  assert.equal(old.filters.days, 1);
  assert.equal(old.filters.end_date, "2026-11-05");
  assert.equal(old.historyId, null);
});

test("both tabs default to yesterday while the period tab defaults to seven days", () => {
  assert.equal(route("").filters.days, 7);
  assert.equal(route("").filters.end_date, "2026-11-09");
  assert.equal(route("view=daily").filters.days, 1);
  assert.equal(route("view=daily").filters.end_date, "2026-11-09");
});

test("daily mode cannot silently create a multi-day analysis from stale query parameters", () => {
  assert.equal(route("view=daily&days=30").filters.days, 1);
  assert.equal(route("days=2").filters.days, 7);
  assert.equal(route("days=30").filters.days, 30);
});

test("invalid dates and IDs never become NaN or invalid date requests", () => {
  assert.deepEqual(
    route("view=daily&date=2026-02-30&department=-1&analysis=abc").filters,
    { end_date: "2026-11-09", days: 1, department_id: null },
  );
  assert.equal(route("analysis=9007199254740992").historyId, null);
  assert.equal(route("department=1.2").filters.department_id, null);
});

test("switching between tabs preserves date and department and drops the other mode's saved run", () => {
  const daily = workAnalysisSearch({
    end_date: "2026-11-05",
    days: 1,
    department_id: 42,
  });
  assert.equal(daily.get("view"), "daily");
  const weekly = workAnalysisSearch({ ...route(daily).filters, days: 7 });
  assert.equal(weekly.get("view"), null);
  assert.deepEqual(route(weekly).filters, {
    end_date: "2026-11-05",
    days: 7,
    department_id: 42,
  });
  assert.equal(weekly.get("analysis"), null);
});

test("a historical version creates the correct daily or periodic link without rewriting its date", () => {
  for (const days of [1, 7, 30]) {
    const filters = { end_date: "2026-01-05", days, department_id: null };
    const selected = route(workAnalysisSearch(filters, 450));
    assert.equal(selected.daily, days === 1);
    assert.equal(selected.historyId, 450);
    assert.deepEqual(selected.filters, filters);
  }
});
