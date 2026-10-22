import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

const importTypeScript = async (relativePath) => {
  const source = await readSource(relativePath);
  const transpiled = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.ES2022,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;

  return import(
    `data:text/javascript;base64,${Buffer.from(transpiled).toString("base64")}`
  );
};

const importTaskFormValidation = async () => {
  const dateOnlySource = await readSource("src/utils/dateOnly.ts");
  const dateOnlyModule = ts.transpileModule(dateOnlySource, {
    compilerOptions: {
      module: ts.ModuleKind.ES2022,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;
  const dateOnlyUrl =
    "data:text/javascript;base64," +
    Buffer.from(dateOnlyModule).toString("base64");
  const validationSource = (
    await readSource("src/utils/taskFormValidation.ts")
  ).replace('from "@/utils/dateOnly"', 'from "' + dateOnlyUrl + '"');
  const validationModule = ts.transpileModule(validationSource, {
    compilerOptions: {
      module: ts.ModuleKind.ES2022,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;

  return import(
    "data:text/javascript;base64," +
      Buffer.from(validationModule).toString("base64")
  );
};

test("date-only utilities preserve API keys and never use ISO timezone conversion", async () => {
  const source = await readSource("src/utils/dateOnly.ts");
  const dates = await importTypeScript("src/utils/dateOnly.ts");

  assert.equal(dates.formatDateOnly("2026-10-09"), "09/10/2026");
  assert.equal(dates.formatDateOnly("not-a-date"), "not-a-date");
  assert.equal(dates.isDateOnly("2026-02-29"), false);
  assert.equal(dates.isDateOnly("2028-02-29"), true);
  assert.equal(dates.addDaysToDateOnly("2026-10-31", 1), "2026-11-01");
  assert.equal(
    dates.isDateOnlySelectable("2026-10-15", {
      minDate: "2026-10-10",
      maxDate: "2026-10-20",
    }),
    true,
  );
  assert.equal(
    dates.isDateOnlySelectable("2026-10-09", { minDate: "2026-10-10" }),
    false,
  );
  assert.equal(
    dates.isDateOnlySelectable("2026-10-21", { maxDate: "2026-10-20" }),
    false,
  );
  assert.equal(
    dates.isDateOnlySelectable("2026-10-15", {
      allowedDates: new Set(["2026-10-14"]),
    }),
    false,
  );
  assert.equal(
    dates.isDateOnlySelectable("2026-10-15", {
      isDateDisabled: (date) => date === "2026-10-15",
    }),
    false,
  );
  assert.equal(
    dates.dateOnlyInTimeZone(
      new Date("2026-10-22T18:30:00.000Z"),
      "Asia/Ho_Chi_Minh",
    ),
    "2026-10-23",
  );
  assert.doesNotMatch(source, /toISOString/);
});

test("shared picker exposes controlled, constraint, clear and accessible popup behavior", async () => {
  const picker = await readSource("src/components/common/KpsDatePicker.tsx");

  assert.match(picker, /export interface KpsDatePickerProps/);
  assert.match(picker, /value:\s*string/);
  assert.match(picker, /onChange:\s*\(date:\s*string\)/);
  assert.match(picker, /onBlur\?:\s*\(\) => void/);
  assert.match(picker, /minDate\?:\s*string/);
  assert.match(picker, /maxDate\?:\s*string/);
  assert.match(picker, /allowedDates\?:\s*readonly string\[\]/);
  assert.match(picker, /isDateDisabled\?:/);
  assert.match(picker, /useId\(\)/);
  assert.match(picker, /useClickOutside\(containerRef, closeCalendar\)/);
  assert.match(picker, /onBlurCapture=/);
  assert.match(picker, /event\.currentTarget\.contains\(nextTarget as Node\)/);
  assert.match(picker, /aria-haspopup="dialog"/);
  assert.match(picker, /aria-expanded=\{isOpen\}/);
  assert.match(picker, /onClick=\{\(\) => finishSelection\(date\)\}/);
  assert.match(picker, /onClick=\{\(\) => finishSelection\(""\)\}/);
  assert.match(picker, /disabled=\{!selectable\}/);
  assert.match(picker, /event\.key === "Escape"/);
  assert.match(picker, /event\.key === "PageUp"/);
  assert.match(picker, /event\.key === "PageDown"/);
  assert.match(picker, /chooseMonth/);
  assert.match(picker, /chooseYear/);
});

test("Task form validation is interaction-aware and rejects invalid submissions", async () => {
  const validation = await importTaskFormValidation();
  const emptyValues = {
    title: "",
    description: "",
    assigneeId: "",
    priority: "MEDIUM",
    startDate: "",
    dueDate: "",
  };
  const emptyErrors = validation.validateTaskForm(emptyValues);

  assert.equal(emptyErrors.dueDate, "dueDateRequired");
  assert.equal(
    validation.shouldShowTaskFormError("dueDate", emptyErrors, {}, false),
    false,
  );
  assert.equal(
    validation.shouldShowTaskFormError(
      "dueDate",
      emptyErrors,
      { dueDate: true },
      false,
    ),
    true,
  );
  assert.equal(
    validation.shouldShowTaskFormError("dueDate", emptyErrors, {}, true),
    true,
  );
  assert.equal(validation.hasTaskFormErrors(emptyErrors), true);

  const validValues = {
    ...emptyValues,
    title: "Chuẩn bị báo cáo",
    assigneeId: "12",
    startDate: "2026-10-22",
    dueDate: "2026-10-23",
  };
  assert.deepEqual(validation.validateTaskForm(validValues), {});

  const invalidOrderErrors = validation.validateTaskForm({
    ...validValues,
    dueDate: "2026-10-21",
  });
  assert.equal(invalidOrderErrors.dueDate, "dueDateBeforeStart");
  assert.equal(
    validation.shouldShowTaskFormError(
      "dueDate",
      invalidOrderErrors,
      { startDate: true },
      false,
    ),
    true,
  );
});

test("Task create and edit share touched date pickers and preserve API date values", async () => {
  const [form, createPage, detailPage] = await Promise.all([
    readSource("src/components/tasks/TaskForm.tsx"),
    readSource("src/pages/Tasks/TaskCreate.tsx"),
    readSource("src/pages/Tasks/TaskDetail.tsx"),
  ]);

  assert.equal((form.match(/<KpsDatePicker/g) ?? []).length, 2);
  assert.match(form, /value=\{values\.startDate\}/);
  assert.match(form, /clearable/);
  assert.match(form, /value=\{values\.dueDate\}/);
  assert.match(form, /minDate=\{values\.startDate \|\| undefined\}/);
  assert.match(form, /onBlur=\{\(\) => markTouched\("startDate"\)\}/);
  assert.match(form, /onBlur=\{\(\) => markTouched\("dueDate"\)\}/);
  assert.match(
    form,
    /if \(hasTaskFormErrors\(clientErrors\)\) return;\s+onSubmit\(event\);/,
  );
  assert.match(form, /disabled=\{isSubmitting\}/);
  assert.doesNotMatch(form, /disabled=\{isSubmitting \|\|/);
  assert.doesNotMatch(form, /type="date"/);

  for (const page of [createPage, detailPage]) {
    assert.match(page, /start_date:\s*values\.startDate \|\| null/);
    assert.match(page, /due_date:\s*values\.dueDate/);
    assert.doesNotMatch(page, /toISOString/);
  }
  assert.match(detailPage, /startDate:\s*task\.start_date \?\? ""/);
  assert.match(detailPage, /dueDate:\s*task\.due_date/);
});
