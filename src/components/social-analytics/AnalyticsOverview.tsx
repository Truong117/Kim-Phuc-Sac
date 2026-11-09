import { useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import { AiIcon, DocsIcon, ChatIcon, EyeIcon } from "@/icons";
import type {
  FacebookAnalyticsData,
  FacebookAnalyticsPost,
  FacebookMetric,
} from "@/types/facebookAnalytics";
import {
  analyticsNumber,
  analyticsSeries,
  interactionGrowth,
  postInPeriod,
} from "@/utils/facebookAnalytics";
import AnalyticsTrend from "./AnalyticsTrend";
import AnalyticsContributions from "./AnalyticsContributions";
interface Props {
  data: FacebookAnalyticsData;
  onPost: (id: number) => void;
  onContent: () => void;
  onAi: () => void;
}
export default function AnalyticsOverview({
  data,
  onPost,
  onContent,
  onAi,
}: Props) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const [metric, setMetric] = useState<FacebookMetric>("likes");
  const [compare, setCompare] = useState(true);
  const [selectedDate, setSelectedDate] = useState<string | null>(null);
  const points = useMemo(
    () => analyticsSeries(data.posts, data.from_date, data.to_date),
    [data],
  );
  const counts = useMemo(() => {
    const result: Record<string, number> = {};
    for (const post of data.posts)
      for (const point of post.trend) {
        if (point[metric] != null)
          result[point.date] = (result[point.date] ?? 0) + 1;
      }
    return result;
  }, [data.posts, metric]);
  const activeDate =
    selectedDate && points.some((p) => p.date === selectedDate)
      ? selectedDate
      : (points.filter((p) => p[metric] != null).at(-1) ?? points.at(-1))?.date;
  const observedDays = points.filter((p) => p[metric] != null).length;
  const published = data.posts.filter((p) =>
    postInPeriod(p, data.from_date, data.to_date),
  );
  const top = [...data.posts]
    .filter((p) => interactionGrowth(p) != null)
    .sort((a, b) => (interactionGrowth(b) ?? 0) - (interactionGrowth(a) ?? 0))
    .slice(0, 3);
  const icons = [DocsIcon, AiIcon, ChatIcon, EyeIcon];
  return (
    <div className="space-y-5">
      <div className="flex justify-end">
        <label className="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
          <input
            type="checkbox"
            checked={compare}
            onChange={(e) => setCompare(e.target.checked)}
            className="accent-kps-primary"
          />
          {t("compare")}
        </label>
      </div>
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        {(["published", "likes", "comments", "media_views"] as const).map(
          (key, index) => {
            const Icon = icons[index],
              growth = key === "published" ? null : data.summary.growth[key];
            const value =
              key === "published" ? data.summary.published : growth?.value;
            return (
              <article
                key={key}
                className="rounded-xl border border-gray-200 bg-white p-4 sm:p-5 dark:border-gray-800 dark:bg-white/3"
              >
                <div className="flex items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                  {t(`metrics.${key}`)}
                  <Icon className="size-4 text-kps-primary" />
                </div>
                <p className="mt-3 text-2xl font-semibold text-gray-900 sm:text-3xl dark:text-white">
                  {analyticsNumber(value)}
                </p>
                <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                  {key === "published"
                    ? t("publishedBasis")
                    : t("observedBasis", { count: growth?.posts ?? 0 })}
                </p>
                {compare && (
                  <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {key === "published"
                      ? data.summary.previous_published == null
                        ? t("noComparison")
                        : t("previousPublished", {
                            count: data.summary.previous_published,
                          })
                      : growth?.comparison
                        ? t("cohortComparison", {
                            count: growth.comparison.posts,
                            current: analyticsNumber(growth.comparison.current),
                            previous: analyticsNumber(
                              growth.comparison.previous,
                            ),
                          })
                        : t("noComparison")}
                  </p>
                )}
              </article>
            );
          },
        )}
      </div>
      <div className="grid gap-5 xl:grid-cols-3">
        <ComponentCard
          title={t("review.trendTitle", {
            metric: t("review.metricNames." + metric),
          })}
          desc={t("review.trendScope", { name: data.page_name })}
          className="min-w-0 xl:col-span-2"
          compact
        >
          <label className="flex items-center justify-end gap-2 text-xs text-gray-500 dark:text-gray-400">
            {t("metric")}
            <select
              value={metric}
              onChange={(e) => setMetric(e.target.value as FacebookMetric)}
              className="rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
            >
              {(["likes", "comments", "media_views"] as const).map((k) => (
                <option key={k} value={k}>
                  {t(`metrics.${k}`)}
                </option>
              ))}
            </select>
          </label>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {t("review.dayCoverage", {
              ready: observedDays,
              total: points.length,
            })}
          </p>
          <AnalyticsTrend
            points={points}
            metric={metric}
            selectedDate={activeDate}
            onSelectDate={setSelectedDate}
            counts={counts}
          />
          <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
            {t("trendPolicy")}
          </p>
          {activeDate && (
            <AnalyticsContributions
              key={data.entry_id + ":" + activeDate + ":" + metric}
              data={data}
              date={activeDate}
              metric={metric}
              onPost={onPost}
            />
          )}
        </ComponentCard>
        <ComponentCard
          title={t("formatsTitle")}
          desc={t("formatsSubtitle")}
          compact
        >
          {(["reel", "video", "photo", "post"] as const).map((type) => {
            const count = published.filter((p) => p.type === type).length;
            return (
              <div key={type}>
                <div className="mb-2 flex justify-between text-xs text-gray-600 dark:text-gray-300">
                  <span>{t(`types.${type}`)}</span>
                  <span>{t("postsCount", { count })}</span>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                  <div
                    className="h-full rounded-full bg-kps-primary/70"
                    style={{
                      width: `${published.length ? (count / published.length) * 100 : 0}%`,
                    }}
                  />
                </div>
              </div>
            );
          })}
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {t("formatPolicy")}
          </p>
        </ComponentCard>
      </div>
      <div className="grid gap-5 xl:grid-cols-3">
        <ComponentCard
          title={t("topPosts")}
          className="min-w-0 xl:col-span-2"
          compact
        >
          <div className="flex justify-end">
            <button
              type="button"
              onClick={onContent}
              className="text-xs text-kps-primary hover:underline dark:text-brand-300"
            >
              {t("allContent")}
            </button>
          </div>
          {top.length === 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {t("noGrowth")}
            </p>
          ) : (
            top.map((p, index) => (
              <button
                key={p.id}
                type="button"
                onClick={() => onPost(p.id)}
                className="flex w-full items-center gap-3 rounded-lg p-2 text-start hover:bg-gray-50 dark:hover:bg-gray-800"
              >
                <span className="text-xs text-gray-400">0{index + 1}</span>
                <PostPicture post={p} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium text-gray-800 dark:text-gray-100">
                    {p.message || t("untitled")}
                  </span>
                  <span className="text-xs text-gray-400">
                    {t(`types.${p.type}`)}
                  </span>
                </span>
                <span className="text-sm font-semibold text-kps-primary dark:text-brand-300">
                  {analyticsNumber(interactionGrowth(p))}
                </span>
              </button>
            ))
          )}
        </ComponentCard>
        <ComponentCard title={t("aiTeaser")} compact>
          <AiIcon className="size-6 text-kps-primary" />
          <p className="text-sm leading-relaxed text-gray-600 dark:text-gray-300">
            {t("aiTeaserBody")}
          </p>
          <button
            type="button"
            onClick={onAi}
            className="text-sm text-kps-primary hover:underline dark:text-brand-300"
          >
            {t("openAi")}
          </button>
        </ComponentCard>
      </div>
    </div>
  );
}
function PostPicture({ post }: { post: FacebookAnalyticsPost }) {
  return post.picture ? (
    <img
      src={post.picture}
      alt=""
      referrerPolicy="no-referrer"
      loading="lazy"
      className="size-10 shrink-0 rounded-lg object-cover"
    />
  ) : (
    <span
      aria-hidden="true"
      className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-kps-primary/10 text-kps-primary"
    >
      <DocsIcon className="size-5" />
    </span>
  );
}
