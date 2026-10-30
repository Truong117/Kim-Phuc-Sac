import type { WeeklyPlan, WeeklyPlanComment } from "@/types/weeklyPlanning";

type CommentContextPlan = Pick<WeeklyPlan, "employee_reviews" | "entries"> &
  Partial<Pick<WeeklyPlan, "review_workflow">>;

export type WeeklyPlanCommentContextType = "PLAN" | "EMPLOYEE" | "ENTRY";

export type WeeklyPlanCommentContextLabels = {
  wholePlan: string;
  allEmployeeWorks: string;
  unavailableEmployee: string;
  unavailableWork: string;
};

export type WeeklyPlanCommentContext = {
  type: WeeklyPlanCommentContextType;
  employeeName: string | null;
  entryTitle: string | null;
  label: string;
};

const findEmployeeName = (
  plan: CommentContextPlan,
  assigneeId: number,
): string | null =>
  plan.review_workflow?.current_round?.decisions.find(
    (decision) => decision.assignee.id === assigneeId,
  )?.assignee.name ??
  plan.employee_reviews.find((review) => review.assignee.id === assigneeId)
    ?.assignee.name ??
  plan.entries.find((entry) => entry.assignee?.id === assigneeId)?.assignee
    ?.name ??
  null;

export const resolveWeeklyPlanCommentContext = (
  plan: CommentContextPlan,
  comment: Pick<WeeklyPlanComment, "entry_id" | "assignee_id">,
  labels: WeeklyPlanCommentContextLabels,
): WeeklyPlanCommentContext => {
  if (comment.entry_id !== null) {
    const entry = plan.entries.find(({ id }) => id === comment.entry_id);
    if (!entry) {
      return {
        type: "ENTRY",
        employeeName: null,
        entryTitle: null,
        label: labels.unavailableWork,
      };
    }

    const employeeName = entry.assignee?.name ?? null;
    return {
      type: "ENTRY",
      employeeName,
      entryTitle: entry.title,
      label: employeeName ? `${employeeName} › ${entry.title}` : entry.title,
    };
  }

  if (comment.assignee_id !== null) {
    const employeeName = findEmployeeName(plan, comment.assignee_id);
    return {
      type: "EMPLOYEE",
      employeeName,
      entryTitle: null,
      label: `${employeeName ?? labels.unavailableEmployee} · ${labels.allEmployeeWorks}`,
    };
  }

  return {
    type: "PLAN",
    employeeName: null,
    entryTitle: null,
    label: labels.wholePlan,
  };
};

export const formatWeeklyPlanCommentTimestamp = (
  value: string | null | undefined,
): string | null => {
  if (!value) return null;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;

  return new Intl.DateTimeFormat("vi-VN", {
    dateStyle: "short",
    timeStyle: "short",
    timeZone: "Asia/Ho_Chi_Minh",
  }).format(date);
};
