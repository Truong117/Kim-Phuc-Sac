import type {
  WeeklyPlan,
  WeeklyPlanGoogleDocsEmployeeMappings,
} from "@/types/weeklyPlanning";
import {
  guardWeeklyPlanWizardStep,
  weeklyPlanWizardSteps,
  type WeeklyPlanWizardStep,
} from "@/utils/weeklyPlanWizard";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

type Props = {
  plan: WeeklyPlan;
  mappings: WeeklyPlanGoogleDocsEmployeeMappings | null;
  currentStep: WeeklyPlanWizardStep;
};

export default function WeeklyPlanWizardStepper({
  plan,
  mappings,
  currentStep,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.wizard",
  });
  const navigate = useNavigate();
  const currentIndex = weeklyPlanWizardSteps.indexOf(currentStep);

  return (
    <nav
      aria-label={t("stepperLabel")}
      className="no-scrollbar overflow-x-auto rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-white/3"
    >
      <ol className="flex min-w-max items-center gap-2 lg:grid lg:min-w-0 lg:grid-cols-5">
        {weeklyPlanWizardSteps.map((step, index) => {
          const completed = index < currentIndex;
          const current = step === currentStep;
          const guarded = guardWeeklyPlanWizardStep(step, plan, mappings);
          const clickable = completed && guarded === step;

          return (
            <li key={step} className="min-w-44 lg:min-w-0">
              <button
                type="button"
                disabled={!clickable}
                aria-current={current ? "step" : undefined}
                onClick={() => navigate(`/weekly-plans/${plan.id}/${step}`)}
                className={`flex w-full items-center gap-2 rounded-xl px-3 py-2 text-start transition ${
                  current
                    ? "bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300"
                    : completed
                      ? "text-success-700 hover:bg-success-50 dark:text-success-400 dark:hover:bg-success-500/10"
                      : "cursor-not-allowed text-gray-400 dark:text-gray-600"
                }`}
              >
                <span className="flex size-7 shrink-0 items-center justify-center rounded-full border border-current text-theme-xs font-bold">
                  {completed ? "✓" : index + 1}
                </span>
                <span className="text-theme-xs font-semibold">
                  {t(`steps.${step}`)}
                </span>
              </button>
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
