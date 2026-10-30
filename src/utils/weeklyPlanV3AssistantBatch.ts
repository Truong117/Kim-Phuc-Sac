import type {
  WeeklyPlan,
  WeeklyPlanReviewStatus,
  WeeklyPlanV3AssistantBatchDecisionInput,
} from "@/types/weeklyPlanning";

export type WeeklyPlanV3AssistantDraftDecision = Exclude<
  WeeklyPlanReviewStatus,
  "PENDING"
>;

export type WeeklyPlanV3AssistantDraft = Record<
  number,
  WeeklyPlanV3AssistantDraftDecision
>;

export const setWeeklyPlanV3AssistantDraftDecision = (
  draft: WeeklyPlanV3AssistantDraft,
  assigneeId: number,
  decision: WeeklyPlanV3AssistantDraftDecision | null,
): WeeklyPlanV3AssistantDraft => {
  const next = { ...draft };
  if (decision === null) {
    delete next[assigneeId];
  } else {
    next[assigneeId] = decision;
  }

  return next;
};

export const buildWeeklyPlanV3AssistantBatch = (
  plan: Pick<WeeklyPlan, "employee_workflows">,
  draft: WeeklyPlanV3AssistantDraft,
): WeeklyPlanV3AssistantBatchDecisionInput[] =>
  (plan.employee_workflows ?? [])
    .filter((workflow) => {
      const decision = draft[workflow.assignee.id];
      return (
        (decision === "ACCEPTED" && workflow.actions.can_accept) ||
        (decision === "CHANGES_REQUESTED" &&
          workflow.actions.can_request_changes)
      );
    })
    .map((workflow) => ({
      assignee_id: workflow.assignee.id,
      decision: draft[workflow.assignee.id],
      expected_state_version: workflow.state_version,
      expected_content_version: workflow.content_version,
    }))
    .sort((first, second) => first.assignee_id - second.assignee_id);

export const summarizeWeeklyPlanV3AssistantDraft = (
  decisions: WeeklyPlanV3AssistantBatchDecisionInput[],
) => ({
  total: decisions.length,
  accepted: decisions.filter((item) => item.decision === "ACCEPTED").length,
  changesRequested: decisions.filter(
    (item) => item.decision === "CHANGES_REQUESTED",
  ).length,
});

export const clearConfirmedWeeklyPlanV3AssistantDraft = (
  draft: WeeklyPlanV3AssistantDraft,
  results: Array<{ assignee_id: number }>,
): WeeklyPlanV3AssistantDraft => {
  const next = { ...draft };
  results.forEach((result) => delete next[result.assignee_id]);

  return next;
};
