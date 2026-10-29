import ComponentCard from "@/components/common/ComponentCard";
import TextArea from "@/components/form/input/TextArea";
import Label from "@/components/form/Label";
import Button from "@/components/ui/button/Button";
import WeeklyPlanCommentContext from "@/components/weekly-plans/WeeklyPlanCommentContext";
import WeeklyPlanDiscussionTargetFields from "@/components/weekly-plans/WeeklyPlanDiscussionTargetFields";
import WeeklyPlanWizardLayout from "@/components/weekly-plans/WeeklyPlanWizardLayout";
import { useWeeklyPlanWizard } from "@/context/WeeklyPlanWizardContext";
import {
  addWeeklyPlanComment,
  submitWeeklyPlan,
} from "@/services/weeklyPlanningService";
import { formatWeeklyPlanCommentTimestamp } from "@/utils/weeklyPlanCommentContext";
import {
  isWeeklyPlanDiscussionEmployeeConsistent,
  resolveWeeklyPlanCommentTargetArguments,
} from "@/utils/weeklyPlanDiscussionTarget";
import { weeklyPlanWorkCounts } from "@/utils/weeklyPlanWizard";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

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
  const { t: eventLabel } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.events",
  });
  const { t: discussionLabel } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.discussion",
  });
  const { plan, busy, perform } = useWeeklyPlanWizard();
  const navigate = useNavigate();
  const [comment, setComment] = useState("");
  const [selectedEmployeeId, setSelectedEmployeeId] = useState<number | null>(
    null,
  );
  const [selectedEntryId, setSelectedEntryId] = useState<number | null>(null);
  const [commentTargetError, setCommentTargetError] = useState<string | null>(
    null,
  );

  if (!plan) {
    return (
      <WeeklyPlanWizardLayout step="submit">{null}</WeeklyPlanWizardLayout>
    );
  }

  const counts = weeklyPlanWorkCounts(plan);
  const submitted =
    plan.status !== "DRAFT" && plan.status !== "CHANGES_REQUESTED";

  const handleAddComment = () => {
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
    void perform(async () => {
      const result = await addWeeklyPlanComment(
        plan.id,
        comment.trim(),
        target.assigneeId,
        target.entryId,
      );
      setComment("");
      return result;
    });
  };

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
          <div className="rounded-xl bg-success-50 p-4 text-success-700 dark:bg-success-500/10 dark:text-success-400">
            <p className="font-semibold">{t("submit.submitted")}</p>
            {plan.submitted_at && (
              <p className="mt-1 text-theme-sm">
                {formatTimestamp(plan.submitted_at)}
              </p>
            )}
          </div>
        ) : (
          plan.actions.can_submit && (
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

        {plan.actions.can_review && (
          <div className="flex justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => navigate(`/weekly-plans/${plan.id}/review`)}
            >
              {t("submit.openReview")}
            </Button>
          </div>
        )}
      </ComponentCard>

      <details
        data-section="weekly-plan-discussion"
        className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3"
      >
        <summary className="cursor-pointer font-semibold text-gray-900 dark:text-white">
          {t("submit.comments")}
        </summary>
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
              <p className="mt-1 text-theme-sm whitespace-pre-wrap text-gray-600 dark:text-gray-300">
                {item.content}
              </p>
            </article>
          ))}
          {plan.comments.length === 0 && (
            <p className="text-theme-sm text-gray-500 dark:text-gray-400">
              {t("submit.noComments")}
            </p>
          )}
          {plan.actions.can_comment && (
            <>
              <div className="space-y-4">
                <p className="text-theme-sm font-medium text-gray-700 dark:text-gray-300">
                  {t("submit.commentTarget")}
                </p>
                <WeeklyPlanDiscussionTargetFields
                  idPrefix="wizard-comment-target"
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
                  <Label htmlFor="wizard-comment-content">
                    {discussionLabel("contentLabel")}
                  </Label>
                  <TextArea
                    id="wizard-comment-content"
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
              </div>
              <div className="flex justify-end">
                <Button
                  type="button"
                  disabled={busy || !comment.trim()}
                  className="bg-kps-primary hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none"
                  onClick={handleAddComment}
                >
                  {t("submit.addComment")}
                </Button>
              </div>
            </>
          )}
        </div>
      </details>

      <details
        data-section="weekly-plan-history"
        className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3"
      >
        <summary className="cursor-pointer font-semibold text-gray-900 dark:text-white">
          {t("submit.history")}
        </summary>
        <ol className="mt-4 space-y-3">
          {plan.events.map((event) => (
            <li
              key={event.id}
              className="rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-800/60"
            >
              <p className="text-theme-sm font-medium text-gray-900 dark:text-white">
                {eventLabel(event.type, { defaultValue: event.type })}
              </p>
              <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                {event.actor?.name ?? t("submit.systemActor")} ·{" "}
                {formatTimestamp(event.created_at)}
              </p>
            </li>
          ))}
        </ol>
      </details>
    </WeeklyPlanWizardLayout>
  );
}
