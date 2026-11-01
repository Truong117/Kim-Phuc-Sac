import { WeeklyPlanReviewWorkCard } from "@/components/weekly-plans/WeeklyPlanReviewEmployeeGroup";
import { ChevronDownIcon } from "@/icons";
import type {
  WeeklyPlanComment,
  WeeklyPlanEmployeeWorkflow,
  WeeklyPlanEntry,
} from "@/types/weeklyPlanning";
import { formatWeeklyPlanCommentTimestamp } from "@/utils/weeklyPlanCommentContext";
import type { ReactNode } from "react";
import { useTranslation } from "react-i18next";

type Props = {
  workflow: WeeklyPlanEmployeeWorkflow;
  entries: WeeklyPlanEntry[];
  comments: WeeklyPlanComment[];
  expanded: boolean;
  draftLabel?: string | null;
  actions?: ReactNode;
  onToggle: () => void;
};

const stateStyle: Record<WeeklyPlanEmployeeWorkflow["state"], string> = {
  AWAITING_ASSISTANT:
    "bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/10 dark:text-blue-light-400",
  STAGED_FOR_OWNER:
    "bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300",
  HR_CORRECTION:
    "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400",
  PUBLISHED:
    "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400",
  NO_WORK_APPROVED:
    "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
};

export default function WeeklyPlanV3EmployeeGroup({
  workflow,
  entries,
  comments,
  expanded,
  draftLabel,
  actions,
  onToggle,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.reviewWorkspace.v3.employee",
  });
  const regionId = `weekly-plan-v3-employee-${workflow.id}`;

  return (
    <section className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
      <button
        type="button"
        aria-expanded={expanded}
        aria-controls={regionId}
        onClick={onToggle}
        className="flex w-full flex-col gap-3 p-4 text-start transition hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none focus-visible:ring-inset sm:flex-row sm:items-center sm:justify-between dark:hover:bg-white/3"
      >
        <span className="flex min-w-0 items-center gap-3">
          <ChevronDownIcon
            aria-hidden="true"
            className={`size-5 shrink-0 text-kps-primary transition-transform ${expanded ? "" : "-rotate-90 rtl:rotate-90"}`}
          />
          <span className="min-w-0">
            <span className="block font-semibold text-gray-900 dark:text-white">
              {workflow.assignee.name}
            </span>
            <span className="mt-1 block text-theme-xs text-gray-500 dark:text-gray-400">
              {t("counts", {
                entries: workflow.active_entry_count,
                items: workflow.active_child_item_count,
              })}
            </span>
          </span>
        </span>
        <span className="flex flex-wrap items-center gap-2">
          {draftLabel && (
            <span className="rounded-full bg-kps-primary/[0.08] px-3 py-1 text-theme-xs font-semibold text-kps-primary dark:bg-kps-primary/20 dark:text-white">
              {draftLabel}
            </span>
          )}
          <span
            className={`rounded-full px-3 py-1 text-theme-xs font-semibold ${stateStyle[workflow.state]}`}
          >
            {t(`state.${workflow.state}`)}
          </span>
        </span>
      </button>

      {expanded && (
        <div
          id={regionId}
          className="space-y-4 border-t border-gray-100 bg-gray-50/60 p-4 dark:border-gray-800 dark:bg-gray-800/30"
        >
          <dl className="grid gap-3 text-theme-xs sm:grid-cols-2 lg:grid-cols-5">
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("context")}
              </dt>
              <dd className="mt-1 font-medium text-gray-800 dark:text-gray-200">
                {t(`mode.${workflow.viewer_context.mode}`)}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("correctionOrigin")}
              </dt>
              <dd className="mt-1 font-medium text-gray-800 dark:text-gray-200">
                {workflow.correction_origin_stage
                  ? t(`stage.${workflow.correction_origin_stage}`)
                  : t("notApplicable")}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("publishedBy")}
              </dt>
              <dd className="mt-1 font-medium text-gray-800 dark:text-gray-200">
                {workflow.publication.published_by?.name ?? t("notApplicable")}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("taskCount")}
              </dt>
              <dd className="mt-1 font-medium text-gray-800 dark:text-gray-200">
                {workflow.publication.task_count}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("publishedAt")}
              </dt>
              <dd className="mt-1 font-medium text-gray-800 dark:text-gray-200">
                {workflow.publication.published_at
                  ? formatWeeklyPlanCommentTimestamp(
                      workflow.publication.published_at,
                    )
                  : t("notApplicable")}
              </dd>
            </div>
          </dl>

          {entries.length > 0 ? (
            entries.map((entry) => (
              <WeeklyPlanReviewWorkCard
                key={entry.id}
                entry={entry}
                multiple={entries.length > 1}
              />
            ))
          ) : (
            <div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
              <p className="font-semibold text-gray-900 dark:text-white">
                {t("zeroWorkTitle")}
              </p>
              <p className="mt-1 text-theme-sm text-gray-600 dark:text-gray-300">
                {t("zeroWorkDescription")}
              </p>
            </div>
          )}

          {comments.length > 0 && (
            <details className="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
              <summary className="cursor-pointer text-theme-sm font-semibold text-gray-900 dark:text-white">
                {t("discussion", { count: comments.length })}
              </summary>
              <div className="mt-3 space-y-2">
                {comments.map((comment) => (
                  <p
                    key={comment.id}
                    className="text-theme-sm text-gray-600 dark:text-gray-300"
                  >
                    <span className="font-medium">{comment.author.name}</span>
                    {formatWeeklyPlanCommentTimestamp(comment.created_at) &&
                      ` · ${formatWeeklyPlanCommentTimestamp(comment.created_at)}`}
                    {": "}
                    {comment.content}
                  </p>
                ))}
              </div>
            </details>
          )}

          {actions}
        </div>
      )}
    </section>
  );
}
