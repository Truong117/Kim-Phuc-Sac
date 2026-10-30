import type {
  WeeklyPlanActions,
  WeeklyPlanStatus,
} from "@/types/weeklyPlanning";

export type WeeklyPlanReviewerActions = Pick<
  WeeklyPlanActions,
  "can_assistant_review" | "can_owner_review"
>;

type ReviewerPlan = {
  id: number;
  status: WeeklyPlanStatus;
  actions: WeeklyPlanReviewerActions;
};

export const canActivelyReviewWeeklyPlan = (
  actions: WeeklyPlanReviewerActions,
): boolean => actions.can_assistant_review || actions.can_owner_review;

export const activeWeeklyPlanReviewPath = (
  plan: ReviewerPlan,
): string | null =>
  canActivelyReviewWeeklyPlan(plan.actions)
    ? `/weekly-plans/${plan.id}/review`
    : null;

export const actorRelativeWeeklyPlanStatusKey = (
  status: WeeklyPlanStatus,
  actions?: WeeklyPlanReviewerActions,
): string | null => {
  if (
    (status === "ASSISTANT_REVIEW" && actions?.can_assistant_review) ||
    (status === "OWNER_REVIEW" && actions?.can_owner_review)
  ) {
    return "weeklyPlanning.status.reviewRequired";
  }

  return null;
};
