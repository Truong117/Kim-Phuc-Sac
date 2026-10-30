import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import {
  createGoogleDocsAiReviewDrafts,
  groupGoogleDocsAiReviewDrafts,
  splitGoogleDocsAiReviewDraft,
} from "../src/utils/weeklyPlanGoogleDocsAiReview.ts";
import {
  createWeeklyPlanDiscussionEmployeeOptions,
  createWeeklyPlanDiscussionWorkOptions,
  filterWeeklyPlanDiscussionOptions,
  isWeeklyPlanDiscussionEmployeeConsistent,
  isWeeklyPlanDiscussionEntryConsistent,
  resolveWeeklyPlanCommentTargetArguments,
} from "../src/utils/weeklyPlanDiscussionTarget.ts";
import { resolveWeeklyPlanCommentContext } from "../src/utils/weeklyPlanCommentContext.ts";
import {
  currentWeeklyPlanSuggestions,
  googleDocsSourceReady,
  groupWeeklyPlanEntriesByEmployee,
  guardWeeklyPlanWizardStep,
  inferWeeklyPlanWizardStep,
  isEditableWizardPlan,
  weeklyPlanAnalysisState,
  weeklyPlanSubmissionReadiness,
  WEEKLY_PLAN_RULE_ANALYZER_VERSION,
  weeklyPlanWizardSteps,
  weeklyPlanWorkCounts,
} from "../src/utils/weeklyPlanWizard.ts";
import { activeWeeklyPlanReviewPath } from "../src/utils/weeklyPlanReviewer.ts";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

const plan = (changes = {}) => ({
  id: 31,
  status: "DRAFT",
  source_type: "GOOGLE_DOCS",
  parse_status: "PARSED",
  entries: [],
  events: [],
  source_document: {
    status: "EXTRACTED",
    google_docs: {
      remaining_tab_ids: ["tab-a"],
      processed_tab_count: 0,
    },
  },
  ...changes,
});

const employee = { id: 7, name: "KPS Admin", email: "admin@example.test" };
const mappings = (tabs) => ({
  tabs,
  employees: [employee],
  actions: { can_update: true },
});
const tab = (status, tabId = "tab-a", mappedEmployee = employee) => ({
  tab_id: tabId,
  title: tabId,
  status,
  employee: mappedEmployee,
});

test("wizard exposes exactly the four source, structure, work, and submit steps", () => {
  assert.deepEqual(weeklyPlanWizardSteps, [
    "source",
    "structure",
    "work",
    "submit",
  ]);
});

test("submit back navigation returns drafts to work and staged plans to the list", async () => {
  const [layout, translationsSource] = await Promise.all([
    readSource("src/components/weekly-plans/WeeklyPlanWizardLayout.tsx"),
    readSource("src/locales/vi/common.json"),
  ]);
  const wizardCopy = JSON.parse(translationsSource).weeklyPlanning.wizard;
  const backNavigation = (status) => {
    const value = plan({ status });
    const returnsToList = !isEditableWizardPlan(value);

    return {
      target: returnsToList
        ? "/weekly-plans"
        : `/weekly-plans/${value.id}/work`,
      label: returnsToList ? wizardCopy.backToPlanList : wizardCopy.back,
    };
  };

  assert.deepEqual(backNavigation("DRAFT"), {
    target: "/weekly-plans/31/work",
    label: "Quay lại",
  });
  assert.deepEqual(backNavigation("CHANGES_REQUESTED"), {
    target: "/weekly-plans/31/work",
    label: "Quay lại",
  });

  for (const status of [
    "ASSISTANT_REVIEW",
    "ASSISTANT_CHANGES_REQUESTED",
    "OWNER_REVIEW",
    "OWNER_CHANGES_REQUESTED",
    "APPROVED",
    "PUBLISHED",
  ]) {
    assert.deepEqual(backNavigation(status), {
      target: "/weekly-plans",
      label: "Quay lại danh sách kế hoạch",
    });
  }

  assert.match(layout, /step === "submit" && !isEditableWizardPlan\(plan\)/);
  assert.match(
    layout,
    /submitStepReturnsToList\s*\? "\/weekly-plans"\s*: `\/weekly-plans\/\$\{plan\.id\}\/\$\{previousStep\}`/,
  );
  assert.match(layout, /onClick=\{\(\) => navigate\(backTarget\)\}/);
  assert.match(layout, /submitStepReturnsToList \? "backToPlanList" : "back"/);
});

test("stage reviewers bypass wizard entry and submit while HR keeps wizard inference", async () => {
  const reviewerPlan = {
    ...plan({ status: "OWNER_REVIEW" }),
    actions: {
      can_assistant_review: false,
      can_owner_review: true,
      can_open_review_workspace: true,
    },
  };
  const hrPlan = {
    ...reviewerPlan,
    actions: {
      can_assistant_review: false,
      can_owner_review: false,
      can_open_review_workspace: false,
    },
  };
  const readonlyOwnerPlan = {
    ...reviewerPlan,
    status: "OWNER_CHANGES_REQUESTED",
    actions: {
      can_assistant_review: false,
      can_owner_review: false,
      can_open_review_workspace: true,
    },
  };
  const readonlyAssistantPlan = {
    ...reviewerPlan,
    status: "ASSISTANT_CHANGES_REQUESTED",
    actions: {
      can_assistant_review: false,
      can_owner_review: false,
      can_open_review_workspace: true,
    },
  };
  const v3AssistantPlan = {
    ...reviewerPlan,
    status: "IN_REVIEW",
    actions: {
      can_assistant_review: false,
      can_owner_review: false,
      can_open_review_workspace: true,
    },
  };
  const v3FeatureDisabledPlan = {
    ...v3AssistantPlan,
    actions: {
      ...v3AssistantPlan.actions,
      can_open_review_workspace: false,
    },
  };

  assert.equal(
    activeWeeklyPlanReviewPath(reviewerPlan),
    "/weekly-plans/31/review",
  );
  assert.equal(activeWeeklyPlanReviewPath(hrPlan), null);
  assert.equal(
    activeWeeklyPlanReviewPath(readonlyOwnerPlan),
    "/weekly-plans/31/review",
  );
  assert.equal(
    activeWeeklyPlanReviewPath(readonlyAssistantPlan),
    "/weekly-plans/31/review",
  );
  assert.equal(
    activeWeeklyPlanReviewPath(v3AssistantPlan),
    "/weekly-plans/31/review",
  );
  assert.equal(activeWeeklyPlanReviewPath(v3FeatureDisabledPlan), null);
  assert.equal(inferWeeklyPlanWizardStep(hrPlan, null), "submit");

  const [entry, submit] = await Promise.all([
    readSource("src/pages/WeeklyPlans/WeeklyPlanWizardEntry.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanWizardSubmit.tsx"),
  ]);
  assert.match(
    entry,
    /const reviewPath = activeWeeklyPlanReviewPath\(plan\);[\s\S]*?if \(reviewPath\) return <Navigate to=\{reviewPath\} replace \/>;[\s\S]*?inferWeeklyPlanWizardStep\(plan, mappings\)/,
  );
  assert.match(
    submit,
    /const reviewPath = activeWeeklyPlanReviewPath\(plan\);[\s\S]*?if \(reviewPath\) return <Navigate to=\{reviewPath\} replace \/>;[\s\S]*?const counts = weeklyPlanWorkCounts\(plan\)/,
  );
  assert.match(submit, /<WeeklyPlanWizardLayout step="submit">/);
});

test("wizard default inference and guards enforce source, structure, work, and submit prerequisites", () => {
  const unresolved = mappings([tab("UNVERIFIED", "tab-a", null)]);
  assert.equal(inferWeeklyPlanWizardStep(plan(), unresolved), "source");
  assert.equal(
    guardWeeklyPlanWizardStep("submit", plan(), unresolved),
    "source",
  );

  const ready = mappings([tab("VERIFIED")]);
  assert.equal(googleDocsSourceReady(plan(), ready), true);
  assert.equal(inferWeeklyPlanWizardStep(plan(), ready), "structure");
  assert.equal(guardWeeklyPlanWizardStep("work", plan(), ready), "structure");

  const completed = plan({
    entries: [{ id: 1 }],
    source_document: {
      status: "EXTRACTED",
      google_docs: { remaining_tab_ids: [], processed_tab_count: 1 },
    },
  });
  assert.equal(inferWeeklyPlanWizardStep(completed, ready), "work");
  assert.equal(guardWeeklyPlanWizardStep("submit", completed, ready), "submit");
  assert.equal(
    inferWeeklyPlanWizardStep(plan({ status: "SUBMITTED" }), null),
    "submit",
  );
  assert.equal(
    guardWeeklyPlanWizardStep("source", plan({ status: "APPROVED" }), null),
    "submit",
  );

  assert.equal(
    inferWeeklyPlanWizardStep(
      plan({
        source_type: "UPLOADED_FILE",
        entries: [],
        source_document: { status: "EXTRACTED", google_docs: null },
      }),
      null,
    ),
    "structure",
  );
});

test("partial Google Docs processing blocks every downstream wizard step", () => {
  const ready = mappings([tab("VERIFIED"), tab("VERIFIED", "tab-b")]);
  const partial = plan({
    entries: [{ id: 1 }],
    source_document: {
      status: "EXTRACTED",
      google_docs: {
        remaining_tab_ids: ["tab-b"],
        processed_tab_count: 1,
      },
    },
  });

  for (const requested of ["work", "submit"]) {
    assert.equal(
      guardWeeklyPlanWizardStep(requested, partial, ready),
      "structure",
    );
  }

  const completed = {
    ...partial,
    source_document: {
      ...partial.source_document,
      google_docs: {
        remaining_tab_ids: [],
        processed_tab_count: 2,
      },
    },
  };
  for (const requested of ["work", "submit"]) {
    assert.equal(
      guardWeeklyPlanWizardStep(requested, completed, ready),
      requested,
    );
  }
});

test("view-only draft viewers use loaded mappings and reach the work screen", async () => {
  const ready = mappings([tab("VERIFIED")]);
  const viewOnly = plan({
    actions: { can_edit: false, can_reset: false },
    entries: [{ id: 1 }],
    source_document: {
      status: "EXTRACTED",
      google_docs: { remaining_tab_ids: [], processed_tab_count: 1 },
    },
  });

  assert.equal(inferWeeklyPlanWizardStep(viewOnly, ready), "work");
  assert.equal(guardWeeklyPlanWizardStep("work", viewOnly, ready), "work");

  const [provider, work] = await Promise.all([
    readSource("src/context/WeeklyPlanWizardContext.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanWizardWork.tsx"),
  ]);
  assert.match(provider, /if \(nextPlan\.source_type === "GOOGLE_DOCS"\)/);
  assert.doesNotMatch(
    provider,
    /source_type === "GOOGLE_DOCS" && nextPlan\.actions\.can_edit/,
  );
  assert.match(work, /plan\.actions\.can_reset &&/);
  assert.match(work, /plan\.actions\.can_edit &&/);
});

test("Google Docs readiness requires every tab resolved and at least one relevant verified tab", () => {
  assert.equal(
    googleDocsSourceReady(
      plan(),
      mappings([tab("VERIFIED"), tab("NOT_APPLICABLE", "tab-b", null)]),
    ),
    true,
  );
  assert.equal(
    googleDocsSourceReady(
      plan(),
      mappings([tab("NOT_APPLICABLE", "tab-b", null)]),
    ),
    false,
  );
  assert.equal(
    googleDocsSourceReady(
      plan(),
      mappings([tab("VERIFIED"), tab("NEEDS_REVIEW", "tab-b", null)]),
    ),
    false,
  );
});

test("analysis state fails closed for legacy, unknown, and missing analyzer versions", () => {
  assert.equal(weeklyPlanAnalysisState([]), "NOT_RUN");
  assert.equal(
    weeklyPlanAnalysisState([
      {
        id: 10,
        type: "ANALYZED",
        metadata: { analyzer: "RULE_BASED_V1" },
      },
    ]),
    "STALE",
  );
  assert.equal(
    weeklyPlanAnalysisState([{ id: 10, type: "ANALYZED", metadata: null }]),
    "STALE",
  );
  assert.equal(
    weeklyPlanAnalysisState([
      { id: 10, type: "ANALYZED", metadata: { suggestion_count: 2 } },
    ]),
    "STALE",
  );
  assert.equal(
    weeklyPlanAnalysisState([
      {
        id: 10,
        type: "ANALYZED",
        metadata: { analyzer: "RULE_BASED_UNKNOWN" },
      },
    ]),
    "STALE",
  );
});

test("analysis state uses the latest V2 analysis by id and honors later invalidation", () => {
  const v2Analysis = {
    id: 10,
    type: "ANALYZED",
    metadata: { analyzer: WEEKLY_PLAN_RULE_ANALYZER_VERSION },
  };
  assert.equal(weeklyPlanAnalysisState([v2Analysis]), "CURRENT");
  assert.equal(
    weeklyPlanAnalysisState([
      v2Analysis,
      { id: 11, type: "SUGGESTIONS_INVALIDATED", metadata: null },
    ]),
    "STALE",
  );
  assert.equal(
    weeklyPlanAnalysisState([
      v2Analysis,
      { id: 12, type: "PLAN_RESET", metadata: null },
    ]),
    "STALE",
  );
  assert.equal(
    weeklyPlanAnalysisState([
      {
        id: 9,
        type: "ANALYZED",
        metadata: { analyzer: WEEKLY_PLAN_RULE_ANALYZER_VERSION },
      },
      {
        id: 5,
        type: "ANALYZED",
        metadata: { analyzer: "RULE_BASED_V1" },
      },
    ]),
    "CURRENT",
  );
});

test("legacy V1 suggestions are excluded from current Quick Check warning groups", () => {
  const legacyEvents = [
    {
      id: 10,
      type: "ANALYZED",
      metadata: { analyzer: "RULE_BASED_V1" },
    },
  ];
  const legacySuggestions = [
    { id: 1, type: "DEADLINE_CONCENTRATION" },
    { id: 2, type: "MISSING_EXPECTED_RESULT" },
  ];

  assert.equal(weeklyPlanAnalysisState(legacyEvents), "STALE");
  assert.deepEqual(
    currentWeeklyPlanSuggestions(legacyEvents, legacySuggestions),
    [],
  );
});

test("work counts and employee groups aggregate multiple source tabs under one KPS account", () => {
  const makeEntry = (id, sourceTitle, itemCount) => ({
    id,
    assignee: employee,
    employee_identifier: null,
    employee_name: employee.name,
    items: Array.from({ length: itemCount }, (_, index) => ({ id: index + 1 })),
    source_passages: [
      {
        source_group: { title: sourceTitle },
      },
    ],
  });
  const entries = [makeEntry(1, "Phương Anh", 2), makeEntry(2, "Lê Hùng", 3)];
  const groups = groupWeeklyPlanEntriesByEmployee(entries);

  assert.equal(groups.length, 1);
  assert.deepEqual(groups[0].sourceTitles, ["Phương Anh", "Lê Hùng"]);
  assert.equal(groups[0].itemCount, 5);
  assert.deepEqual(
    weeklyPlanWorkCounts({
      entries,
      source_document: { google_docs: { processed_tab_count: 2 } },
    }),
    { employeeCount: 1, entryCount: 2, itemCount: 5, processedTabCount: 2 },
  );
});

test("submission readiness counts blocking work once while preserving issue categories", () => {
  const complete = {
    id: 1,
    assignee: employee,
    validation_status: "VALID",
    expected_result: "Hoàn tất",
    start_date: "2026-10-26",
    due_date: "2026-10-30",
  };
  assert.deepEqual(weeklyPlanSubmissionReadiness([]), {
    ready: false,
    totalBlocking: 1,
    noEntries: 1,
    missingAssignee: 0,
    invalidEntries: 0,
    missingExpectedResult: 0,
    missingDates: 0,
    validEntries: 0,
    assignedEmployees: 0,
  });

  const result = weeklyPlanSubmissionReadiness([
    complete,
    {
      ...complete,
      id: 2,
      assignee: null,
      validation_status: "INVALID",
      expected_result: " ",
      start_date: null,
    },
  ]);
  assert.equal(result.ready, false);
  assert.equal(result.totalBlocking, 1);
  assert.equal(result.missingAssignee, 1);
  assert.equal(result.invalidEntries, 1);
  assert.equal(result.missingExpectedResult, 1);
  assert.equal(result.missingDates, 1);
});

test("split group promotes children locally with scoped evidence and preserves trusted assignment", () => {
  const sourceKeys = ["google:tab-a:1", "google:tab-a:2"];
  const drafts = createGoogleDocsAiReviewDrafts(
    [
      {
        source_keys: sourceKeys,
        title: "HR administration",
        content: "Parent content",
        expected_result: "Completed",
        priority: "HIGH",
        note: "Shared safe note",
        grouping_basis: "AI_GROUPED",
        items: [
          { title: "Payroll", content: null, source_keys: [sourceKeys[0]] },
          {
            title: "Insurance",
            content: "Review",
            source_keys: [sourceKeys[1]],
          },
        ],
        source_tab: { tab_id: "tab-a", title: "Minh Đăng" },
        assignee: employee,
        assignment_source: "VERIFIED_GOOGLE_DOCS_TAB_MAPPING",
        detail_suggestions: null,
        verification_fields: [],
        evidence: "Evidence",
        provenance: {},
      },
    ],
    sourceKeys.map((source_key, index) => ({ id: index + 11, source_key })),
  );
  const split = splitGoogleDocsAiReviewDraft(drafts, 0);

  assert.equal(split.length, 2);
  assert.deepEqual(
    split.map((draft) => draft.title),
    ["Payroll", "Insurance"],
  );
  assert.deepEqual(
    split.map((draft) => draft.sourcePassageIds),
    [[11], [12]],
  );
  assert.deepEqual(
    split.map((draft) => draft.groupingBasis),
    ["ATOMIC", "ATOMIC"],
  );
  assert.ok(split.every((draft) => draft.assignee.id === employee.id));
  assert.ok(split.every((draft) => draft.sourceTab.tab_id === "tab-a"));
  assert.equal(split[0].priority, "HIGH");
  assert.equal(split[0].note, "");
  assert.deepEqual(split[0].items, []);

  const atomic = [{ ...drafts[0], groupingBasis: "ATOMIC" }];
  assert.equal(splitGoogleDocsAiReviewDraft(atomic, 0), atomic);
});

test("AI preview grouping keeps multiple tabs for the same trusted employee in one group", () => {
  const base = {
    source_keys: ["a"],
    title: "A",
    content: null,
    expected_result: null,
    priority: "MEDIUM",
    note: null,
    grouping_basis: "ATOMIC",
    items: [],
    assignee: employee,
    assignment_source: "VERIFIED_GOOGLE_DOCS_TAB_MAPPING",
    detail_suggestions: null,
    verification_fields: [],
    evidence: "",
    provenance: {},
  };
  const drafts = createGoogleDocsAiReviewDrafts(
    [
      { ...base, source_tab: { tab_id: "tab-a", title: "Phương Anh" } },
      {
        ...base,
        source_keys: ["b"],
        title: "B",
        source_tab: { tab_id: "tab-b", title: "Lê Hùng" },
      },
    ],
    [
      { id: 1, source_key: "a" },
      { id: 2, source_key: "b" },
    ],
  );
  const groups = groupGoogleDocsAiReviewDrafts(drafts);
  assert.equal(groups.length, 1);
  assert.deepEqual(groups[0].tabTitles, ["Phương Anh", "Lê Hùng"]);
});

test("routes, reset confirmation, reset navigation, and grouping labels are wired", async () => {
  const [app, work, service, translationsSource] = await Promise.all([
    readSource("src/App.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanWizardWork.tsx"),
    readSource("src/services/weeklyPlanningService.ts"),
    readSource("src/locales/vi/common.json"),
  ]);
  const copy = JSON.parse(translationsSource).weeklyPlanning;

  for (const route of ["source", "structure", "work", "submit"]) {
    assert.match(app, new RegExp(`path="${route}"`));
  }
  assert.match(
    app,
    /path="quality"\s+element={<Navigate to="\.\.\/work" replace \/>}/,
  );
  assert.match(app, /path="\/weekly-plans\/:id\/review"/);
  assert.match(work, /isOpen={showReset}/);
  assert.match(work, /resetWeeklyPlan\(plan\.id\)/);
  assert.match(work, /weekly-plans\/\$\{plan\.id\}\/structure/);
  assert.match(service, /method: "POST"/);
  assert.match(
    copy.wizard.reset.description,
    /xóa toàn bộ công việc và đầu mục/,
  );
  assert.match(
    copy.wizard.reset.description,
    /mapping nhân viên sẽ được giữ nguyên/,
  );
  assert.deepEqual(copy.wizard.groupingBasis, {
    SOURCE_STRUCTURE: "Theo cấu trúc nguồn",
    AI_GROUPED: "AI đề xuất nhóm",
    ATOMIC: "Công việc độc lập",
  });
});

test("wizard active states and primary actions use the KPS palette", async () => {
  const [stepper, structure, source, work, quickCheck, submit] =
    await Promise.all([
      readSource("src/components/weekly-plans/WeeklyPlanWizardStepper.tsx"),
      readSource("src/pages/WeeklyPlans/WeeklyPlanWizardStructure.tsx"),
      readSource("src/pages/WeeklyPlans/WeeklyPlanWizardSource.tsx"),
      readSource("src/pages/WeeklyPlans/WeeklyPlanWizardWork.tsx"),
      readSource("src/components/weekly-plans/WeeklyPlanQuickCheck.tsx"),
      readSource("src/pages/WeeklyPlans/WeeklyPlanWizardSubmit.tsx"),
    ]);

  assert.match(stepper, /bg-kps-primary\/\[0\.08\]/);
  assert.match(stepper, /text-kps-primary/);
  assert.doesNotMatch(stepper, /brand-/);
  assert.match(structure, /border-kps-primary\/10/);
  assert.match(structure, /bg-kps-primary\/\[0\.06\]/);
  assert.doesNotMatch(structure, /brand-/);
  for (const wizardScreen of [source, work, submit]) {
    assert.match(wizardScreen, /bg-kps-primary/);
    assert.match(wizardScreen, /hover:bg-kps-primary-hover/);
    assert.doesNotMatch(wizardScreen, /brand-/);
  }
  assert.match(quickCheck, /weeklyPlanSubmissionReadiness/);
  assert.doesNotMatch(quickCheck, /decideWeeklyPlanSuggestion/);
});

test("quick check groups blockers and advisory warnings without becoming a navigation gate", async () => {
  const [
    work,
    quickCheck,
    submit,
    discussionHistory,
    stepper,
    translationsSource,
  ] = await Promise.all([
    readSource("src/pages/WeeklyPlans/WeeklyPlanWizardWork.tsx"),
    readSource("src/components/weekly-plans/WeeklyPlanQuickCheck.tsx"),
    readSource("src/pages/WeeklyPlans/WeeklyPlanWizardSubmit.tsx"),
    readSource(
      "src/components/weekly-plans/WeeklyPlanStep4DiscussionHistory.tsx",
    ),
    readSource("src/components/weekly-plans/WeeklyPlanWizardStepper.tsx"),
    readSource("src/locales/vi/common.json"),
  ]);
  const wizard = JSON.parse(translationsSource).weeklyPlanning.wizard;

  assert.deepEqual(Object.keys(wizard.steps), [
    "source",
    "structure",
    "work",
    "submit",
  ]);
  assert.equal(wizard.steps.work, "Hoàn thiện & kiểm tra");
  assert.match(stepper, /lg:grid-cols-4/);
  assert.match(stepper, /index \+ 1/);
  assert.match(work, /weekly-plans\/\$\{plan\.id\}\/submit/);
  assert.match(work, /disabled={busy \|\| !readiness\.ready}/);
  assert.doesNotMatch(work, /analysisState/);
  assert.match(quickCheck, /blockers\s*\.filter/);
  assert.match(quickCheck, /warningGroups\.map/);
  assert.match(quickCheck, /<details/);
  assert.doesNotMatch(quickCheck, /ACCEPTED|REJECTED|Bỏ qua|Đã xử lý/);
  assert.doesNotMatch(
    submit,
    /weeklyPlanAnalysisState|reviewCounts|pending|resolved/,
  );
  assert.match(discussionHistory, /submit\.comments/);
  assert.match(discussionHistory, /submit\.history/);
});

const discussionPlan = {
  employee_reviews: [
    { assignee: { id: 7, name: "Huyền My" } },
    { assignee: { id: 8, name: "Lê Hùng" } },
    { assignee: { id: 9, name: "Đăng" } },
  ],
  entries: [
    {
      id: 90001,
      title: "Chăm sóc và tư vấn khách hàng",
      assignee: { id: 7, name: "Huyền My" },
    },
    {
      id: 90002,
      title: "Bán hàng và theo dõi đơn hàng",
      assignee: { id: 7, name: "Huyền My" },
    },
    {
      id: 90003,
      title: "Vận hành TikTok Kim Phúc Sắc",
      assignee: { id: 8, name: "Lê Hùng" },
    },
    {
      id: 90004,
      title: "Phối hợp với Đăng quay content",
      assignee: { id: 9, name: "Đăng" },
    },
  ],
};

test("discussion target options follow employee ownership and keep ids out of labels", () => {
  const employees = createWeeklyPlanDiscussionEmployeeOptions(discussionPlan);
  const huyenWorks = createWeeklyPlanDiscussionWorkOptions(discussionPlan, 7);

  assert.deepEqual(
    employees.map(({ label }) => label),
    ["Huyền My", "Lê Hùng", "Đăng"],
  );
  assert.deepEqual(
    huyenWorks.map(({ label }) => label),
    ["Chăm sóc và tư vấn khách hàng", "Bán hàng và theo dõi đơn hàng"],
  );
  assert.doesNotMatch(JSON.stringify(huyenWorks), /Vận hành TikTok/);
  huyenWorks.forEach(({ label }) => assert.doesNotMatch(label, /9000\d|#/));
});

test("discussion employee and work search remains Vietnamese diacritic-insensitive", () => {
  const employees = createWeeklyPlanDiscussionEmployeeOptions(discussionPlan);
  const works = discussionPlan.entries.map(({ id: value, title: label }) => ({
    value,
    label,
  }));

  assert.deepEqual(
    filterWeeklyPlanDiscussionOptions(employees, "huyen").map(
      ({ label }) => label,
    ),
    ["Huyền My"],
  );
  assert.deepEqual(
    filterWeeklyPlanDiscussionOptions(works, "BÁN HÀNG").map(
      ({ label }) => label,
    ),
    ["Bán hàng và theo dõi đơn hàng"],
  );
  assert.deepEqual(
    filterWeeklyPlanDiscussionOptions(employees, "dang").map(
      ({ label }) => label,
    ),
    ["Đăng"],
  );
  assert.deepEqual(
    filterWeeklyPlanDiscussionOptions(works, "dang quay").map(
      ({ label }) => label,
    ),
    ["Phối hợp với Đăng quay content"],
  );
  assert.deepEqual(
    filterWeeklyPlanDiscussionOptions(works, "TikTok").map(
      ({ label }) => label,
    ),
    ["Vận hành TikTok Kim Phúc Sắc"],
  );
});

test("discussion target payload maps plan, employee, work, and stale work safely", () => {
  assert.deepEqual(
    resolveWeeklyPlanCommentTargetArguments(discussionPlan, {
      selectedEmployeeId: null,
      selectedEntryId: null,
    }),
    { valid: true, assigneeId: undefined, entryId: undefined },
  );
  assert.deepEqual(
    resolveWeeklyPlanCommentTargetArguments(discussionPlan, {
      selectedEmployeeId: 7,
      selectedEntryId: null,
    }),
    { valid: true, assigneeId: 7, entryId: undefined },
  );
  assert.deepEqual(
    resolveWeeklyPlanCommentTargetArguments(discussionPlan, {
      selectedEmployeeId: 7,
      selectedEntryId: 90002,
    }),
    { valid: true, assigneeId: undefined, entryId: 90002 },
  );
  const staleTarget = { selectedEmployeeId: 8, selectedEntryId: 90002 };
  assert.equal(
    isWeeklyPlanDiscussionEntryConsistent(discussionPlan, staleTarget),
    false,
  );
  assert.deepEqual(
    resolveWeeklyPlanCommentTargetArguments(discussionPlan, staleTarget),
    { valid: false, assigneeId: undefined, entryId: undefined },
  );
  assert.deepEqual(
    resolveWeeklyPlanCommentTargetArguments(discussionPlan, {
      selectedEmployeeId: 7,
      selectedEntryId: 99999,
    }),
    { valid: false, assigneeId: undefined, entryId: undefined },
  );
  const unavailableEmployeeTarget = {
    selectedEmployeeId: 99,
    selectedEntryId: null,
  };
  assert.equal(
    isWeeklyPlanDiscussionEmployeeConsistent(
      discussionPlan,
      unavailableEmployeeTarget,
    ),
    false,
  );
  assert.deepEqual(
    resolveWeeklyPlanCommentTargetArguments(
      discussionPlan,
      unavailableEmployeeTarget,
    ),
    { valid: false, assigneeId: undefined, entryId: undefined },
  );
});

test("comment context resolves plan, employee, entry, and deleted entry without ids", () => {
  const labels = {
    wholePlan: "Toàn bộ kế hoạch",
    allEmployeeWorks: "Toàn bộ công việc",
    unavailableEmployee: "Nhân viên không còn khả dụng",
    unavailableWork: "Công việc không còn khả dụng",
  };
  const resolve = (comment) =>
    resolveWeeklyPlanCommentContext(discussionPlan, comment, labels);

  assert.equal(
    resolve({ assignee_id: null, entry_id: null }).label,
    "Toàn bộ kế hoạch",
  );
  assert.equal(
    resolve({ assignee_id: 7, entry_id: null }).label,
    "Huyền My · Toàn bộ công việc",
  );
  assert.equal(
    resolve({ assignee_id: 8, entry_id: 90002 }).label,
    "Huyền My › Bán hàng và theo dõi đơn hàng",
  );
  assert.equal(
    resolve({ assignee_id: 8, entry_id: 99999 }).label,
    "Công việc không còn khả dụng",
  );
  assert.doesNotMatch(
    JSON.stringify([
      resolve({ assignee_id: 7, entry_id: null }),
      resolve({ assignee_id: 8, entry_id: 90002 }),
      resolve({ assignee_id: 8, entry_id: 99999 }),
    ]),
    /#90002|#99999|Công việc #/,
  );
});

test("HR and OWNER reuse the hierarchical accessible discussion controls", async () => {
  const [submit, detail, picker, targetUtility, translationsSource] =
    await Promise.all([
      readSource("src/pages/WeeklyPlans/WeeklyPlanWizardSubmit.tsx"),
      readSource("src/pages/WeeklyPlans/WeeklyPlanDetail.tsx"),
      readSource(
        "src/components/weekly-plans/WeeklyPlanDiscussionTargetFields.tsx",
      ),
      readSource("src/utils/weeklyPlanDiscussionTarget.ts"),
      readSource("src/locales/vi/common.json"),
    ]);
  const copy = JSON.parse(translationsSource).weeklyPlanning;

  assert.match(submit, /<WeeklyPlanStep4DiscussionHistory/);
  const discussionHistory = await readSource(
    "src/components/weekly-plans/WeeklyPlanStep4DiscussionHistory.tsx",
  );
  assert.match(
    discussionHistory,
    /<details\s+data-section="weekly-plan-discussion"/,
  );
  assert.match(
    discussionHistory,
    /<details\s+data-section="weekly-plan-history"/,
  );
  assert.doesNotMatch(discussionHistory, /<details[^>]*\sopen(?:=|\s|>)/);
  assert.match(discussionHistory, /WeeklyPlanDiscussionTargetFields/);
  assert.match(detail, /WeeklyPlanDiscussionTargetFields/);
  assert.match(discussionHistory, /WeeklyPlanCommentContext/);
  assert.match(detail, /WeeklyPlanCommentContext/);
  assert.match(
    discussionHistory,
    /formatWeeklyPlanCommentTimestamp\(item\.created_at\)/,
  );
  assert.match(detail, /formatWeeklyPlanCommentTimestamp\(item\.created_at\)/);
  assert.doesNotMatch(
    discussionHistory,
    /import Select|<Select|commentTarget\.split/,
  );
  assert.doesNotMatch(detail, /import Select|<Select|commentTarget\.split/);
  assert.doesNotMatch(detail, /Công việc #|commentForEntry|commentEntry/);
  assert.match(discussionHistory, /resolveWeeklyPlanCommentTargetArguments/);
  assert.match(detail, /resolveWeeklyPlanCommentTargetArguments/);
  assert.match(discussionHistory, /disabled={busy \|\| !comment\.trim\(\)}/);
  assert.match(detail, /plan\.actions\.can_comment/);
  assert.match(submit, /submitWeeklyPlan\(plan\.id\)/);

  assert.match(picker, /value\.selectedEmployeeId !== null/);
  assert.match(targetUtility, /entry\.assignee\?\.id === selectedEmployeeId/);
  assert.match(picker, /selectedEntryId: null/);
  assert.match(picker, /selectedEmployeeId: null, selectedEntryId: null/);
  assert.match(picker, /aria-haspopup="listbox"/);
  assert.match(picker, /aria-expanded={isOpen}/);
  assert.match(picker, /role="listbox"/);
  assert.match(picker, /role="option"/);
  assert.match(picker, /event\.key !== "Escape"/);
  assert.match(picker, /max-h-72/);
  assert.match(picker, /overflow-y-auto/);
  assert.doesNotMatch(picker, /brand-|text-(?:pink|rose)-/);

  assert.equal(copy.discussion.employeeLabel, "Nhân viên");
  assert.equal(copy.discussion.workLabel, "Công việc");
  assert.equal(
    copy.discussion.allEmployeeWorksOption,
    "Tất cả công việc của nhân viên",
  );
  assert.equal(copy.discussion.contentLabel, "Nội dung ghi chú");
  assert.match(copy.discussion.targetChanged, /Vui lòng chọn lại công việc/);
  assert.doesNotMatch(JSON.stringify(copy.discussion), /Công việc #\{id\}/);

  for (const consumer of [discussionHistory, detail]) {
    const invalidTargetCheck = consumer.indexOf("if (!target.valid)");
    const invalidReturn = consumer.indexOf("return;", invalidTargetCheck);
    const apiCall = consumer.indexOf(
      "await addWeeklyPlanComment",
      invalidReturn,
    );
    const commentClear = consumer.indexOf(
      'setComment("");',
      invalidTargetCheck,
    );

    assert.ok(invalidTargetCheck >= 0);
    assert.ok(invalidReturn > invalidTargetCheck);
    assert.ok(apiCall > invalidReturn);
    assert.ok(commentClear > invalidReturn);
    assert.match(
      consumer.slice(invalidTargetCheck, invalidReturn),
      /setSelectedEntryId\(null\)/,
    );
    assert.doesNotMatch(
      consumer.slice(invalidTargetCheck, invalidReturn),
      /setComment\(/,
    );
  }
});
