import WeeklyPlanStatusBadge from "@/components/weekly-plans/WeeklyPlanStatusBadge";
import type { WeeklyPlan } from "@/types/weeklyPlanning";
import { formatDateOnly } from "@/utils/dateOnly";
import { useTranslation } from "react-i18next";

export default function WeeklyPlanWizardHeader({ plan }: { plan: WeeklyPlan }) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.wizard",
  });

  return (
    <header className="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-5 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800 dark:bg-white/3">
      <div>
        <p className="text-theme-xs font-semibold tracking-wide text-kps-primary uppercase dark:text-white">
          {t("eyebrow")}
        </p>
        <h1 className="mt-1 text-title-sm font-semibold text-gray-900 dark:text-white">
          {formatDateOnly(plan.week_start)} – {formatDateOnly(plan.week_end)}
        </h1>
        <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
          {t("createdBy", { name: plan.creator.name })}
        </p>
      </div>
      <WeeklyPlanStatusBadge status={plan.status} />
    </header>
  );
}
