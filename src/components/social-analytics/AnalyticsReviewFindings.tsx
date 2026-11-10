import { useTranslation } from "react-i18next";
import type {
  FacebookPeriodReviewData,
  FacebookMetric,
} from "@/types/facebookAnalytics";
import {
  analyticsNumber,
  observationDate,
  facebookPostTitle,
} from "@/utils/facebookAnalytics";

export default function AnalyticsReviewFindings({
  result,
  metric,
  onPost,
}: {
  result: FacebookPeriodReviewData;
  metric: FacebookMetric;
  onPost?: (id: number) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  return (
    <section className="space-y-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
      <h3 className="font-semibold text-gray-800 dark:text-gray-100">
        {t("period.findings")}
      </h3>
      {!result.groups.some((g) => g.status !== "insufficient") && (
        <p
          role="status"
          className="rounded-lg bg-warning-50 p-3 text-xs leading-relaxed text-warning-800 dark:bg-warning-500/10 dark:text-warning-400"
        >
          {t("period.notEnough")}
        </p>
      )}
      {!result.catalogue_covered && (
        <p className="text-xs text-warning-700 dark:text-warning-400">
          {t("age.reasons.catalogue_incomplete")}
        </p>
      )}
      <div className="grid min-w-0 gap-3 lg:grid-cols-2">
        {result.groups.map((g) => (
          <article
            key={g.id}
            className="min-w-0 space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700"
          >
            <p className="text-xs font-semibold text-kps-primary dark:text-brand-300">
              {t(`period.groupStatus.${g.status}`)}
            </p>
            <h4 className="text-sm font-medium text-gray-800 dark:text-gray-100">
              {t(`types.${g.identity.type}`)} ·{" "}
              {t(`topics.${g.identity.topic ?? "none"}`)} ·{" "}
              {t(`age.objectiveValues.${g.identity.objective ?? "unknown"}`)}
            </h4>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t(
                `age.distributionValues.${g.identity.distribution ?? "unknown"}`,
              )}
            </p>
            <div className="grid grid-cols-2 gap-3">
              {(
                [
                  ["current", g.current, g.current_total],
                  ["previous", g.previous, g.previous_total],
                ] as const
              ).map(([period, stat, total]) => (
                <div key={period}>
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    {t(`age.period.${period}`)}
                  </p>
                  <p className="text-xl font-semibold text-gray-800 dark:text-gray-100">
                    {analyticsNumber(stat.median)}
                  </p>
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    {t("age.coverage", {
                      n: stat.posts,
                      total,
                      percent: Math.round(stat.coverage * 100),
                    })}
                  </p>
                </div>
              ))}
            </div>
            <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
              {g.comparison.reason
                ? t(`age.reasons.${g.comparison.reason}`)
                : t("age.change", {
                    percent: analyticsNumber(g.comparison.percent),
                    difference: analyticsNumber(g.comparison.difference),
                  })}
            </p>
            {g.comparison.reason === "zero_baseline" && (
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {t("age.absoluteChange", {
                  value: analyticsNumber(g.comparison.difference),
                })}
              </p>
            )}
            {g.evidence.length > 0 && (
              <div className="space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  {t("period.evidenceNote")}
                </p>
                {g.evidence.map((p) => (
                  <div key={p.kind} className="min-w-0 text-xs">
                    <p className="font-medium text-gray-700 dark:text-gray-200">
                      {t(`period.evidenceKinds.${p.kind}`)} ·{" "}
                      {analyticsNumber(p.point.metrics[metric])}
                    </p>
                    {onPost ? (
                      <button
                        type="button"
                        onClick={() => onPost(p.id)}
                        className="block w-full truncate text-start text-kps-primary underline dark:text-brand-300"
                      >
                        {facebookPostTitle(p.message) || t("untitled")}
                      </button>
                    ) : (
                      <details>
                        <summary className="cursor-pointer break-words text-kps-primary dark:text-brand-300">
                          {facebookPostTitle(p.message) || t("untitled")}
                        </summary>
                        <p className="mt-2 break-words whitespace-pre-wrap">
                          {p.message}
                        </p>
                      </details>
                    )}
                    <p className="text-gray-500 dark:text-gray-400">
                      {t("age.actual", {
                        at: observationDate(p.point.sampled_at),
                      })}
                    </p>
                  </div>
                ))}
              </div>
            )}
            <details className="text-xs text-gray-500 dark:text-gray-400">
              <summary className="cursor-pointer">
                {t("age.definition")}
              </summary>
              <p className="mt-1 break-all">{g.identity.definition}</p>
            </details>
          </article>
        ))}
      </div>
    </section>
  );
}
