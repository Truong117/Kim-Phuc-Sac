import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import {
  createGoogleDocsAiReviewDrafts,
  groupGoogleDocsAiReviewDrafts,
  splitGoogleDocsAiReviewDraft,
} from "../src/utils/weeklyPlanGoogleDocsAiReview.ts";
import {
  googleDocsSourceReady,
  groupWeeklyPlanEntriesByEmployee,
  guardWeeklyPlanWizardStep,
  inferWeeklyPlanWizardStep,
  weeklyPlanAnalysisState,
  weeklyPlanWorkCounts,
} from "../src/utils/weeklyPlanWizard.ts";

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

test("wizard default inference and guards enforce source, structure, work, and submit prerequisites", () => {
  const unresolved = mappings([tab("UNVERIFIED", "tab-a", null)]);
  assert.equal(inferWeeklyPlanWizardStep(plan(), unresolved), "source");
  assert.equal(
    guardWeeklyPlanWizardStep("quality", plan(), unresolved),
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
  assert.equal(
    guardWeeklyPlanWizardStep("quality", completed, ready),
    "quality",
  );
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

  for (const requested of ["work", "quality", "submit"]) {
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
  for (const requested of ["work", "quality", "submit"]) {
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

test("analysis state distinguishes missing, current, and invalidated review", () => {
  assert.equal(weeklyPlanAnalysisState([]), "NOT_RUN");
  assert.equal(
    weeklyPlanAnalysisState([{ id: 4, type: "ANALYZED" }]),
    "CURRENT",
  );
  assert.equal(
    weeklyPlanAnalysisState([
      { id: 4, type: "ANALYZED" },
      { id: 5, type: "SUGGESTIONS_INVALIDATED" },
    ]),
    "STALE",
  );
  assert.equal(
    weeklyPlanAnalysisState([
      { id: 4, type: "ANALYZED" },
      { id: 6, type: "PLAN_RESET" },
    ]),
    "STALE",
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

  for (const route of ["source", "structure", "work", "quality", "submit"]) {
    assert.match(app, new RegExp(`path="${route}"`));
  }
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
  const [stepper, structure, source, work, quality, submit] = await Promise.all(
    [
      readSource("src/components/weekly-plans/WeeklyPlanWizardStepper.tsx"),
      readSource("src/pages/WeeklyPlans/WeeklyPlanWizardStructure.tsx"),
      readSource("src/pages/WeeklyPlans/WeeklyPlanWizardSource.tsx"),
      readSource("src/pages/WeeklyPlans/WeeklyPlanWizardWork.tsx"),
      readSource("src/pages/WeeklyPlans/WeeklyPlanWizardQuality.tsx"),
      readSource("src/pages/WeeklyPlans/WeeklyPlanWizardSubmit.tsx"),
    ],
  );

  assert.match(stepper, /bg-kps-primary\/\[0\.08\]/);
  assert.match(stepper, /text-kps-primary/);
  assert.doesNotMatch(stepper, /brand-/);
  assert.match(structure, /border-kps-primary\/10/);
  assert.match(structure, /bg-kps-primary\/\[0\.06\]/);
  assert.doesNotMatch(structure, /brand-/);
  for (const wizardScreen of [source, work, quality, submit]) {
    assert.match(wizardScreen, /bg-kps-primary/);
    assert.match(wizardScreen, /hover:bg-kps-primary-hover/);
    assert.doesNotMatch(wizardScreen, /brand-/);
  }
});
