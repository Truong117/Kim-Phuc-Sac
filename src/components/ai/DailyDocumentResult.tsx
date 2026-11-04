import { useTranslation } from "react-i18next";
import { ChatIcon, MultiUserIcon, TaskIcon } from "@/icons";
import type { DailyDocument } from "@/types/dailyDocument";

type Result = NonNullable<DailyDocument["result"]>;

export default function DailyDocumentResult({ result }: { result: Result }) {
  const { t } = useTranslation("common", { keyPrefix: "dailyDocuments" });
  const quantified: Result["work_totals"] = [];
  const unresolved: Result["work_totals"] = [];
  for (const total of result.work_totals) {
    if (
      total.status === "confirmed" &&
      total.total !== null &&
      Number.isFinite(total.total) &&
      total.total >= 0
    )
      quantified.push(total);
    else unresolved.push(total);
  }
  return (
    <div className="space-y-6">
      <section aria-label={t("reportSummary")}>
        <h3 className="flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
          <TaskIcon
            aria-hidden="true"
            className="h-4 w-4 text-brand-600 dark:text-brand-300"
          />
          {t("reportSummary")}
        </h3>
        {result.work_groups?.length ? (
          <ul className="mt-3 list-disc space-y-3 ps-5 leading-7">
            {result.work_groups.map((group, index) => (
              <li key={index} className="break-words whitespace-pre-wrap">
                <span className="font-medium text-gray-900 dark:text-gray-100">
                  {group.title}:{" "}
                </span>
                {group.text}
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-3 text-gray-500 dark:text-gray-400">
            {t("noGroupedWork")}
          </p>
        )}
        {quantified.length > 0 && (
          <div
            aria-label={t("reportedMetrics")}
            className="mt-4 flex flex-wrap gap-2"
          >
            {quantified.map((total, index) => (
              <span
                key={index}
                className="rounded-lg bg-gray-50 px-3 py-1.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300"
              >
                {total.metric}:{" "}
                <strong className="font-semibold text-brand-700 dark:text-brand-300">
                  {total.total}
                </strong>{" "}
                {total.unit}
              </span>
            ))}
          </div>
        )}
      </section>
      <section
        aria-label={t("clarifications")}
        className={
          unresolved.length
            ? "rounded-e-lg border-s-2 border-warning-400 bg-warning-50 p-4 dark:border-warning-600 dark:bg-warning-500/10"
            : "flex flex-wrap items-baseline gap-x-3 gap-y-1"
        }
      >
        <h3
          className={
            unresolved.length
              ? "font-semibold text-warning-800 dark:text-warning-300"
              : "font-semibold text-gray-900 dark:text-gray-100"
          }
        >
          {t("clarifications")}
        </h3>
        {unresolved.length > 0 ? (
          <>
            <p className="mt-2 text-sm text-gray-700 dark:text-gray-300">
              {t("unresolvedTotalsHint")}
            </p>
            <details className="mt-2 text-sm">
              <summary className="cursor-pointer text-warning-800 dark:text-warning-300">
                {t("clarificationCount", { count: unresolved.length })}
              </summary>
              <ul className="mt-2 list-disc space-y-1 ps-5">
                {unresolved.map((total, index) => (
                  <li key={index}>{total.metric}</li>
                ))}
              </ul>
            </details>
          </>
        ) : (
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {t("noClarifications")}
          </p>
        )}
      </section>
      <div className="grid gap-6 border-t border-gray-100 pt-5 xl:grid-cols-2 dark:border-gray-800">
        <section aria-label={t("review")}>
          <h3 className="mb-2 flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
            <ChatIcon
              aria-hidden="true"
              className="h-4 w-4 text-brand-600 dark:text-brand-300"
            />
            {t("review")}
          </h3>
          <p
            className={
              result.comments.length
                ? "leading-7 break-words whitespace-pre-wrap"
                : "text-xs text-gray-500 dark:text-gray-400"
            }
          >
            {result.comments.length
              ? result.comments.map((entry) => entry.text).join(" ")
              : t("noComments")}
          </p>
        </section>
        <section aria-label={t("recommendations")}>
          <h3 className="mb-2 flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
            <MultiUserIcon
              aria-hidden="true"
              className="h-4 w-4 text-brand-600 dark:text-brand-300"
            />
            {t("recommendations")}
          </h3>
          <p
            className={
              result.recommendations.length
                ? "leading-7 break-words whitespace-pre-wrap"
                : "text-xs text-gray-500 dark:text-gray-400"
            }
          >
            {result.recommendations.length
              ? result.recommendations.map((entry) => entry.text).join(" ")
              : t("noRecommendations")}
          </p>
        </section>
      </div>
    </div>
  );
}
