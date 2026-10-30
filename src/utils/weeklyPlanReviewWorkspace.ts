import type {
  WeeklyPlan,
  WeeklyPlanEntry,
  WeeklyPlanReviewDecision,
  WeeklyPlanReviewRound,
  WeeklyPlanReviewStage,
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

const ISO_TIMESTAMP_PATTERN =
  /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/;

const safeTimestamp = (value: string | null | undefined): number | null => {
  if (!value || !ISO_TIMESTAMP_PATTERN.test(value)) return null;
  const timestamp = Date.parse(value);

  return Number.isFinite(timestamp) ? timestamp : null;
};

export const isCarriedAcceptedDecision = (
  round: WeeklyPlanReviewRound,
  decision: WeeklyPlanReviewDecision,
): boolean => {
  if (decision.status !== "ACCEPTED") return false;
  const startedAt = safeTimestamp(round.started_at);
  const reviewedAt = safeTimestamp(decision.reviewed_at);

  return startedAt !== null && reviewedAt !== null && reviewedAt < startedAt;
};

const isCurrentRoundAcceptedDecision = (
  round: WeeklyPlanReviewRound,
  decision: WeeklyPlanReviewDecision,
): boolean => {
  if (decision.status !== "ACCEPTED") return false;
  const startedAt = safeTimestamp(round.started_at);
  const reviewedAt = safeTimestamp(decision.reviewed_at);

  return startedAt !== null && reviewedAt !== null && reviewedAt > startedAt;
};

const canActOnCurrentWeeklyPlanRound = (
  plan: Pick<WeeklyPlan, "status" | "actions" | "review_workflow">,
): boolean => {
  const stage = activeWeeklyPlanReviewStage(plan);
  const round = plan.review_workflow?.current_round;
  if (!round || round.status !== "OPEN" || round.stage !== stage) return false;

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

const isCurrentRoundDecision = (
  plan: Pick<WeeklyPlan, "review_workflow">,
  decision: WeeklyPlanReviewDecision,
): boolean =>
  plan.review_workflow?.current_round?.decisions.some(
    (currentDecision) => currentDecision.id === decision.id,
  ) ?? false;

export const canAcceptWeeklyPlanDecision = (
  plan: Pick<WeeklyPlan, "status" | "actions" | "review_workflow">,
  decision: WeeklyPlanReviewDecision,
): boolean =>
  decision.status === "PENDING" &&
  isCurrentRoundDecision(plan, decision) &&
  canActOnCurrentWeeklyPlanRound(plan);

export const canRequestChangesForWeeklyPlanDecision = (
  plan: Pick<WeeklyPlan, "status" | "actions" | "review_workflow">,
  decision: WeeklyPlanReviewDecision,
): boolean => {
  if (
    !isCurrentRoundDecision(plan, decision) ||
    !canActOnCurrentWeeklyPlanRound(plan)
  ) {
    return false;
  }

  if (decision.status === "PENDING") return true;
  const round = plan.review_workflow?.current_round;

  return round ? isCurrentRoundAcceptedDecision(round, decision) : false;
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
