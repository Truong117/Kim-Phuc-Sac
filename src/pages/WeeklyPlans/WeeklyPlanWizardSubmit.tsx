import ComponentCard from "@/components/common/ComponentCard";
import TextArea from "@/components/form/input/TextArea";
import Label from "@/components/form/Label";
import Button from "@/components/ui/button/Button";
import WeeklyPlanDiscussionScopePicker from "@/components/weekly-plans/WeeklyPlanDiscussionScopePicker";
import WeeklyPlanWizardLayout from "@/components/weekly-plans/WeeklyPlanWizardLayout";
import { useWeeklyPlanWizard } from "@/context/WeeklyPlanWizardContext";
import {
  addWeeklyPlanComment,
  submitWeeklyPlan,
} from "@/services/weeklyPlanningService";
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
  const { plan, busy, perform } = useWeeklyPlanWizard();
  const navigate = useNavigate();
  const [comment, setComment] = useState("");
  const [commentTarget, setCommentTarget] = useState("plan");

  if (!plan) {
    return (
      <WeeklyPlanWizardLayout step="submit">{null}</WeeklyPlanWizardLayout>
    );
  }

  const counts = weeklyPlanWorkCounts(plan);
  const submitted =
    plan.status !== "DRAFT" && plan.status !== "CHANGES_REQUESTED";

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
              <p className="text-theme-sm font-semibold text-gray-900 dark:text-white">
                {item.author.name}
              </p>
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
              <div>
                <Label htmlFor="wizard-comment-target">
                  {t("submit.commentTarget")}
                </Label>
                <WeeklyPlanDiscussionScopePicker
                  id="wizard-comment-target"
                  plan={plan}
                  value={commentTarget}
                  onChange={setCommentTarget}
                  disabled={busy}
                />
              </div>
              <TextArea
                rows={3}
                value={comment}
                placeholder={t("submit.commentPlaceholder")}
                onChange={setComment}
              />
              <div className="flex justify-end">
                <Button
                  type="button"
                  disabled={busy || !comment.trim()}
                  className="bg-kps-primary hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none"
                  onClick={() =>
                    void perform(async () => {
                      const [targetType, targetId] = commentTarget.split(":");
                      const result = await addWeeklyPlanComment(
                        plan.id,
                        comment.trim(),
                        targetType === "employee"
                          ? Number(targetId)
                          : undefined,
                        targetType === "entry" ? Number(targetId) : undefined,
                      );
                      setComment("");
                      return result;
                    })
                  }
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
