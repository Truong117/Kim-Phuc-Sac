import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const moduleUrl = async (path) => {
  const source = await readFile(new URL(`../${path}`, import.meta.url), "utf8");
  return `data:text/javascript;base64,${Buffer.from(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ES2022, target: ts.ScriptTarget.ES2022 } }).outputText).toString("base64")}`;
};
const dateUrl = await moduleUrl("src/utils/dateOnly.ts");
const source = await readFile(
  new URL("../src/utils/reportHolidays.ts", import.meta.url),
  "utf8",
);
const js = ts
  .transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.ES2022,
      target: ts.ScriptTarget.ES2022,
    },
  })
  .outputText.replace('"./dateOnly"', JSON.stringify(dateUrl));
const {
  countScheduledReportDays,
  overlappingReportHoliday,
  reportHolidayStatus,
} = await import(
  `data:text/javascript;base64,${Buffer.from(js).toString("base64")}`
);
const workingDays = [1, 2, 3, 4, 5, 6];

test("report holiday counts are inclusive, exclude weekly rest and handle year boundaries and leap years", () => {
  assert.equal(
    countScheduledReportDays("2026-11-27", "2026-11-30", workingDays),
    3,
  );
  assert.equal(
    countScheduledReportDays("2026-11-29", "2026-11-29", workingDays),
    0,
  );
  assert.equal(
    countScheduledReportDays("2026-12-31", "2027-01-02", workingDays),
    3,
  );
  assert.equal(
    countScheduledReportDays("2028-02-28", "2028-03-01", workingDays),
    3,
  );
  assert.equal(
    countScheduledReportDays("2026-11-23", "2026-11-26", [1, 3, 5]),
    2,
  );
  assert.equal(
    countScheduledReportDays("2026-02-30", "2026-03-01", workingDays),
    0,
  );
  assert.equal(
    countScheduledReportDays("2026-11-30", "2026-11-27", workingDays),
    0,
  );
});

test("overlap includes boundary dates, excludes inactive records and permits editing the same record", () => {
  const rows = [
    {
      id: 1,
      start_date: "2026-12-07",
      end_date: "2026-12-08",
      is_active: true,
    },
    {
      id: 2,
      start_date: "2026-12-10",
      end_date: "2026-12-11",
      is_active: false,
    },
  ];
  assert.equal(
    overlappingReportHoliday(rows, "2026-12-08", "2026-12-09"),
    rows[0],
  );
  assert.equal(
    overlappingReportHoliday(rows, "2026-12-07", "2026-12-08", 1),
    undefined,
  );
  assert.equal(
    overlappingReportHoliday(rows, "2026-12-10", "2026-12-11"),
    undefined,
  );
});

test("holiday statuses distinguish disabled, current, past and future using the server business date", () => {
  const row = {
    is_active: true,
    start_date: "2026-12-07",
    end_date: "2026-12-08",
  };
  assert.equal(reportHolidayStatus(row, "2026-12-06"), "upcoming");
  assert.equal(reportHolidayStatus(row, "2026-12-08"), "current");
  assert.equal(reportHolidayStatus(row, "2026-12-09"), "past");
  assert.equal(
    reportHolidayStatus({ ...row, is_active: false }, "2026-12-07"),
    "inactive",
  );
});
