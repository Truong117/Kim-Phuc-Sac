import ComponentCard from "@/components/common/ComponentCard";
import Button from "@/components/ui/button/Button";
import WeeklyPlanStep4DiscussionHistory from "@/components/weekly-plans/WeeklyPlanStep4DiscussionHistory";
import WeeklyPlanWizardLayout from "@/components/weekly-plans/WeeklyPlanWizardLayout";
import WeeklyPlanV3HrCorrections from "@/components/weekly-plans/WeeklyPlanV3HrCorrections";
import { useWeeklyPlanWizard } from "@/context/WeeklyPlanWizardContext";
import { submitWeeklyPlan } from "@/services/weeklyPlanningService";
import {
  isWeeklyPlanWaitingForHr,
  weeklyPlanSubmitStatusKey,
} from "@/utils/weeklyPlanReviewWorkspace";
import { weeklyPlanWorkCounts } from "@/utils/weeklyPlanWizard";
import { activeWeeklyPlanReviewPath } from "@/utils/weeklyPlanReviewer";
import { weeklyPlanV3HasHrCorrectionAccess } from "@/utils/weeklyPlanV3ReviewWorkspace";
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
  const { plan, busy, perform } = useWeeklyPlanWizard();

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
  const submitted =
    plan.status !== "DRAFT" && plan.status !== "CHANGES_REQUESTED";
  const waitingForHr = isWeeklyPlanWaitingForHr(plan.status);
  const correctionDecisions =
    plan.review_workflow?.current_round?.decisions.filter(
      (decision) => decision.status === "CHANGES_REQUESTED",
    ) ?? [];
  const canInitialSubmit =
    ["DRAFT", "CHANGES_REQUESTED"].includes(plan.status) &&
    plan.actions.can_submit;
  const canResubmit = waitingForHr && plan.actions.can_resubmit_changes;

  return (
    <WeeklyPlanWizardLayout step="submit">
      <ComponentCard title={t("submit.title")} desc={t("submit.description")}>
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
