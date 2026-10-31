import type {
  WeeklyPlan,
  WeeklyPlanEmployeeWorkflow,
  WeeklyPlanEntry,
  WeeklyPlanPublicationSummary,
  WeeklyPlanV3AssistantBatchDecisionInput,
  WeeklyPlanV3OwnerBatchDecisionInput,
} from "@/types/weeklyPlanning";
import type { WeeklyPlanV3PendingBatchConfirmation } from "@/utils/weeklyPlanV3BatchConfirmation";

export type WeeklyPlanV3ReviewerView = "OVERVIEW" | "ASSISTANT" | "OWNER";

export type WeeklyPlanV3OwnerPendingConfirmation =
  WeeklyPlanV3PendingBatchConfirmation<WeeklyPlanV3OwnerBatchDecisionInput> & {
    taskCountPreview: number;
  };

export type WeeklyPlanV3ReviewerWorkspaceState = {
  view: WeeklyPlanV3ReviewerView;
  assistantPending: WeeklyPlanV3PendingBatchConfirmation<WeeklyPlanV3AssistantBatchDecisionInput> | null;
  ownerPending: WeeklyPlanV3OwnerPendingConfirmation | null;
};

export type WeeklyPlanV3ReviewerWorkspaceAction =
  | { type: "SET_VIEW"; view: WeeklyPlanV3ReviewerView }
  | {
      type: "SET_ASSISTANT_PENDING";
      pending: WeeklyPlanV3ReviewerWorkspaceState["assistantPending"];
    }
  | {
      type: "SET_OWNER_PENDING";
      pending: WeeklyPlanV3ReviewerWorkspaceState["ownerPending"];
    };

export const createWeeklyPlanV3ReviewerWorkspaceState = (
  view: WeeklyPlanV3ReviewerView,
): WeeklyPlanV3ReviewerWorkspaceState => ({
  view,
  assistantPending: null,
  ownerPending: null,
});

export const weeklyPlanV3ReviewerWorkspaceReducer = (
  state: WeeklyPlanV3ReviewerWorkspaceState,
  action: WeeklyPlanV3ReviewerWorkspaceAction,
): WeeklyPlanV3ReviewerWorkspaceState => {
  switch (action.type) {
    case "SET_VIEW":
      return { ...state, view: action.view };
    case "SET_ASSISTANT_PENDING":
      return { ...state, assistantPending: action.pending };
    case "SET_OWNER_PENDING":
      return { ...state, ownerPending: action.pending };
  }
};

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
