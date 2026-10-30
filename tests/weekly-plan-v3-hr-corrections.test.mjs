import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import {
  clearConfirmedWeeklyPlanV3CorrectionSelection,
  resolveWeeklyPlanV3CorrectionConfirmation,
  weeklyPlanV3CorrectionFailureMode,
} from "../src/utils/weeklyPlanV3CorrectionConfirmation.ts";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

test("V3 HR corrections render directly on Step 4 without changing V2 submit", async () => {
  const submit = await readSource(
    "src/pages/WeeklyPlans/WeeklyPlanWizardSubmit.tsx",
  );

  assert.match(submit, /plan\.workflow_version === 3/);
  assert.match(
    submit,
    /workflow\.state === "HR_CORRECTION"[\s\S]*?<WeeklyPlanV3HrCorrections/,
  );
  assert.match(
    submit,
    /<WeeklyPlanV3HrCorrections[\s\S]*?<WeeklyPlanStep4DiscussionHistory/,
  );
  assert.match(submit, /const canResubmit = waitingForHr/);
  assert.match(submit, /submitWeeklyPlan\(plan\.id\)/);
});

test("correction workspace exposes only backend-authorized HR employee mutations", async () => {
  const workspace = await readSource(
    "src/components/weekly-plans/WeeklyPlanV3HrCorrections.tsx",
  );

  assert.match(
    workspace,
    /filter\([\s\S]*?workflow\.state === "HR_CORRECTION"/,
  );
  assert.match(
    workspace,
    /const canEdit =[\s\S]*?workflow\.actions\.can_correct/,
  );
  assert.match(workspace, /const canSelect = workflow\.actions\.can_resubmit/);
  assert.match(workspace, /lockAssignee/);
  assert.match(workspace, /lockedAssignee=\{employee\}/);
  assert.match(workspace, /<WeeklyPlanEntryForm/);
  assert.match(workspace, /<WeeklyPlanEntries/);
  assert.match(workspace, /correction_origin_stage/);
  assert.doesNotMatch(workspace, /role\s*===|HR_MANAGER|OWNER/);
});

test("entry mutations send the exact employee content version to isolated V3 endpoints", async () => {
  const service = await readSource("src/services/weeklyPlanningService.ts");

  assert.match(
    service,
    /v3-corrections\/\$\{assigneeId\}\/entries`[\s\S]*?expected_content_version: expectedContentVersion/,
  );
  assert.match(
    service,
    /v3-corrections\/\$\{assigneeId\}\/entries\/\$\{entryId\}`/,
  );
  assert.match(service, /method: "DELETE"[\s\S]*?expected_content_version/);
  assert.match(service, /\/correction-batches`/);
  assert.match(service, /"Idempotency-Key": idempotencyKey/);
});

test("batch confirmation is selection-scoped single-flight and preserves drafts on errors", async () => {
  const workspace = await readSource(
    "src/components/weekly-plans/WeeklyPlanV3HrCorrections.tsx",
  );

  assert.match(
    workspace,
    /submitRef\.current \|\|[\s\S]*?selectedEligibleIds\.length === 0[\s\S]*?pendingConfirmation === null/,
  );
  assert.match(workspace, /submitRef\.current = true/);
  assert.match(
    workspace,
    /resolveWeeklyPlanV3CorrectionConfirmation\([\s\S]*?pendingConfirmation[\s\S]*?currentEmployees/,
  );
  assert.match(
    workspace,
    /expected_state_version: workflow\.state_version[\s\S]*?expected_content_version: workflow\.content_version/,
  );
  assert.match(
    workspace,
    /clearConfirmedWeeklyPlanV3CorrectionSelection\([\s\S]*?batchResult!\.results/,
  );
  assert.match(
    workspace,
    /\.then\(\(ok\) => \{[\s\S]*?if \(ok\) setEditor\(null\)/,
  );
});

const employee = (assigneeId, stateVersion, contentVersion) => ({
  assignee_id: assigneeId,
  expected_state_version: stateVersion,
  expected_content_version: contentVersion,
});

test("ambiguous network failure keeps one immutable request for an exact retry", () => {
  let sequence = 0;
  const createRequestId = () => `request-${++sequence}`;
  const payload = [employee(22, 4, 8), employee(11, 2, 6)];
  const first = resolveWeeklyPlanV3CorrectionConfirmation(
    null,
    800,
    payload,
    createRequestId,
  );
  const retry = resolveWeeklyPlanV3CorrectionConfirmation(
    first.confirmation,
    800,
    [...payload].reverse(),
    createRequestId,
  );

  assert.equal(first.confirmation.requestId, "request-1");
  assert.equal(weeklyPlanV3CorrectionFailureMode({ status: 0 }), "AMBIGUOUS");
  assert.equal(weeklyPlanV3CorrectionFailureMode({ status: 503 }), "AMBIGUOUS");
  assert.equal(retry.confirmation, first.confirmation);
  assert.equal(retry.reconciliationRequired, false);
  assert.equal(sequence, 1);
});

test("definitive stale and validation failures preserve drafts but release the request key", () => {
  assert.equal(weeklyPlanV3CorrectionFailureMode({ status: 409 }), "STALE");
  assert.equal(
    weeklyPlanV3CorrectionFailureMode({ status: 422 }),
    "VALIDATION",
  );
});

test("changed selection reconciles the failed payload before identifying a new batch", () => {
  let sequence = 0;
  const createRequestId = () => `request-${++sequence}`;
  const failed = resolveWeeklyPlanV3CorrectionConfirmation(
    null,
    800,
    [employee(11, 2, 6)],
    createRequestId,
  ).confirmation;
  const reconcile = resolveWeeklyPlanV3CorrectionConfirmation(
    failed,
    800,
    [employee(22, 4, 8)],
    createRequestId,
  );

  assert.equal(reconcile.confirmation, failed);
  assert.equal(reconcile.reconciliationRequired, true);
  assert.deepEqual(reconcile.confirmation.employees, [employee(11, 2, 6)]);
  assert.equal(sequence, 1);

  const next = resolveWeeklyPlanV3CorrectionConfirmation(
    null,
    800,
    [employee(22, 4, 8)],
    createRequestId,
  );
  assert.equal(next.confirmation.requestId, "request-2");
});

test("changed expected versions cannot reuse an unresolved request identity", () => {
  const failed = resolveWeeklyPlanV3CorrectionConfirmation(
    null,
    800,
    [employee(11, 2, 6)],
    () => "version-request-1",
  ).confirmation;
  const reconcile = resolveWeeklyPlanV3CorrectionConfirmation(
    failed,
    800,
    [employee(11, 3, 7)],
    () => "must-not-be-used",
  );

  assert.equal(reconcile.confirmation.requestId, "version-request-1");
  assert.equal(reconcile.reconciliationRequired, true);
  assert.deepEqual(reconcile.confirmation.employees, [employee(11, 2, 6)]);
});

test("successful recovery clears only confirmed employees and preserves new selections", () => {
  assert.deepEqual(
    clearConfirmedWeeklyPlanV3CorrectionSelection(
      [11, 22, 33],
      [{ assignee_id: 11 }, { assignee_id: 33 }],
    ),
    [22],
  );
});

test("shared Step 4 discussions keep comment targeting and event history for V2 and V3", async () => {
  const submit = await readSource(
    "src/pages/WeeklyPlans/WeeklyPlanWizardSubmit.tsx",
  );
  const shared = await readSource(
    "src/components/weekly-plans/WeeklyPlanStep4DiscussionHistory.tsx",
  );

  assert.equal(submit.match(/<WeeklyPlanStep4DiscussionHistory/g)?.length, 2);
  assert.match(shared, /data-section="weekly-plan-discussion"/);
  assert.match(shared, /data-section="weekly-plan-history"/);
  assert.match(shared, /WeeklyPlanDiscussionTargetFields/);
  assert.match(shared, /plan\.actions\.can_comment/);
  assert.match(shared, /addWeeklyPlanComment/);
  assert.match(shared, /plan\.events\.map/);
});
