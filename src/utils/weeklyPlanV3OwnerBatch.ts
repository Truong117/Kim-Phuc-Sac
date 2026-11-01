import type {
  WeeklyPlan,
  WeeklyPlanV3OwnerBatchDecisionInput,
  WeeklyPlanV3OwnerDecision,
} from "@/types/weeklyPlanning";

export type WeeklyPlanV3OwnerDraft = Record<number, WeeklyPlanV3OwnerDecision>;

export const setWeeklyPlanV3OwnerDraftDecision = (
  draft: WeeklyPlanV3OwnerDraft,
  assigneeId: number,
  decision: WeeklyPlanV3OwnerDecision | null,
): WeeklyPlanV3OwnerDraft => {
  const next = { ...draft };
  if (decision === null) delete next[assigneeId];
  else next[assigneeId] = decision;

  return next;
};

export const buildWeeklyPlanV3OwnerBatch = (
  plan: Pick<WeeklyPlan, "employee_workflows">,
  draft: WeeklyPlanV3OwnerDraft,
): WeeklyPlanV3OwnerBatchDecisionInput[] =>
  (plan.employee_workflows ?? [])
    .filter((workflow) => {
      const decision = draft[workflow.assignee.id];
      if (workflow.viewer_context.mode !== "OWNER_REVIEW") return false;

      return (
        (decision === "ACCEPTED" &&
          workflow.active_entry_count > 0 &&
          workflow.actions.can_publish) ||
        (decision === "CHANGES_REQUESTED" &&
          workflow.actions.can_request_changes) ||
        (decision === "APPROVE_NO_WORK" &&
          workflow.active_entry_count === 0 &&
          workflow.actions.can_approve_no_work)
      );
    })
    .map((workflow) => ({
      assignee_id: workflow.assignee.id,
      decision: draft[workflow.assignee.id],
      expected_state_version: workflow.state_version,
      expected_content_version: workflow.content_version,
    }))
    .sort((first, second) => first.assignee_id - second.assignee_id);

export const summarizeWeeklyPlanV3OwnerDraft = (
  decisions: WeeklyPlanV3OwnerBatchDecisionInput[],
  entryCountByAssignee: ReadonlyMap<number, number>,
) => ({
  total: decisions.length,
  accepted: decisions.filter((item) => item.decision === "ACCEPTED").length,
  changesRequested: decisions.filter(
    (item) => item.decision === "CHANGES_REQUESTED",
  ).length,
  noWorkApproved: decisions.filter(
    (item) => item.decision === "APPROVE_NO_WORK",
  ).length,
  taskCount: decisions
    .filter((item) => item.decision === "ACCEPTED")
    .reduce(
      (total, item) =>
        total + (entryCountByAssignee.get(item.assignee_id) ?? 0),
      0,
    ),
});

export const clearConfirmedWeeklyPlanV3OwnerDraft = (
  draft: WeeklyPlanV3OwnerDraft,
  results: Array<{ assignee_id: number }>,
): WeeklyPlanV3OwnerDraft => {
  const next = { ...draft };
  results.forEach((result) => delete next[result.assignee_id]);

  return next;
};
