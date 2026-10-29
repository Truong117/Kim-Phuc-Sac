import assert from "node:assert/strict";
import { after, before, test } from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { createServer } from "vite";
import { createGoogleDocsAiReviewDrafts } from "../src/utils/weeklyPlanGoogleDocsAiReview.ts";
import { normalizeWeeklyPlanAiSuggestions } from "../src/utils/weeklyPlanAiSuggestions.ts";

let vite;
let GoogleDocsAiReview;
let GoogleDocsAiReviewJobRow;

before(async () => {
  vite = await createServer({
    appType: "custom",
    logLevel: "silent",
    server: { middlewareMode: true },
  });
  await vite.ssrLoadModule("/src/i18n/index.ts");
  GoogleDocsAiReview = (
    await vite.ssrLoadModule(
      "/src/components/weekly-plans/WeeklyPlanGoogleDocsAiReview.tsx",
    )
  ).default;
  GoogleDocsAiReviewJobRow = (
    await vite.ssrLoadModule(
      "/src/components/weekly-plans/WeeklyPlanGoogleDocsAiReviewJobRow.tsx",
    )
  ).default;
});

after(async () => {
  await vite?.close();
});

const employeeA = {
  id: 101,
  name: "KPS Employee A",
  email: "a@example.test",
};
const employeeB = {
  id: 202,
  name: "KPS Employee B",
  email: "b@example.test",
};

const passage = (id, tabId) => ({
  id,
  source_key: `google-docs:${tabId}:${id}`,
  sequence: id,
  kind: "paragraph",
  text: `Passage ${id}`,
  metadata: {},
  source_context: null,
  source_group: null,
});

const suggestion = ({
  passageIds,
  tabId,
  tabTitle,
  assignee,
  title,
  groupingBasis,
  items = [],
  detailSuggestions = null,
  content = `Description for ${title}`,
  expectedResult = null,
  note = null,
}) => ({
  source_keys: passageIds.map((id) => `google-docs:${tabId}:${id}`),
  title,
  content,
  expected_result: expectedResult,
  employee_identifier: null,
  start_date: null,
  due_date: null,
  priority: "MEDIUM",
  note,
  grouping_basis: groupingBasis,
  items,
  source_tab: { tab_id: tabId, title: tabTitle },
  assignee,
  assignment_source: "VERIFIED_GOOGLE_DOCS_TAB_MAPPING",
  detail_suggestions: detailSuggestions,
  verification_fields: [],
  evidence: "Source evidence",
  provenance: {},
});

const renderReview = (suggestions, passages) =>
  renderToStaticMarkup(
    React.createElement(GoogleDocsAiReview, {
      suggestions,
      passages,
      weekStart: "2026-11-02",
      weekEnd: "2026-11-07",
      busy: false,
      onApplyAll: async () => ({ succeeded: true }),
      onApplied: () => {},
    }),
  );

const renderJobRow = (aiSuggestion, passages) => {
  const [draft] = createGoogleDocsAiReviewDrafts([aiSuggestion], passages);

  return renderToStaticMarkup(
    React.createElement(GoogleDocsAiReviewJobRow, {
      draft,
      index: 0,
      displayIndex: 1,
      errors: {},
      expanded: true,
      busy: false,
      applying: false,
      onToggle: () => {},
      onChange: () => {},
      onItemChange: () => {},
      onAddItem: () => {},
      onRemoveItem: () => {},
      onMoveItem: () => {},
      onSplit: () => {},
    }),
  );
};

test("Google Docs AI review renders a realistic two-tab v5 response without throwing", () => {
  const passages = [
    passage(1, "tab-a"),
    passage(2, "tab-a"),
    passage(3, "tab-b"),
    passage(4, "tab-b"),
  ];
  const suggestions = [
    suggestion({
      passageIds: [1, 2],
      tabId: "tab-a",
      tabTitle: "Phương Anh",
      assignee: employeeA,
      title: "Source structured work",
      groupingBasis: "SOURCE_STRUCTURE",
      items: [
        {
          title: "Nested source item",
          content: null,
          source_keys: ["google-docs:tab-a:1"],
        },
      ],
      detailSuggestions: null,
      expectedResult: null,
      note: null,
    }),
    suggestion({
      passageIds: [3],
      tabId: "tab-b",
      tabTitle: "Huyền My",
      assignee: employeeB,
      title: "AI grouped work",
      groupingBasis: "AI_GROUPED",
      items: [
        {
          title: "Nested AI item",
          content: "Review details",
          source_keys: ["google-docs:tab-b:3"],
        },
      ],
      detailSuggestions: {
        channel: { value: "TikTok", basis: "SOURCE_DERIVED" },
        account: null,
        frequency: { value: "Daily", basis: "AI_SUGGESTION" },
        format: null,
        content_direction: null,
        objective: null,
        notes: null,
      },
      expectedResult: "Completed",
      note: null,
    }),
    suggestion({
      passageIds: [4],
      tabId: "tab-b",
      tabTitle: "Huyền My",
      assignee: employeeB,
      title: "Atomic work",
      groupingBasis: "ATOMIC",
      expectedResult: null,
      note: null,
    }),
  ];

  const markup = renderReview(suggestions, passages);
  assert.match(markup, /KPS Employee A/);
  assert.match(markup, /KPS Employee B/);
  assert.doesNotMatch(markup, /Source structured work/);
  assert.doesNotMatch(markup, /AI grouped work/);
  assert.equal((markup.match(/aria-expanded="false"/g) ?? []).length, 2);
  assert.match(markup, /3 công việc/);
  assert.match(markup, /2 nhân viên/);
});

test("Google Docs AI review renders a single-tab v5 response without throwing", () => {
  const passages = [passage(11, "tab-single")];
  const markup = renderReview(
    [
      suggestion({
        passageIds: [11],
        tabId: "tab-single",
        tabTitle: "Minh Đăng",
        assignee: employeeA,
        title: "Single tab work",
        groupingBasis: "ATOMIC",
        expectedResult: null,
        note: null,
      }),
    ],
    passages,
  );

  assert.match(markup, /KPS Employee A/);
  assert.match(markup, /Single tab work/);
  assert.match(markup, /aria-expanded="false"/);
});

test("grouped job prioritizes compact child rows and keeps parent AI details collapsed", () => {
  const passages = [passage(21, "tab-grouped"), passage(22, "tab-grouped")];
  const markup = renderJobRow(
    suggestion({
      passageIds: [21, 22],
      tabId: "tab-grouped",
      tabTitle: "Grouped tab",
      assignee: employeeA,
      title: "Grouped parent",
      content: "Hidden parent content",
      expectedResult: "Hidden parent result",
      note: "Hidden parent note",
      groupingBasis: "AI_GROUPED",
      items: [
        {
          title: "Visible child A",
          content: "Visible child content A",
          source_keys: ["google-docs:tab-grouped:21"],
        },
        {
          title: "Visible child B",
          content: null,
          source_keys: ["google-docs:tab-grouped:22"],
        },
      ],
      detailSuggestions: {
        channel: { value: "TikTok", basis: "SOURCE_DERIVED" },
        account: { value: "KPS Official", basis: "AI_SUGGESTION" },
        frequency: null,
        format: null,
        content_direction: {
          value: "Hidden AI direction",
          basis: "AI_SUGGESTION",
        },
        objective: null,
        notes: null,
      },
    }),
    passages,
  );

  assert.match(markup, /Visible child A/);
  assert.match(markup, /Visible child content A/);
  assert.match(markup, /Visible child B/);
  assert.match(markup, /TikTok/);
  assert.match(markup, /KPS Official/);
  assert.match(markup, /· AI/);
  assert.match(markup, /Thông tin chung/);
  assert.match(markup, /Chi tiết AI/);
  assert.doesNotMatch(markup, /Hidden parent content/);
  assert.doesNotMatch(markup, /Hidden parent result/);
  assert.doesNotMatch(markup, /Hidden parent note/);
  assert.doesNotMatch(markup, /Hidden AI direction/);
});

test("atomic job without children keeps its general fields visible", () => {
  const passages = [passage(31, "tab-atomic")];
  const markup = renderJobRow(
    suggestion({
      passageIds: [31],
      tabId: "tab-atomic",
      tabTitle: "Atomic tab",
      assignee: employeeA,
      title: "Atomic parent",
      content: "Visible atomic content",
      expectedResult: "Visible atomic result",
      note: "Visible atomic note",
      groupingBasis: "ATOMIC",
    }),
    passages,
  );

  assert.match(markup, /Visible atomic content/);
  assert.match(markup, /Visible atomic result/);
  assert.match(markup, /Visible atomic note/);
  assert.doesNotMatch(markup, /Thông tin chung/);
});

test("normalized trusted response with missing runtime arrays no longer crashes review rendering", () => {
  const normalized = normalizeWeeklyPlanAiSuggestions(
    [
      {
        title: "Runtime-safe work",
        content: null,
        expected_result: null,
        note: null,
        source_tab: { tab_id: "tab-runtime", title: "Runtime tab" },
        assignee: employeeA,
        assignment_source: "VERIFIED_GOOGLE_DOCS_TAB_MAPPING",
        items: [{ title: "Runtime-safe child" }],
      },
    ],
    "GOOGLE_DOCS",
  );
  assert.equal(normalized.ok, true);

  const markup = renderReview(normalized.suggestions, []);
  assert.match(markup, /Runtime-safe work/);
  assert.match(markup, /KPS Employee A/);
});
