import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

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
  assert.match(workspace, /const canEdit = workflow\.actions\.can_correct/);
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
    /if \(submitRef\.current \|\| selected\.length === 0\) return/,
  );
  assert.match(workspace, /submitRef\.current = true/);
  assert.match(workspace, /idempotencyKeyRef\.current \?\?= requestId\(\)/);
  assert.match(
    workspace,
    /expected_state_version: workflow\.state_version[\s\S]*?expected_content_version: workflow\.content_version/,
  );
  assert.match(
    workspace,
    /\.then\(\(result\) => result\.plan\)[\s\S]*?if \(ok\) \{[\s\S]*?setSelected\(\[\]\)/,
  );
  assert.doesNotMatch(
    workspace,
    /catch[\s\S]*?setEditor\(null\)|finally[\s\S]*?setEditor\(null\)/,
  );
});
