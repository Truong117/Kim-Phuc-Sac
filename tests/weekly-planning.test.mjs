import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import i18next from "i18next";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

test("weekly planning stays route-guarded but is exposed as an authorized work tab", async () => {
  const [app, sidebar, authorization, tabs, taskList, weeklyList] =
    await Promise.all([
      readSource("src/App.tsx"),
      readSource("src/layout/AppSidebar.tsx"),
      readSource("src/types/authorization.ts"),
      readSource("src/components/tasks/WorkManagementTabs.tsx"),
      readSource("src/pages/Tasks/TaskList.tsx"),
      readSource("src/pages/WeeklyPlans/WeeklyPlanList.tsx"),
    ]);

  assert.match(
    app,
    /AuthorizationRoute navigationKey="weekly-plans"[\s\S]*?path="\/weekly-plans"[\s\S]*?path="\/weekly-plans\/new"[\s\S]*?path="\/weekly-plans\/:id\/review"[\s\S]*?path="\/weekly-plans\/:id"/,
  );
  assert.doesNotMatch(sidebar, /navigationKey:\s*"weekly-plans"/);
  assert.match(
    sidebar,
    /navigationKey:\s*"tasks"[\s\S]*?activePaths:\s*\["\/weekly-plans"\]/,
  );
  assert.match(tabs, /canNavigate\("weekly-plans"\)/);
  assert.match(tabs, /to:\s*"\/weekly-plans"/);
  assert.match(taskList, /WorkManagementTabs activeTab="tasks"/);
  assert.match(weeklyList, /WorkManagementTabs activeTab="weekly-plans"/);
  assert.match(authorization, /"weekly-plans"/);
  assert.doesNotMatch(
    app + sidebar + tabs,
    /role\s*===|OWNER|HR_MANAGER|ADMIN/,
  );
});

test("weekly planning API client exposes explicit analysis and atomic final publication", async () => {
  const service = await readSource("src/services/weeklyPlanningService.ts");

  assert.match(service, /"\/api\/weekly-plans\/import"/);
  assert.match(service, /apiDownload\("\/api\/weekly-plans\/template"\)/);
  assert.match(service, /planAction\(id, "analyze"\)/);
  assert.match(service, /planAction\(id, "approve-and-publish"\)/);
  assert.match(service, /\/employee-reviews/);
  assert.match(service, /\/suggestions\//);
  assert.match(service, /\/comments/);
  assert.match(service, /\/source\/extract/);
  assert.match(service, /\/source\/replace/);
  assert.match(service, /\/source\/apply/);
  assert.match(service, /\/source\/group/);
  assert.match(service, /\/source\/ai-extract/);
});

test("weekly AI preview treats an empty suggestion list as a successful empty state", async () => {
  const [sourceDocument, aiPreview, translationsSource] = await Promise.all([
    readSource("src/components/weekly-plans/WeeklyPlanSourceDocument.tsx"),
    readSource("src/components/weekly-plans/WeeklyPlanAiExtractionPreview.tsx"),
    readSource("src/locales/vi/common.json"),
  ]);
  const translations = JSON.parse(translationsSource);

  assert.match(
    sourceDocument,
    /const suggestions =[\s\S]*?await onAiExtract\(\)/,
  );
  assert.match(sourceDocument, /setAiSuggestions\(normalized\.suggestions\)/);
  assert.match(sourceDocument, /setAiPreviewOpen\(true\)/);
  assert.match(aiPreview, /suggestions\.length === 0/);
  assert.match(aiPreview, /\{t\("empty"\)\}/);
  assert.equal(
    translations.weeklyPlanning.sourceDocument.aiPreview.empty,
    "AI không tìm thấy công việc có đủ căn cứ trong tài liệu này.",
  );
});

test("DOCX source extraction and missing-file recovery are driven by opaque actions", async () => {
  const [sourceDocument, sourceRecovery, detail, entries, types] =
    await Promise.all([
      readSource("src/components/weekly-plans/WeeklyPlanSourceDocument.tsx"),
      readSource("src/components/weekly-plans/WeeklyPlanSourceRecovery.tsx"),
      readSource("src/pages/WeeklyPlans/WeeklyPlanDetail.tsx"),
      readSource("src/components/weekly-plans/WeeklyPlanEntries.tsx"),
      readSource("src/types/weeklyPlanning.ts"),
    ]);

  assert.match(sourceDocument, /plan\.actions\.can_extract_source/);
  assert.match(sourceDocument, /plan\.actions\.can_apply_source/);
  assert.match(sourceDocument, /\{plan\.actions\.can_ai_extract_source && \(/);
  assert.match(sourceDocument, /disabled=\{busy \|\| aiLoading\}/);
  assert.match(sourceDocument, /const runAiExtraction = async/);
  assert.match(
    sourceDocument,
    /const suggestions =[\s\S]*?await onAiExtract\(\)/,
  );
  assert.match(sourceDocument, /setAiPreviewOpen\(true\)/);
  assert.match(sourceDocument, /<WeeklyPlanAiExtractionPreview/);
  assert.match(sourceDocument, /crypto\.randomUUID\(\)/);
  assert.match(sourceDocument, /source_passage_ids: draft\.passageIds/);
  assert.match(sourceDocument, /<WeeklyPlanEntryForm/);
  assert.match(sourceRecovery, /plan\.source_file\.availability/);
  assert.match(sourceRecovery, /plan\.actions\.can_replace_source/);
  assert.match(sourceRecovery, /accept=\{`\.\$\{extension\}`\}/);
  assert.match(sourceRecovery, /onReplace\(replacement\)/);
  assert.match(
    types,
    /availability:[\s\S]*"AVAILABLE"[\s\S]*"MISSING"[\s\S]*"UNAVAILABLE"[\s\S]*"NOT_APPLICABLE"[\s\S]*null/,
  );
  assert.doesNotMatch(
    sourceDocument + sourceRecovery + detail,
    /role\s*===|HR_MANAGER|OWNER|ADMIN/,
  );
  assert.match(detail, /<WeeklyPlanSourceDocument/);
  assert.match(detail, /<WeeklyPlanSourceRecovery/);
  assert.match(detail, /applyWeeklyPlanSourceEntries/);
  assert.match(entries, /entry\.source_passages\.map/);
});

test("source passages use ordered accordions and explicit manual grouping without identity guessing", async () => {
  const [sourceDocument, detail, service, types] = await Promise.all([
    readSource("src/components/weekly-plans/WeeklyPlanSourceDocument.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanDetail.tsx"),
    readSource("src/services/weeklyPlanningService.ts"),
    readSource("src/types/weeklyPlanning.ts"),
  ]);

  assert.match(sourceDocument, /const sourceGroup = passage\.source_group/);
  assert.match(sourceDocument, /const sourceContext = passage\.source_context/);
  assert.match(sourceDocument, /const descriptor = sourceGroup/);
  assert.match(sourceDocument, /previous\?\.baseKey === descriptor\.baseKey/);
  assert.match(sourceDocument, /first\.sequence - second\.sequence/);
  assert.match(sourceDocument, /aria-expanded=\{isExpanded\}/);
  assert.match(sourceDocument, /role="region"/);
  assert.match(sourceDocument, /toggleBlockSelection\(block\)/);
  assert.match(sourceDocument, /plan\.actions\.can_group_source/);
  assert.match(sourceDocument, /selectedIndexes\[position - 1\] \+ 1/);
  assert.match(sourceDocument, /groupRangeRequired/);
  assert.match(sourceDocument, /entryGroupSelectionRequired/);
  assert.match(sourceDocument, /includesGroupedPassage/);
  assert.match(
    sourceDocument,
    /selectedGroupKeys\.some\(\(groupKey\) => groupKey === null\)/,
  );
  assert.match(sourceDocument, /new Set\(selectedGroupKeys\)\.size !== 1/);
  assert.match(sourceDocument, /draft\.assigneeId === null/);
  assert.match(sourceDocument, /identityUnverified/);
  assert.doesNotMatch(
    sourceDocument,
    /source_group[^\n]*(assigneeId|assignee_id)|group\.title[^\n]*employee/i,
  );

  assert.match(detail, /onAssignGroup=\{async \(passageIds, groupTitle\)/);
  assert.match(detail, /assignWeeklyPlanSourceGroup/);
  assert.match(service, /method: "PATCH"/);
  assert.match(service, /passage_ids: passageIds/);
  assert.match(service, /group_title: groupTitle/);
  assert.match(types, /origin: "MANUAL" \| "GOOGLE_DOCS_TAB"/);
  assert.match(types, /origin: "DOCX_SECTION" \| "GOOGLE_DOCS_TAB"/);
  assert.match(types, /tab_id: string \| null/);
  assert.match(types, /parent_tab_id: string \| null/);
  assert.match(types, /nesting_level: number \| null/);
  assert.match(types, /identity_verified: false/);
  assert.match(types, /can_group_source: boolean/);
});

test("weekly entries are grouped by stable user identity with an unresolved fallback", async () => {
  const entries = await readSource(
    "src/components/weekly-plans/WeeklyPlanEntries.tsx",
  );

  assert.match(entries, /`user:\$\{entry\.assignee\.id\}`/);
  assert.match(entries, /: "unresolved"/);
  assert.doesNotMatch(entries, /key\s*=\s*entry\.assignee\?\.name/);
  assert.match(entries, /entry\.validation_status === "VALID"/);
  assert.match(entries, /!entry\.expected_result\?\.trim\(\)/);
  assert.match(entries, /!entry\.start_date \|\| !entry\.due_date/);
  assert.match(entries, /aria-expanded=\{!isCollapsed\}/);
  assert.match(entries, /setCollapsedKeys\(new Set\(\)\)/);
  assert.match(entries, /visibleGroups\.map/);
  assert.match(entries, /noFilteredEntries/);
});

test("weekly entry filters preserve the external edit modal and actions stay opaque", async () => {
  const [entries, detail, sourceDocument, aiPreview] = await Promise.all([
    readSource("src/components/weekly-plans/WeeklyPlanEntries.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanDetail.tsx"),
    readSource("src/components/weekly-plans/WeeklyPlanSourceDocument.tsx"),
    readSource("src/components/weekly-plans/WeeklyPlanAiExtractionPreview.tsx"),
  ]);

  assert.match(entries, /setQuery/);
  assert.match(entries, /setEmployeeFilter/);
  assert.match(entries, /setValidationFilter/);
  assert.match(entries, /setCompletenessFilter/);
  assert.match(detail, /isOpen=\{editing !== null\}/);
  assert.match(detail, /canEdit=\{plan\.actions\.can_edit\}/);
  assert.match(detail, /canReview=\{reviewMode && plan\.actions\.can_review\}/);
  assert.doesNotMatch(entries + detail, /role\s*===|HR_MANAGER|OWNER|ADMIN/);
  assert.match(sourceDocument, /createFromSelection/);
  assert.match(sourceDocument, /applyToDraft/);
  assert.match(sourceDocument, /draft\.assigneeId === null/);
  assert.match(sourceDocument, /assignee_id: draft\.assigneeId/);
  assert.match(sourceDocument, /source_passage_ids: draft\.passageIds/);
  assert.match(sourceDocument, /key=\{draft\.requestId\}/);
  assert.match(sourceDocument, /assigneeId: lockedAssignee\?\.id \?\? null/);
  assert.match(
    sourceDocument,
    /assigneeId: verifiedGoogleDocsTab\?\.employee\.id \?\? null/,
  );
  assert.match(sourceDocument, /aiSuggestionIndex: index/);
  assert.match(aiPreview, /suggestion\.source_keys/);
  assert.match(aiPreview, /suggestion\.evidence/);
  assert.match(aiPreview, /suggestion\.verification_fields/);
  assert.match(aiPreview, /passage\?\.text \?\? t\("sourceUnavailable"\)/);
  assert.doesNotMatch(
    sourceDocument + aiPreview,
    /role\s*===|HR_MANAGER|OWNER|ADMIN/,
  );
});

test("weekly entry form uses date-only picker and interaction-gated validation", async () => {
  const [form, textArea, googleJobRow] = await Promise.all([
    readSource("src/components/weekly-plans/WeeklyPlanEntryForm.tsx"),
    readSource("src/components/form/input/TextArea.tsx"),
    readSource(
      "src/components/weekly-plans/WeeklyPlanGoogleDocsAiReviewJobRow.tsx",
    ),
  ]);

  assert.match(form, /KpsDatePicker/);
  assert.doesNotMatch(form, /type=["']date["']/);
  assert.match(form, /const \[touched, setTouched\]/);
  assert.match(form, /const \[submitted, setSubmitted\]/);
  assert.match(form, /submitted \|\| touched\[field\]/);
  assert.match(
    form,
    /compareDateOnly\(values\.due_date, values\.start_date\) < 0/,
  );
  assert.match(form, /minDate=\{weekStart\}/);
  assert.match(form, /maxDate=\{weekEnd\}/);
  assert.match(form, /serverErrors\[`entries\.0\.\$\{field\}`\]/);
  assert.match(form, /clearServerError\("assignee_id"\)/);
  assert.match(form, /clearServerError\("due_date"\)/);
  assert.match(textArea, /containerClassName/);
  assert.match(form, /grid items-stretch gap-4 md:grid-cols-2/);
  assert.match(form, /containerClassName="flex flex-1"/);
  assert.match(form, /className="h-full min-h-32"/);
  assert.match(googleJobRow, /grid items-stretch gap-4 md:grid-cols-2/);
  assert.match(googleJobRow, /containerClassName="flex flex-1"/);
});

test("weekly UI groups entries by employee and consumes opaque action flags", async () => {
  const [entries, detail, list, upload] = await Promise.all([
    readSource("src/components/weekly-plans/WeeklyPlanEntries.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanDetail.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanList.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanImport.tsx"),
  ]);

  assert.match(entries, /entries\.reduce/);
  assert.match(entries, /entry\.assignee\?\.id/);
  assert.match(detail, /plan\.actions\.can_edit/);
  assert.match(detail, /plan\.actions\.can_review/);
  assert.match(detail, /plan\.actions\.can_analyze/);
  assert.match(detail, /plan\.actions\.can_approve_and_publish/);
  assert.match(detail, /plan\.actions\.can_comment/);
  assert.match(detail, /analyzeWeeklyPlan/);
  assert.match(detail, /approveAndPublishWeeklyPlan/);
  assert.match(detail, /showAssignmentConfirm/);
  assert.match(detail, /plan\.events\.map/);
  assert.match(detail, /commentTarget/);
  assert.match(list, /loading && !result/);
  assert.match(list, /errors\.forbidden/);
  assert.match(list, /references\?\.actions\.can_import/);
  assert.match(upload, /type="file"/);
  assert.match(upload, /accept="\.xlsx,\.docx"/);
  assert.match(upload, /submitted \|\| touched/);
  assert.match(upload, /nextError\.status === 403/);
  assert.match(upload, /nextError\.status === 404/);
  assert.match(upload, /nextError\.status === 409[\s\S]*?nextError\.message/);
});

test("weekly create and review flows return to the authorized weekly list", async () => {
  const [upload, detail, review] = await Promise.all([
    readSource("src/pages/WeeklyPlans/WeeklyPlanImport.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanDetail.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanReview.tsx"),
  ]);

  assert.match(upload, /navigate\("\/weekly-plans"\)/);
  assert.match(detail, /to="\/weekly-plans"/);
  assert.match(detail, /to=\{"\/weekly-plans\/" \+ plan\.id \+ "\/review"\}/);
  assert.match(review, /<WeeklyPlanDetail reviewMode \/>/);
});

test("Vietnamese weekly translations interpolate actual values with single braces", async () => {
  const resource = JSON.parse(await readSource("src/locales/vi/common.json"));
  const instance = i18next.createInstance();
  await instance.init({
    lng: "vi",
    resources: { vi: { common: resource } },
    defaultNS: "common",
    interpolation: { prefix: "{", suffix: "}", escapeValue: false },
  });

  assert.equal(
    instance.t("weeklyPlanning.detail.period", {
      start: "26/10/2026",
      end: "31/10/2026",
    }),
    "Tuần 26/10/2026 – 31/10/2026",
  );
  assert.equal(
    instance.t("weeklyPlanning.detail.commentEmployee", { name: "Nguyễn An" }),
    "Nhân viên: Nguyễn An",
  );
  assert.equal(
    instance.t("weeklyPlanning.review.confirmDescription", {
      employees: 3,
      tasks: 8,
    }),
    "Kế hoạch gồm 3 nhân viên và 8 Task sẽ được tạo.",
  );
  assert.equal(
    instance.t("weeklyPlanning.sourceDocument.selectedCount", { count: 2 }),
    "Đã chọn 2 đoạn nguồn",
  );
  assert.equal(
    instance.t("weeklyPlanning.sourceRecovery.missingHelp", {
      extension: "xlsx",
    }),
    "Dữ liệu kế hoạch đã lưu vẫn được giữ nguyên. Hãy tải lại file .xlsx để tiếp tục các thao tác cần đọc file gốc.",
  );
  assert.equal(
    instance.t("weeklyPlanning.sourceDocument.aiNotConnected"),
    "Chưa kết nối AI",
  );
  assert.equal(
    instance.t("weeklyPlanning.sourceDocument.aiPreview.employeeHint", {
      identifier: "Nhân viên A",
    }),
    "Định danh trong tài liệu: Nhân viên A",
  );
  assert.equal(
    instance.t("weeklyPlanning.sourceDocument.aiPreview.sources", {
      sources: "docx-paragraph:1",
    }),
    "Đoạn nguồn: docx-paragraph:1",
  );
  assert.equal(
    instance.t("weeklyPlanning.sourceDocument.aiPreview.provenance", {
      values: "Tiêu đề: Nguyên văn",
    }),
    "Nguồn gốc trường: Tiêu đề: Nguyên văn",
  );
  assert.equal(
    instance.t("weeklyPlanning.sourceDocument.groupModalDescription", {
      count: 3,
    }),
    "Đặt nhãn chung cho 3 đoạn nguồn đã chọn.",
  );
  assert.equal(
    instance.t("weeklyPlanning.sourceDocument.groupEvidence", {
      evidence: "paragraph:2 – paragraph:4",
    }),
    "Dấu vết: paragraph:2 – paragraph:4",
  );
  assert.equal(
    instance.t("weeklyPlanning.sourceDocument.entryGroupSelectionRequired"),
    "Khi dùng đoạn đã gán nhóm, vui lòng chỉ chọn các đoạn thuộc cùng một nhóm để tạo công việc.",
  );
  assert.equal(
    instance.t("weeklyPlanning.detail.filters.resultCount", {
      groups: 2,
      entries: 5,
    }),
    "Hiển thị 5 công việc trong 2 nhóm",
  );
  assert.equal(
    instance.t("weeklyPlanning.detail.groupCounts.missing", { count: 3 }),
    "3 cần xử lý",
  );
});
