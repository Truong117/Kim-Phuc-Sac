import PageMeta from "@/components/common/PageMeta";
import { useWeeklyPlanWizard } from "@/context/WeeklyPlanWizardContext";
import { inferWeeklyPlanWizardStep } from "@/utils/weeklyPlanWizard";
import { activeWeeklyPlanReviewPath } from "@/utils/weeklyPlanReviewer";
import { useTranslation } from "react-i18next";
import { Navigate } from "react-router";

export default function WeeklyPlanWizardEntry() {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.wizard",
  });
  const { plan, mappings, loading, error } = useWeeklyPlanWizard();

  if (loading && !plan) {
    return (
      <>
        <PageMeta title={t("pageTitle")} description={t("pageDescription")} />
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("loading")}
        </p>
      </>
    );
  }
  if (!plan) {
    return (
      <>
        <PageMeta title={t("pageTitle")} description={t("pageDescription")} />
        <p className="text-theme-sm text-error-600 dark:text-error-400">
          {error ?? t("loadError")}
        </p>
      </>
    );
  }

  const reviewPath = activeWeeklyPlanReviewPath(plan);
  if (reviewPath) return <Navigate to={reviewPath} replace />;

  return (
    <Navigate
      to={`/weekly-plans/${plan.id}/${inferWeeklyPlanWizardStep(plan, mappings)}`}
      replace
    />
  );
}
