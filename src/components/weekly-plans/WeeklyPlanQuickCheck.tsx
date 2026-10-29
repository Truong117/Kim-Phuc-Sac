import ComponentCard from "@/components/common/ComponentCard";
import Button from "@/components/ui/button/Button";
import type { WeeklyPlan, WeeklyPlanSuggestion } from "@/types/weeklyPlanning";
import {
  weeklyPlanAnalysisState,
  weeklyPlanSubmissionReadiness,
} from "@/utils/weeklyPlanWizard";
import { useMemo } from "react";
import { useTranslation } from "react-i18next";

type Props = {
  plan: WeeklyPlan;
  busy: boolean;
  onAnalyze: () => void;
};

type WarningGroup = {
  type: string;
  suggestions: WeeklyPlanSuggestion[];
};

export default function WeeklyPlanQuickCheck({ plan, busy, onAnalyze }: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.wizard.quickCheck",
  });
  const readiness = useMemo(
    () => weeklyPlanSubmissionReadiness(plan.entries),
    [plan.entries],
  );
  const analysisState = weeklyPlanAnalysisState(plan.events);
  const warningGroups = useMemo<WarningGroup[]>(() => {
    const grouped = new Map<string, WeeklyPlanSuggestion[]>();
    for (const suggestion of plan.suggestions) {
      grouped.set(suggestion.type, [
        ...(grouped.get(suggestion.type) ?? []),
        suggestion,
      ]);
    }
    return [...grouped].map(([type, suggestions]) => ({ type, suggestions }));
  }, [plan.suggestions]);
  const entryById = useMemo(
    () => new Map(plan.entries.map((entry) => [entry.id, entry])),
    [plan.entries],
  );

  const blockers = [
    ["noEntries", readiness.noEntries],
    ["missingAssignee", readiness.missingAssignee],
    ["invalidEntries", readiness.invalidEntries],
    ["missingExpectedResult", readiness.missingExpectedResult],
    ["missingDates", readiness.missingDates],
  ] as const;

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      <div
        className={`rounded-xl p-4 ${
          readiness.ready
            ? "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400"
            : "bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400"
        }`}
      >
        <p className="font-semibold">
          {readiness.ready
            ? t("ready")
            : t("blocked", { count: readiness.totalBlocking })}
        </p>
        <dl className="mt-3 grid gap-2 text-theme-sm sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt>
              {plan.entries.length > 0 && readiness.invalidEntries === 0
                ? "✓"
                : "⚠"}{" "}
              {t("checks.validEntries")}
            </dt>
            <dd className="font-semibold">
              {readiness.validEntries}/{plan.entries.length}
            </dd>
          </div>
          <div>
            <dt>
              {plan.entries.length > 0 && readiness.missingAssignee === 0
                ? "✓"
                : "⚠"}{" "}
              {t("checks.assignedEmployees")}
            </dt>
            <dd className="font-semibold">{readiness.assignedEmployees}</dd>
          </div>
          <div>
            <dt>
              {plan.entries.length > 0 && readiness.missingExpectedResult === 0
                ? "✓"
                : "⚠"}{" "}
              {t("checks.expectedResults")}
            </dt>
            <dd className="font-semibold">
              {plan.entries.length - readiness.missingExpectedResult}/
              {plan.entries.length}
            </dd>
          </div>
          <div>
            <dt>
              {plan.entries.length > 0 && readiness.missingDates === 0
                ? "✓"
                : "⚠"}{" "}
              {t("checks.dates")}
            </dt>
            <dd className="font-semibold">
              {plan.entries.length - readiness.missingDates}/
              {plan.entries.length}
            </dd>
          </div>
        </dl>
      </div>

      {!readiness.ready && (
        <ul className="grid gap-2 text-theme-sm sm:grid-cols-2">
          {blockers
            .filter(([, count]) => count > 0)
            .map(([key, count]) => (
              <li
                key={key}
                className="rounded-lg border border-error-200 px-3 py-2 text-error-700 dark:border-error-800 dark:text-error-400"
              >
                {t(`blockers.${key}`, { count })}
              </li>
            ))}
        </ul>
      )}

      <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="font-semibold text-gray-900 dark:text-white">
              {t("warnings.title")}
            </p>
            <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
              {analysisState === "CURRENT"
                ? warningGroups.length === 0
                  ? t("warnings.clean")
                  : t("warnings.found", { count: plan.suggestions.length })
                : analysisState === "STALE"
                  ? t("warnings.stale")
                  : t("warnings.notRun")}
            </p>
          </div>
          {plan.actions.can_analyze && (
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={busy}
              onClick={onAnalyze}
            >
              {analysisState === "CURRENT"
                ? t("warnings.runAgain")
                : t("warnings.run")}
            </Button>
          )}
        </div>

        {analysisState === "CURRENT" && warningGroups.length > 0 && (
          <div className="mt-3 space-y-2">
            {warningGroups.map((group) => (
              <details
                key={group.type}
                className="rounded-lg bg-warning-50 px-3 py-2 dark:bg-warning-500/10"
              >
                <summary className="cursor-pointer text-theme-sm font-semibold text-warning-700 dark:text-warning-400">
                  {t(`warnings.types.${group.type}`, {
                    defaultValue: group.type,
                  })}{" "}
                  ({group.suggestions.length})
                </summary>
                <ul className="mt-2 space-y-1 text-theme-xs text-gray-600 dark:text-gray-300">
                  {group.suggestions.map((suggestion) => {
                    const entry = entryById.get(suggestion.entry_id);
                    return (
                      <li key={suggestion.id}>
                        {entry?.assignee?.name ?? t("warnings.unassigned")} ·{" "}
                        {entry?.title ?? `#${suggestion.entry_id}`}
                      </li>
                    );
                  })}
                </ul>
              </details>
            ))}
          </div>
        )}
      </div>
    </ComponentCard>
  );
}
