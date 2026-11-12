import { useEffect, useState } from "react";
import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import Button from "@/components/ui/button/Button";
import {
  getTaskProgressHistory,
  type TaskProgressEvent,
} from "@/services/reportTaskService";
import { formatDateOnly } from "@/utils/dateOnly";

export default function TaskProgressHistory({ taskId }: { taskId: number }) {
  const { t } = useTranslation("common", { keyPrefix: "taskProgress" });
  const [rows, setRows] = useState<TaskProgressEvent[]>([]),
    [page, setPage] = useState(1),
    [lastPage, setLastPage] = useState(1),
    [error, setError] = useState(false);
  useEffect(() => {
    const controller = new AbortController();
    getTaskProgressHistory(taskId, page, controller.signal)
      .then((result) => {
        if (!controller.signal.aborted) {
          setRows(result.data);
          setLastPage(result.meta?.last_page ?? 1);
          setError(false);
        }
      })
      .catch(() => {
        if (!controller.signal.aborted) {
          setError(true);
          setRows([]);
        }
      });
    return () => controller.abort();
  }, [taskId, page]);
  return (
    <ComponentCard title={t("historyTitle")}>
      <div className="space-y-4 text-theme-sm text-gray-700 dark:text-gray-300">
        {error && (
          <p role="alert" className="text-error-600 dark:text-error-400">
            {t("loadError")}
          </p>
        )}
        {!error && rows.length === 0 && <p>{t("noHistory")}</p>}
        {rows.map((row) => (
          <article
            key={row.id}
            className="space-y-2 border-b border-gray-200 pb-3 last:border-0 dark:border-gray-700"
          >
            <div className="flex flex-wrap justify-between gap-2">
              <Link
                className="font-medium text-kps-primary underline dark:text-sidebar-selected"
                to={`/reports/${row.report_id}${row.report_item_id ? `#work-item-${row.report_item_id}` : ""}`}
              >
                {t("historySource", {
                  date: formatDateOnly(row.report_date),
                  id: row.report_id,
                })}
              </Link>
              <span className="text-theme-xs text-gray-500 dark:text-gray-400">
                {new Intl.DateTimeFormat("vi-VN", {
                  dateStyle: "short",
                  timeStyle: "short",
                  timeZone: "Asia/Ho_Chi_Minh",
                }).format(new Date(row.created_at))}
              </span>
            </div>
            <p>
              {row.applied
                ? t("historyState", {
                    before: t(`wholeState.${row.status_before}`, {
                      defaultValue: row.status_before,
                    }),
                    after: t(`wholeState.${row.status_after}`, {
                      defaultValue: row.status_after,
                    }),
                  })
                : t("historical")}
            </p>
            {row.outputs.length > 0 && (
              <p className="text-theme-xs">
                {row.outputs
                  .map(
                    (o) =>
                      `${o.name} (${t(o.completed ? "outputCompleted" : "outputOngoing")})`,
                  )
                  .join(", ")}
              </p>
            )}
          </article>
        ))}
      </div>
      {lastPage > 1 && (
        <div className="mt-4 flex gap-3">
          <Button
            size="sm"
            variant="outline"
            disabled={page <= 1}
            onClick={() => setPage((p) => p - 1)}
          >
            {t("previous")}
          </Button>
          <Button
            size="sm"
            variant="outline"
            disabled={page >= lastPage}
            onClick={() => setPage((p) => p + 1)}
          >
            {t("next")}
          </Button>
        </div>
      )}
    </ComponentCard>
  );
}
