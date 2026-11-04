import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import Badge from "@/components/ui/badge/Badge";
import Button from "@/components/ui/button/Button";
import { formatDateOnly } from "@/utils/dateOnly";
import type { DailyDocumentsPage } from "@/types/dailyDocument";

type Row = DailyDocumentsPage["reports"][number];
const statusColors = {
  pending: "info",
  running: "info",
  completed: "success",
  partial: "warning",
  failed: "error",
} as const;

export default function DailyDocumentEmployee({
  row,
  busy,
  onRetry,
}: {
  row: Row;
  busy: boolean;
  onRetry: () => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "dailyDocuments" });
  const document = row.document;
  // Counts must refer to the same frozen source as the AI result.
  const source = document?.source ?? row.source;
  const result = document?.result;
  const errors = document?.units.filter((unit) => unit.error_code) ?? [];
  const canRetry = document?.units.some((unit) => unit.can_retry) ?? false;
  const retryUnavailable =
    errors.length > 0 && !canRetry && !document?.requires_new_document;
  return (
    <article className="rounded-2xl border border-gray-200 bg-white text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300">
      <details className="group">
        <summary className="flex cursor-pointer list-none flex-wrap items-center gap-3 rounded-2xl px-5 py-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 [&::-webkit-details-marker]:hidden">
          <span className="min-w-0 flex-1 font-semibold break-words text-gray-800 dark:text-white/90">
            {row.employee_name}
          </span>
          <Badge
            color={document ? statusColors[document.status] : "light"}
            size="sm"
          >
            {document ? t(`status.${document.status}`) : t("notStarted")}
          </Badge>
          {document && (
            <span className="text-xs text-gray-500 dark:text-gray-400">
              {t("progress", {
                count: document.completed_units,
                total: document.total_units,
              })}
            </span>
          )}
          <span className="text-xs font-medium text-brand-600 dark:text-brand-300">
            <span className="group-open:hidden">{t("expand")}</span>
            <span className="hidden group-open:inline">{t("collapse")}</span>
          </span>
        </summary>
        <div className="space-y-5 border-t border-gray-100 p-5 dark:border-gray-800">
          {document && (
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t(document.mode === "fake" ? "fake" : "model", {
                model: document.model,
              })}
            </p>
          )}
          {!result ? (
            <p>{t("noAiResult")}</p>
          ) : (
            <>
              {Boolean(result.work_groups?.length) && (
                <section className="space-y-3">
                  <h3 className="font-semibold">{t("groupedWork")}</h3>
                  {result.work_groups?.map((group, index) => (
                    <article
                      key={index}
                      className="rounded-lg border border-brand-100 bg-brand-25 p-3 dark:border-brand-800 dark:bg-brand-500/5"
                    >
                      <h4 className="font-medium">{group.title}</h4>
                      <p className="mt-2 leading-7 break-words whitespace-pre-wrap">
                        {group.text}
                      </p>
                      <details className="mt-2 text-xs">
                        <summary className="cursor-pointer text-brand-600 dark:text-brand-300">
                          {t("groupSources")}
                        </summary>
                        {group.evidence.map((ref) => (
                          <p
                            key={`${ref.report_id}:${ref.item_id}`}
                            className="mt-2 break-words"
                          >
                            <Link
                              to={`/reports/${ref.report_id}#work-item-${ref.item_id}`}
                              className="text-brand-600 underline dark:text-brand-300"
                            >
                              {t("quote", {
                                id: ref.item_id,
                                quote: ref.quote,
                              })}
                            </Link>
                          </p>
                        ))}
                      </details>
                    </article>
                  ))}
                </section>
              )}
              <section className="rounded-lg bg-success-50 p-3 dark:bg-success-500/10">
                <h3 className="font-semibold text-success-700 dark:text-success-400">
                  {t("totals")}
                </h3>
                {!result.work_totals.length ? (
                  <p className="mt-2">{t("noTotals")}</p>
                ) : (
                  <ul className="mt-2 space-y-1">
                    {result.work_totals.map((total, index) => (
                      <li key={index}>
                        {total.metric}:{" "}
                        {total.total === null
                          ? t("needsConfirmation")
                          : `${total.total} ${total.unit}`}
                      </li>
                    ))}
                  </ul>
                )}
              </section>
              {(["comments", "recommendations"] as const).map((field) => (
                <section
                  key={field}
                  className={
                    field === "comments"
                      ? "rounded-lg bg-blue-light-50 p-3 dark:bg-blue-light-500/10"
                      : "rounded-lg bg-warning-50 p-3 dark:bg-warning-500/10"
                  }
                >
                  <h3
                    className={
                      field === "comments"
                        ? "font-semibold text-blue-light-700 dark:text-blue-light-400"
                        : "font-semibold text-warning-700 dark:text-warning-400"
                    }
                  >
                    {t(field)}
                  </h3>
                  {!result[field].length ? (
                    <p className="mt-2">{t("noReview")}</p>
                  ) : (
                    <ul className="mt-2 space-y-3">
                      {result[field].map((entry, index) => (
                        <li
                          key={index}
                          className="break-words whitespace-pre-wrap"
                        >
                          <p>{entry.text}</p>
                          <details className="mt-1 text-xs">
                            <summary className="cursor-pointer">
                              {t("groupSources")}
                            </summary>
                            <Link
                              to={`/reports/${source.report_id}#work-item-${entry.item_id}`}
                              className="text-brand-600 underline dark:text-brand-300"
                            >
                              {t("quote", {
                                quote: entry.quote,
                                id: entry.item_id,
                              })}
                            </Link>
                          </details>
                        </li>
                      ))}
                    </ul>
                  )}
                </section>
              ))}
            </>
          )}
          {errors.length > 0 && (
            <details className="text-xs text-error-600 dark:text-error-400">
              <summary className="cursor-pointer">{t("errorDetails")}</summary>
              {errors.map((unit) => (
                <p key={unit.id} className="mt-2 break-words">
                  {t("unitError", {
                    part: unit.part + 1,
                    code: unit.error_code,
                  })}
                </p>
              ))}
            </details>
          )}
        </div>
      </details>
      <div className="space-y-3 border-t border-gray-100 px-5 py-3 dark:border-gray-800">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 text-xs">
          <Link
            to={`/reports/${source.report_id}`}
            className="text-brand-600 underline dark:text-brand-300"
          >
            {t("source", {
              id: source.report_id,
              date: formatDateOnly(source.date),
            })}
          </Link>
          {source.is_makeup && (
            <Badge size="sm" color="warning">
              {t("makeup")}
            </Badge>
          )}
          {(["COMPLETED", "IN_PROGRESS", "BLOCKED"] as const).map((status) => (
            <span key={status}>
              {t(`groups.${status}`)}:{" "}
              {source.items.filter((item) => item.status === status).length}
            </span>
          ))}
        </div>
        <p className="text-xs text-gray-500 dark:text-gray-400">
          {t("selfReported")}
        </p>
        {row.source_changed && (
          <p role="status" className="text-warning-700 dark:text-warning-400">
            {t("sourceChanged")}
          </p>
        )}
        {document?.requires_new_document && (
          <p role="status" className="text-warning-700 dark:text-warning-400">
            {t("contractChanged")}
          </p>
        )}
        {errors.length > 0 && (
          <div
            role="status"
            className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-error-50 p-3 text-error-700 dark:bg-error-500/10 dark:text-error-400"
          >
            <p className="min-w-0 flex-1">
              {t(retryUnavailable ? "retryExhausted" : "partialError")}
            </p>
            {canRetry && (
              <Button variant="outline" onClick={onRetry} disabled={busy}>
                {t("retry")}
              </Button>
            )}
          </div>
        )}
      </div>
    </article>
  );
}
