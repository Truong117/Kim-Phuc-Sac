import Button from "@/components/ui/button/Button";
import { ApiServiceError } from "@/services/apiClient";
import { confirmWeeklyPlanAssistantBatch } from "@/services/weeklyPlanningService";
import type {
  WeeklyPlan,
  WeeklyPlanEmployeeWorkflow,
} from "@/types/weeklyPlanning";
import {
  buildWeeklyPlanV3AssistantBatch,
  clearConfirmedWeeklyPlanV3AssistantDraft,
  setWeeklyPlanV3AssistantDraftDecision,
  summarizeWeeklyPlanV3AssistantDraft,
  type WeeklyPlanV3AssistantDraft,
  type WeeklyPlanV3AssistantDraftDecision,
} from "@/utils/weeklyPlanV3AssistantBatch";
import { useMemo, useRef, useState } from "react";
import { useTranslation } from "react-i18next";

type Props = {
  plan: WeeklyPlan;
  onPlanChange: (plan: WeeklyPlan) => void;
};

const requestId = (): string => globalThis.crypto.randomUUID();

export default function WeeklyPlanV3AssistantBatchReview({
  plan,
  onPlanChange,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.reviewWorkspace.v3Assistant",
  });
  const [draft, setDraft] = useState<WeeklyPlanV3AssistantDraft>({});
  const [confirming, setConfirming] = useState(false);
  const [idempotencyKey, setIdempotencyKey] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const submitting = useRef(false);
  const workflows = plan.employee_workflows ?? [];
  const decisions = useMemo(
    () => buildWeeklyPlanV3AssistantBatch(plan, draft),
    [draft, plan],
  );
  const summary = useMemo(
    () => summarizeWeeklyPlanV3AssistantDraft(decisions),
    [decisions],
  );

  const updateDecision = (
    workflow: WeeklyPlanEmployeeWorkflow,
    decision: WeeklyPlanV3AssistantDraftDecision | null,
  ) => {
    if (busy) return;
    if (
      (decision === "ACCEPTED" && !workflow.actions.can_accept) ||
      (decision === "CHANGES_REQUESTED" &&
        !workflow.actions.can_request_changes)
    ) {
      return;
    }

    setDraft((current) =>
      setWeeklyPlanV3AssistantDraftDecision(
        current,
        workflow.assignee.id,
        decision,
      ),
    );
    setConfirming(false);
    setIdempotencyKey(null);
    setError(null);
  };

  const openConfirmation = () => {
    if (decisions.length === 0 || busy) return;
    setIdempotencyKey((current) => current ?? requestId());
    setConfirming(true);
    setError(null);
  };

  const closeConfirmation = () => {
    if (busy) return;
    setConfirming(false);
  };

  const confirm = async () => {
    if (
      decisions.length === 0 ||
      idempotencyKey === null ||
      submitting.current
    ) {
      return;
    }

    submitting.current = true;
    setBusy(true);
    setError(null);
    try {
      const result = await confirmWeeklyPlanAssistantBatch(
        plan.id,
        decisions,
        idempotencyKey,
      );
      onPlanChange(result.plan);
      setDraft((current) =>
        clearConfirmedWeeklyPlanV3AssistantDraft(current, result.results),
      );
      setConfirming(false);
      setIdempotencyKey(null);
    } catch (nextError) {
      const validation =
        nextError instanceof ApiServiceError
          ? Object.values(nextError.validationErrors ?? {})[0]?.[0]
          : undefined;
      setError(
        validation ??
          (nextError instanceof ApiServiceError && nextError.status === 409
            ? nextError.message
            : t("errors.submit")),
      );
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };

  return (
    <section
      aria-labelledby="weekly-plan-v3-assistant-title"
      className="space-y-4"
    >
      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
        <h2
          id="weekly-plan-v3-assistant-title"
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
          const entries = plan.entries.filter(
            (entry) => entry.assignee?.id === workflow.assignee.id,
          );
          const actionable =
            workflow.actions.can_accept || workflow.actions.can_request_changes;

          return (
            <article
              key={workflow.id}
              className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3"
            >
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h3 className="font-semibold text-gray-900 dark:text-white">
                    {workflow.assignee.name}
                  </h3>
                  <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    {t("counts", {
                      entries: workflow.active_entry_count,
                      items: workflow.active_child_item_count,
                    })}
                  </p>
                </div>
                <span className="rounded-full bg-gray-100 px-3 py-1 text-theme-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                  {selected
                    ? t(`draft.${selected}`)
                    : t(`state.${workflow.state}`)}
                </span>
              </div>

              {entries.length > 0 ? (
                <ul className="mt-4 space-y-2">
                  {entries.map((entry) => (
                    <li
                      key={entry.id}
                      className="rounded-xl bg-gray-50 px-3 py-2 text-theme-sm text-gray-700 dark:bg-gray-800/60 dark:text-gray-300"
                    >
                      {entry.title}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="mt-4 rounded-xl bg-gray-50 px-3 py-2 text-theme-sm text-gray-600 dark:bg-gray-800/60 dark:text-gray-400">
                  {t("zeroWork")}
                </p>
              )}

              {actionable ? (
                <div className="mt-4 flex flex-wrap gap-2">
                  {workflow.actions.can_accept && (
                    <Button
                      type="button"
                      size="sm"
                      variant={selected === "ACCEPTED" ? "primary" : "outline"}
                      disabled={busy}
                      onClick={() => updateDecision(workflow, "ACCEPTED")}
                    >
                      {t("accept")}
                    </Button>
                  )}
                  {workflow.actions.can_request_changes && (
                    <Button
                      type="button"
                      size="sm"
                      variant={
                        selected === "CHANGES_REQUESTED" ? "primary" : "outline"
                      }
                      disabled={busy}
                      onClick={() =>
                        updateDecision(workflow, "CHANGES_REQUESTED")
                      }
                    >
                      {t("requestChanges")}
                    </Button>
                  )}
                  {selected && (
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={busy}
                      onClick={() => updateDecision(workflow, null)}
                    >
                      {t("clear")}
                    </Button>
                  )}
                </div>
              ) : (
                <p className="mt-4 text-theme-xs text-gray-500 dark:text-gray-400">
                  {t("readOnly")}
                </p>
              )}
            </article>
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

      {decisions.length > 0 && !confirming && (
        <div className="flex justify-end">
          <Button type="button" disabled={busy} onClick={openConfirmation}>
            {t("reviewBatch", { count: decisions.length })}
          </Button>
        </div>
      )}

      {confirming && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="weekly-plan-v3-confirm-title"
          className="rounded-2xl border border-kps-primary/20 bg-kps-primary/[0.04] p-5 dark:border-kps-primary/30 dark:bg-kps-primary/10"
        >
          <h3
            id="weekly-plan-v3-confirm-title"
            className="font-semibold text-gray-900 dark:text-white"
          >
            {t("confirmation.title")}
          </h3>
          <p className="mt-2 text-theme-sm text-gray-700 dark:text-gray-300">
            {t("confirmation.summary", {
              total: summary.total,
              accepted: summary.accepted,
              changesRequested: summary.changesRequested,
            })}
          </p>
          <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
            {t("confirmation.unselected")}
          </p>
          <div className="mt-4 flex flex-wrap justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              disabled={busy}
              onClick={closeConfirmation}
            >
              {t("confirmation.cancel")}
            </Button>
            <Button
              type="button"
              disabled={busy}
              onClick={() => void confirm()}
            >
              {busy ? t("confirmation.submitting") : t("confirmation.submit")}
            </Button>
          </div>
        </div>
      )}
    </section>
  );
}
