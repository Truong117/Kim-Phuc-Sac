import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

test("AI daily reports use the scoped available-dates API through the shared date picker", async () => {
  const [page, service, picker, sharedPicker] = await Promise.all([
    readSource("src/pages/AI/AiDailyInsights.tsx"),
    readSource("src/services/aiDailyReportService.ts"),
    readSource("src/components/ai/AiReportDatePicker.tsx"),
    readSource("src/components/common/KpsDatePicker.tsx"),
  ]);

  assert.match(service, /\/api\/ai\/daily-reports\/available-dates/);
  assert.match(page, /getAiDailyAvailableDates/);
  assert.match(page, /availableDates\.includes\(date\)/);
  assert.doesNotMatch(page, /type=["']date["']/);
  assert.match(picker, /<KpsDatePicker/);
  assert.match(picker, /allowedDates=\{availableDates\}/);
  assert.match(picker, /highlightSelectableDates/);
  assert.match(sharedPicker, /<CalenderIcon/);
  assert.match(sharedPicker, /aria-haspopup="dialog"/);
  assert.match(sharedPicker, /disabled=\{!selectable\}/);
});

test("AI report calendar preserves keyboard, availability and refresh behavior", async () => {
  const [page, picker, sharedPicker, dateUtility, translations] = await Promise.all([
    readSource("src/pages/AI/AiDailyInsights.tsx"),
    readSource("src/components/ai/AiReportDatePicker.tsx"),
    readSource("src/components/common/KpsDatePicker.tsx"),
    readSource("src/utils/dateOnly.ts"),
    readSource("src/locales/vi/common.json"),
  ]);
  const messages = JSON.parse(translations).aiDailyReport;

  assert.match(
    page,
    /window\.addEventListener\("focus", refreshAvailableDates\)/,
  );
  assert.match(page, /document\.addEventListener\("visibilitychange"/);
  assert.match(picker, /selectableDateLabel=\{t\("available"\)\}/);
  assert.match(picker, /disabledDateLabel=\{t\("unavailable"\)\}/);
  assert.match(sharedPicker, /event\.key === "ArrowDown"/);
  assert.match(sharedPicker, /event\.key === "ArrowLeft"/);
  assert.match(sharedPicker, /event\.key === "ArrowRight"/);
  assert.match(sharedPicker, /event\.key === "Escape"/);
  assert.match(sharedPicker, /previousMonth/);
  assert.match(sharedPicker, /nextMonth/);
  assert.match(dateUtility, /return `\$\{day\}\/\$\{month\}\/\$\{year\}`/);
  assert.doesNotMatch(dateUtility, /toISOString/);
  assert.equal(messages.noAvailableDatesTitle, "Chưa có ngày báo cáo phù hợp");
  assert.equal(
    messages.calendar.unavailable,
    "Không có báo cáo phù hợp, không thể chọn",
  );
});

test("AI report dates render as Vietnamese dd/MM/yyyy without changing API keys", async () => {
  const source = await readSource("src/utils/dateOnly.ts");
  const transpiled = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.ES2022,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;
  const dateModule = await import(
    `data:text/javascript;base64,${Buffer.from(transpiled).toString("base64")}`
  );

  assert.equal(dateModule.formatDateOnly("2026-10-09"), "09/10/2026");
});
