import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { after, before, test } from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { MemoryRouter } from "react-router";
import { createServer } from "vite";
import { requestWeeklyPlanAssistantBatch } from "../src/services/weeklyPlanAssistantBatchRequest.ts";
import {
  buildWeeklyPlanV3AssistantBatch,
  clearConfirmedWeeklyPlanV3AssistantDraft,
  setWeeklyPlanV3AssistantDraftDecision,
  summarizeWeeklyPlanV3AssistantDraft,
} from "../src/utils/weeklyPlanV3AssistantBatch.ts";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

let vite;
let ReviewWorkspace;

before(async () => {
  vite = await createServer({
    appType: "custom",
    logLevel: "silent",
    server: { middlewareMode: true },
  });
  await vite.ssrLoadModule("/src/i18n/index.ts");
  ReviewWorkspace = (
    await vite.ssrLoadModule(
      "/src/components/weekly-plans/WeeklyPlanReviewWorkspace.tsx",
    )
  ).default;
});

after(async () => {
  await vite?.close();
});

const workflow = (id, name, changes = {}) => ({
  id,
  assignee: { id, name },
  state: "AWAITING_ASSISTANT",
  state_version: id + 10,
  content_version: id + 20,
  correction_origin_stage: null,
  active_entry_count: 1,
  active_child_item_count: 0,
  publication: {
    published_by: null,
    published_at: null,
    task_count: 0,
  },
  viewer_context: { mode: "ASSISTANT_REVIEW", read_only: false },
  actions: {
    can_accept: true,
    can_request_changes: true,
    can_correct: false,
    can_resubmit: false,
    can_publish: false,
  },
  ...changes,
});

const entry = (id, assignee) => ({
  id,
  source_row: null,
  employee_identifier: null,
  employee_name: assignee.name,
  department_name: null,
  assignee: { ...assignee, email: `${id}@example.test` },
  title: `Work ${id}`,
  content: null,
  expected_result: `Result ${id}`,
  start_date: "2027-01-04",
  due_date: "2027-01-09",
  priority: "MEDIUM",
  note: null,
  sort_order: id,
  validation_status: "VALID",
  validation_errors: [],
  items: [],
  source_passages: [],
});

const actions = {
  can_edit: false,
  can_correct_requested_changes: false,
  can_resubmit_changes: false,
  can_reset: false,
  can_delete: false,
  can_analyze: false,
  can_submit: false,
  can_review: false,
  can_review_edit: false,
  can_assistant_review: false,
  can_finalize_assistant_review: false,
  can_owner_review: false,
  can_open_review_workspace: true,
  can_approve: false,
  can_publish: false,
  can_approve_and_publish: false,
  can_comment: true,
  can_extract_source: false,
  can_replace_source: false,
  can_apply_source: false,
  can_group_source: false,
  can_ai_extract_source: false,
  can_ai_extract_selected_source: false,
  can_manage_google_docs_mappings: false,
};

const plan = (workflowChanges = []) => {
  const employeeWorkflows = [
    workflow(11, "Assistant Employee A"),
    workflow(22, "Assistant Employee B"),
  ].map((item, index) => ({ ...item, ...(workflowChanges[index] ?? {}) }));

  return {
    id: 800,
    workflow_version: 3,
    week_start: "2027-01-04",
    week_end: "2027-01-09",
    status: "IN_REVIEW",
    creator: { id: 1, name: "HR" },
    entries: employeeWorkflows.map((item) => entry(item.id, item.assignee)),
    employee_workflows: employeeWorkflows,
    publication_summary: {
      total: 2,
      awaiting_assistant: 2,
      staged_for_owner: 0,
      hr_correction: 0,
      published: 0,
      no_work_approved: 0,
      phase: "IN_REVIEW",
    },
    employee_reviews: [],
    comments: [],
    events: [],
    review_workflow: null,
    actions,
  };
};

const renderWorkspace = (value) =>
  renderToStaticMarkup(
    React.createElement(
      MemoryRouter,
      null,
      React.createElement(ReviewWorkspace, {
        plan: value,
        onPlanChange: () => {},
        onAssistantCompleted: () => {},
      }),
    ),
  );

test("local Assistant selections replace and clear without mutation requests", async () => {
  const requestSource = await readSource(
    "src/services/weeklyPlanAssistantBatchRequest.ts",
  );
  let draft = {};
  draft = setWeeklyPlanV3AssistantDraftDecision(draft, 11, "ACCEPTED");
  assert.deepEqual(draft, { 11: "ACCEPTED" });
  draft = setWeeklyPlanV3AssistantDraftDecision(draft, 11, "CHANGES_REQUESTED");
  assert.deepEqual(draft, { 11: "CHANGES_REQUESTED" });
  draft = setWeeklyPlanV3AssistantDraftDecision(draft, 11, null);
  assert.deepEqual(draft, {});
  assert.doesNotMatch(
    setWeeklyPlanV3AssistantDraftDecision.toString(),
    /assistant-review-batches|fetch|apiRequest/,
  );
  assert.match(requestSource, /method: "POST"/);
});

test("partial batch includes only selected authorized employees and exact versions", () => {
  const value = plan();
  const draft = {
    22: "CHANGES_REQUESTED",
  };
  assert.deepEqual(buildWeeklyPlanV3AssistantBatch(value, draft), [
    {
      assignee_id: 22,
      decision: "CHANGES_REQUESTED",
      expected_state_version: 32,
      expected_content_version: 42,
    },
  ]);
  assert.deepEqual(
    summarizeWeeklyPlanV3AssistantDraft(
      buildWeeklyPlanV3AssistantBatch(value, draft),
    ),
    { total: 1, accepted: 0, changesRequested: 1 },
  );
  assert.equal(value.employee_workflows[0].state_version, 21);
});

test("read-only backend actions are never added to a confirmation batch", () => {
  const readOnlyWorkflow = {
    viewer_context: { mode: "OBSERVER", read_only: true },
    actions: {
      can_accept: false,
      can_request_changes: false,
      can_correct: false,
      can_resubmit: false,
      can_publish: false,
    },
  };
  const value = plan([readOnlyWorkflow, readOnlyWorkflow]);
  assert.deepEqual(
    buildWeeklyPlanV3AssistantBatch(value, { 11: "ACCEPTED" }),
    [],
  );
  const markup = renderWorkspace(value);
  assert.doesNotMatch(markup, />Chấp nhận<\/button>/);
  assert.doesNotMatch(markup, /Xem lại lô \(/);
});

test("confirmation request sends versions and one opaque idempotency key", async () => {
  const calls = [];
  const decisions = buildWeeklyPlanV3AssistantBatch(plan(), {
    11: "ACCEPTED",
    22: "CHANGES_REQUESTED",
  });
  const expected = {
    plan: plan(),
    batch: {
      id: 91,
      stage: "ASSISTANT",
      confirmed_at: "2027-01-01T00:00:00Z",
      accepted: 1,
      changes_requested: 1,
      affected_employee_ids: [11, 22],
    },
    results: [
      { assignee_id: 11, to_state: "STAGED_FOR_OWNER" },
      { assignee_id: 22, to_state: "HR_CORRECTION" },
    ],
  };
  const result = await requestWeeklyPlanAssistantBatch(
    async (path, init) => {
      calls.push({ path, init });
      return { data: expected };
    },
    800,
    decisions,
    "logical-confirmation-001",
  );

  assert.equal(result, expected);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].path, "/api/weekly-plans/800/assistant-review-batches");
  assert.equal(
    calls[0].init.headers["Idempotency-Key"],
    "logical-confirmation-001",
  );
  assert.deepEqual(JSON.parse(calls[0].init.body), { decisions });
});

test("failed submissions preserve drafts and success clears only confirmed employees", async () => {
  const original = { 11: "ACCEPTED", 22: "CHANGES_REQUESTED" };
  await assert.rejects(
    requestWeeklyPlanAssistantBatch(
      async () => {
        throw new Error("conflict");
      },
      800,
      buildWeeklyPlanV3AssistantBatch(plan(), original),
      "logical-confirmation-002",
    ),
    /conflict/,
  );
  assert.deepEqual(original, { 11: "ACCEPTED", 22: "CHANGES_REQUESTED" });
  assert.deepEqual(
    clearConfirmedWeeklyPlanV3AssistantDraft(original, [{ assignee_id: 11 }]),
    { 22: "CHANGES_REQUESTED" },
  );
});

test("V3 workspace is isolated while discussion notes and V2 rendering stay unchanged", async () => {
  const translations = JSON.parse(
    await readSource("src/locales/vi/common.json"),
  );
  const v3Markup = renderWorkspace(plan());
  assert.match(
    v3Markup,
    new RegExp(translations.weeklyPlanning.reviewWorkspace.v3Assistant.title),
  );
  assert.match(
    v3Markup,
    new RegExp(translations.weeklyPlanning.reviewWorkspace.v3Assistant.accept),
  );
  assert.match(
    v3Markup,
    new RegExp(translations.weeklyPlanning.reviewWorkspace.discussion.title),
  );
  assert.doesNotMatch(
    v3Markup,
    new RegExp(translations.weeklyPlanning.reviewWorkspace.final.title),
  );

  const component = await readSource(
    "src/components/weekly-plans/WeeklyPlanV3AssistantBatchReview.tsx",
  );
  const workspace = await readSource(
    "src/components/weekly-plans/WeeklyPlanReviewWorkspace.tsx",
  );
  assert.doesNotMatch(component, /addWeeklyPlanComment|decideWeeklyPlanReview/);
  assert.match(workspace, /addWeeklyPlanComment/);
  assert.match(workspace, /isV3 \? \(/);
  assert.match(workspace, /plan\.review_workflow\?\.current_round \? \(/);
  assert.match(component, /onPlanChange\(result\.plan\)/);
  assert.match(
    component,
    /catch \(nextError\)[\s\S]*?setError\([\s\S]*?finally/,
  );
  assert.doesNotMatch(
    component.match(/catch \(nextError\)[\s\S]*?finally/)?.[0] ?? "",
    /setDraft/,
  );
  assert.match(component, /submitting\.current/);
  assert.match(component, /current \?\? requestId\(\)/);
});
