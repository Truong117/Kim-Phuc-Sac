import type {
  WeeklyPlan,
  WeeklyPlanEntry,
  WeeklyPlanReviewDecision,
  WeeklyPlanReviewStage,
  WeeklyPlanReviewStatus,
  WeeklyPlanStatus,
} from "@/types/weeklyPlanning";

export type WeeklyPlanReviewEmployeeGroup = {
  key: string;
  assigneeId: number;
  employeeName: string;
  departments: string[];
  decision: WeeklyPlanReviewDecision;
  entries: WeeklyPlanEntry[];
  itemCount: number;
};

export const activeWeeklyPlanReviewStage = (
  plan: Pick<WeeklyPlan, "review_workflow">,
): WeeklyPlanReviewStage | null => plan.review_workflow?.stage ?? null;

export const activeWeeklyPlanReviewSummary = (
  plan: Pick<WeeklyPlan, "review_workflow">,
) => {
  const workflow = plan.review_workflow;
  if (!workflow) {
    return { total: 0, accepted: 0, changes_requested: 0, pending: 0 };
  }

  return workflow.stage === "ASSISTANT"
    ? workflow.assistant_summary
    : workflow.stage === "OWNER"
      ? workflow.owner_summary
      : { total: 0, accepted: 0, changes_requested: 0, pending: 0 };
};

export const createWeeklyPlanReviewEmployeeGroups = (
  plan: Pick<WeeklyPlan, "entries" | "review_workflow">,
): WeeklyPlanReviewEmployeeGroup[] => {
  const decisions = plan.review_workflow?.current_round?.decisions ?? [];

  return decisions.map((decision) => {
    const entries = plan.entries.filter(
      (entry) => entry.assignee?.id === decision.assignee.id,
    );
    const departments = entries.reduce<string[]>((result, entry) => {
      if (entry.department_name && !result.includes(entry.department_name)) {
        result.push(entry.department_name);
      }
      return result;
    }, []);

    return {
      key: `employee:${decision.assignee.id}`,
      assigneeId: decision.assignee.id,
      employeeName: decision.assignee.name,
      departments,
      decision,
      entries,
      itemCount: entries.reduce(
        (total, entry) => total + entry.items.length,
        0,
      ),
    };
  });
};

export const canDecideWeeklyPlanReview = (
  plan: Pick<WeeklyPlan, "status" | "actions" | "review_workflow">,
  decisionStatus: WeeklyPlanReviewStatus,
): boolean => {
  if (decisionStatus !== "PENDING") return false;

  const stage = activeWeeklyPlanReviewStage(plan);
  if (stage === "ASSISTANT") {
    return (
      plan.status === "ASSISTANT_REVIEW" && plan.actions.can_assistant_review
    );
  }
  if (stage === "OWNER") {
    return plan.status === "OWNER_REVIEW" && plan.actions.can_owner_review;
  }

  return false;
};

export const isWeeklyPlanWaitingForHr = (status: WeeklyPlanStatus): boolean =>
  status === "ASSISTANT_CHANGES_REQUESTED" ||
  status === "OWNER_CHANGES_REQUESTED";

export const weeklyPlanSubmitStatusKey = (status: WeeklyPlanStatus): string => {
  switch (status) {
    case "ASSISTANT_REVIEW":
      return "assistantReview";
    case "ASSISTANT_CHANGES_REQUESTED":
      return "assistantChangesRequested";
    case "OWNER_REVIEW":
      return "ownerReview";
    case "OWNER_CHANGES_REQUESTED":
      return "ownerChangesRequested";
    case "APPROVED":
      return "approved";
    case "PUBLISHED":
      return "published";
    default:
      return "submittedLegacy";
  }
};

type ChangeRequestSequence<T> = {
  note: string;
  postComment: (content: string) => Promise<T>;
  postDecision: () => Promise<T>;
};

export const submitWeeklyPlanChangeRequest = async <T>({
  note,
  postComment,
  postDecision,
}: ChangeRequestSequence<T>): Promise<T> => {
  const content = note.trim();
  if (content) {
    await postComment(content);
  }

  return postDecision();
};
