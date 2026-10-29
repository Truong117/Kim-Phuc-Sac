import PageMeta from "@/components/common/PageMeta";
import WeeklyPlanWizardHeader from "@/components/weekly-plans/WeeklyPlanWizardHeader";
import WeeklyPlanWizardStepper from "@/components/weekly-plans/WeeklyPlanWizardStepper";
import Button from "@/components/ui/button/Button";
import { useWeeklyPlanWizard } from "@/context/WeeklyPlanWizardContext";
import {
  guardWeeklyPlanWizardStep,
  weeklyPlanWizardSteps,
  type WeeklyPlanWizardStep,
} from "@/utils/weeklyPlanWizard";
import type { ReactNode } from "react";
import { useTranslation } from "react-i18next";
import { Navigate, useNavigate } from "react-router";

export default function WeeklyPlanWizardLayout({
  step,
  children,
}: {
  step: WeeklyPlanWizardStep;
  children: ReactNode;
}) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.wizard",
  });
  const { plan, mappings, loading, error } = useWeeklyPlanWizard();
  const navigate = useNavigate();

  if (loading && !plan) {
    return (
      <>
        <PageMeta title={t("pageTitle")} description={t("pageDescription")} />
        <div className="rounded-2xl border border-gray-200 bg-white p-8 text-theme-sm text-gray-500 dark:border-gray-800 dark:bg-white/3 dark:text-gray-400">
          {t("loading")}
        </div>
      </>
    );
  }

  if (!plan) {
    return (
      <>
        <PageMeta title={t("pageTitle")} description={t("pageDescription")} />
        <div className="rounded-2xl bg-error-50 p-4 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400">
          {error ?? t("loadError")}
        </div>
      </>
    );
  }

  const allowedStep = guardWeeklyPlanWizardStep(step, plan, mappings);
  if (allowedStep !== step) {
    return <Navigate to={`/weekly-plans/${plan.id}/${allowedStep}`} replace />;
  }

  const currentIndex = weeklyPlanWizardSteps.indexOf(step);
  const previousStep = weeklyPlanWizardSteps[Math.max(0, currentIndex - 1)];

  return (
    <>
      <PageMeta title={t("pageTitle")} description={t("pageDescription")} />
      <div className="space-y-5">
        <WeeklyPlanWizardHeader plan={plan} />
        <WeeklyPlanWizardStepper
          plan={plan}
          mappings={mappings}
          currentStep={step}
        />
        {error && (
          <p className="rounded-xl bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400">
            {error}
          </p>
        )}
        {currentIndex > 0 && (
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => navigate(`/weekly-plans/${plan.id}/${previousStep}`)}
          >
            <span className="rtl:rotate-180">←</span>
            {t("back")}
          </Button>
        )}
        {children}
      </div>
    </>
  );
}
