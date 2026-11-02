import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import { formatDateOnly } from "@/utils/dateOnly";
import type { WeeklyWorkGroup, WorkTotal } from "@/types/workReportAnalysis";

interface Props {
  inventory: WeeklyWorkGroup[];
  totals: WorkTotal[];
  partial: boolean;
}

export default function WorkAnalysisWeeklyWork({
  inventory,
  totals,
  partial,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "workReportAnalysis.weeklyWork",
  });
  return (
    <section className="space-y-4">
      <h3 className="font-semibold text-gray-900 dark:text-white">
        {t("title")}
      </h3>
      <p className="text-xs leading-6 text-gray-600 dark:text-gray-400">
        {t("basis")}
      </p>
      {totals.length > 0 && (
        <div className="space-y-3">
          <h4 className="text-sm font-medium text-gray-900 dark:text-white">
            {t("totals")}
          </h4>
          {partial && (
            <p className="text-xs text-warning-700 dark:text-warning-300">
              {t("partialTotals")}
            </p>
          )}
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {totals.map((total) => (
              <div
                key={`${total.metric}:${total.unit}`}
                className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900"
              >
                <p className="text-sm font-medium text-gray-800 dark:text-gray-200">
                  {total.metric}
                </p>
                <p className="mt-2 text-xl font-semibold text-gray-900 dark:text-white">
                  {total.total === null
                    ? t("unconfirmed")
                    : `${new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 6 }).format(total.total)} ${total.unit}`}
                </p>
                {total.total === null && (
                  <p className="mt-2 text-xs leading-6 text-warning-700 dark:text-warning-300">
                    {t("cumulative")}
                  </p>
                )}
                <details className="mt-3 text-xs text-gray-600 dark:text-gray-400">
                  <summary className="cursor-pointer font-medium">
                    {t("quantitySources")}
                  </summary>
                  <ul className="mt-2 space-y-2">
                    {total.entries.map((entry, index) => (
                      <li key={`${entry.report_id}:${entry.item_id}:${index}`}>
                        <Link
                          to={`/reports/${entry.report_id}#work-item-${entry.item_id}`}
                          className="text-brand-600 underline dark:text-brand-300"
                        >
                          {t("source", {
                            report: entry.report_id,
                            item: entry.item_id,
                          })}
                        </Link>
                        <p className="break-words whitespace-pre-wrap">
                          {entry.quote}
                        </p>
                      </li>
                    ))}
                  </ul>
                </details>
              </div>
            ))}
          </div>
        </div>
      )}
      <ol className="space-y-3">
        {inventory.map((group, index) => (
          <li
            key={group.content}
            className="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
          >
            <h4 className="text-sm leading-7 font-medium break-words whitespace-pre-wrap text-gray-900 dark:text-white">
              {index + 1}. {group.content}
            </h4>
            <ul className="mt-3 space-y-3">
              {group.observations.map((observation) => (
                <li
                  key={`${observation.report_id}:${observation.item_id}`}
                  className="border-s-2 border-gray-200 ps-3 text-sm leading-6 text-gray-700 dark:border-gray-700 dark:text-gray-300"
                >
                  <p className="text-xs font-medium text-gray-600 dark:text-gray-400">
                    <time dateTime={observation.date}>
                      {formatDateOnly(observation.date)}
                    </time>{" "}
                    · {t(`status.${observation.status}`)}
                  </p>
                  {observation.result && (
                    <p className="mt-1 break-words whitespace-pre-wrap">
                      {t("result")}: {observation.result}
                    </p>
                  )}
                  {observation.note && (
                    <p className="mt-1 break-words whitespace-pre-wrap">
                      {t("note")}: {observation.note}
                    </p>
                  )}
                  <Link
                    to={`/reports/${observation.report_id}#work-item-${observation.item_id}`}
                    className="text-xs text-brand-600 underline dark:text-brand-300"
                  >
                    {t("source", {
                      report: observation.report_id,
                      item: observation.item_id,
                    })}
                  </Link>
                </li>
              ))}
            </ul>
          </li>
        ))}
      </ol>
    </section>
  );
}
