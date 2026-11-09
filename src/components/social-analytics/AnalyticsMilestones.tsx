import { useEffect, useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import type { FacebookAnalyticsData } from "@/types/facebookAnalytics";
import { dateOnlyInTimeZone, formatDateOnly } from "@/utils/dateOnly";
import { observationDate, postMilestoneAt } from "@/utils/facebookAnalytics";

export default function AnalyticsMilestones({
  data,
  onPost,
}: {
  data: FacebookAnalyticsData;
  onPost: (id: number) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const [cohort, setCohort] = useState("matured");
  const [type, setType] = useState("all");
  const [page, setPage] = useState(1);
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 60000);
    return () => window.clearInterval(timer);
  }, []);
  const rows = useMemo(
    () =>
      data.posts
        .filter((p) => {
          const date = dateOnlyInTimeZone(
            new Date(
              cohort === "matured"
                ? postMilestoneAt(p.published_at, 7)
                : p.published_at,
            ),
          );
          return (
            date >= data.from_date &&
            date <= data.to_date &&
            (type === "all" || p.type === type)
          );
        })
        .sort((a, b) => b.published_at.localeCompare(a.published_at)),
    [data, cohort, type],
  );
  const pending = rows.filter(
    (p) => new Date(postMilestoneAt(p.published_at, 7)).getTime() > now,
  ).length;
  const pages = Math.max(1, Math.ceil(rows.length / 25)),
    current = Math.min(page, pages);
  const shown = rows.slice((current - 1) * 25, current * 25);
  const status = (published: string) =>
    new Date(postMilestoneAt(published, 7)).getTime() > now
      ? t("review.waitingUntil", {
          at: observationDate(postMilestoneAt(published, 7)),
        })
      : t("review.unclassifiedMilestone");
  return (
    <div className="space-y-4">
      <section className="space-y-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="font-semibold text-gray-800 dark:text-gray-100">
              {t("review.milestoneTitle")}
            </h2>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              {t("review.milestoneDefinition")}
            </p>
          </div>
          <span className="rounded-md bg-warning-50 px-2 py-1 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
            {t("review.nextPhase")}
          </span>
        </div>
        <p
          role="status"
          className="rounded-lg bg-warning-50 p-3 text-xs leading-relaxed text-warning-800 dark:bg-warning-500/10 dark:text-warning-400"
        >
          {t("review.milestoneNotReady")}
        </p>
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div className="flex flex-wrap gap-2">
            {["matured", "published"].map((key) => (
              <button
                type="button"
                key={key}
                aria-pressed={cohort === key}
                onClick={() => {
                  setCohort(key);
                  setPage(1);
                }}
                className={
                  "min-h-10 rounded-lg border px-3 py-2 text-xs " +
                  (cohort === key
                    ? "border-kps-primary bg-kps-primary/10 text-kps-primary dark:text-brand-300"
                    : "border-gray-200 text-gray-500 dark:border-gray-700 dark:text-gray-400")
                }
              >
                {t("review.cohorts." + key)}
              </button>
            ))}
          </div>
          <label className="w-full space-y-1 text-xs text-gray-500 sm:w-auto dark:text-gray-400">
            <span className="block">{t("type")}</span>
            <select
              value={type}
              onChange={(e) => {
                setType(e.target.value);
                setPage(1);
              }}
              className="h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-900"
            >
              <option value="all">{t("allTypes")}</option>
              {["reel", "video", "photo", "post"].map((k) => (
                <option key={k} value={k}>
                  {t("types." + k)}
                </option>
              ))}
            </select>
          </label>
        </div>
        <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
          {t("review.cohortScope." + cohort, {
            from: formatDateOnly(data.from_date),
            to: formatDateOnly(data.to_date),
          })}
        </p>
      </section>
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        {[
          ["loadedCohort", rows.length],
          ["waitingAge", pending],
          ["needsMilestone", rows.length - pending],
          ["medianD7", "—"],
        ].map(([label, value]) => (
          <article
            key={label}
            className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/3"
          >
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t("review." + label)}
            </p>
            <p className="mt-2 text-2xl font-semibold text-gray-800 tabular-nums dark:text-gray-100">
              {value}
            </p>
          </article>
        ))}
      </div>
      <section className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
        <div className="hidden overflow-x-auto md:block">
          <table className="w-full text-start text-sm">
            <thead className="bg-gray-50 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
              <tr>
                <th className="p-4" scope="col">
                  {t("content")}
                </th>
                {[1, 3, 7].map((d) => (
                  <th key={d} className="p-4" scope="col">
                    D{d}
                  </th>
                ))}
                <th className="p-4" scope="col">
                  {t("review.milestoneStatus")}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {shown.map((p) => (
                <tr key={p.id}>
                  <td className="max-w-80 p-4">
                    <button
                      type="button"
                      onClick={() => onPost(p.id)}
                      className="line-clamp-2 max-w-full text-start break-words text-kps-primary underline dark:text-brand-300"
                    >
                      {p.message || t("untitled")}
                    </button>
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                      {observationDate(p.published_at)}
                    </p>
                  </td>
                  {[1, 3, 7].map((d) => (
                    <td key={d} className="p-4 text-gray-400">
                      —
                    </td>
                  ))}
                  <td className="p-4 text-xs text-warning-700 dark:text-warning-400">
                    {status(p.published_at)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="divide-y divide-gray-100 px-4 md:hidden dark:divide-gray-800">
          {shown.map((p) => (
            <article key={p.id} className="py-4">
              <button
                type="button"
                onClick={() => onPost(p.id)}
                className="text-start text-sm text-kps-primary underline dark:text-brand-300"
              >
                {p.message || t("untitled")}
              </button>
              <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {observationDate(p.published_at)}
              </p>
              <div className="mt-3 grid grid-cols-3 gap-2">
                {[1, 3, 7].map((d) => (
                  <p
                    key={d}
                    className="rounded-lg bg-gray-50 p-2 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                  >
                    D{d}
                    <span className="mt-1 block text-lg">—</span>
                  </p>
                ))}
              </div>
              <p className="mt-2 text-xs text-warning-700 dark:text-warning-400">
                {status(p.published_at)}
              </p>
            </article>
          ))}
        </div>
        {rows.length === 0 && (
          <p
            role="status"
            className="p-8 text-center text-sm text-gray-500 dark:text-gray-400"
          >
            {t("review.noMilestonePosts")}
          </p>
        )}
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 p-4 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
          <span>{t("review.loadedOnly")}</span>
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
    </div>
  );
}
