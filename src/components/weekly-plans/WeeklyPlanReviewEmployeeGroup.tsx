import Button from "@/components/ui/button/Button";
import WeeklyPlanStatusBadge from "@/components/weekly-plans/WeeklyPlanStatusBadge";
import { ChevronDownIcon } from "@/icons";
import type { WeeklyPlanEntry } from "@/types/weeklyPlanning";
import { formatDateOnly } from "@/utils/dateOnly";
import type { WeeklyPlanReviewEmployeeGroup as ReviewGroup } from "@/utils/weeklyPlanReviewWorkspace";
import { useState } from "react";
import { useTranslation } from "react-i18next";

type Props = {
  group: ReviewGroup;
  expanded: boolean;
  canDecide: boolean;
  busy: boolean;
  onToggle: () => void;
  onAccept: () => void;
  onRequestChanges: () => void;
};

function WorkCard({
  entry,
  multiple,
}: {
  entry: WeeklyPlanEntry;
  multiple: boolean;
}) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.reviewWorkspace",
  });
  const { t: commonLabel } = useTranslation("common");
  const [expanded, setExpanded] = useState(!multiple);
  const regionId = `weekly-review-work-${entry.id}`;

  return (
    <article className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-theme-xs dark:border-gray-700 dark:bg-gray-900">
      <button
        type="button"
        aria-expanded={expanded}
        aria-controls={regionId}
        onClick={() => setExpanded((current) => !current)}
        className="flex w-full flex-col gap-3 p-4 text-start transition-colors hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none focus-visible:ring-inset sm:flex-row sm:items-start sm:justify-between dark:hover:bg-white/3"
      >
        <span className="flex min-w-0 items-start gap-3">
          <ChevronDownIcon
            aria-hidden="true"
            className={`mt-0.5 size-4 shrink-0 text-kps-primary transition-transform ${expanded ? "" : "-rotate-90 rtl:rotate-90"}`}
          />
          <span className="min-w-0">
            <span className="block font-semibold text-gray-900 dark:text-white">
              {entry.title || t("work.untitled")}
            </span>
            <span className="mt-1 block text-theme-xs text-gray-500 dark:text-gray-400">
              {t("work.compactSummary", {
                result: entry.expected_result || t("work.missingValue"),
                start: entry.start_date
                  ? formatDateOnly(entry.start_date)
                  : t("work.missingValue"),
                due: entry.due_date
                  ? formatDateOnly(entry.due_date)
                  : t("work.missingValue"),
              })}
            </span>
          </span>
        </span>
        <span className="flex shrink-0 flex-wrap gap-2 text-theme-xs">
          <span className="rounded-full bg-gray-100 px-2.5 py-1 font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
            {t("work.priority", {
              priority: commonLabel(
                `weeklyPlanning.detail.priorities.${entry.priority ?? "MEDIUM"}`,
              ),
            })}
          </span>
          <span className="rounded-full bg-kps-primary/[0.08] px-2.5 py-1 font-medium text-kps-primary dark:bg-kps-primary/20 dark:text-white">
            {t("work.itemCount", { count: entry.items.length })}
          </span>
        </span>
      </button>

      {expanded && (
        <div
          id={regionId}
          className="space-y-4 border-t border-gray-100 px-4 py-4 dark:border-gray-800"
        >
          {entry.content && (
            <div>
              <h4 className="text-theme-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
                {t("work.content")}
              </h4>
              <p className="mt-1 text-theme-sm whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                {entry.content}
              </p>
            </div>
          )}
          <dl className="grid gap-4 text-theme-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("work.expectedResult")}
              </dt>
              <dd className="mt-1 font-medium text-gray-900 dark:text-white">
                {entry.expected_result || t("work.missingValue")}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("work.startDate")}
              </dt>
              <dd className="mt-1 font-medium text-gray-900 dark:text-white">
                {entry.start_date
                  ? formatDateOnly(entry.start_date)
                  : t("work.missingValue")}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("work.dueDate")}
              </dt>
              <dd className="mt-1 font-medium text-gray-900 dark:text-white">
                {entry.due_date
                  ? formatDateOnly(entry.due_date)
                  : t("work.missingValue")}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500 dark:text-gray-400">
                {t("work.priorityLabel")}
              </dt>
              <dd className="mt-1 font-medium text-gray-900 dark:text-white">
                {commonLabel(
                  `weeklyPlanning.detail.priorities.${entry.priority ?? "MEDIUM"}`,
                )}
              </dd>
            </div>
          </dl>
          {entry.note && (
            <div className="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-800/60">
              <p className="text-theme-xs font-semibold text-gray-500 dark:text-gray-400">
                {t("work.note")}
              </p>
              <p className="mt-1 text-theme-sm whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                {entry.note}
              </p>
            </div>
          )}
          <div>
            <h4 className="text-theme-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
              {t("work.items", { count: entry.items.length })}
            </h4>
            {entry.items.length > 0 ? (
              <ol className="mt-2 list-decimal space-y-2 ps-5">
                {entry.items.map((item) => (
                  <li
                    key={item.id}
                    className="text-theme-sm text-gray-700 dark:text-gray-300"
                  >
                    <span className="font-medium">{item.title}</span>
                    {item.content && (
                      <p className="mt-1 text-theme-xs whitespace-pre-wrap text-gray-500 dark:text-gray-400">
                        {item.content}
                      </p>
                    )}
                  </li>
                ))}
              </ol>
            ) : (
              <p className="mt-2 text-theme-sm text-gray-500 dark:text-gray-400">
                {t("work.noItems")}
              </p>
            )}
          </div>
          {entry.source_passages.length > 0 && (
            <details className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/60">
              <summary className="cursor-pointer text-theme-sm font-semibold text-gray-700 dark:text-gray-300">
                {t("work.sourceInformation")}
              </summary>
              <ul className="mt-2 space-y-2">
                {entry.source_passages.map((passage) => (
                  <li
                    key={passage.id}
                    className="text-theme-xs whitespace-pre-wrap text-gray-600 dark:text-gray-400"
                  >
                    {passage.text}
                  </li>
                ))}
              </ul>
            </details>
          )}
        </div>
      )}
    </article>
  );
}

export default function WeeklyPlanReviewEmployeeGroup({
  group,
  expanded,
  canDecide,
  busy,
  onToggle,
  onAccept,
  onRequestChanges,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.reviewWorkspace",
  });
  const regionId = `weekly-review-employee-${group.assigneeId}`;

  return (
    <section
      className={`overflow-hidden rounded-2xl border bg-white transition-shadow dark:bg-gray-900 ${expanded ? "border-kps-primary/35 shadow-theme-sm" : "border-kps-primary/15 dark:border-kps-primary/30"}`}
    >
      <button
        type="button"
        aria-expanded={expanded}
        aria-controls={regionId}
        onClick={onToggle}
        className="flex w-full flex-col gap-3 border-s-4 border-s-kps-primary bg-kps-primary/[0.04] p-4 text-start transition-colors hover:bg-kps-primary/[0.07] focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none focus-visible:ring-inset sm:flex-row sm:items-center sm:justify-between dark:bg-kps-primary/10 dark:hover:bg-kps-primary/15"
      >
        <span className="flex min-w-0 items-center gap-3">
          <ChevronDownIcon
            aria-hidden="true"
            className={`size-5 shrink-0 text-kps-primary transition-transform dark:text-white ${expanded ? "" : "-rotate-90 rtl:rotate-90"}`}
          />
          <span className="min-w-0">
            <span className="flex flex-wrap items-center gap-2">
              <span className="font-semibold text-gray-900 dark:text-white">
                {group.employeeName}
              </span>
              <WeeklyPlanStatusBadge status={group.decision.status} />
            </span>
            <span className="mt-1 block text-theme-xs text-gray-500 dark:text-gray-400">
              {group.departments.length > 0
                ? group.departments.join(", ")
                : t("employee.noDepartment")}
            </span>
          </span>
        </span>
        <span className="text-theme-xs font-medium text-gray-600 dark:text-gray-300">
          {t("employee.counts", {
            entries: group.entries.length,
            items: group.itemCount,
          })}
        </span>
      </button>

      {expanded && (
        <div
          id={regionId}
          className="space-y-4 border-t border-kps-primary/10 bg-gray-50/60 px-3 py-4 sm:px-4 dark:border-kps-primary/20 dark:bg-gray-800/30"
        >
          {group.entries.length === 0 ? (
            <div className="rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-800 dark:bg-warning-500/10">
              <p className="font-semibold text-warning-800 dark:text-warning-300">
                {t("employee.zeroWorkTitle")}
              </p>
              <p className="mt-2 text-theme-sm text-warning-700 dark:text-warning-400">
                {t("employee.zeroWorkDescription")}
              </p>
            </div>
          ) : (
            group.entries.map((entry) => (
              <WorkCard
                key={entry.id}
                entry={entry}
                multiple={group.entries.length > 1}
              />
            ))
          )}

          {canDecide && (
            <div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
              <p className="text-theme-sm font-semibold text-gray-900 dark:text-white">
                {t("decision.title")}
              </p>
              <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                {t("decision.description")}
              </p>
              <div className="mt-3 flex flex-wrap justify-end gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={busy}
                  onClick={onRequestChanges}
                >
                  {t("decision.requestChanges")}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  disabled={busy}
                  className="bg-kps-primary hover:bg-kps-primary-hover"
                  onClick={onAccept}
                >
                  {t("decision.accept")}
                </Button>
              </div>
            </div>
          )}
        </div>
      )}
    </section>
  );
}
