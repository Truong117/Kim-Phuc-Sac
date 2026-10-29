import ComponentCard from "@/components/common/ComponentCard";
import WeeklyPlanWizardLayout from "@/components/weekly-plans/WeeklyPlanWizardLayout";
import Button from "@/components/ui/button/Button";
import { useWeeklyPlanWizard } from "@/context/WeeklyPlanWizardContext";
import {
  analyzeWeeklyPlan,
  decideWeeklyPlanSuggestion,
} from "@/services/weeklyPlanningService";
import { weeklyPlanAnalysisState } from "@/utils/weeklyPlanWizard";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

export default function WeeklyPlanWizardQuality() {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.wizard",
  });
  const { plan, busy, perform } = useWeeklyPlanWizard();
  const navigate = useNavigate();
  const analysisState = plan ? weeklyPlanAnalysisState(plan.events) : "NOT_RUN";
  const affectedEntries = plan
    ? new Set(plan.suggestions.map((suggestion) => suggestion.entry_id)).size
    : 0;

  return (
    <WeeklyPlanWizardLayout step="quality">
      {plan && (
        <>
          <ComponentCard
            title={t("quality.title")}
            desc={t("quality.description")}
          >
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                  {t("quality.issueCountLabel")}
                </p>
                <p className="mt-1 text-title-sm font-semibold text-gray-900 dark:text-white">
                  {plan.suggestions.length}
                </p>
              </div>
              <div>
                <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                  {t("quality.affectedCountLabel")}
                </p>
                <p className="mt-1 text-title-sm font-semibold text-gray-900 dark:text-white">
                  {affectedEntries}
                </p>
              </div>
            </div>
            {analysisState !== "CURRENT" && (
              <p className="rounded-xl bg-warning-50 px-4 py-3 text-theme-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                {analysisState === "STALE"
                  ? t("quality.stale")
                  : t("quality.notRun")}
              </p>
            )}
            {analysisState === "CURRENT" && plan.suggestions.length === 0 && (
              <p className="rounded-xl bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:bg-success-500/10 dark:text-success-400">
                {t("quality.clean")}
              </p>
            )}
            {plan.actions.can_analyze && (
              <div className="flex justify-end">
                <Button
                  type="button"
                  disabled={busy}
                  className="bg-kps-primary hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none"
                  onClick={() => void perform(() => analyzeWeeklyPlan(plan.id))}
                >
                  {analysisState === "CURRENT"
                    ? t("quality.runAgain")
                    : t("quality.run")}
                </Button>
              </div>
            )}
          </ComponentCard>

          {analysisState === "CURRENT" && plan.suggestions.length > 0 && (
            <ComponentCard title={t("quality.suggestionsTitle")} compact>
              <div className="space-y-3">
                {plan.suggestions.map((suggestion) => (
                  <article
                    key={suggestion.id}
                    className="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
                  >
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <p className="font-medium text-gray-900 dark:text-white">
                        {suggestion.suggested_value}
                      </p>
                      <span className="rounded-full bg-gray-100 px-2 py-1 text-theme-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                        {t(`quality.status.${suggestion.status}`)}
                      </span>
                    </div>
                    <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                      {suggestion.reason}
                    </p>
                    {plan.actions.can_edit &&
                      suggestion.status === "PENDING" && (
                        <div className="mt-3 flex gap-2">
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={() =>
                              void perform(() =>
                                decideWeeklyPlanSuggestion(
                                  plan.id,
                                  suggestion.id,
                                  "REJECTED",
                                ),
                              )
                            }
                          >
                            {t("quality.reject")}
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            disabled={busy}
                            className="bg-kps-primary hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none"
                            onClick={() =>
                              void perform(() =>
                                decideWeeklyPlanSuggestion(
                                  plan.id,
                                  suggestion.id,
                                  "ACCEPTED",
                                ),
                              )
                            }
                          >
                            {t("quality.accept")}
                          </Button>
                        </div>
                      )}
                  </article>
                ))}
              </div>
            </ComponentCard>
          )}

          <div className="flex justify-end">
            <Button
              type="button"
              disabled={analysisState !== "CURRENT"}
              className="bg-kps-primary hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none"
              onClick={() => navigate(`/weekly-plans/${plan.id}/submit`)}
            >
              {t("quality.continue")}
            </Button>
          </div>
        </>
      )}
    </WeeklyPlanWizardLayout>
  );
}
