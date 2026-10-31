import type {
  WeeklyPlan,
  WeeklyPlanEmployeeWorkflow,
  WeeklyPlanEntry,
  WeeklyPlanPublicationSummary,
} from "@/types/weeklyPlanning";

export type WeeklyPlanV3EmployeeFilter =
  | "MY_REVIEW"
  | "OTHER_REVIEW"
  | "HR_CORRECTION"
  | "PUBLISHED"
  | "NO_WORK_APPROVED"
  | "ALL";

export const weeklyPlanV3EntriesByAssignee = (
  entries: WeeklyPlanEntry[],
): Map<number, WeeklyPlanEntry[]> => {
  const groups = new Map<number, WeeklyPlanEntry[]>();
  entries.forEach((entry) => {
    if (!entry.assignee) return;
    const current = groups.get(entry.assignee.id) ?? [];
    current.push(entry);
    groups.set(entry.assignee.id, current);
  });

  return groups;
};

export const weeklyPlanV3WorkflowNeedsMyReview = (
  workflow: WeeklyPlanEmployeeWorkflow,
): boolean =>
  (workflow.viewer_context.mode === "ASSISTANT_REVIEW" &&
    (workflow.actions.can_accept || workflow.actions.can_request_changes)) ||
  (workflow.viewer_context.mode === "OWNER_REVIEW" &&
    (workflow.actions.can_publish ||
      workflow.actions.can_request_changes ||
      workflow.actions.can_approve_no_work));

export const filterWeeklyPlanV3Workflows = (
  workflows: WeeklyPlanEmployeeWorkflow[],
  filter: WeeklyPlanV3EmployeeFilter,
): WeeklyPlanEmployeeWorkflow[] =>
  workflows.filter((workflow) => {
    switch (filter) {
      case "MY_REVIEW":
        return weeklyPlanV3WorkflowNeedsMyReview(workflow);
      case "OTHER_REVIEW":
        return (
          ["AWAITING_ASSISTANT", "STAGED_FOR_OWNER"].includes(workflow.state) &&
          !weeklyPlanV3WorkflowNeedsMyReview(workflow)
        );
      case "HR_CORRECTION":
        return workflow.state === "HR_CORRECTION";
      case "PUBLISHED":
        return workflow.state === "PUBLISHED";
      case "NO_WORK_APPROVED":
        return workflow.state === "NO_WORK_APPROVED";
      case "ALL":
        return true;
    }
  });

export const weeklyPlanV3TerminalProgress = (
  summary: WeeklyPlanPublicationSummary | undefined,
): { terminal: number; total: number; percentage: number } => {
  const total = summary?.total ?? 0;
  const terminal = (summary?.published ?? 0) + (summary?.no_work_approved ?? 0);

  return {
    terminal,
    total,
    percentage: total === 0 ? 0 : Math.round((terminal / total) * 100),
  };
};

export const weeklyPlanV3HasHrCorrectionAccess = (
  plan: Pick<WeeklyPlan, "workflow_version" | "employee_workflows">,
): boolean =>
  plan.workflow_version === 3 &&
  (plan.employee_workflows ?? []).some(
    (workflow) =>
      workflow.state === "HR_CORRECTION" &&
      (workflow.actions.can_correct || workflow.actions.can_resubmit),
  );
