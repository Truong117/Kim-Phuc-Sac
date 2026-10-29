import assert from "node:assert/strict";
import { after, before, test } from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { createServer } from "vite";
import { normalizeWeeklyPlanAiSuggestions } from "../src/utils/weeklyPlanAiSuggestions.ts";

let vite;
let GoogleDocsAiReview;

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
  expectedResult = null,
  note = null,
}) => ({
  source_keys: passageIds.map((id) => `google-docs:${tabId}:${id}`),
  title,
  content: `Description for ${title}`,
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
  assert.match(markup, /Source structured work/);
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
