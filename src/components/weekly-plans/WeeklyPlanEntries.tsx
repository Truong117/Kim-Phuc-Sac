import Button from "@/components/ui/button/Button";
import WeeklyPlanStatusBadge from "@/components/weekly-plans/WeeklyPlanStatusBadge";
import type {
  WeeklyPlanEntry,
  WeeklyPlanReview,
} from "@/types/weeklyPlanning";
import { formatDateOnly } from "@/utils/dateOnly";
import { useTranslation } from "react-i18next";

type Props = {
  entries: WeeklyPlanEntry[];
  reviews: WeeklyPlanReview[];
  canEdit: boolean;
  canReview: boolean;
  busy: boolean;
  onEdit: (entry: WeeklyPlanEntry) => void;
  onDelete: (entry: WeeklyPlanEntry) => void;
  onReview: (
    assigneeId: number,
    status: "ACCEPTED" | "CHANGES_REQUESTED",
  ) => void;
};

export default function WeeklyPlanEntries({
  entries,
  reviews,
  canEdit,
  canReview,
  busy,
  onEdit,
  onDelete,
  onReview,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.detail",
  });
  const groups = Object.values(
    entries.reduce<
      Record<
        string,
        {
          assigneeId: number | null;
          name: string;
          departments: string[];
          entries: WeeklyPlanEntry[];
        }
      >
    >((result, entry) => {
      const key = String(entry.assignee?.id ?? "unresolved-" + entry.id);
      result[key] ??= {
        assigneeId: entry.assignee?.id ?? null,
        name: entry.assignee?.name ?? entry.employee_name ?? t("unresolvedEmployee"),
        departments: [],
        entries: [],
      };
      if (
        entry.department_name &&
        !result[key].departments.includes(entry.department_name)
      ) {
        result[key].departments.push(entry.department_name);
      }
      result[key].entries.push(entry);
      return result;
    }, {}),
  );

  if (groups.length === 0) {
    return (
      <p className="rounded-xl bg-gray-50 px-4 py-8 text-center text-theme-sm text-gray-500 dark:bg-gray-800/50 dark:text-gray-400">
        {t("noEntries")}
      </p>
    );
  }

  return (
    <div className="space-y-4">
      {groups.map((group) => {
        const review = reviews.find(
          (item) => item.assignee.id === group.assigneeId,
        );
        return (
          <section
            key={group.assigneeId ?? group.name}
            className="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
          >
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
              <div className="flex flex-wrap items-center gap-2">
                <div>
                  <h3 className="font-semibold text-gray-900 dark:text-white">
                    {group.name}
                  </h3>
                  {group.departments.length > 0 && (
                    <p className="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                      {group.departments.join(", ")}
                    </p>
                  )}
                </div>
                {review && <WeeklyPlanStatusBadge status={review.status} />}
              </div>
              {canReview && group.assigneeId && (
                <div className="flex gap-2">
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={busy}
                    onClick={() =>
                      onReview(group.assigneeId as number, "CHANGES_REQUESTED")
                    }
                  >
                    {t("requestChanges")}
                  </Button>
                  <Button
                    type="button"
                    size="sm"
                    disabled={busy}
                    className="bg-kps-primary hover:bg-kps-primary-hover"
                    onClick={() =>
                      onReview(group.assigneeId as number, "ACCEPTED")
                    }
                  >
                    {t("acceptEmployee")}
                  </Button>
                </div>
              )}
            </div>

            <div className="space-y-3">
              {group.entries.map((entry) => (
                <article
                  key={entry.id}
                  className="rounded-xl bg-gray-50 p-4 dark:bg-gray-800/60"
                >
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <h4 className="font-semibold text-gray-900 dark:text-white">
                          {entry.title || t("untitled")}
                        </h4>
                        {entry.source_row && (
                          <span className="rounded-full bg-white px-2 py-0.5 text-theme-xs font-medium text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                            {t("sourceRow", { row: entry.source_row })}
                          </span>
                        )}
                        <span className="rounded-full bg-white px-2 py-0.5 text-theme-xs font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                          {t("priorities." + (entry.priority ?? "MEDIUM"))}
                        </span>
                      </div>
                      <p className="mt-1 text-theme-sm text-gray-600 dark:text-gray-300">
                        {formatDateOnly(entry.start_date ?? "")} –{" "}
                        {formatDateOnly(entry.due_date ?? "")}
                      </p>
                      {entry.expected_result && (
                        <p className="mt-2 text-theme-sm text-gray-700 dark:text-gray-300">
                          <span className="font-medium">{t("expectedResult")}:</span>{" "}
                          {entry.expected_result}
                        </p>
                      )}
                      {entry.note && (
                        <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                          {entry.note}
                        </p>
                      )}
                      {entry.validation_errors.length > 0 && (
                        <ul className="mt-3 list-disc space-y-1 ps-5 text-theme-xs text-error-600 dark:text-error-400">
                          {entry.validation_errors.map((message) => (
                            <li key={message}>{message}</li>
                          ))}
                        </ul>
                      )}
                    </div>
                    {(canEdit || canReview) && (
                      <div className="flex shrink-0 gap-2">
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          disabled={busy}
                          onClick={() => onEdit(entry)}
                        >
                          {t("edit")}
                        </Button>
                        {canEdit && (
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={() => onDelete(entry)}
                          >
                            {t("delete")}
                          </Button>
                        )}
                      </div>
                    )}
                  </div>
                </article>
              ))}
            </div>
          </section>
        );
      })}
    </div>
  );
}
