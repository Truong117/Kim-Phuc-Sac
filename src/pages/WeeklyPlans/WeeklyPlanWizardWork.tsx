import ComponentCard from "@/components/common/ComponentCard";
import WeeklyPlanEntries from "@/components/weekly-plans/WeeklyPlanEntries";
import WeeklyPlanEntryForm from "@/components/weekly-plans/WeeklyPlanEntryForm";
import WeeklyPlanQuickCheck from "@/components/weekly-plans/WeeklyPlanQuickCheck";
import WeeklyPlanWizardLayout from "@/components/weekly-plans/WeeklyPlanWizardLayout";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import { useWeeklyPlanWizard } from "@/context/WeeklyPlanWizardContext";
import type { ValidationErrors } from "@/services/apiClient";
import {
  addWeeklyPlanEntry,
  analyzeWeeklyPlan,
  deleteWeeklyPlanEntry,
  resetWeeklyPlan,
  updateWeeklyPlanEntry,
} from "@/services/weeklyPlanningService";
import type {
  WeeklyPlanEntry,
  WeeklyPlanEntryInput,
} from "@/types/weeklyPlanning";
import {
  groupWeeklyPlanEntriesByEmployee,
  weeklyPlanSubmissionReadiness,
  weeklyPlanWorkCounts,
} from "@/utils/weeklyPlanWizard";
import { useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

export default function WeeklyPlanWizardWork() {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.wizard",
  });
  const { plan, references, busy, perform } = useWeeklyPlanWizard();
  const navigate = useNavigate();
  const [editing, setEditing] = useState<WeeklyPlanEntry | "new" | null>(null);
  const [showReset, setShowReset] = useState(false);
  const [entryErrors, setEntryErrors] = useState<ValidationErrors>({});
  const [entryErrorVersion, setEntryErrorVersion] = useState(0);
  const groups = useMemo(
    () => groupWeeklyPlanEntriesByEmployee(plan?.entries ?? []),
    [plan?.entries],
  );
  const counts = plan ? weeklyPlanWorkCounts(plan) : null;
  const readiness = useMemo(
    () => weeklyPlanSubmissionReadiness(plan?.entries ?? []),
    [plan?.entries],
  );

  return (
    <WeeklyPlanWizardLayout step="work">
      {plan && references && counts && (
        <>
          <ComponentCard title={t("work.title")} desc={t("work.description")}>
            <dl className="grid gap-4 text-theme-sm sm:grid-cols-2 lg:grid-cols-4">
              {[
                [t("counts.entries"), counts.entryCount],
                [t("counts.items"), counts.itemCount],
                [t("counts.employees"), counts.employeeCount],
                [t("counts.processedTabs"), counts.processedTabCount],
              ].map(([label, value]) => (
                <div key={String(label)}>
                  <dt className="text-gray-500 dark:text-gray-400">{label}</dt>
                  <dd className="mt-1 text-title-sm font-semibold text-gray-900 dark:text-white">
                    {value}
                  </dd>
                </div>
              ))}
            </dl>
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              {groups.map((group) => (
                <div
                  key={group.key}
                  className="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
                >
                  <p className="font-semibold text-gray-900 dark:text-white">
                    {group.name || t("work.unassigned")}
                  </p>
                  {group.sourceTitles.length > 0 && (
                    <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                      {t("work.sources", {
                        sources: group.sourceTitles.join(" · "),
                      })}
                    </p>
                  )}
                  <p className="mt-2 text-theme-xs font-medium text-kps-primary dark:text-white">
                    {t("work.groupCounts", {
                      entries: group.entries.length,
                      items: group.itemCount,
                    })}
                  </p>
                </div>
              ))}
            </div>
          </ComponentCard>

          <ComponentCard title={t("work.entriesTitle")} compact>
            <div className="flex flex-wrap justify-between gap-3">
              {plan.actions.can_reset && (
                <Button
                  type="button"
                  variant="outline"
                  disabled={busy}
                  onClick={() => setShowReset(true)}
                >
                  {t("reset.action")}
                </Button>
              )}
              {plan.actions.can_edit && (
                <Button
                  type="button"
                  disabled={busy}
                  className="bg-kps-primary hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none"
                  onClick={() => {
                    setEntryErrors({});
                    setEditing("new");
                  }}
                >
                  {t("work.addEntry")}
                </Button>
              )}
            </div>
            <WeeklyPlanEntries
              entries={plan.entries}
              reviews={plan.employee_reviews}
              canEdit={plan.actions.can_edit}
              canReview={false}
              busy={busy}
              onEdit={(entry) => {
                setEntryErrors({});
                setEditing(entry);
              }}
              onDelete={(entry) => {
                if (window.confirm(t("work.deleteEntry"))) {
                  void perform(() => deleteWeeklyPlanEntry(plan.id, entry.id));
                }
              }}
              onReview={() => undefined}
            />
          </ComponentCard>

          <WeeklyPlanQuickCheck
            plan={plan}
            busy={busy}
            onAnalyze={() => void perform(() => analyzeWeeklyPlan(plan.id))}
          />

          <div className="flex flex-col items-end gap-2">
            {!readiness.ready && (
              <p className="text-theme-sm text-error-600 dark:text-error-400">
                {t("work.continueBlocked")}
              </p>
            )}
            <Button
              type="button"
              disabled={busy || !readiness.ready}
              className="bg-kps-primary hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none"
              onClick={() => navigate(`/weekly-plans/${plan.id}/submit`)}
            >
              {t("work.continue")}
            </Button>
          </div>

          <Modal
            isOpen={editing !== null}
            onClose={() => !busy && setEditing(null)}
            className="max-h-[90vh] max-w-3xl overflow-y-auto p-6"
            ariaLabelledBy="wizard-entry-form-title"
          >
            <h2
              id="wizard-entry-form-title"
              className="mb-5 text-lg font-semibold text-gray-900 dark:text-white"
            >
              {editing === "new" ? t("work.addEntry") : t("work.editEntry")}
            </h2>
            <WeeklyPlanEntryForm
              key={editing === "new" ? "new" : editing?.id}
              entry={editing === "new" ? undefined : (editing ?? undefined)}
              employees={references.employees}
              weekStart={plan.week_start}
              weekEnd={plan.week_end}
              isSaving={busy}
              serverErrors={entryErrors}
              serverErrorVersion={entryErrorVersion}
              onCancel={() => setEditing(null)}
              onSave={(input: WeeklyPlanEntryInput) => {
                const action =
                  editing === "new"
                    ? () => addWeeklyPlanEntry(plan.id, input)
                    : () =>
                        updateWeeklyPlanEntry(
                          plan.id,
                          (editing as WeeklyPlanEntry).id,
                          input,
                        );
                void perform(action, (errors) => {
                  setEntryErrors(errors);
                  setEntryErrorVersion((current) => current + 1);
                }).then((succeeded) => succeeded && setEditing(null));
              }}
            />
          </Modal>

          <Modal
            isOpen={showReset}
            onClose={() => !busy && setShowReset(false)}
            showCloseButton={false}
            className="max-w-xl p-6"
            ariaLabelledBy="weekly-plan-reset-title"
          >
            <h2
              id="weekly-plan-reset-title"
              className="text-lg font-semibold text-gray-900 dark:text-white"
            >
              {t("reset.title")}
            </h2>
            <p className="mt-3 text-theme-sm text-gray-600 dark:text-gray-300">
              {t("reset.description")}
            </p>
            <dl className="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-gray-50 p-4 text-theme-sm dark:bg-gray-800/60">
              <div>
                <dt className="text-gray-500 dark:text-gray-400">
                  {t("counts.entries")}
                </dt>
                <dd className="font-semibold text-gray-900 dark:text-white">
                  {counts.entryCount}
                </dd>
              </div>
              <div>
                <dt className="text-gray-500 dark:text-gray-400">
                  {t("counts.items")}
                </dt>
                <dd className="font-semibold text-gray-900 dark:text-white">
                  {counts.itemCount}
                </dd>
              </div>
            </dl>
            <div className="mt-6 flex justify-end gap-3">
              <Button
                type="button"
                variant="outline"
                disabled={busy}
                onClick={() => setShowReset(false)}
              >
                {t("reset.cancel")}
              </Button>
              <Button
                type="button"
                disabled={busy}
                className="bg-error-600 hover:bg-error-700 disabled:bg-error-300"
                onClick={() =>
                  void perform(() => resetWeeklyPlan(plan.id)).then(
                    (succeeded) => {
                      if (!succeeded) return;
                      setShowReset(false);
                      navigate(`/weekly-plans/${plan.id}/structure`, {
                        replace: true,
                      });
                    },
                  )
                }
              >
                {t("reset.confirm")}
              </Button>
            </div>
          </Modal>
        </>
      )}
    </WeeklyPlanWizardLayout>
  );
}
