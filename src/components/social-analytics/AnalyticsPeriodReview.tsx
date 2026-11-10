import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import { useFacebookPeriodReview } from "@/hooks/useFacebookPeriodReview";
import type {
  FacebookAnalyticsData,
  FacebookMetric,
} from "@/types/facebookAnalytics";
import { observationDate } from "@/utils/facebookAnalytics";
import { formatDateOnly } from "@/utils/dateOnly";
import AnalyticsReviewFindings from "./AnalyticsReviewFindings";
import AnalyticsReviewPlan from "./AnalyticsReviewPlan";

export default function AnalyticsPeriodReview({
  data,
  onPost,
  onDirtyChange,
}: {
  data: FacebookAnalyticsData;
  onPost: (id: number) => void;
  onDirtyChange: (dirty: boolean) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const [metric, setMetric] = useState<FacebookMetric>("likes");
  const api = useFacebookPeriodReview(data, metric);
  useEffect(() => {
    onDirtyChange(api.dirty);
    return () => onDirtyChange(false);
  }, [api.dirty, onDirtyChange]);
  const result = api.result;
  const eligible =
    result?.groups.filter((g) => g.status !== "insufficient") ?? [];
  const expected = result?.plan.expected_posts;
  return (
    <div className="space-y-4">
      <section className="space-y-3 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="font-semibold text-gray-800 dark:text-gray-100">
              {t("period.title")}
            </h2>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              {t("period.scope")}
            </p>
          </div>
          <label className="w-full min-w-0 space-y-1 text-xs text-gray-500 sm:w-auto dark:text-gray-400">
            <span className="block">{t("metric")}</span>
            <select
              value={metric}
              disabled={api.saving}
              onChange={(e) => setMetric(e.target.value as FacebookMetric)}
              className="h-11 w-full max-w-full min-w-0 rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-900"
            >
              {["likes", "comments", "media_views"].map((k) => (
                <option key={k} value={k}>
                  {t(`age.metricNames.${k}`)}
                </option>
              ))}
            </select>
          </label>
        </div>
        <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
          {t("period.policy")}
        </p>
        <p className="text-xs text-gray-500 dark:text-gray-400">
          {t("period.sampling", {
            status: t(`sampling.statuses.${data.sampling?.status ?? "off"}`),
            at: observationDate(
              data.sampling?.last_sampled_at ?? data.sync.sampled_at,
            ),
          })}
        </p>
        {api.failed && (
          <p
            role="alert"
            className="text-xs text-error-600 dark:text-error-400"
          >
            {t("period.loadFailed")}
          </p>
        )}
        <Button
          size="sm"
          variant="outline"
          disabled={api.loading || api.saving}
          onClick={api.refresh}
        >
          {t("reload")}
        </Button>
      </section>
      {!result ? (
        <p
          role="status"
          className="p-4 text-sm text-gray-500 dark:text-gray-400"
        >
          {api.failed ? t("period.loadFailed") : t("loading")}
        </p>
      ) : (
        <>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {t("period.previous", {
              from: formatDateOnly(result.previous_from),
              to: formatDateOnly(result.previous_to),
            })}
          </p>
          <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
            {[
              ["published", result.publication.count],
              ["onTime", result.quality.statuses.on_time],
              [
                "missingLate",
                result.quality.statuses.missing + result.quality.statuses.late,
              ],
              ["eligible", eligible.length],
            ].map(([key, value]) => (
              <article
                key={key}
                className="min-w-0 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/3"
              >
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  {t(`period.counts.${key}`)}
                </p>
                <p className="mt-2 text-2xl font-semibold text-gray-800 dark:text-gray-100">
                  {value}
                </p>
              </article>
            ))}
          </div>
          <section className="space-y-3 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
            <h3 className="font-semibold text-gray-800 dark:text-gray-100">
              {t("period.cadenceTitle")}
            </h3>
            <p className="text-sm text-gray-600 dark:text-gray-300">
              {!result.publication.covered
                ? t("period.partialPublication")
                : expected == null
                  ? t("period.noTarget")
                  : t("period.targetProgress", {
                      actual: result.publication.count,
                      expected,
                      gap: Math.max(0, expected - result.publication.count),
                    })}
            </p>
            <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
              {t("period.cadenceNote")}
            </p>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t("period.waiting", {
                count:
                  result.quality.statuses.waiting + result.quality.statuses.due,
              })}
            </p>
          </section>
          <AnalyticsReviewFindings
            result={result}
            metric={metric}
            onPost={onPost}
          />
          <AnalyticsReviewPlan api={api} data={result} />
        </>
      )}
    </div>
  );
}
