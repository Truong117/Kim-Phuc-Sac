import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

const hookSource = await readFile(
  new URL("../src/hooks/useWorkReportDraft.ts", import.meta.url),
  "utf8",
);
const pageSource = await readFile(
  new URL("../src/pages/Reports/NewReport.tsx", import.meta.url),
  "utf8",
);
const dialogSource = await readFile(
  new URL(
    "../src/components/reports/ReportDraftDialog.tsx",
    import.meta.url,
  ),
  "utf8",
);
const locale = JSON.parse(
  await readFile(
    new URL("../src/locales/vi/common.json", import.meta.url),
    "utf8",
  ),
);

test("autosave waits 900 ms and is gated until backend initialization or restore choice", () => {
  assert.match(hookSource, /const AUTOSAVE_DELAY_MS = 900/);
  assert.match(hookSource, /initializedRef\.current = false/);
  assert.match(hookSource, /autosaveEnabledRef\.current = false/);
  assert.match(
    hookSource,
    /!initializedRef\.current[\s\S]*!autosaveEnabledRef\.current[\s\S]*!active/,
  );
  assert.match(hookSource, /setTimeout\(\(\) => \{/);
});

test("draft identity uses the authenticated user and backend business date without fallback", () => {
  assert.match(pageSource, /userId: String\(currentUser\.id\)/);
  assert.match(pageSource, /reportDate: today\.business_date/);
  assert.doesNotMatch(pageSource, /userId:\s*["'](?:default|anonymous|guest|1)["']/i);
});

test("restore is explicit and locked reports expose view or copy instead of editable restore", () => {
  assert.match(hookSource, /kind: "restore"/);
  assert.match(hookSource, /kind: "locked"/);
  assert.match(dialogSource, /onRestore/);
  assert.match(dialogSource, /onUseServer/);
  assert.match(dialogSource, /lockedDraftText/);
  assert.match(dialogSource, /navigator\.clipboard\.writeText/);
  assert.doesNotMatch(
    dialogSource.slice(
      dialogSource.indexOf('{state?.kind === "locked"'),
      dialogSource.indexOf('{state?.kind === "external"'),
    ),
    /onRestore/,
  );
});

test("server changes and cross-tab storage changes require an explicit conflict choice", () => {
  assert.match(hookSource, /serverReportId !== serverReportId/);
  assert.match(hookSource, /serverUpdatedAt !== serverUpdatedAt/);
  assert.match(hookSource, /window\.addEventListener\("storage"/);
  assert.match(hookSource, /setDialog\(\{ kind: "external", incoming \}\)/);
  assert.match(dialogSource, /onUseExternal/);
  assert.match(dialogSource, /onKeepCurrent/);
});

test("navigation and page-close paths flush the latest initialized draft", () => {
  assert.match(hookSource, /window\.addEventListener\("pagehide", flush\)/);
  assert.match(
    hookSource,
    /document\.addEventListener\("visibilitychange", handleVisibility\)/,
  );
  assert.match(
    hookSource,
    /return \(\) => \{[\s\S]*removeEventListener\("pagehide"[\s\S]*flush\(\)/,
  );
});

test("submit flushes first, deletes only after success, and preserves draft on API errors", () => {
  const submitStart = pageSource.indexOf("const handleSubmit = async");
  const submitEnd = pageSource.indexOf("const isSaving", submitStart);
  const submitSource = pageSource.slice(submitStart, submitEnd);
  const requestIndex = Math.min(
    ...["await updateReport", "await createReport"].map((needle) => {
      const index = submitSource.indexOf(needle);
      return index < 0 ? Number.POSITIVE_INFINITY : index;
    }),
  );

  assert.ok(submitSource.indexOf("reportDraft.flushNow()") < requestIndex);
  assert.ok(submitSource.indexOf("reportDraft.handleSubmitSuccess()") > requestIndex);
  const catchSource = submitSource.slice(submitSource.indexOf("} catch"));
  assert.doesNotMatch(catchSource, /handleSubmitSuccess/);
  assert.match(hookSource, /handleSubmitSuccess[\s\S]*clearTimer\(\)/);
  assert.match(hookSource, /submittedRef\.current = true/);
});

test("cutoff conflicts suspend autosave and retain copyable unsent content", () => {
  assert.match(pageSource, /if \(submitError\.status === 409\)/);
  assert.match(pageSource, /reportDraft\.suspend\(\)/);
  assert.match(pageSource, /setRecoveryText\(unsavedContent \|\| null\)/);
});

test("Vietnamese UI labels describe actual local draft states", () => {
  const draft = locale.dailyReport.draft;
  assert.equal(draft.status.saving, "Đang lưu nháp...");
  assert.equal(draft.status.saved, "Đã lưu nháp lúc {time}");
  assert.equal(draft.status.error, "Không thể lưu nháp");
  assert.equal(draft.status.restored, "Đã khôi phục bản nháp");
  assert.match(draft.localOnly, /trình duyệt/);
});
