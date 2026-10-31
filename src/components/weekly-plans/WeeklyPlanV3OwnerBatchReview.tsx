import Button from "@/components/ui/button/Button";
import WeeklyPlanV3EmployeeGroup from "@/components/weekly-plans/WeeklyPlanV3EmployeeGroup";
import { ApiServiceError } from "@/services/apiClient";
import { confirmWeeklyPlanOwnerBatch } from "@/services/weeklyPlanningService";
import type {
  WeeklyPlan,
  WeeklyPlanEmployeeWorkflow,
  WeeklyPlanV3OwnerDecision,
} from "@/types/weeklyPlanning";
import {
  resolveWeeklyPlanV3BatchConfirmation,
  weeklyPlanV3BatchFailureMode,
  type WeeklyPlanV3BatchFailureMode,
} from "@/utils/weeklyPlanV3BatchConfirmation";
import {
  buildWeeklyPlanV3OwnerBatch,
  clearConfirmedWeeklyPlanV3OwnerDraft,
  setWeeklyPlanV3OwnerDraftDecision,
  summarizeWeeklyPlanV3OwnerDraft,
  type WeeklyPlanV3OwnerDraft,
} from "@/utils/weeklyPlanV3OwnerBatch";
import { weeklyPlanV3EntriesByAssignee } from "@/utils/weeklyPlanV3ReviewWorkspace";
import type { WeeklyPlanV3OwnerPendingConfirmation } from "@/utils/weeklyPlanV3ReviewWorkspace";
import { useMemo, useRef, useState } from "react";
import { useTranslation } from "react-i18next";

type Props = {
  plan: WeeklyPlan;
  onPlanChange: (plan: WeeklyPlan) => void;
  onReload: () => Promise<void>;
  pending: WeeklyPlanV3OwnerPendingConfirmation | null;
  onPendingChange: (
    pending: WeeklyPlanV3OwnerPendingConfirmation | null,
  ) => void;
};

const requestId = (): string =>
  `owner-${Date.now()}-${globalThis.crypto.randomUUID()}`;

export default function WeeklyPlanV3OwnerBatchReview({
  plan,
  onPlanChange,
  onReload,
  pending,
  onPendingChange,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.reviewWorkspace.v3Owner",
  });
  const [draft, setDraft] = useState<WeeklyPlanV3OwnerDraft>({});
  const [confirming, setConfirming] = useState(false);
  const [recoveryState, setRecoveryState] = useState<
    WeeklyPlanV3BatchFailureMode | "RECONCILED" | null
  >(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [expandedId, setExpandedId] = useState<number | null>(
    () =>
      (plan.employee_workflows ?? []).find(
        (workflow) => workflow.viewer_context.mode === "OWNER_REVIEW",
      )?.id ?? null,
  );
  const submitting = useRef(false);
  const workflows = (plan.employee_workflows ?? []).filter(
    (workflow) => workflow.viewer_context.mode === "OWNER_REVIEW",
  );
  const entriesByAssignee = useMemo(
    () => weeklyPlanV3EntriesByAssignee(plan.entries),
    [plan.entries],
  );
  const entryCounts = useMemo(
    () =>
      new Map(
        [...entriesByAssignee.entries()].map(([assigneeId, entries]) => [
          assigneeId,
          entries.length,
        ]),
      ),
    [entriesByAssignee],
  );
  const decisions = useMemo(
    () => buildWeeklyPlanV3OwnerBatch(plan, draft),
    [draft, plan],
  );
  const draftSummary = useMemo(
    () => summarizeWeeklyPlanV3OwnerDraft(decisions, entryCounts),
    [decisions, entryCounts],
  );
  const confirmationDecisions = pending?.decisions ?? decisions;
  const confirmationSummary = useMemo(
    () => summarizeWeeklyPlanV3OwnerDraft(confirmationDecisions, entryCounts),
    [confirmationDecisions, entryCounts],
  );
  const confirmationTaskCount =
    pending?.taskCountPreview ?? confirmationSummary.taskCount;

  const updateDecision = (
    workflow: WeeklyPlanEmployeeWorkflow,
    decision: WeeklyPlanV3OwnerDecision | null,
  ) => {
    if (busy || pending !== null) return;
    const allowed =
      decision === null ||
      (decision === "ACCEPTED" &&
        workflow.viewer_context.mode === "OWNER_REVIEW" &&
        workflow.active_entry_count > 0 &&
        workflow.actions.can_publish) ||
      (decision === "CHANGES_REQUESTED" &&
        workflow.viewer_context.mode === "OWNER_REVIEW" &&
        workflow.actions.can_request_changes) ||
      (decision === "APPROVE_NO_WORK" &&
        workflow.viewer_context.mode === "OWNER_REVIEW" &&
        workflow.active_entry_count === 0 &&
        workflow.actions.can_approve_no_work);
    if (!allowed) return;

    setDraft((current) =>
      setWeeklyPlanV3OwnerDraftDecision(
        current,
        workflow.assignee.id,
        decision,
      ),
    );
    setConfirming(false);
    setRecoveryState(null);
    setError(null);
  };

  const confirm = async () => {
    if ((decisions.length === 0 && pending === null) || submitting.current) {
      return;
    }

    const resolution = resolveWeeklyPlanV3BatchConfirmation(
      pending,
      plan.id,
      "OWNER",
      decisions,
      requestId,
    );
    const confirmation: WeeklyPlanV3OwnerPendingConfirmation = pending ?? {
      ...resolution.confirmation,
      taskCountPreview: draftSummary.taskCount,
    };
    onPendingChange(confirmation);
    submitting.current = true;
    setBusy(true);
    setError(null);
    setRecoveryState(null);
    try {
      const result = await confirmWeeklyPlanOwnerBatch(
        confirmation.planId,
        confirmation.decisions,
        confirmation.requestId,
      );
      onPlanChange(result.plan);
      setDraft((current) =>
        clearConfirmedWeeklyPlanV3OwnerDraft(current, result.results),
      );
      setConfirming(false);
      onPendingChange(null);
      setRecoveryState(resolution.reconciliationRequired ? "RECONCILED" : null);
    } catch (nextError) {
      const mode = weeklyPlanV3BatchFailureMode(nextError);
      const validation =
        nextError instanceof ApiServiceError
          ? Object.values(nextError.validationErrors ?? {})[0]?.[0]
          : undefined;
      if (mode !== "AMBIGUOUS") onPendingChange(null);
      setRecoveryState(mode);
      setError(
        validation ??
          (nextError instanceof ApiServiceError && nextError.status === 409
            ? nextError.message
            : t(`recovery.${mode}`)),
      );
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };

  const reload = async () => {
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      await onReload();
      setRecoveryState(null);
    } catch {
      setError(t("recovery.reloadFailed"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section aria-labelledby="weekly-plan-v3-owner-title" className="space-y-4">
      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
        <h2
          id="weekly-plan-v3-owner-title"
          className="font-semibold text-gray-900 dark:text-white"
        >
          {t("title")}
        </h2>
        <p className="mt-1 text-theme-sm text-gray-600 dark:text-gray-300">
          {t("description")}
        </p>
      </div>

      <div className="space-y-3">
        {workflows.map((workflow) => {
          const selected = draft[workflow.assignee.id];
          const entries = entriesByAssignee.get(workflow.assignee.id) ?? [];
          const comments = plan.comments.filter(
            (comment) =>
              comment.assignee_id === workflow.assignee.id ||
              entries.some((entry) => entry.id === comment.entry_id),
          );

          return (
            <WeeklyPlanV3EmployeeGroup
              key={workflow.id}
              workflow={workflow}
              entries={entries}
              comments={comments}
              expanded={expandedId === workflow.id || workflows.length === 1}
              draftLabel={selected ? t(`draft.${selected}`) : null}
              onToggle={() =>
                setExpandedId((current) =>
                  current === workflow.id ? null : workflow.id,
                )
              }
              actions={
                <div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                  <p className="text-theme-sm font-semibold text-gray-900 dark:text-white">
                    {t("decisionTitle")}
                  </p>
                  <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    {t("decisionDescription")}
                  </p>
                  <div className="mt-3 flex flex-wrap justify-end gap-2">
                    {workflow.actions.can_request_changes && (
                      <Button
                        type="button"
                        size="sm"
                        variant={
                          selected === "CHANGES_REQUESTED"
                            ? "primary"
                            : "outline"
                        }
                        disabled={busy || pending !== null}
                        onClick={() =>
                          updateDecision(workflow, "CHANGES_REQUESTED")
                        }
                      >
                        {t("requestChanges")}
                      </Button>
                    )}
                    {workflow.active_entry_count > 0 &&
                      workflow.actions.can_publish && (
                        <Button
                          type="button"
                          size="sm"
                          variant={
                            selected === "ACCEPTED" ? "primary" : "outline"
                          }
                          disabled={busy || pending !== null}
                          onClick={() => updateDecision(workflow, "ACCEPTED")}
                        >
                          {t("publish")}
                        </Button>
                      )}
                    {workflow.active_entry_count === 0 &&
                      workflow.actions.can_approve_no_work && (
                        <Button
                          type="button"
                          size="sm"
                          variant={
                            selected === "APPROVE_NO_WORK"
                              ? "primary"
                              : "outline"
                          }
                          disabled={busy || pending !== null}
                          onClick={() =>
                            updateDecision(workflow, "APPROVE_NO_WORK")
                          }
                        >
                          {t("approveNoWork")}
                        </Button>
                      )}
                    {selected && (
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={busy || pending !== null}
                        onClick={() => updateDecision(workflow, null)}
                      >
                        {t("clear")}
                      </Button>
                    )}
                  </div>
                </div>
              }
            />
          );
        })}
      </div>

      {workflows.length === 0 && (
        <p className="rounded-xl bg-gray-50 p-4 text-theme-sm text-gray-600 dark:bg-gray-800/60 dark:text-gray-400">
          {t("empty")}
        </p>
      )}

      {error && (
        <p
          role="alert"
          className="rounded-xl bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
        >
          {error}
        </p>
      )}

      {(decisions.length > 0 || pending !== null) && !confirming && (
        <div className="flex justify-end">
          <Button
            type="button"
            disabled={busy}
            onClick={() => setConfirming(true)}
          >
            {pending
              ? t("recovery.retry")
              : t("reviewBatch", { count: decisions.length })}
          </Button>
        </div>
      )}

      {confirming && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="weekly-plan-v3-owner-confirm-title"
          className="rounded-2xl border border-kps-primary/20 bg-kps-primary/[0.04] p-5 dark:border-kps-primary/30 dark:bg-kps-primary/10"
        >
          <h3
            id="weekly-plan-v3-owner-confirm-title"
            className="font-semibold text-gray-900 dark:text-white"
          >
            {t("confirmation.title")}
          </h3>
          <p className="mt-2 text-theme-sm text-gray-700 dark:text-gray-300">
            {t("confirmation.summary", {
              total: confirmationSummary.total,
              accepted: confirmationSummary.accepted,
              tasks: confirmationTaskCount,
              changesRequested: confirmationSummary.changesRequested,
              noWorkApproved: confirmationSummary.noWorkApproved,
            })}
          </p>
          <ul className="mt-3 space-y-1 text-theme-xs text-gray-600 dark:text-gray-300">
            {confirmationDecisions.map((decision) => (
              <li key={decision.assignee_id}>
                {workflows.find(
                  (workflow) => workflow.assignee.id === decision.assignee_id,
                )?.assignee.name ?? `#${decision.assignee_id}`}{" "}
                {" — "}
                {t(`payload.${decision.decision}`)}
                {" · "}
                {t("confirmation.versions", {
                  state: decision.expected_state_version,
                  content: decision.expected_content_version,
                })}
              </li>
            ))}
          </ul>
          <p className="mt-2 text-theme-sm font-medium text-warning-700 dark:text-warning-400">
            {t("confirmation.effects")}
          </p>
          <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
            {t("confirmation.unselected")}
          </p>
          <div className="mt-4 flex flex-wrap justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              disabled={busy}
              onClick={() => setConfirming(false)}
            >
              {t("confirmation.cancel")}
            </Button>
            <Button
              type="button"
              disabled={busy}
              onClick={() => void confirm()}
            >
              {busy
                ? t("confirmation.submitting")
                : pending
                  ? t("recovery.retry")
                  : t("confirmation.submit")}
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
          <div className="mt-3 flex flex-wrap justify-end gap-2">
            {recoveryState === "AMBIGUOUS" && (
              <Button
                type="button"
                disabled={busy}
                onClick={() => void confirm()}
              >
                {t("recovery.retry")}
              </Button>
            )}
            {recoveryState === "STALE" && (
              <Button
                type="button"
                variant="outline"
                disabled={busy}
                onClick={() => void reload()}
              >
                {t("recovery.reload")}
              </Button>
            )}
          </div>
        </div>
      )}
    </section>
  );
}
