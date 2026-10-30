import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import WeeklyPlanCommentContext from "@/components/weekly-plans/WeeklyPlanCommentContext";
import WeeklyPlanDiscussionTargetFields from "@/components/weekly-plans/WeeklyPlanDiscussionTargetFields";
import WeeklyPlanReviewDecisionModal, {
  type WeeklyPlanDecisionConfirmation,
} from "@/components/weekly-plans/WeeklyPlanReviewDecisionModal";
import WeeklyPlanReviewEmployeeGroup from "@/components/weekly-plans/WeeklyPlanReviewEmployeeGroup";
import WeeklyPlanReviewFinalModal, {
  type WeeklyPlanReviewFinalAction,
} from "@/components/weekly-plans/WeeklyPlanReviewFinalModal";
import WeeklyPlanStatusBadge from "@/components/weekly-plans/WeeklyPlanStatusBadge";
import { useModal } from "@/hooks/useModal";
import { ApiServiceError } from "@/services/apiClient";
import {
  addWeeklyPlanComment,
  approveWeeklyPlan,
  decideWeeklyPlanReview,
  finalizeAssistantWeeklyPlanReview,
  publishWeeklyPlan,
} from "@/services/weeklyPlanningService";
import type { WeeklyPlan } from "@/types/weeklyPlanning";
import { formatDateOnly } from "@/utils/dateOnly";
import { formatWeeklyPlanCommentTimestamp } from "@/utils/weeklyPlanCommentContext";
import {
  isWeeklyPlanDiscussionEmployeeConsistent,
  resolveWeeklyPlanCommentTargetArguments,
} from "@/utils/weeklyPlanDiscussionTarget";
import {
  activeWeeklyPlanReviewStage,
  activeWeeklyPlanReviewSummary,
  canDecideWeeklyPlanReview,
  createWeeklyPlanReviewEmployeeGroups,
  isWeeklyPlanWaitingForHr,
  submitWeeklyPlanChangeRequest,
} from "@/utils/weeklyPlanReviewWorkspace";
import { useCallback, useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

type Props = {
  plan: WeeklyPlan;
  onPlanChange: (plan: WeeklyPlan) => void;
  onAssistantCompleted: () => void;
};

export default function WeeklyPlanReviewWorkspace({
  plan,
  onPlanChange,
  onAssistantCompleted,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.reviewWorkspace",
  });
  const { t: discussionLabel } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.discussion",
  });
  const { t: eventLabel } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.events",
  });
  const decisionModal = useModal();
  const finalModal = useModal();
  const groups = useMemo(
    () => createWeeklyPlanReviewEmployeeGroups(plan),
    [plan],
  );
  const groupKeys = useMemo(() => groups.map((group) => group.key), [groups]);
  const [expandedGroupKey, setExpandedGroupKey] = useState<string | null>(() =>
    groupKeys.length === 1 ? groupKeys[0] : null,
  );
  const [decisionConfirmation, setDecisionConfirmation] =
    useState<WeeklyPlanDecisionConfirmation | null>(null);
  const [finalAction, setFinalAction] =
    useState<WeeklyPlanReviewFinalAction | null>(null);
  const [changeNote, setChangeNote] = useState("");
  const [comment, setComment] = useState("");
  const [selectedEmployeeId, setSelectedEmployeeId] = useState<number | null>(
    null,
  );
  const [selectedEntryId, setSelectedEntryId] = useState<number | null>(null);
  const [commentTargetError, setCommentTargetError] = useState<string | null>(
    null,
  );
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const stage = activeWeeklyPlanReviewStage(plan);
  const summary = activeWeeklyPlanReviewSummary(plan);
  const waitingForHr = isWeeklyPlanWaitingForHr(plan.status);
  const activeExpandedGroupKey =
    expandedGroupKey && groupKeys.includes(expandedGroupKey)
      ? expandedGroupKey
      : groupKeys.length === 1
        ? groupKeys[0]
        : null;

  const actionError = useCallback(
    (nextError: unknown) => {
      const validation =
        nextError instanceof ApiServiceError
          ? Object.values(nextError.validationErrors ?? {})[0]?.[0]
          : undefined;
      return (
        validation ??
        (nextError instanceof ApiServiceError && nextError.status === 409
          ? nextError.message
          : t("errors.action"))
      );
    },
    [t],
  );

  const perform = async (action: () => Promise<WeeklyPlan>) => {
    if (busy) return null;
    setBusy(true);
    setError(null);
    try {
      const nextPlan = await action();
      onPlanChange(nextPlan);
      return nextPlan;
    } catch (nextError) {
      setError(actionError(nextError));
      return null;
    } finally {
      setBusy(false);
    }
  };

  const closeDecisionModal = () => {
    if (busy) return;
    decisionModal.closeModal();
    setDecisionConfirmation(null);
    setChangeNote("");
    setError(null);
  };

  const openDecisionModal = (
    decision: WeeklyPlanDecisionConfirmation["decision"],
    assigneeId: number,
    employeeName: string,
  ) => {
    setDecisionConfirmation({ decision, assigneeId, employeeName });
    setChangeNote("");
    setError(null);
    decisionModal.openModal();
  };

  const confirmDecision = async () => {
    if (!decisionConfirmation || busy) return;

    setBusy(true);
    setError(null);
    try {
      const nextPlan =
        decisionConfirmation.decision === "CHANGES_REQUESTED"
          ? await submitWeeklyPlanChangeRequest({
              note: changeNote,
              postComment: (content) =>
                addWeeklyPlanComment(
                  plan.id,
                  content,
                  decisionConfirmation.assigneeId,
                ),
              postDecision: () =>
                decideWeeklyPlanReview(
                  plan.id,
                  decisionConfirmation.assigneeId,
                  "CHANGES_REQUESTED",
                ),
            })
          : await decideWeeklyPlanReview(
              plan.id,
              decisionConfirmation.assigneeId,
              "ACCEPTED",
            );
      onPlanChange(nextPlan);
      decisionModal.closeModal();
      setDecisionConfirmation(null);
      setChangeNote("");
    } catch (nextError) {
      setError(actionError(nextError));
    } finally {
      setBusy(false);
    }
  };

  const handleAddComment = async () => {
    const target = resolveWeeklyPlanCommentTargetArguments(plan, {
      selectedEmployeeId,
      selectedEntryId,
    });
    if (!target.valid) {
      if (
        !isWeeklyPlanDiscussionEmployeeConsistent(plan, {
          selectedEmployeeId,
        })
      ) {
        setSelectedEmployeeId(null);
      }
      setSelectedEntryId(null);
      setCommentTargetError(discussionLabel("targetChanged"));
      return;
    }

    setCommentTargetError(null);
    const nextPlan = await perform(() =>
      addWeeklyPlanComment(
        plan.id,
        comment.trim(),
        target.assigneeId,
        target.entryId,
      ),
    );
    if (nextPlan) setComment("");
  };

  const openFinalModal = (action: WeeklyPlanReviewFinalAction) => {
    setFinalAction(action);
    setError(null);
    finalModal.openModal();
  };

  const closeFinalModal = () => {
    if (busy) return;
    finalModal.closeModal();
    setFinalAction(null);
    setError(null);
  };

  const confirmFinalAction = async () => {
    if (!finalAction) return;

    const nextPlan = await perform(() => {
      if (finalAction === "FINALIZE_ASSISTANT") {
        return finalizeAssistantWeeklyPlanReview(plan.id);
      }
      if (finalAction === "APPROVE") return approveWeeklyPlan(plan.id);
      return publishWeeklyPlan(plan.id);
    });
    if (!nextPlan) return;

    finalModal.closeModal();
    setFinalAction(null);
    if (
      finalAction === "FINALIZE_ASSISTANT" &&
      !nextPlan.actions.can_owner_review
    ) {
      onAssistantCompleted();
    }
  };

  return (
    <div className="space-y-5">
      <section className="rounded-2xl border border-kps-primary/15 bg-white p-5 shadow-theme-xs dark:border-kps-primary/30 dark:bg-white/3">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 className="text-title-sm font-semibold text-gray-900 dark:text-white">
              {t("header.title")}
            </h1>
            <p className="mt-2 text-base font-semibold text-kps-primary dark:text-white">
              {t("header.period", {
                start: formatDateOnly(plan.week_start),
                end: formatDateOnly(plan.week_end),
              })}
            </p>
            <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
              {t("header.creator", { name: plan.creator.name })}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2 sm:justify-end">
            {stage && (
              <span className="rounded-full bg-kps-primary/[0.08] px-3 py-1 text-theme-xs font-semibold text-kps-primary dark:bg-kps-primary/20 dark:text-white">
                {t(`stage.${stage}`)}
              </span>
            )}
            <WeeklyPlanStatusBadge status={plan.status} />
          </div>
        </div>
      </section>

      {error && (
        <p
          role="alert"
          className="rounded-xl bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
        >
          {error}
        </p>
      )}

      {waitingForHr && (
        <section className="rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-800 dark:bg-warning-500/10">
          <h2 className="font-semibold text-warning-800 dark:text-warning-300">
            {t("waiting.title")}
          </h2>
          <p className="mt-1 text-theme-sm text-warning-700 dark:text-warning-400">
            {t("waiting.description")}
          </p>
        </section>
      )}

      {plan.review_workflow?.current_round ? (
        <>
          <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="font-semibold text-gray-900 dark:text-white">
                  {t("summary.title")}
                </h2>
                <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                  {t("summary.round", {
                    round: plan.review_workflow.current_round.round_number,
                  })}
                </p>
              </div>
              <p className="text-theme-sm font-semibold text-kps-primary dark:text-white">
                {t("summary.total", { count: summary.total })}
              </p>
            </div>
            <dl className="mt-4 grid gap-3 sm:grid-cols-3">
              <div className="rounded-xl bg-success-50 px-4 py-3 dark:bg-success-500/10">
                <dt className="text-theme-xs text-success-700 dark:text-success-400">
                  {t("summary.accepted")}
                </dt>
                <dd className="mt-1 text-xl font-bold text-success-700 dark:text-success-400">
                  {summary.accepted}
                </dd>
              </div>
              <div className="rounded-xl bg-warning-50 px-4 py-3 dark:bg-warning-500/10">
                <dt className="text-theme-xs text-warning-700 dark:text-warning-400">
                  {t("summary.changesRequested")}
                </dt>
                <dd className="mt-1 text-xl font-bold text-warning-700 dark:text-warning-400">
                  {summary.changes_requested}
                </dd>
              </div>
              <div className="rounded-xl bg-gray-100 px-4 py-3 dark:bg-gray-800">
                <dt className="text-theme-xs text-gray-600 dark:text-gray-400">
                  {t("summary.pending")}
                </dt>
                <dd className="mt-1 text-xl font-bold text-gray-700 dark:text-gray-300">
                  {summary.pending}
                </dd>
              </div>
            </dl>
          </section>

          <section aria-labelledby="weekly-review-employees-title">
            <h2
              id="weekly-review-employees-title"
              className="mb-3 font-semibold text-gray-900 dark:text-white"
            >
              {t("employee.title")}
            </h2>
            <div className="space-y-3">
              {groups.map((group) => (
                <WeeklyPlanReviewEmployeeGroup
                  key={group.key}
                  group={group}
                  expanded={activeExpandedGroupKey === group.key}
                  canDecide={canDecideWeeklyPlanReview(
                    plan,
                    group.decision.status,
                  )}
                  busy={busy}
                  onToggle={() =>
                    setExpandedGroupKey(
                      activeExpandedGroupKey === group.key ? null : group.key,
                    )
                  }
                  onAccept={() =>
                    openDecisionModal(
                      "ACCEPTED",
                      group.assigneeId,
                      group.employeeName,
                    )
                  }
                  onRequestChanges={() =>
                    openDecisionModal(
                      "CHANGES_REQUESTED",
                      group.assigneeId,
                      group.employeeName,
                    )
                  }
                />
              ))}
            </div>
          </section>
        </>
      ) : !["APPROVED", "PUBLISHED"].includes(plan.status) ? (
        <section className="rounded-2xl border border-warning-200 bg-warning-50 p-5 text-theme-sm text-warning-700 dark:border-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
          {t("legacyUnavailable")}
        </section>
      ) : null}

      <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
        <h2 className="font-semibold text-gray-900 dark:text-white">
          {t("discussion.title")}
        </h2>
        <p className="mt-1 text-theme-sm font-medium text-gray-600 dark:text-gray-300">
          {t("discussion.neutralHelp")}
        </p>
        <div className="mt-4 space-y-3">
          {plan.comments.map((item) => (
            <article
              key={item.id}
              className="rounded-xl bg-gray-50 p-3 dark:bg-gray-800/60"
            >
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-theme-sm font-semibold text-gray-900 dark:text-white">
                  {item.author.name}
                  {formatWeeklyPlanCommentTimestamp(item.created_at) && (
                    <span className="font-normal text-gray-500 dark:text-gray-400">
                      {" · "}
                      {formatWeeklyPlanCommentTimestamp(item.created_at)}
                    </span>
                  )}
                </p>
                <WeeklyPlanCommentContext plan={plan} comment={item} />
              </div>
              <p className="mt-1 text-theme-sm whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                {item.content}
              </p>
            </article>
          ))}
          {plan.comments.length === 0 && (
            <p className="text-theme-sm text-gray-500 dark:text-gray-400">
              {t("discussion.empty")}
            </p>
          )}
        </div>

        {plan.actions.can_comment && (
          <div className="mt-5 space-y-4 border-t border-gray-100 pt-4 dark:border-gray-800">
            <WeeklyPlanDiscussionTargetFields
              idPrefix="review-comment-target"
              plan={plan}
              value={{ selectedEmployeeId, selectedEntryId }}
              onChange={(target) => {
                setSelectedEmployeeId(target.selectedEmployeeId);
                setSelectedEntryId(target.selectedEntryId);
                setCommentTargetError(null);
              }}
              disabled={busy}
            />
            <div>
              <Label htmlFor="review-comment-content">
                {discussionLabel("contentLabel")}
              </Label>
              <TextArea
                id="review-comment-content"
                rows={3}
                value={comment}
                placeholder={discussionLabel("contentPlaceholder")}
                onChange={setComment}
                disabled={busy}
              />
            </div>
            {commentTargetError && (
              <p role="alert" className="text-theme-sm text-error-500">
                {commentTargetError}
              </p>
            )}
            <div className="flex justify-end">
              <Button
                type="button"
                variant="outline"
                disabled={busy || !comment.trim()}
                onClick={() => void handleAddComment()}
              >
                {t("discussion.add")}
              </Button>
            </div>
          </div>
        )}
      </section>

      <section className="rounded-2xl border border-kps-primary/20 bg-kps-primary/[0.04] p-5 dark:border-kps-primary/30 dark:bg-kps-primary/10">
        <h2 className="font-semibold text-gray-900 dark:text-white">
          {t("final.title")}
        </h2>
        <div className="mt-4 flex flex-wrap items-center gap-3">
          {plan.actions.can_finalize_assistant_review && (
            <Button
              type="button"
              disabled={busy}
              className="bg-kps-primary hover:bg-kps-primary-hover"
              onClick={() => openFinalModal("FINALIZE_ASSISTANT")}
            >
              {t("final.finalizeAssistant")}
            </Button>
          )}
          {plan.actions.can_approve && (
            <Button
              type="button"
              disabled={busy}
              className="bg-kps-primary hover:bg-kps-primary-hover"
              onClick={() => openFinalModal("APPROVE")}
            >
              {t("final.approve")}
            </Button>
          )}
          {plan.actions.can_publish && (
            <Button
              type="button"
              disabled={busy}
              className="bg-kps-primary hover:bg-kps-primary-hover"
              onClick={() => openFinalModal("PUBLISH")}
            >
              {t("final.publish")}
            </Button>
          )}
          {!plan.actions.can_finalize_assistant_review &&
            !plan.actions.can_approve &&
            !plan.actions.can_publish && (
              <p className="text-theme-sm text-gray-600 dark:text-gray-300">
                {t("final.unavailable")}
              </p>
            )}
        </div>
      </section>

      <details className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
        <summary className="cursor-pointer font-semibold text-gray-900 dark:text-white">
          {t("history.title")}
        </summary>
        <ol className="mt-4 space-y-3">
          {plan.events.map((event) => (
            <li
              key={event.id}
              className="rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-800/60"
            >
              <p className="text-theme-sm font-medium text-gray-900 dark:text-white">
                {eventLabel(event.type, {
                  defaultValue: t("history.unknownEvent"),
                })}
              </p>
              <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                {event.actor?.name ?? t("history.systemActor")}
                {formatWeeklyPlanCommentTimestamp(event.created_at) &&
                  ` · ${formatWeeklyPlanCommentTimestamp(event.created_at)}`}
              </p>
            </li>
          ))}
        </ol>
      </details>

      <div className="flex justify-start">
        <Link
          to="/weekly-plans"
          className="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2.5 text-theme-sm font-semibold text-gray-700 transition hover:bg-gray-50 focus-visible:ring-3 focus-visible:ring-kps-primary/25 focus-visible:outline-none dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
        >
          {t("back")}
        </Link>
      </div>

      <WeeklyPlanReviewDecisionModal
        isOpen={decisionModal.isOpen}
        confirmation={decisionConfirmation}
        note={changeNote}
        busy={busy}
        error={error}
        onNoteChange={setChangeNote}
        onClose={closeDecisionModal}
        onConfirm={() => void confirmDecision()}
      />

      <WeeklyPlanReviewFinalModal
        isOpen={finalModal.isOpen}
        action={finalAction}
        summary={summary}
        workCount={plan.entries.length}
        busy={busy}
        onClose={closeFinalModal}
        onConfirm={() => void confirmFinalAction()}
      />
    </div>
  );
}
