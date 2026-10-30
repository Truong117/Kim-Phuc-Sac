import ComponentCard from "@/components/common/ComponentCard";
import Button from "@/components/ui/button/Button";
import WeeklyPlanEntryForm from "@/components/weekly-plans/WeeklyPlanEntryForm";
import WeeklyPlanEntries from "@/components/weekly-plans/WeeklyPlanEntries";
import { useWeeklyPlanWizard } from "@/context/WeeklyPlanWizardContext";
import type { ValidationErrors } from "@/services/apiClient";
import {
  addWeeklyPlanV3CorrectionEntry,
  confirmWeeklyPlanV3CorrectionBatch,
  deleteWeeklyPlanV3CorrectionEntry,
  updateWeeklyPlanV3CorrectionEntry,
} from "@/services/weeklyPlanningService";
import type {
  WeeklyPlanEmployeeWorkflow,
  WeeklyPlanEntry,
  WeeklyPlanEntryInput,
} from "@/types/weeklyPlanning";
import {
  clearConfirmedWeeklyPlanV3CorrectionSelection,
  resolveWeeklyPlanV3CorrectionConfirmation,
  weeklyPlanV3CorrectionFailureMode,
  type WeeklyPlanV3PendingCorrectionConfirmation,
} from "@/utils/weeklyPlanV3CorrectionConfirmation";
import { useMemo, useRef, useState } from "react";
import { useTranslation } from "react-i18next";

type Editor = {
  workflow: WeeklyPlanEmployeeWorkflow;
  entry?: WeeklyPlanEntry;
};

type RecoveryState = "AMBIGUOUS" | "STALE" | "VALIDATION" | "RECONCILED" | null;

const requestId = () =>
  `hr-correction-${Date.now()}-${crypto.randomUUID?.() ?? Math.random().toString(36).slice(2)}`;

export default function WeeklyPlanV3HrCorrections() {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.v3Corrections",
  });
  const { plan, references, busy, perform, refresh } = useWeeklyPlanWizard();
  const [editor, setEditor] = useState<Editor | null>(null);
  const [selected, setSelected] = useState<number[]>([]);
  const [confirming, setConfirming] = useState(false);
  const [serverErrors, setServerErrors] = useState<ValidationErrors>({});
  const [serverErrorVersion, setServerErrorVersion] = useState(0);
  const [pendingConfirmation, setPendingConfirmation] =
    useState<WeeklyPlanV3PendingCorrectionConfirmation | null>(null);
  const [recoveryState, setRecoveryState] = useState<RecoveryState>(null);
  const submitRef = useRef(false);

  const correctionWorkflows = useMemo(
    () =>
      (plan?.employee_workflows ?? []).filter(
        (workflow) => workflow.state === "HR_CORRECTION",
      ),
    [plan?.employee_workflows],
  );
  const otherWorkflows = (plan?.employee_workflows ?? []).filter(
    (workflow) => workflow.state !== "HR_CORRECTION",
  );
  const selectedEligibleIds = useMemo(() => {
    const eligible = new Set(
      correctionWorkflows.map((workflow) => workflow.assignee.id),
    );

    return selected.filter((id) => eligible.has(id));
  }, [correctionWorkflows, selected]);

  if (!plan) return null;

  const entriesFor = (assigneeId: number) =>
    plan.entries.filter((entry) => entry.assignee?.id === assigneeId);
  const lockedEmployee = (workflow: WeeklyPlanEmployeeWorkflow) =>
    references?.employees.find(
      (employee) => employee.id === workflow.assignee.id,
    ) ??
    plan.entries.find((entry) => entry.assignee?.id === workflow.assignee.id)
      ?.assignee;
  const save = (input: WeeklyPlanEntryInput) => {
    if (!editor) return;
    setServerErrors({});
    void perform(
      () =>
        editor.entry
          ? updateWeeklyPlanV3CorrectionEntry(
              plan.id,
              editor.workflow.assignee.id,
              editor.entry.id,
              editor.workflow.content_version,
              input,
            )
          : addWeeklyPlanV3CorrectionEntry(
              plan.id,
              editor.workflow.assignee.id,
              editor.workflow.content_version,
              input,
            ),
      (errors) => {
        setServerErrors(errors);
        setServerErrorVersion((version) => version + 1);
      },
    ).then((ok) => {
      if (ok) setEditor(null);
    });
  };
  const confirmBatch = () => {
    if (
      submitRef.current ||
      (selectedEligibleIds.length === 0 && pendingConfirmation === null)
    ) {
      return;
    }
    submitRef.current = true;
    const currentEmployees = correctionWorkflows
      .filter((workflow) => selected.includes(workflow.assignee.id))
      .map((workflow) => ({
        assignee_id: workflow.assignee.id,
        expected_state_version: workflow.state_version,
        expected_content_version: workflow.content_version,
      }));
    const resolution = resolveWeeklyPlanV3CorrectionConfirmation(
      pendingConfirmation,
      plan.id,
      currentEmployees,
      requestId,
    );
    setPendingConfirmation(resolution.confirmation);
    setRecoveryState(null);
    let failure: unknown;
    let batchResult: Awaited<
      ReturnType<typeof confirmWeeklyPlanV3CorrectionBatch>
    > | null = null;
    void perform(async () => {
      try {
        batchResult = await confirmWeeklyPlanV3CorrectionBatch(
          plan.id,
          resolution.confirmation.employees,
          resolution.confirmation.requestId,
        );
        return batchResult.plan;
      } catch (error) {
        failure = error;
        throw error;
      }
    }).then((ok) => {
      if (ok && batchResult) {
        setSelected((current) =>
          clearConfirmedWeeklyPlanV3CorrectionSelection(
            current,
            batchResult!.results,
          ),
        );
        setConfirming(false);
        setPendingConfirmation(null);
        setRecoveryState(
          resolution.reconciliationRequired ? "RECONCILED" : null,
        );
      } else {
        const failureMode = weeklyPlanV3CorrectionFailureMode(failure);
        if (failureMode !== "AMBIGUOUS") {
          setPendingConfirmation(null);
        }
        setRecoveryState(failureMode);
      }
      submitRef.current = false;
    });
  };

  const confirmationCount =
    pendingConfirmation?.employees.length ?? selectedEligibleIds.length;

  return (
    <div className="space-y-5" data-workspace="v3-hr-corrections">
      <ComponentCard title={t("title")} desc={t("description")}>
        <div className="space-y-5">
          {correctionWorkflows.map((workflow) => {
            const entries = entriesFor(workflow.assignee.id);
            const canEdit =
              workflow.actions.can_correct && pendingConfirmation === null;
            const canSelect = workflow.actions.can_resubmit;
            const employee = lockedEmployee(workflow);
            const comments = plan.comments.filter(
              (comment) =>
                comment.assignee_id === workflow.assignee.id ||
                entries.some((entry) => entry.id === comment.entry_id),
            );

            return (
              <section
                key={workflow.id}
                className="space-y-4 rounded-xl border border-warning-200 p-4 dark:border-warning-800"
              >
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h3 className="font-semibold text-gray-900 dark:text-white">
                      {workflow.assignee.name}
                    </h3>
                    <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                      {t(`states.${workflow.state}`)}
                      {" · "}
                      {t("origin", {
                        stage: t(
                          `stages.${workflow.correction_origin_stage ?? "ASSISTANT"}`,
                        ),
                      })}
                      {" · "}
                      {t("counts", {
                        entries: workflow.active_entry_count,
                        items: workflow.active_child_item_count,
                      })}
                    </p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {canEdit && (
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={busy || !employee}
                        onClick={() => setEditor({ workflow })}
                      >
                        {t("addEntry")}
                      </Button>
                    )}
                    {canSelect && (
                      <label className="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                        <input
                          type="checkbox"
                          checked={selected.includes(workflow.assignee.id)}
                          onChange={(event) =>
                            setSelected((current) =>
                              event.target.checked
                                ? [...current, workflow.assignee.id]
                                : current.filter(
                                    (id) => id !== workflow.assignee.id,
                                  ),
                            )
                          }
                        />
                        {t("select")}
                      </label>
                    )}
                  </div>
                </div>

                <WeeklyPlanEntries
                  entries={entries}
                  reviews={[]}
                  canEdit={canEdit}
                  canReview={false}
                  busy={busy}
                  onEdit={(entry) => setEditor({ workflow, entry })}
                  onDelete={(entry) => {
                    if (!window.confirm(t("deleteConfirm"))) return;
                    void perform(() =>
                      deleteWeeklyPlanV3CorrectionEntry(
                        plan.id,
                        workflow.assignee.id,
                        entry.id,
                        workflow.content_version,
                      ),
                    );
                  }}
                  onReview={() => undefined}
                />

                {comments.length > 0 && (
                  <details className="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
                    <summary className="cursor-pointer text-theme-sm font-medium text-gray-900 dark:text-white">
                      {t("discussion", { count: comments.length })}
                    </summary>
                    <div className="mt-3 space-y-2">
                      {comments.map((comment) => (
                        <p
                          key={comment.id}
                          className="text-theme-sm text-gray-600 dark:text-gray-300"
                        >
                          <span className="font-medium">
                            {comment.author.name}:
                          </span>{" "}
                          {comment.content}
                        </p>
                      ))}
                    </div>
                  </details>
                )}

                {editor?.workflow.id === workflow.id && employee && (
                  <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <WeeklyPlanEntryForm
                      key={`${workflow.id}-${editor.entry?.id ?? "new"}`}
                      entry={editor.entry}
                      initialInput={{ assignee_id: workflow.assignee.id }}
                      employees={references?.employees ?? []}
                      weekStart={plan.week_start}
                      weekEnd={plan.week_end}
                      lockAssignee
                      lockedAssignee={employee}
                      serverErrors={serverErrors}
                      serverErrorVersion={serverErrorVersion}
                      isSaving={busy}
                      onSave={save}
                      onCancel={() => setEditor(null)}
                    />
                  </div>
                )}
              </section>
            );
          })}

          {correctionWorkflows.length === 0 && (
            <p className="text-theme-sm text-gray-500 dark:text-gray-400">
              {t("empty")}
            </p>
          )}

          {(selectedEligibleIds.length > 0 || pendingConfirmation !== null) && (
            <div className="flex justify-end">
              <Button
                type="button"
                disabled={busy}
                onClick={() => setConfirming(true)}
              >
                {t("resubmit", { count: confirmationCount })}
              </Button>
            </div>
          )}

          {confirming && (
            <div
              role="dialog"
              aria-modal="true"
              className="rounded-xl border border-brand-200 bg-brand-25 p-4 dark:border-brand-800 dark:bg-brand-500/10"
            >
              <p className="font-semibold text-gray-900 dark:text-white">
                {t("confirmTitle")}
              </p>
              <p className="mt-1 text-theme-sm text-gray-600 dark:text-gray-300">
                {t("confirmDescription", { count: confirmationCount })}
              </p>
              <div className="mt-4 flex justify-end gap-2">
                <Button
                  type="button"
                  variant="outline"
                  disabled={busy}
                  onClick={() => setConfirming(false)}
                >
                  {t("cancel")}
                </Button>
                <Button type="button" disabled={busy} onClick={confirmBatch}>
                  {pendingConfirmation ? t("recovery.retry") : t("confirm")}
                </Button>
              </div>
            </div>
          )}

          {recoveryState && (
            <div
              role="alert"
              className="rounded-xl border border-warning-200 bg-warning-50 p-4 text-theme-sm text-warning-800 dark:border-warning-800 dark:bg-warning-500/10 dark:text-warning-300"
            >
              <p>{t(`recovery.${recoveryState}`)}</p>
              {recoveryState === "AMBIGUOUS" && (
                <div className="mt-3 flex justify-end">
                  <Button type="button" disabled={busy} onClick={confirmBatch}>
                    {t("recovery.retry")}
                  </Button>
                </div>
              )}
              {recoveryState === "STALE" && (
                <div className="mt-3 flex justify-end">
                  <Button
                    type="button"
                    variant="outline"
                    disabled={busy}
                    onClick={() => {
                      void refresh().then(() => setRecoveryState(null));
                    }}
                  >
                    {t("recovery.reload")}
                  </Button>
                </div>
              )}
            </div>
          )}
        </div>
      </ComponentCard>

      {otherWorkflows.length > 0 && (
        <ComponentCard title={t("otherTitle")} desc={t("otherDescription")}>
          <ul className="space-y-2">
            {otherWorkflows.map((workflow) => (
              <li
                key={workflow.id}
                className="flex justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 text-theme-sm dark:bg-gray-800/60"
              >
                <span className="text-gray-700 dark:text-gray-300">
                  {workflow.assignee.name}
                </span>
                <span className="font-medium text-gray-500 dark:text-gray-400">
                  {t(`states.${workflow.state}`)}
                </span>
              </li>
            ))}
          </ul>
        </ComponentCard>
      )}
    </div>
  );
}
