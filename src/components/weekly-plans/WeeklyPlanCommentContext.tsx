import type { WeeklyPlan, WeeklyPlanComment } from "@/types/weeklyPlanning";
import { resolveWeeklyPlanCommentContext } from "@/utils/weeklyPlanCommentContext";
import { useTranslation } from "react-i18next";

type Props = {
  plan: Pick<WeeklyPlan, "employee_reviews" | "entries">;
  comment: Pick<WeeklyPlanComment, "entry_id" | "assignee_id">;
};

export default function WeeklyPlanCommentContext({ plan, comment }: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.discussion",
  });
  const context = resolveWeeklyPlanCommentContext(plan, comment, {
    wholePlan: t("wholePlan"),
    allEmployeeWorks: t("allEmployeeWorks"),
    unavailableEmployee: t("unavailableEmployee"),
    unavailableWork: t("unavailableWork"),
  });

  return (
    <span className="inline-flex rounded-full bg-gray-200 px-2 py-0.5 text-theme-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">
      {context.label}
    </span>
  );
}
