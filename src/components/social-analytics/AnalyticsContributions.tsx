import { useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import type {
  FacebookAnalyticsData,
  FacebookMetric,
} from "@/types/facebookAnalytics";
import {
  analyticsNumber,
  observationDate,
  trendContributors,
} from "@/utils/facebookAnalytics";
import { formatDateOnly } from "@/utils/dateOnly";

interface Props {
  data: FacebookAnalyticsData;
  date: string;
  metric: FacebookMetric;
  onPost: (id: number) => void;
}
export default function AnalyticsContributions({
  data,
  date,
  metric,
  onPost,
}: Props) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const [page, setPage] = useState(1);
  const rows = useMemo(
    () => trendContributors(data.posts, date, metric),
    [data.posts, date, metric],
  );
  const count = Math.max(1, Math.ceil(rows.length / 25)),
    current = Math.min(page, count);
  const visible = rows.slice((current - 1) * 25, current * 25);
  const value = rows.length ? rows.reduce((n, r) => n + r.value, 0) : null;
  const extended = rows.filter(
    (r) => r.interval && r.interval.max_gap_seconds > 86400,
  ).length;
  return (
    <section
      className="mt-4 space-y-3 border-t border-gray-100 pt-4 dark:border-gray-800"
      aria-label={t("review.daySources")}
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-100">
          {formatDateOnly(date)} · {t("metrics." + metric)}:{" "}
          {analyticsNumber(value)}
        </h3>
        <span className="rounded-md bg-kps-primary/10 px-2 py-1 text-xs text-kps-primary dark:text-brand-300">
          {t("review.contributorCount", { count: rows.length })}
        </span>
      </div>
      {rows.length === 0 ? (
        <p
          role="status"
          className="rounded-lg bg-gray-50 p-4 text-xs leading-relaxed text-gray-500 dark:bg-gray-800 dark:text-gray-400"
        >
          {t("review.noDayPairs")}
        </p>
      ) : (
        <>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {t("review.sourceOrder")}
          </p>
          {extended > 0 && (
            <p className="rounded-lg bg-warning-50 p-3 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
              {t("review.extendedIntervals", { count: extended })}
            </p>
          )}
          <div className="divide-y divide-gray-100 dark:divide-gray-800">
            {visible.map(({ post, value, interval }) => (
              <article
                key={post.id}
                className="flex min-w-0 items-start gap-3 py-3"
              >
                <div className="min-w-0 flex-1">
                  <button
                    type="button"
                    onClick={() => onPost(post.id)}
                    className="line-clamp-2 max-w-full text-start text-sm font-medium break-words text-kps-primary underline underline-offset-2 dark:text-brand-300"
                  >
                    {post.message || t("untitled")}
                  </button>
                  <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t("types." + post.type)} ·{" "}
                    {observationDate(post.published_at)}
                  </p>
                  {interval ? (
                    <p className="mt-1 text-xs leading-relaxed break-words text-gray-500 dark:text-gray-400">
                      {observationDate(interval.from)} →{" "}
                      {observationDate(interval.to)} ·{" "}
                      {t("review.samplePairs", { count: interval.pairs })}
                    </p>
                  ) : (
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                      {t("review.intervalUnavailable")}
                    </p>
                  )}
                </div>
                <span className="shrink-0 text-sm font-semibold text-kps-primary tabular-nums dark:text-brand-300">
                  {value > 0 ? "+" : ""}
                  {analyticsNumber(value)}
                </span>
              </article>
            ))}
          </div>
          {count > 1 && (
            <div className="flex flex-wrap items-center justify-end gap-3 text-xs text-gray-500 dark:text-gray-400">
              <Button
                size="sm"
                variant="outline"
                disabled={current <= 1}
                onClick={() => setPage((n) => n - 1)}
              >
                {t("previous")}
              </Button>
              {t("pageNumber", { current, total: count })}
              <Button
                size="sm"
                variant="outline"
                disabled={current >= count}
                onClick={() => setPage((n) => n + 1)}
              >
                {t("next")}
              </Button>
            </div>
          )}
        </>
      )}
    </section>
  );
}
