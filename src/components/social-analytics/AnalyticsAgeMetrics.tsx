import { useEffect, useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import { getFacebookMilestones } from "@/services/facebookAnalyticsService";
import type {
  FacebookAnalyticsData,
  FacebookMilestoneData,
  FacebookMetric,
} from "@/types/facebookAnalytics";
import { analyticsNumber, observationDate } from "@/utils/facebookAnalytics";
import { formatDateOnly } from "@/utils/dateOnly";
import AnalyticsMilestoneValue from "./AnalyticsMilestoneValue";

export default function AnalyticsAgeMetrics({
  data,
  onPost,
}: {
  data: FacebookAnalyticsData;
  onPost: (id: number) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const [basis, setBasis] = useState<"matured" | "published">("matured");
  const [metric, setMetric] = useState<FacebookMetric>("likes");
  const [filters, setFilters] = useState({
    type: "all",
    objective: "all",
    distribution: "all",
  });
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [response, setResponse] = useState<{
    key: string;
    value: FacebookMilestoneData;
  } | null>(null);
  const [failed, setFailed] = useState<string | null>(null);
  const [loading, setLoading] = useState<string | null>(null);
  const key = `${data.entry_id}:${data.from_date}:${data.to_date}:${basis}`;
  useEffect(() => {
    const controller = new AbortController();
    let active = true,
      fetching = false;
    async function load() {
      if (fetching) return;
      fetching = true;
      setLoading(key);
      try {
        const result = await getFacebookMilestones(
          data.entry_id,
          data.from_date,
          data.to_date,
          basis,
          AbortSignal.any([controller.signal, AbortSignal.timeout(45000)]),
        );
        if (active) {
          setResponse({ key, value: result.data });
          setFailed(null);
        }
      } catch {
        if (active) {
          setFailed(key);
          setResponse(null);
        }
      } finally {
        fetching = false;
        if (active) setLoading(null);
      }
    }
    void load();
    const timer = window.setInterval(() => {
      if (document.visibilityState === "visible") void load();
    }, 60000);
    return () => {
      active = false;
      controller.abort();
      window.clearInterval(timer);
    };
  }, [key, basis, data.entry_id, data.from_date, data.to_date, data, revision]);
  const result = response?.key === key ? response.value : null;
  const matches = (row: {
    type: string;
    objective: string | null;
    distribution: string | null;
  }) =>
    Object.entries(filters).every(
      ([k, value]) =>
        value === "all" || (row[k as keyof typeof row] ?? "unknown") === value,
    );
  const rows = result?.posts.filter(matches) ?? [];
  const groups = result?.groups.filter((g) => matches(g.identity)) ?? [];
  const stats = useMemo(() => {
    const rows = result?.posts ?? [];
    return {
      total: rows.length,
      ready: rows.filter((p) => p.milestones.D7.status === "on_time").length,
      waiting: rows.filter((p) =>
        ["waiting", "due"].includes(p.milestones.D7.status),
      ).length,
      unavailable: rows.filter((p) =>
        ["late", "missing"].includes(p.milestones.D7.status),
      ).length,
    };
  }, [result]);
  const pages = Math.max(1, Math.ceil(rows.length / 25)),
    current = Math.min(page, pages);
  const selectClass =
    "h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200";
  return (
    <div className="space-y-4">
      <section className="space-y-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="font-semibold text-gray-800 dark:text-gray-100">
              {t("review.milestoneTitle")}
            </h2>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              {t("age.policy")}
            </p>
          </div>
          <Button
            size="sm"
            variant="outline"
            disabled={loading === key}
            onClick={() => setRevision((n) => n + 1)}
          >
            {t("reload")}
          </Button>
        </div>
        <div className="flex flex-wrap gap-2">
          {(["matured", "published"] as const).map((k) => (
            <button
              type="button"
              key={k}
              aria-pressed={basis === k}
              onClick={() => {
                setBasis(k);
                setPage(1);
              }}
              className={
                "min-h-10 rounded-lg border px-3 py-2 text-xs " +
                (basis === k
                  ? "border-kps-primary bg-kps-primary/10 text-kps-primary dark:text-brand-300"
                  : "border-gray-200 text-gray-500 dark:border-gray-700 dark:text-gray-400")
              }
            >
              {t(`review.cohorts.${k}`)}
            </button>
          ))}
        </div>
        <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
          {t(`age.scope.${basis}`, {
            from: formatDateOnly(data.from_date),
            to: formatDateOnly(data.to_date),
          })}
        </p>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <label className="space-y-1 text-xs text-gray-500 dark:text-gray-400">
            <span>{t("type")}</span>
            <select
              className={selectClass}
              value={filters.type}
              onChange={(e) => {
                setFilters({ ...filters, type: e.target.value });
                setPage(1);
              }}
            >
              <option value="all">{t("allTypes")}</option>
              {["reel", "video", "photo", "post"].map((k) => (
                <option key={k} value={k}>
                  {t(`types.${k}`)}
                </option>
              ))}
            </select>
          </label>
          {(["objective", "distribution"] as const).map((k) => (
            <label
              key={k}
              className="space-y-1 text-xs text-gray-500 dark:text-gray-400"
            >
              <span>{t(`age.${k}`)}</span>
              <select
                className={selectClass}
                value={filters[k]}
                onChange={(e) => {
                  setFilters({ ...filters, [k]: e.target.value });
                  setPage(1);
                }}
              >
                <option value="all">{t("age.all")}</option>
                {(k === "objective"
                  ? ["unknown", "awareness", "engagement", "traffic", "leads"]
                  : ["unknown", "organic", "paid"]
                ).map((v) => (
                  <option value={v} key={v}>
                    {t(`age.${k}Values.${v}`)}
                  </option>
                ))}
              </select>
            </label>
          ))}
          <label className="space-y-1 text-xs text-gray-500 dark:text-gray-400">
            <span>{t("metric")}</span>
            <select
              className={selectClass}
              value={metric}
              onChange={(e) => setMetric(e.target.value as FacebookMetric)}
            >
              {(["likes", "comments", "media_views"] as const).map((k) => (
                <option key={k} value={k}>
                  {t(`age.metricNames.${k}`)}
                </option>
              ))}
            </select>
          </label>
        </div>
        <p className="rounded-lg bg-warning-50 p-3 text-xs leading-relaxed text-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
          {t("age.manualNote")}
        </p>
      </section>
      {failed === key ? (
        <div
          role="alert"
          className="rounded-xl bg-error-50 p-4 text-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
        >
          {t("age.loadError")}
        </div>
      ) : !result ? (
        <p
          role="status"
          className="p-4 text-sm text-gray-500 dark:text-gray-400"
        >
          {t("loading")}
        </p>
      ) : (
        <>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {t("age.asOf", { at: observationDate(result.as_of) })}
          </p>
          <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
            {Object.entries(stats).map(([label, value]) => (
              <article
                key={label}
                className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/3"
              >
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  {t(`age.counts.${label}`)}
                </p>
                <p className="mt-2 text-2xl font-semibold text-gray-800 dark:text-gray-100">
                  {value}
                </p>
              </article>
            ))}
          </div>
          <section className="space-y-3 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
            <h3 className="font-semibold text-gray-800 dark:text-gray-100">
              {t("age.compareTitle")}
            </h3>
            <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
              {t("age.comparePolicy", {
                n: result.policy.minimum_posts,
                coverage: result.policy.minimum_coverage * 100,
              })}
            </p>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t("age.previousPeriod", {
                from: formatDateOnly(result.previous_from),
                to: formatDateOnly(result.previous_to),
              })}
            </p>
            {!result.catalogue_covered && (
              <p
                role="status"
                className="text-xs text-warning-700 dark:text-warning-400"
              >
                {t("age.reasons.catalogue_incomplete")}
              </p>
            )}
            <div className="grid gap-3 lg:grid-cols-2">
              {groups.map((g) => {
                const a = g.current.metrics[metric],
                  b = g.previous.metrics[metric],
                  comparison = g.comparison[metric];
                return (
                  <article
                    key={JSON.stringify(g.identity)}
                    className="min-w-0 space-y-2 rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                  >
                    <p className="text-sm font-medium text-gray-800 dark:text-gray-100">
                      {t(`types.${g.identity.type}`)} ·{" "}
                      {t(
                        `age.objectiveValues.${g.identity.objective ?? "unknown"}`,
                      )}{" "}
                      ·{" "}
                      {t(
                        `age.distributionValues.${g.identity.distribution ?? "unknown"}`,
                      )}
                    </p>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                      {t("topic")}: {t(`topics.${g.identity.topic ?? "none"}`)}
                    </p>
                    <div className="grid grid-cols-2 gap-3">
                      {(
                        [
                          ["current", a, g.current.posts],
                          ["previous", b, g.previous.posts],
                        ] as const
                      ).map(([p, statistic, count]) => (
                        <div key={p}>
                          <p className="text-xs text-gray-500 dark:text-gray-400">
                            {t(`age.period.${p}`)}
                          </p>
                          <p className="text-xl font-semibold text-gray-800 dark:text-gray-100">
                            {analyticsNumber(statistic.median)}
                          </p>
                          <p className="text-xs text-gray-500 dark:text-gray-400">
                            {t("age.coverage", {
                              n: statistic.posts,
                              total: count,
                              percent: Math.round(statistic.coverage * 100),
                            })}
                          </p>
                        </div>
                      ))}
                    </div>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                      {comparison.reason
                        ? t(`age.reasons.${comparison.reason}`)
                        : t("age.change", {
                            percent: analyticsNumber(comparison.percent),
                            difference: analyticsNumber(comparison.difference),
                          })}
                    </p>
                    <details className="text-xs text-gray-500 dark:text-gray-400">
                      <summary className="cursor-pointer">
                        {t("age.definition")}
                      </summary>
                      <p className="mt-1 break-all">{g.identity.definition}</p>
                    </details>
                  </article>
                );
              })}
            </div>
            {!groups.length && (
              <p className="text-sm text-gray-500 dark:text-gray-400">
                {t("review.noMilestonePosts")}
              </p>
            )}
          </section>
          <section className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
            <div className="hidden overflow-x-auto md:block">
              <table className="w-full text-start text-sm">
                <thead className="bg-gray-50 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                  <tr>
                    <th scope="col" className="p-4">
                      {t("content")}
                    </th>
                    {["D1", "D3", "D7"].map((k) => (
                      <th key={k} scope="col" className="p-4">
                        {k} · {t(`age.metricNames.${metric}`)}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {rows.slice((current - 1) * 25, current * 25).map((p) => (
                    <tr key={p.id}>
                      <td className="max-w-72 p-4 align-top">
                        <button
                          type="button"
                          className="line-clamp-2 text-start break-words text-kps-primary underline dark:text-brand-300"
                          onClick={() => onPost(p.id)}
                        >
                          {p.message || t("untitled")}
                        </button>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                          {observationDate(p.published_at)}
                        </p>
                      </td>
                      {(["D1", "D3", "D7"] as const).map((k) => (
                        <td key={k} className="p-4 align-top">
                          <AnalyticsMilestoneValue
                            point={p.milestones[k]}
                            metric={metric}
                          />
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="divide-y divide-gray-100 px-4 md:hidden dark:divide-gray-800">
              {rows.slice((current - 1) * 25, current * 25).map((p) => (
                <article key={p.id} className="space-y-3 py-4">
                  <button
                    type="button"
                    onClick={() => onPost(p.id)}
                    className="text-start text-sm break-words text-kps-primary underline dark:text-brand-300"
                  >
                    {p.message || t("untitled")}
                  </button>
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    {observationDate(p.published_at)}
                  </p>
                  <div className="grid grid-cols-1 gap-3 xsm:grid-cols-3">
                    {(["D1", "D3", "D7"] as const).map((k) => (
                      <div
                        key={k}
                        className="rounded-lg bg-gray-50 p-3 dark:bg-gray-800"
                      >
                        <p className="mb-1 text-xs font-semibold text-gray-700 dark:text-gray-200">
                          {k}
                        </p>
                        <AnalyticsMilestoneValue
                          point={p.milestones[k]}
                          metric={metric}
                        />
                      </div>
                    ))}
                  </div>
                </article>
              ))}
            </div>
            {!rows.length && (
              <p
                role="status"
                className="p-8 text-center text-sm text-gray-500 dark:text-gray-400"
              >
                {t("review.noMilestonePosts")}
              </p>
            )}
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 p-4 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
              <span>{t("age.filteredPosts", { count: rows.length })}</span>
              <div className="flex items-center gap-3">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={current <= 1}
                  onClick={() => setPage((n) => n - 1)}
                >
                  {t("previous")}
                </Button>
                {t("pageNumber", { current, total: pages })}
                <Button
                  size="sm"
                  variant="outline"
                  disabled={current >= pages}
                  onClick={() => setPage((n) => n + 1)}
                >
                  {t("next")}
                </Button>
              </div>
            </div>
          </section>
        </>
      )}
    </div>
  );
}
