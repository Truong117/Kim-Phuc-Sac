import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

test("AI daily reports use the scoped available-dates API instead of a native date input", async () => {
  const [page, service, picker] = await Promise.all([
    readSource("src/pages/AI/AiDailyInsights.tsx"),
    readSource("src/services/aiDailyReportService.ts"),
    readSource("src/components/ai/AiReportDatePicker.tsx"),
  ]);

  assert.match(service, /\/api\/ai\/daily-reports\/available-dates/);
  assert.match(page, /getAiDailyAvailableDates/);
  assert.match(page, /availableDates\.includes\(date\)/);
  assert.doesNotMatch(page, /type=["']date["']/);
  assert.match(picker, /<CalenderIcon/);
  assert.match(picker, /aria-haspopup="dialog"/);
  assert.match(picker, /disabled=\{!available\}/);
});

test("AI report calendar exposes keyboard and refresh behavior", async () => {
  const [page, picker, dateUtility, translations] = await Promise.all([
    readSource("src/pages/AI/AiDailyInsights.tsx"),
    readSource("src/components/ai/AiReportDatePicker.tsx"),
    readSource("src/utils/aiReportDate.ts"),
    readSource("src/locales/vi/common.json"),
  ]);
  const messages = JSON.parse(translations).aiDailyReport;

  assert.match(
    page,
    /window\.addEventListener\("focus", refreshAvailableDates\)/,
  );
  assert.match(page, /document\.addEventListener\("visibilitychange"/);
  assert.match(picker, /event\.key === "ArrowDown"/);
  assert.match(picker, /"ArrowLeft", "ArrowUp"/);
  assert.match(picker, /"ArrowRight", "ArrowDown"/);
  assert.match(picker, /event\.key === "Escape"/);
  assert.match(picker, /previousMonth/);
  assert.match(picker, /nextMonth/);
  assert.match(dateUtility, /day:\s*"2-digit"/);
  assert.match(dateUtility, /month:\s*"2-digit"/);
  assert.match(dateUtility, /timeZone:\s*"UTC"/);
  assert.equal(messages.noAvailableDatesTitle, "Chưa có ngày báo cáo phù hợp");
  assert.equal(
    messages.calendar.unavailable,
    "Không có báo cáo phù hợp, không thể chọn",
  );
});

test("AI report dates render as Vietnamese dd/MM/yyyy without changing API keys", async () => {
  const source = await readSource("src/utils/aiReportDate.ts");
  const transpiled = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.ES2022,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;
  const dateModule = await import(
    `data:text/javascript;base64,${Buffer.from(transpiled).toString("base64")}`
  );

  assert.equal(dateModule.formatAiReportDate("2026-10-09"), "09/10/2026");
});
