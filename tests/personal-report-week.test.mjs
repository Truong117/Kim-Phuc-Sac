import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const moduleUrl = (code) =>
  `data:text/javascript;base64,${Buffer.from(ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText).toString("base64")}`;
const dates = moduleUrl(
  await readFile(new URL("../src/utils/dateOnly.ts", import.meta.url), "utf8"),
);
const { lastReportSaturday, personalReportWeek } = await import(
  moduleUrl(
    (
      await readFile(
        new URL("../src/utils/personalReportWeek.ts", import.meta.url),
        "utf8",
      )
    ).replace('"./dateOnly"', JSON.stringify(dates)),
  )
);

test("default personal report uses the last Saturday across weekdays and month boundaries", () => {
  assert.equal(lastReportSaturday("2026-11-11"), "2026-11-07");
  assert.equal(lastReportSaturday("2026-11-08"), "2026-11-07");
  assert.equal(lastReportSaturday("2026-11-07"), "2026-11-07");
  assert.equal(lastReportSaturday("2027-01-01"), "2026-12-26");
});

test("saved weekday and Sunday endings are accepted without changing the seven-day range", () => {
  for (const invalid of [null, "2026-02-30", "2026-11-21", "bad"]) {
    assert.equal(personalReportWeek(invalid, "2026-11-11"), "2026-11-07");
  }
  assert.equal(personalReportWeek("2026-11-14", "2026-11-11"), "2026-11-14");
  assert.equal(personalReportWeek("2026-11-08", "2026-11-11"), "2026-11-08");
  assert.equal(personalReportWeek("2026-11-04", "2026-11-11"), "2026-11-04");
  assert.equal(personalReportWeek("2026-10-31", "2026-11-11"), "2026-10-31");
  assert.equal(personalReportWeek("2026-11-14", "2026-11-07"), "2026-11-07");
});
