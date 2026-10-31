import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { after, before, test } from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { MemoryRouter } from "react-router";
import { createServer } from "vite";
import { requestWeeklyPlanOwnerBatch } from "../src/services/weeklyPlanOwnerBatchRequest.ts";
import {
  resolveWeeklyPlanV3BatchConfirmation,
  weeklyPlanV3BatchFailureMode,
} from "../src/utils/weeklyPlanV3BatchConfirmation.ts";
import {
  buildWeeklyPlanV3OwnerBatch,
  clearConfirmedWeeklyPlanV3OwnerDraft,
  summarizeWeeklyPlanV3OwnerDraft,
} from "../src/utils/weeklyPlanV3OwnerBatch.ts";
import {
  filterWeeklyPlanV3Workflows,
  weeklyPlanV3HasHrCorrectionAccess,
  weeklyPlanV3TerminalProgress,
} from "../src/utils/weeklyPlanV3ReviewWorkspace.ts";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

class ApiServiceError extends Error {
  constructor(status) {
    super(`API request failed with status ${status}`);
    this.status = status;
  }
}

let vite;
let ReviewerWorkspace;

before(async () => {
  vite = await createServer({
    appType: "custom",
    logLevel: "silent",
    server: { middlewareMode: true },
  });
  await vite.ssrLoadModule("/src/i18n/index.ts");
  ReviewerWorkspace = (
    await vite.ssrLoadModule(
      "/src/components/weekly-plans/WeeklyPlanV3ReviewerWorkspace.tsx",
    )
  ).default;
});

after(async () => {
  await vite?.close();
});

const actions = (changes = {}) => ({
  can_accept: false,
  can_request_changes: false,
  can_correct: false,
  can_resubmit: false,
  can_publish: false,
  can_approve_no_work: false,
  ...changes,
});

const workflow = (id, state, mode = "OBSERVER", changes = {}) => ({
  id,
  assignee: { id, name: `Employee ${id}` },
  state,
  state_version: id + 1,
  content_version: id + 2,
  correction_origin_stage: state === "HR_CORRECTION" ? "OWNER" : null,
  active_entry_count: state === "NO_WORK_APPROVED" ? 0 : 1,
  active_child_item_count: 0,
  publication: {
    published_by: state === "PUBLISHED" ? { id: 1, name: "OWNER" } : null,
    published_at: state === "PUBLISHED" ? "2027-01-10T01:00:00Z" : null,
    task_count: state === "PUBLISHED" ? 1 : 0,
  },
  viewer_context: { mode, read_only: mode === "OBSERVER" },
  actions: actions(),
  ...changes,
});

const entry = (id) => ({
  id,
  source_row: null,
  employee_identifier: null,
  employee_name: `Employee ${id}`,
  department_name: "Operations",
  assignee: { id, name: `Employee ${id}`, email: `${id}@example.test` },
  title: `Work ${id}`,
  content: `Content ${id}`,
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

const plan = (employeeWorkflows) => ({
  id: 900,
  workflow_version: 3,
  week_start: "2027-01-04",
  week_end: "2027-01-09",
  status: "IN_REVIEW",
  creator: { id: 9, name: "HR" },
  entries: employeeWorkflows
    .filter((item) => item.active_entry_count > 0)
    .map((item) => entry(item.assignee.id)),
  employee_workflows: employeeWorkflows,
  publication_summary: {
    total: employeeWorkflows.length,
    awaiting_assistant: employeeWorkflows.filter(
      (item) => item.state === "AWAITING_ASSISTANT",
    ).length,
    staged_for_owner: employeeWorkflows.filter(
      (item) => item.state === "STAGED_FOR_OWNER",
    ).length,
    hr_correction: employeeWorkflows.filter(
      (item) => item.state === "HR_CORRECTION",
    ).length,
    published: employeeWorkflows.filter((item) => item.state === "PUBLISHED")
      .length,
    no_work_approved: employeeWorkflows.filter(
      (item) => item.state === "NO_WORK_APPROVED",
    ).length,
    phase: "PARTIALLY_PUBLISHED",
  },
  comments: [],
  events: [],
  employee_reviews: [],
  review_workflow: null,
  actions: { can_comment: false },
});

const mixedWorkflows = () => [
  workflow(11, "AWAITING_ASSISTANT", "ASSISTANT_REVIEW", {
    actions: actions({ can_accept: true, can_request_changes: true }),
  }),
  workflow(22, "STAGED_FOR_OWNER", "OWNER_REVIEW", {
    actions: actions({
      can_accept: true,
      can_request_changes: true,
      can_publish: true,
    }),
  }),
  workflow(33, "HR_CORRECTION", "HR_CORRECTION", {
    actions: actions({ can_correct: true, can_resubmit: true }),
  }),
  workflow(44, "PUBLISHED"),
  workflow(55, "NO_WORK_APPROVED", "OBSERVER", {
    active_entry_count: 0,
  }),
  workflow(66, "STAGED_FOR_OWNER"),
];

const renderWorkspace = (value) =>
  renderToStaticMarkup(
    React.createElement(
      MemoryRouter,
      null,
      React.createElement(ReviewerWorkspace, {
        plan: value,
        onPlanChange: () => {},
        onReload: async () => {},
      }),
    ),
  );

test("mixed workflow filters and terminal progress use employee states", () => {
  const workflows = mixedWorkflows();
  assert.deepEqual(
    filterWeeklyPlanV3Workflows(workflows, "MY_REVIEW").map((item) => item.id),
    [11, 22],
  );
  assert.deepEqual(
    filterWeeklyPlanV3Workflows(workflows, "OTHER_REVIEW").map(
      (item) => item.id,
    ),
    [66],
  );
  assert.deepEqual(
    filterWeeklyPlanV3Workflows(workflows, "HR_CORRECTION").map(
      (item) => item.id,
    ),
    [33],
  );
  assert.deepEqual(
    weeklyPlanV3TerminalProgress(plan(workflows).publication_summary),
    { terminal: 2, total: 6, percentage: 33 },
  );
  assert.deepEqual(weeklyPlanV3TerminalProgress(undefined), {
    terminal: 0,
    total: 0,
    percentage: 0,
  });
});

test("multi-permission workspace exposes separated queues and HR correction navigation", () => {
  const value = plan(mixedWorkflows());
  const markup = renderWorkspace(value);

  assert.match(markup, /Hàng đợi Trợ lý/);
  assert.match(markup, /Hàng đợi OWNER/);
  assert.match(markup, /Mở Step 4 chỉnh sửa HR/);
  assert.match(markup, /Đã phát hành một phần/);
  assert.match(markup, /2\/6/);
  assert.equal(weeklyPlanV3HasHrCorrectionAccess(value), true);
});

test("Assistant OWNER HR and observer contexts remain backend-action relative", () => {
  const assistantMarkup = renderWorkspace(plan([mixedWorkflows()[0]]));
  assert.match(assistantMarkup, /Xét duyệt theo lô của Trợ lý Giám đốc/);
  assert.match(assistantMarkup, />Chấp nhận<\/button>/);
  assert.doesNotMatch(assistantMarkup, /Phê duyệt và tạo Task/);

  const ownerMarkup = renderWorkspace(plan([mixedWorkflows()[1]]));
  assert.match(ownerMarkup, /Xét duyệt và phát hành theo lô của OWNER/);
  assert.match(ownerMarkup, /Phê duyệt và tạo Task/);
  assert.doesNotMatch(ownerMarkup, /Xác nhận lô xét duyệt\?/);

  const hrWorkflow = mixedWorkflows()[2];
  const hrValue = plan([hrWorkflow]);
  const hrMarkup = renderWorkspace(hrValue);
  assert.match(hrMarkup, /Mở Step 4 chỉnh sửa HR/);
  assert.doesNotMatch(hrMarkup, /Phê duyệt và tạo Task|>Chấp nhận<\/button>/);
  assert.equal(weeklyPlanV3HasHrCorrectionAccess(hrValue), true);

  const observerValue = plan([
    workflow(88, "STAGED_FOR_OWNER", "OBSERVER", {
      actions: actions(),
    }),
  ]);
  const observerMarkup = renderWorkspace(observerValue);
  assert.match(observerMarkup, /Chỉ xem/);
  assert.doesNotMatch(
    observerMarkup,
    /Phê duyệt và tạo Task|Yêu cầu HR chỉnh sửa|>Chấp nhận<\/button>/,
  );
  assert.equal(weeklyPlanV3HasHrCorrectionAccess(observerValue), false);
});

test("fully published employee workflows are read-only and terminal", () => {
  const value = plan([
    workflow(44, "PUBLISHED"),
    workflow(55, "NO_WORK_APPROVED", "OBSERVER", {
      active_entry_count: 0,
    }),
  ]);
  value.status = "PUBLISHED";
  value.publication_summary = {
    total: 2,
    awaiting_assistant: 0,
    staged_for_owner: 0,
    hr_correction: 0,
    published: 1,
    no_work_approved: 1,
    phase: "FULLY_PUBLISHED",
  };
  const markup = renderWorkspace(value);

  assert.match(markup, /Đã hoàn tất phát hành/);
  assert.match(markup, /2\/2/);
  assert.doesNotMatch(
    markup,
    /Phê duyệt và tạo Task|Yêu cầu HR chỉnh sửa|>Chấp nhận<\/button>/,
  );
});

test("missing V3 projection fails closed when rollout data is unavailable", async () => {
  const workspace = await readSource(
    "src/components/weekly-plans/WeeklyPlanReviewWorkspace.tsx",
  );

  assert.match(
    workspace,
    /plan\.workflow_version === 3 && Array\.isArray\(plan\.employee_workflows\)/,
  );
  assert.equal(
    weeklyPlanV3HasHrCorrectionAccess({ workflow_version: 3 }),
    false,
  );
});

test("OWNER draft enforces backend actions, zero-work semantics and task preview", () => {
  const publishable = mixedWorkflows()[1];
  const zeroWork = workflow(77, "STAGED_FOR_OWNER", "OWNER_REVIEW", {
    active_entry_count: 0,
    actions: actions({
      can_request_changes: true,
      can_approve_no_work: true,
    }),
  });
  const value = plan([publishable, zeroWork]);
  const draft = { 22: "ACCEPTED", 77: "APPROVE_NO_WORK", 999: "ACCEPTED" };
  const decisions = buildWeeklyPlanV3OwnerBatch(value, draft);

  assert.deepEqual(decisions, [
    {
      assignee_id: 22,
      decision: "ACCEPTED",
      expected_state_version: 23,
      expected_content_version: 24,
    },
    {
      assignee_id: 77,
      decision: "APPROVE_NO_WORK",
      expected_state_version: 78,
      expected_content_version: 79,
    },
  ]);
  assert.deepEqual(
    summarizeWeeklyPlanV3OwnerDraft(decisions, new Map([[22, 1]])),
    {
      total: 2,
      accepted: 1,
      changesRequested: 0,
      noWorkApproved: 1,
      taskCount: 1,
    },
  );
  assert.deepEqual(
    clearConfirmedWeeklyPlanV3OwnerDraft(draft, [{ assignee_id: 22 }]),
    { 77: "APPROVE_NO_WORK", 999: "ACCEPTED" },
  );
});

test("OWNER client sends one exact batch request with its idempotency key", async () => {
  const calls = [];
  const decisions = buildWeeklyPlanV3OwnerBatch(plan([mixedWorkflows()[1]]), {
    22: "CHANGES_REQUESTED",
  });
  const expected = {
    plan: plan([]),
    batch: {
      id: 12,
      stage: "OWNER",
      confirmed_at: "2027-01-10T00:00:00Z",
      accepted: 0,
      changes_requested: 1,
      no_work_approved: 0,
      published_task_count: 0,
      affected_employee_ids: [22],
    },
    results: [{ assignee_id: 22, to_state: "HR_CORRECTION", task_count: 0 }],
  };
  const result = await requestWeeklyPlanOwnerBatch(
    async (path, init) => {
      calls.push({ path, init });
      return { data: expected };
    },
    900,
    decisions,
    "owner-logical-request-001",
  );

  assert.equal(result, expected);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].path, "/api/weekly-plans/900/owner-review-batches");
  assert.equal(
    calls[0].init.headers["Idempotency-Key"],
    "owner-logical-request-001",
  );
  assert.deepEqual(JSON.parse(calls[0].init.body), { decisions });
});

test("ambiguous review retry preserves immutable identity and changed selections reconcile first", () => {
  let sequence = 0;
  const createRequestId = () => `review-request-${++sequence}`;
  const original = [
    {
      assignee_id: 22,
      decision: "ACCEPTED",
      expected_state_version: 2,
      expected_content_version: 3,
    },
  ];
  const first = resolveWeeklyPlanV3BatchConfirmation(
    null,
    900,
    original,
    createRequestId,
  );
  const changed = resolveWeeklyPlanV3BatchConfirmation(
    first.confirmation,
    900,
    [{ ...original[0], decision: "CHANGES_REQUESTED" }],
    createRequestId,
  );

  assert.equal(changed.confirmation, first.confirmation);
  assert.deepEqual(changed.confirmation.decisions, original);
  assert.equal(changed.confirmation.requestId, "review-request-1");
  assert.equal(changed.reconciliationRequired, true);
  assert.equal(sequence, 1);
});

test("review failure classification is conservative and drafts survive server outcomes", () => {
  assert.equal(
    weeklyPlanV3BatchFailureMode(new ApiServiceError(0)),
    "AMBIGUOUS",
  );
  assert.equal(
    weeklyPlanV3BatchFailureMode(new ApiServiceError(503)),
    "AMBIGUOUS",
  );
  assert.equal(weeklyPlanV3BatchFailureMode(new SyntaxError()), "AMBIGUOUS");
  assert.equal(
    weeklyPlanV3BatchFailureMode(new DOMException("aborted", "AbortError")),
    "AMBIGUOUS",
  );
  assert.equal(weeklyPlanV3BatchFailureMode(new Error("unknown")), "AMBIGUOUS");
  assert.equal(weeklyPlanV3BatchFailureMode(new ApiServiceError(409)), "STALE");
  assert.equal(
    weeklyPlanV3BatchFailureMode(new ApiServiceError(422)),
    "VALIDATION",
  );
});

test("review UI stays on V3 endpoints and keeps comments independent", async () => {
  const owner = await readSource(
    "src/components/weekly-plans/WeeklyPlanV3OwnerBatchReview.tsx",
  );
  const assistant = await readSource(
    "src/components/weekly-plans/WeeklyPlanV3AssistantBatchReview.tsx",
  );
  const workspace = await readSource(
    "src/components/weekly-plans/WeeklyPlanReviewWorkspace.tsx",
  );
  const submit = await readSource(
    "src/pages/WeeklyPlans/WeeklyPlanWizardSubmit.tsx",
  );

  assert.match(owner, /confirmWeeklyPlanOwnerBatch/);
  assert.match(owner, /onPlanChange\(result\.plan\)/);
  assert.match(owner, /submitting\.current/);
  assert.doesNotMatch(
    owner.match(/catch \(nextError\)[\s\S]*?finally/)?.[0] ?? "",
    /setDraft/,
  );
  assert.doesNotMatch(
    owner,
    /approveWeeklyPlan|publishWeeklyPlan|decideWeeklyPlanReview/,
  );
  assert.match(assistant, /resolveWeeklyPlanV3BatchConfirmation/);
  assert.match(assistant, /pending !== null/);
  assert.doesNotMatch(assistant, /setIdempotencyKey\(null\)/);
  assert.match(workspace, /addWeeklyPlanComment/);
  assert.match(workspace, /WeeklyPlanV3ReviewerWorkspace/);
  assert.match(
    submit,
    /hasV3CorrectionAccess[\s\S]*?return v3CorrectionWorkspace[\s\S]*?activeWeeklyPlanReviewPath/,
  );
});
