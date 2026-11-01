import ComponentCard from "@/components/common/ComponentCard";
import Button from "@/components/ui/button/Button";
import WeeklyPlanStep4DiscussionHistory from "@/components/weekly-plans/WeeklyPlanStep4DiscussionHistory";
import WeeklyPlanWizardLayout from "@/components/weekly-plans/WeeklyPlanWizardLayout";
import WeeklyPlanV3HrCorrections from "@/components/weekly-plans/WeeklyPlanV3HrCorrections";
import { useWeeklyPlanWizard } from "@/context/WeeklyPlanWizardContext";
import {
  submitWeeklyPlan,
  updateWeeklyPlanV3Roster,
} from "@/services/weeklyPlanningService";
import {
  isWeeklyPlanWaitingForHr,
  weeklyPlanSubmitStatusKey,
} from "@/utils/weeklyPlanReviewWorkspace";
import { weeklyPlanWorkCounts } from "@/utils/weeklyPlanWizard";
import { activeWeeklyPlanReviewPath } from "@/utils/weeklyPlanReviewer";
import { weeklyPlanV3HasHrCorrectionAccess } from "@/utils/weeklyPlanV3ReviewWorkspace";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import { Navigate } from "react-router";

const formatTimestamp = (value: string) =>
  new Intl.DateTimeFormat("vi-VN", {
    dateStyle: "short",
    timeStyle: "short",
    timeZone: "Asia/Ho_Chi_Minh",
  }).format(new Date(value));

export default function WeeklyPlanWizardSubmit() {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.wizard",
  });
  const { plan, references, busy, perform } = useWeeklyPlanWizard();
  const [rosterDraft, setRosterDraft] = useState<{
    planId: number;
    assigneeIds: number[];
  } | null>(null);

  if (!plan) {
    return (
      <WeeklyPlanWizardLayout step="submit">{null}</WeeklyPlanWizardLayout>
    );
  }

  const isV3CorrectionWorkspace =
    plan.workflow_version === 3 &&
    (plan.employee_workflows ?? []).some(
      (workflow) => workflow.state === "HR_CORRECTION",
    );
  const hasV3CorrectionAccess = weeklyPlanV3HasHrCorrectionAccess(plan);
  const v3CorrectionWorkspace = (
    <WeeklyPlanWizardLayout step="submit">
      <WeeklyPlanV3HrCorrections />
      <WeeklyPlanStep4DiscussionHistory />
    </WeeklyPlanWizardLayout>
  );
  if (isV3CorrectionWorkspace && hasV3CorrectionAccess) {
    return v3CorrectionWorkspace;
  }

  const reviewPath = activeWeeklyPlanReviewPath(plan);
  if (reviewPath) return <Navigate to={reviewPath} replace />;

  if (isV3CorrectionWorkspace) {
    return v3CorrectionWorkspace;
  }

  const counts = weeklyPlanWorkCounts(plan);
  const isV3Draft = plan.workflow_version === 3 && plan.status === "DRAFT";
  const savedRoster = Array.from(
    new Set(
      (plan.employee_workflows ?? []).map((workflow) => workflow.assignee.id),
    ),
  );
  const defaultRosterSelection = Array.from(
    new Set([
      ...savedRoster,
      ...plan.entries
        .map((entry) => entry.assignee?.id)
        .filter((id): id is number => id !== undefined),
    ]),
  );
  const selectedRoster =
    rosterDraft?.planId === plan.id
      ? rosterDraft.assigneeIds
      : defaultRosterSelection;
  const selectedRosterSet = new Set(selectedRoster);
  const savedRosterSet = new Set(savedRoster);
  const hasUnsavedRosterChanges =
    selectedRoster.length !== savedRoster.length ||
    selectedRoster.some((employeeId) => !savedRosterSet.has(employeeId));
  const rosterEntryEmployeeIds = new Set(
    plan.entries
      .map((entry) => entry.assignee?.id)
      .filter((id): id is number => id !== undefined),
  );
  const zeroWorkEmployees = selectedRoster.filter(
    (employeeId) => !rosterEntryEmployeeIds.has(employeeId),
  ).length;
  const submitted =
    plan.status !== "DRAFT" && plan.status !== "CHANGES_REQUESTED";
  const waitingForHr = isWeeklyPlanWaitingForHr(plan.status);
  const correctionDecisions =
    plan.review_workflow?.current_round?.decisions.filter(
      (decision) => decision.status === "CHANGES_REQUESTED",
    ) ?? [];
  const canInitialSubmit =
    ["DRAFT", "CHANGES_REQUESTED"].includes(plan.status) &&
    plan.actions.can_submit &&
    (!isV3Draft || !hasUnsavedRosterChanges);
  const canResubmit = waitingForHr && plan.actions.can_resubmit_changes;

  return (
    <WeeklyPlanWizardLayout step="submit">
      <ComponentCard title={t("submit.title")} desc={t("submit.description")}>
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-900/60">
          <div>
            <p className="text-theme-sm font-semibold text-gray-800 dark:text-white/90">
              {t("submit.workflowVersion", {
                version: plan.workflow_version === 3 ? "V3" : "V2",
              })}
            </p>
            {plan.workflow_version === 3 && (
              <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                {t("submit.v3Description")}
              </p>
            )}
          </div>
        </div>

        {isV3Draft && (
          <section className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <h3 className="font-semibold text-gray-900 dark:text-white">
              {t("submit.roster.title")}
            </h3>
            <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
              {t("submit.roster.description")}
            </p>
            <div className="mt-4 grid gap-2 sm:grid-cols-2">
              {(references?.employees ?? []).map((employee) => {
                const hasWork = rosterEntryEmployeeIds.has(employee.id);
                return (
                  <label
                    key={employee.id}
                    className="flex items-start gap-3 rounded-lg border border-gray-200 px-3 py-2.5 dark:border-gray-700"
                  >
                    <input
                      type="checkbox"
                      checked={selectedRosterSet.has(employee.id)}
                      disabled={hasWork || busy}
                      onChange={(event) => {
                        setRosterDraft({
                          planId: plan.id,
                          assigneeIds: event.target.checked
                            ? [...selectedRoster, employee.id]
                            : selectedRoster.filter((id) => id !== employee.id),
                        });
                      }}
                      className="mt-0.5 size-4 rounded border-gray-300 text-kps-primary focus:ring-kps-primary dark:border-gray-600 dark:bg-gray-900"
                    />
                    <span className="min-w-0">
                      <span className="block text-theme-sm font-medium text-gray-800 dark:text-white/90">
                        {employee.name}
                      </span>
                      <span className="block text-theme-xs text-gray-500 dark:text-gray-400">
                        {hasWork
                          ? t("submit.roster.hasWork")
                          : t("submit.roster.zeroWork")}
                      </span>
                    </span>
                  </label>
                );
              })}
            </div>
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
              <p className="text-theme-sm text-gray-600 dark:text-gray-300">
                {t("submit.roster.summary", {
                  selected: selectedRoster.length,
                  zeroWork: zeroWorkEmployees,
                })}
              </p>
              <Button
                type="button"
                variant="outline"
                disabled={
                  busy ||
                  selectedRoster.length === 0 ||
                  !hasUnsavedRosterChanges
                }
                onClick={() =>
                  void perform(() =>
                    updateWeeklyPlanV3Roster(plan.id, selectedRoster),
                  )
                }
              >
                {t("submit.roster.save")}
              </Button>
            </div>
          </section>
        )}

        <dl className="grid gap-4 text-theme-sm sm:grid-cols-2 lg:grid-cols-4">
          {[
            [t("counts.employees"), counts.employeeCount],
            [t("counts.entries"), counts.entryCount],
            [t("counts.items"), counts.itemCount],
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

        {submitted ? (
          <div
            className={`rounded-xl p-4 ${waitingForHr ? "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400" : "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400"}`}
          >
            <p className="font-semibold">
              {t(`submit.status.${weeklyPlanSubmitStatusKey(plan.status)}`)}
            </p>
            {plan.submitted_at && (
              <p className="mt-1 text-theme-sm">
                {formatTimestamp(plan.submitted_at)}
              </p>
            )}
          </div>
        ) : (
          canInitialSubmit && (
            <div className="flex justify-end">
              <Button
                type="button"
                disabled={busy}
                className="bg-kps-primary hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none"
                onClick={() => void perform(() => submitWeeklyPlan(plan.id))}
              >
                {t("submit.action")}
              </Button>
            </div>
          )
        )}

        {waitingForHr && (
          <div className="rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-800 dark:bg-warning-500/10">
            <p className="font-semibold text-warning-800 dark:text-warning-300">
              {t("submit.correction.title")}
            </p>
            <p className="mt-1 text-theme-sm text-warning-700 dark:text-warning-400">
              {correctionDecisions.length === 1
                ? t("submit.correction.single", {
                    stage: t(
                      `submit.correction.${plan.review_workflow?.stage ?? "OWNER"}`,
                    ),
                    employee: correctionDecisions[0].assignee.name,
                  })
                : t("submit.correction.multiple", {
                    count: correctionDecisions.length,
                  })}
            </p>
            <p className="mt-2 text-theme-xs font-medium text-warning-700 dark:text-warning-400">
              {t("submit.correction.scope")}
            </p>
          </div>
        )}

        {canResubmit && (
          <div className="flex justify-end">
            <Button
              type="button"
              disabled={busy}
              className="bg-kps-primary hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none"
              onClick={() => void perform(() => submitWeeklyPlan(plan.id))}
            >
              {t("submit.resubmitAction")}
            </Button>
          </div>
        )}
      </ComponentCard>

      <WeeklyPlanStep4DiscussionHistory />
    </WeeklyPlanWizardLayout>
  );
}
