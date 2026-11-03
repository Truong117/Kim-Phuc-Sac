import type { WorkItemFormData } from "@/types/reports";
import { useTranslation } from "react-i18next";
export default function ReportResultSummary({
  items,
}: {
  items: WorkItemFormData[];
}) {
  const { t } = useTranslation("common", { keyPrefix: "dailyReport.v3" });
  const results = items.filter(
    (item) => item.content.trim() && item.result.trim(),
  );
  return (
    <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
      <h2 className="font-semibold text-gray-900 dark:text-white">
        {t("resultsTitle")}
      </h2>
      <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
        {t("resultsHint")}
      </p>
      {results.length ? (
        <ul className="mt-3 space-y-3">
          {results.map((item) => (
            <li
              key={item.clientId}
              className="text-theme-sm break-words whitespace-pre-wrap text-gray-700 dark:text-gray-300"
            >
              <span className="font-medium">{item.content}</span>
              <p className="mt-1">{item.result}</p>
            </li>
          ))}
        </ul>
      ) : (
        <p className="mt-3 text-theme-sm text-gray-500 dark:text-gray-400">
          {t("resultsEmpty")}
        </p>
      )}
    </section>
  );
}
