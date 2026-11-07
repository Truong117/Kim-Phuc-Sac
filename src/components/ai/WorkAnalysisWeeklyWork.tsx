import { useTranslation } from "react-i18next";
import type { GroupedWork, WorkTotal } from "@/types/workReportAnalysis";

import { reportText } from "@/utils/reportPresentation";

export default function WorkAnalysisWeeklyWork({
  groups,
  totals,
  partial,
}: {
  groups: GroupedWork[];
  totals: WorkTotal[];
  partial: boolean;
}) {
  const { t } = useTranslation("common", { keyPrefix: "workReportAnalysis" });
  const confirmed = totals.filter(
    (total) =>
      total.status === "confirmed" &&
      total.total !== null &&
      Number.isFinite(total.total) &&
      total.total >= 0,
  );
  return (
    <section className="space-y-4">
      <h3 className="font-semibold text-gray-900 dark:text-white">
        {t("reader.summary")}
      </h3>
      {groups.length > 0 ? (
        <ul className="space-y-4">
          {groups.map((group, index) => (
            <li key={index} className="flex gap-3">
              <span
                aria-hidden="true"
                className="mt-2.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-400 dark:bg-brand-300"
              />
              <div className="min-w-0 text-sm leading-7">
                <h4 className="font-medium break-words text-gray-900 dark:text-white">
                  {group.title}
                </h4>
                <p className="break-words whitespace-pre-wrap text-gray-600 dark:text-gray-300">
                  {reportText(group.text)}
                </p>
              </div>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-gray-500 dark:text-gray-400">
          {t("reader.noGroups")}
        </p>
      )}
      {confirmed.length > 0 && (
        <div
          aria-label={t("weeklyWork.totals")}
          className="flex flex-wrap gap-2"
        >
          {confirmed.map((total, index) => (
            <span
              key={index}
              className="rounded-lg bg-success-50 px-3 py-1.5 text-xs leading-6 text-success-800 dark:bg-success-950/40 dark:text-success-200"
            >
              {total.metric}:{" "}
              <strong className="font-medium">
                {new Intl.NumberFormat("vi-VN", {
                  maximumFractionDigits: 6,
                }).format(total.total!)}{" "}
                {total.unit}
              </strong>
            </span>
          ))}
        </div>
      )}
      {partial && totals.length > 0 && (
        <p className="text-xs leading-6 text-warning-700 dark:text-warning-300">
          {t("weeklyWork.partialTotals")}
        </p>
      )}
    </section>
  );
}
