import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { after, before, test } from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { MemoryRouter } from "react-router";
import { createServer } from "vite";
import {
  initialExpandedEmployeeKey,
  toggleExpandedEmployeeKey,
} from "../src/utils/weeklyPlanEmployeeAccordion.ts";
import { resolveWeeklyPlanCommentContext } from "../src/utils/weeklyPlanCommentContext.ts";
import { createWeeklyPlanDiscussionEmployeeOptions } from "../src/utils/weeklyPlanDiscussionTarget.ts";
import {
  activeWeeklyPlanReviewSummary,
  canAcceptWeeklyPlanDecision,
  canRequestChangesForWeeklyPlanDecision,
  createWeeklyPlanReviewEmployeeGroups,
  submitWeeklyPlanChangeRequest,
  weeklyPlanSubmitStatusKey,
} from "../src/utils/weeklyPlanReviewWorkspace.ts";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

let vite;
let ReviewWorkspace;
let StatusBadge;

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
  StatusBadge = (
    await vite.ssrLoadModule(
      "/src/components/weekly-plans/WeeklyPlanStatusBadge.tsx",
    )
  ).default;
});

after(async () => {
  await vite?.close();
});

const actions = (changes = {}) => ({
  can_edit: false,
  can_correct_requested_changes: false,
  can_resubmit_changes: false,
  can_reset: false,
  can_delete: false,
  can_analyze: false,
  can_submit: false,
  can_review: false,
  can_review_edit: false,
  can_assistant_review: true,
  can_finalize_assistant_review: false,
  can_owner_review: false,
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
  ...changes,
});

const employee = (id, name) => ({ id, name });
const decision = (
  id,
  assignee,
  status,
  canRequestChanges = status === "PENDING" || status === "ACCEPTED",
) => ({
  id,
  assignee,
  status,
  reviewed_by: status === "PENDING" ? null : { id: 99, name: "Reviewer" },
  reviewed_at: status === "PENDING" ? null : "2026-11-01T08:00:00Z",
  actions: { can_request_changes: canRequestChanges },
});
const entry = (id, assignee, title = `Work ${id}`) => ({
  id,
  source_row: null,
  employee_identifier: null,
  employee_name: assignee.name,
  department_name: "Marketing",
  assignee: { ...assignee, email: `${assignee.id}@example.test` },
  title,
  content: `Content ${id}`,
  expected_result: `Result ${id}`,
  start_date: "2026-11-02",
  due_date: "2026-11-07",
  priority: "MEDIUM",
  note: null,
  sort_order: id,
  validation_status: "VALID",
  validation_errors: [],
  items: [],
  source_passages: [],
});

const assistantA = employee(11, "Huyền My");
const assistantB = employee(22, "Lê Hùng");

const plan = (changes = {}) => ({
  id: 501,
  week_start: "2026-11-02",
  week_end: "2026-11-07",
  status: "ASSISTANT_REVIEW",
  creator: { id: 1, name: "KPS Staging HR (Fake)" },
  entries: [entry(1, assistantA), entry(2, assistantB)],
  employee_reviews: [],
  comments: [],
  events: [],
  actions: actions(),
  review_workflow: {
    stage: "ASSISTANT",
    current_round: {
      id: 90,
      stage: "ASSISTANT",
      round_number: 2,
      status: "OPEN",
      started_by: null,
      started_at: "2026-11-01T07:00:00Z",
      completed_by: null,
      completed_at: null,
      decisions: [
        decision(1, assistantA, "ACCEPTED"),
        decision(2, assistantB, "PENDING"),
      ],
    },
    assistant_summary: {
      total: 2,
      accepted: 1,
      changes_requested: 0,
      pending: 1,
    },
    owner_summary: {
      total: 0,
      accepted: 0,
      changes_requested: 0,
      pending: 0,
    },
  },
  ...changes,
});

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

test("Assistant and OWNER workspaces derive their mode from staged workflow data", () => {
  const assistantMarkup = renderWorkspace(plan());
  assert.match(assistantMarkup, /Vòng Trợ lý Giám đốc/);
  assert.match(assistantMarkup, /Cần xét duyệt/);

  const ownerPlan = plan({
    status: "OWNER_REVIEW",
    actions: actions({ can_assistant_review: false, can_owner_review: true }),
    review_workflow: {
      ...plan().review_workflow,
      stage: "OWNER",
      current_round: {
        ...plan().review_workflow.current_round,
        stage: "OWNER",
      },
      owner_summary: {
        total: 2,
        accepted: 1,
        changes_requested: 0,
        pending: 1,
      },
    },
  });
  const ownerMarkup = renderWorkspace(ownerPlan);
  assert.match(ownerMarkup, /Vòng OWNER/);
  assert.match(ownerMarkup, /Cần xét duyệt/);
});

test("summary and employee groups use only the current staged round", () => {
  const value = plan({
    employee_reviews: [
      { id: 8, assignee: assistantB, status: "CHANGES_REQUESTED" },
    ],
  });
  assert.deepEqual(activeWeeklyPlanReviewSummary(value), {
    total: 2,
    accepted: 1,
    changes_requested: 0,
    pending: 1,
  });
  assert.deepEqual(
    createWeeklyPlanReviewEmployeeGroups(value).map((group) => ({
      name: group.employeeName,
      status: group.decision.status,
      entries: group.entries.length,
    })),
    [
      { name: "Huyền My", status: "ACCEPTED", entries: 1 },
      { name: "Lê Hùng", status: "PENDING", entries: 1 },
    ],
  );
});

test("employee status badges render accepted pending and changes requested labels", () => {
  const value = plan();
  value.review_workflow.current_round.decisions = [
    decision(1, assistantA, "ACCEPTED"),
    decision(2, assistantB, "CHANGES_REQUESTED"),
    decision(3, employee(33, "Phương Anh"), "PENDING"),
  ];
  value.review_workflow.assistant_summary = {
    total: 3,
    accepted: 1,
    changes_requested: 1,
    pending: 1,
  };
  const markup = renderWorkspace(value);
  assert.match(markup, /Đã chấp nhận/);
  assert.match(markup, /Cần chỉnh sửa/);
  assert.match(markup, /Chờ xét duyệt/);
});

test("employee accordion supports one open group, active close, and one-employee auto expand", () => {
  assert.equal(
    initialExpandedEmployeeKey(["employee:11", "employee:22"]),
    null,
  );
  assert.equal(initialExpandedEmployeeKey(["employee:11"]), "employee:11");
  assert.equal(toggleExpandedEmployeeKey(null, "employee:11"), "employee:11");
  assert.equal(
    toggleExpandedEmployeeKey("employee:11", "employee:22"),
    "employee:22",
  );
  assert.equal(toggleExpandedEmployeeKey("employee:22", "employee:22"), null);
});

test("zero-work pending employee stays visible with decision guidance", () => {
  const value = plan({
    entries: [],
    review_workflow: {
      ...plan().review_workflow,
      current_round: {
        ...plan().review_workflow.current_round,
        decisions: [decision(2, assistantB, "PENDING")],
      },
      assistant_summary: {
        total: 1,
        accepted: 0,
        changes_requested: 0,
        pending: 1,
      },
    },
  });
  const markup = renderWorkspace(value);
  assert.match(markup, /Lê Hùng/);
  assert.match(markup, /Không còn công việc sau chỉnh sửa/);
  assert.match(markup, /Bạn vẫn cần xác nhận thay đổi này/);
  assert.match(markup, /Yêu cầu chỉnh sửa/);
  assert.match(markup, /Chấp nhận/);
});

test("single employee work details render localized compact data", () => {
  const value = plan({
    entries: [entry(2, assistantB)],
    review_workflow: {
      ...plan().review_workflow,
      current_round: {
        ...plan().review_workflow.current_round,
        decisions: [decision(2, assistantB, "PENDING")],
      },
      assistant_summary: {
        total: 1,
        accepted: 0,
        changes_requested: 0,
        pending: 1,
      },
    },
  });
  const markup = renderWorkspace(value);
  assert.match(markup, /Work 2/);
  assert.match(markup, /Trung bình/);
  assert.doesNotMatch(markup, /weeklyPlanning\.detail\.priorities/);
});

test("zero-work staged employee remains a safe discussion and history target", () => {
  const value = plan({
    entries: [],
    review_workflow: {
      ...plan().review_workflow,
      current_round: {
        ...plan().review_workflow.current_round,
        decisions: [decision(2, assistantB, "PENDING")],
      },
    },
  });
  assert.deepEqual(createWeeklyPlanDiscussionEmployeeOptions(value), [
    { value: assistantB.id, label: assistantB.name },
  ]);
  assert.equal(
    resolveWeeklyPlanCommentContext(
      value,
      { entry_id: null, assignee_id: assistantB.id },
      {
        wholePlan: "Toàn bộ kế hoạch",
        allEmployeeWorks: "Toàn bộ công việc",
        unavailableEmployee: "Nhân viên không còn khả dụng",
        unavailableWork: "Công việc không còn khả dụng",
      },
    ).label,
    "Lê Hùng · Toàn bộ công việc",
  );
});

test("discussion copy makes comment semantics visibly state-neutral", () => {
  const markup = renderWorkspace(plan());
  assert.match(
    markup,
    /Ghi chú chỉ dùng để trao đổi và không làm thay đổi trạng thái xét duyệt/,
  );
  assert.match(markup, /Thêm ghi chú/);
});

test("comment handler never calls the formal review-decision endpoint", async () => {
  const workspace = await readSource(
    "src/components/weekly-plans/WeeklyPlanReviewWorkspace.tsx",
  );
  const commentHandler = workspace.match(
    /const handleAddComment = async[\s\S]*?const openFinalModal/,
  )?.[0];
  assert.ok(commentHandler);
  assert.match(commentHandler, /addWeeklyPlanComment/);
  assert.doesNotMatch(commentHandler, /decideWeeklyPlanReview/);
});

test("staged decision and Assistant finalize services use dedicated endpoints", async () => {
  const service = await readSource("src/services/weeklyPlanningService.ts");
  assert.match(service, /review-decisions\/\$\{assigneeId\}/);
  assert.match(service, /body: JSON\.stringify\(\{ decision \}\)/);
  assert.match(service, /planAction\(id, "finalize-assistant-review"\)/);
});

test("request changes opens confirmation before any formal mutation", async () => {
  const workspace = await readSource(
    "src/components/weekly-plans/WeeklyPlanReviewWorkspace.tsx",
  );
  assert.match(
    workspace,
    /onRequestChanges=\{\(\) =>[\s\S]*?openDecisionModal\([\s\S]*?"CHANGES_REQUESTED"/,
  );
  assert.match(
    workspace,
    /const confirmDecision = async[\s\S]*?decideWeeklyPlanReview/,
  );
});

test("optional change note is posted first and a note failure prevents decision", async () => {
  const calls = [];
  const result = await submitWeeklyPlanChangeRequest({
    note: "  Please revise  ",
    postComment: async (content) => {
      calls.push(["comment", content]);
      return "commented";
    },
    postDecision: async () => {
      calls.push(["decision"]);
      return "decided";
    },
  });
  assert.equal(result, "decided");
  assert.deepEqual(calls, [["comment", "Please revise"], ["decision"]]);

  const failedCalls = [];
  await assert.rejects(
    submitWeeklyPlanChangeRequest({
      note: "Keep my text",
      postComment: async () => {
        failedCalls.push("comment");
        throw new Error("comment failed");
      },
      postDecision: async () => {
        failedCalls.push("decision");
        return "decided";
      },
    }),
    /comment failed/,
  );
  assert.deepEqual(failedCalls, ["comment"]);
});

test("empty change note proceeds directly to the formal decision", async () => {
  const calls = [];
  await submitWeeklyPlanChangeRequest({
    note: "   ",
    postComment: async () => calls.push("comment"),
    postDecision: async () => calls.push("decision"),
  });
  assert.deepEqual(calls, ["decision"]);
});

test("final Assistant OWNER and publish CTAs follow opaque backend actions", () => {
  assert.match(
    renderWorkspace(
      plan({ actions: actions({ can_finalize_assistant_review: true }) }),
    ),
    /Chuyển OWNER xét duyệt/,
  );
  assert.match(
    renderWorkspace(
      plan({
        status: "OWNER_REVIEW",
        actions: actions({
          can_assistant_review: false,
          can_owner_review: true,
          can_approve: true,
        }),
      }),
    ),
    /Phê duyệt kế hoạch/,
  );
  assert.match(
    renderWorkspace(
      plan({
        status: "APPROVED",
        actions: actions({ can_assistant_review: false, can_publish: true }),
      }),
    ),
    /Phát hành công việc/,
  );
});

test("changes-requested workspace is read-only while waiting for HR", () => {
  const value = plan({
    status: "ASSISTANT_CHANGES_REQUESTED",
    review_workflow: {
      ...plan().review_workflow,
      current_round: {
        ...plan().review_workflow.current_round,
        status: "CHANGES_REQUESTED",
        decisions: [decision(2, assistantB, "CHANGES_REQUESTED")],
      },
      assistant_summary: {
        total: 1,
        accepted: 0,
        changes_requested: 1,
        pending: 0,
      },
    },
  });
  const [changeDecision] = value.review_workflow.current_round.decisions;
  assert.equal(canAcceptWeeklyPlanDecision(value, changeDecision), false);
  assert.equal(
    canRequestChangesForWeeklyPlanDecision(value, changeDecision),
    false,
  );
  const markup = renderWorkspace(value);
  assert.match(markup, /Đang chờ Nhân sự chỉnh sửa và gửi lại/);
  assert.doesNotMatch(markup, /Quyết định xét duyệt chính thức/);
});

test("backend action allows an equal-timestamp current acceptance to reopen", () => {
  const value = plan({
    entries: [entry(1, assistantA)],
    actions: actions({ can_finalize_assistant_review: false }),
    review_workflow: {
      ...plan().review_workflow,
      current_round: {
        ...plan().review_workflow.current_round,
        started_at: "2026-11-01T08:00:00Z",
        decisions: [decision(1, assistantA, "ACCEPTED")],
      },
      assistant_summary: {
        total: 1,
        accepted: 1,
        changes_requested: 0,
        pending: 0,
      },
    },
  });
  const [accepted] = value.review_workflow.current_round.decisions;
  assert.equal(canAcceptWeeklyPlanDecision(value, accepted), false);
  assert.equal(canRequestChangesForWeeklyPlanDecision(value, accepted), true);

  const markup = renderWorkspace(value);
  assert.match(markup, />Yêu cầu chỉnh sửa<\/button>/);
  assert.doesNotMatch(markup, />Chấp nhận<\/button>/);
  assert.match(markup, /Đã chấp nhận trong vòng xét duyệt này/);
});

test("backend action locks an equal-timestamp carried acceptance", () => {
  const value = plan();
  value.review_workflow.current_round.round_number = 3;
  value.review_workflow.current_round.started_at = "2026-11-01T08:00:00Z";
  value.review_workflow.current_round.decisions[0].actions.can_request_changes = false;
  const [accepted, pending] = createWeeklyPlanReviewEmployeeGroups(value);
  assert.equal(accepted.decision.status, "ACCEPTED");
  assert.equal(pending.decision.status, "PENDING");
  assert.equal(canAcceptWeeklyPlanDecision(value, accepted.decision), false);
  assert.equal(
    canRequestChangesForWeeklyPlanDecision(value, accepted.decision),
    false,
  );
  assert.equal(canAcceptWeeklyPlanDecision(value, pending.decision), true);
  assert.equal(
    canRequestChangesForWeeklyPlanDecision(value, pending.decision),
    true,
  );

  value.entries = [value.entries[0]];
  value.review_workflow.current_round.decisions = [accepted.decision];
  const markup = renderWorkspace(value);
  assert.match(markup, /Đã chấp nhận/);
  assert.doesNotMatch(markup, /Quyết định xét duyệt chính thức/);
  assert.doesNotMatch(markup, />Yêu cầu chỉnh sửa<\/button>/);
  assert.doesNotMatch(markup, />Chấp nhận<\/button>/);
});

test("viewer and round authority still gate backend decision actions", () => {
  const value = plan();
  const round = value.review_workflow.current_round;
  const accepted = round.decisions[0];

  accepted.reviewed_at = round.started_at;
  accepted.actions.can_request_changes = true;
  assert.equal(canRequestChangesForWeeklyPlanDecision(value, accepted), true);

  value.actions.can_assistant_review = false;
  assert.equal(canRequestChangesForWeeklyPlanDecision(value, accepted), false);

  value.actions.can_assistant_review = true;
  round.status = "COMPLETED";
  assert.equal(canRequestChangesForWeeklyPlanDecision(value, accepted), false);

  round.status = "OPEN";
  accepted.actions.can_request_changes = false;
  assert.equal(canRequestChangesForWeeklyPlanDecision(value, accepted), false);
});

test("OWNER current-round acceptance can reopen when approval readiness still blocks", () => {
  const value = plan({
    status: "OWNER_REVIEW",
    entries: [entry(1, assistantA)],
    actions: actions({
      can_assistant_review: false,
      can_owner_review: true,
      can_approve: false,
    }),
    review_workflow: {
      ...plan().review_workflow,
      stage: "OWNER",
      current_round: {
        ...plan().review_workflow.current_round,
        stage: "OWNER",
        started_at: "2026-11-01T08:00:00Z",
        decisions: [decision(1, assistantA, "ACCEPTED")],
      },
      owner_summary: {
        total: 1,
        accepted: 1,
        changes_requested: 0,
        pending: 0,
      },
    },
  });
  const [accepted] = value.review_workflow.current_round.decisions;
  assert.equal(canAcceptWeeklyPlanDecision(value, accepted), false);
  assert.equal(canRequestChangesForWeeklyPlanDecision(value, accepted), true);
  const markup = renderWorkspace(value);
  assert.match(markup, />Yêu cầu chỉnh sửa<\/button>/);
  assert.doesNotMatch(markup, />Chấp nhận<\/button>/);
});

test("all staged plan status badges resolve Vietnamese text without raw keys", () => {
  for (const status of [
    "ASSISTANT_REVIEW",
    "ASSISTANT_CHANGES_REQUESTED",
    "OWNER_REVIEW",
    "OWNER_CHANGES_REQUESTED",
  ]) {
    const markup = renderToStaticMarkup(
      React.createElement(StatusBadge, { status }),
    );
    assert.doesNotMatch(markup, /weeklyPlanning\.status/);
  }

  assert.match(
    renderToStaticMarkup(
      React.createElement(StatusBadge, { status: "OWNER_REVIEW" }),
    ),
    /Giám đốc đang xét duyệt/,
  );

  assert.match(
    renderToStaticMarkup(
      React.createElement(StatusBadge, {
        status: "OWNER_REVIEW",
        actions: {
          can_assistant_review: false,
          can_owner_review: true,
        },
      }),
    ),
    /Cần xét duyệt/,
  );
  assert.match(
    renderToStaticMarkup(
      React.createElement(StatusBadge, {
        status: "ASSISTANT_REVIEW",
        actions: {
          can_assistant_review: true,
          can_owner_review: false,
        },
      }),
    ),
    /Cần xét duyệt/,
  );
});

test("list and review workspace headers share actor-relative status badges", async () => {
  const [list, wizardHeader, reviewWorkspace, badge] = await Promise.all([
    readSource("src/pages/WeeklyPlans/WeeklyPlanList.tsx"),
    readSource("src/components/weekly-plans/WeeklyPlanWizardHeader.tsx"),
    readSource("src/components/weekly-plans/WeeklyPlanReviewWorkspace.tsx"),
    readSource("src/components/weekly-plans/WeeklyPlanStatusBadge.tsx"),
  ]);
  assert.match(list, /<WeeklyPlanStatusBadge[\s\S]*?status=\{plan\.status\}/);
  assert.match(wizardHeader, /<WeeklyPlanStatusBadge status=\{plan\.status\}/);
  assert.match(reviewWorkspace, /actions=\{plan\.actions\}/);
  assert.match(list, /actions=\{plan\.actions\}/);
  assert.match(badge, /weeklyPlanning\.status\.\$\{status\}/);
  assert.match(badge, /actorRelativeWeeklyPlanStatusKey\(status, actions\)/);
  assert.doesNotMatch(badge, /keyPrefix:\s*"weeklyPlanning\.status"/);
});

test("HR Step 4 wording follows staged workflow and resubmit authority", async () => {
  assert.equal(
    weeklyPlanSubmitStatusKey("ASSISTANT_REVIEW"),
    "assistantReview",
  );
  assert.equal(weeklyPlanSubmitStatusKey("OWNER_REVIEW"), "ownerReview");
  const submit = await readSource(
    "src/pages/WeeklyPlans/WeeklyPlanWizardSubmit.tsx",
  );
  assert.match(submit, /plan\.actions\.can_resubmit_changes/);
  assert.match(submit, /submit\.resubmitAction/);
  assert.match(submit, /submit\.correction\.scope/);
  assert.doesNotMatch(submit, /submit\.submitted/);
});

test("known staged audit events and HR statuses always have human labels", async () => {
  const resource = JSON.parse(await readSource("src/locales/vi/common.json"));
  for (const event of [
    "ASSISTANT_REVIEW_STARTED",
    "ASSISTANT_EMPLOYEE_ACCEPTED",
    "ASSISTANT_EMPLOYEE_CHANGES_REQUESTED",
    "ASSISTANT_REVIEW_RESUBMITTED",
    "ASSISTANT_REVIEW_COMPLETED",
    "OWNER_REVIEW_STARTED",
    "OWNER_EMPLOYEE_ACCEPTED",
    "OWNER_EMPLOYEE_CHANGES_REQUESTED",
    "OWNER_REVIEW_RESUBMITTED",
    "OWNER_REVIEW_COMPLETED",
    "APPROVED",
    "PUBLISHED",
  ]) {
    assert.ok(resource.weeklyPlanning.events[event]);
    assert.notEqual(resource.weeklyPlanning.events[event], event);
  }

  assert.equal(
    resource.weeklyPlanning.wizard.submit.status.ownerReview,
    "Đã chuyển OWNER xét duyệt",
  );
  assert.equal(
    resource.weeklyPlanning.wizard.submit.status.assistantReview,
    "Đã gửi Trợ lý Giám đốc xét duyệt",
  );
});

test("workspace authority is action-based and approval stays separate from publish", async () => {
  const [workspace, reviewModel] = await Promise.all([
    readSource("src/components/weekly-plans/WeeklyPlanReviewWorkspace.tsx"),
    readSource("src/utils/weeklyPlanReviewWorkspace.ts"),
  ]);
  assert.match(reviewModel, /actions\.can_assistant_review/);
  assert.match(reviewModel, /actions\.can_owner_review/);
  assert.match(workspace, /actions\.can_approve/);
  assert.match(workspace, /actions\.can_publish/);
  assert.match(workspace, /approveWeeklyPlan/);
  assert.match(workspace, /publishWeeklyPlan/);
  assert.doesNotMatch(
    workspace + reviewModel,
    /role\s*===|HR_MANAGER|EXECUTIVE_ASSISTANT|approveAndPublishWeeklyPlan/,
  );
});
