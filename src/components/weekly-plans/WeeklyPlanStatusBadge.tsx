import type {
  WeeklyPlanReviewStatus,
  WeeklyPlanStatus,
} from "@/types/weeklyPlanning";
import { cn } from "@/utils";
import {
  actorRelativeWeeklyPlanStatusKey,
  type WeeklyPlanReviewerActions,
} from "@/utils/weeklyPlanReviewer";
import { useTranslation } from "react-i18next";

type Props = {
  status: WeeklyPlanStatus | WeeklyPlanReviewStatus;
  actions?: WeeklyPlanReviewerActions;
};

export default function WeeklyPlanStatusBadge({ status, actions }: Props) {
  const { t } = useTranslation("common");
  const positive = ["ACCEPTED", "APPROVED", "PUBLISHED"].includes(status);
  const warning = [
    "CHANGES_REQUESTED",
    "ASSISTANT_CHANGES_REQUESTED",
    "OWNER_CHANGES_REQUESTED",
    "IN_REVIEW",
    "SUBMITTED",
  ].includes(status);

  return (
    <span
      className={cn(
        "inline-flex rounded-full px-2.5 py-1 text-theme-xs font-semibold",
        positive
          ? "bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400"
          : warning
            ? "bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400"
            : "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
      )}
    >
      {t(
        (status === "ASSISTANT_REVIEW" || status === "OWNER_REVIEW"
          ? actorRelativeWeeklyPlanStatusKey(status, actions)
          : null) ?? `weeklyPlanning.status.${status}`,
      )}
    </span>
  );
}
