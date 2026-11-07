import { useDeferredValue, useMemo, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import type {
  FacebookAnalyticsData,
  FacebookAnalyticsPost,
} from "@/types/facebookAnalytics";
import {
  analyticsNumber,
  csvCell,
  facebookTopics,
  interactionGrowth,
  observationDate,
  postInPeriod,
} from "@/utils/facebookAnalytics";
interface Props {
  data: FacebookAnalyticsData;
  onPost: (id: number) => void;
}
export default function AnalyticsContent({ data, onPost }: Props) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const [query, setQuery] = useState("");
  const [type, setType] = useState("all");
  const [report, setReport] = useState("all");
  const [topic, setTopic] = useState("all");
  const [sort, setSort] = useState("date");
  const [page, setPage] = useState(1);
  const deferred = useDeferredValue(query);
  const rows = useMemo(
    () =>
      data.posts
        .filter(
          (p) =>
            postInPeriod(p, data.from_date, data.to_date) &&
            (type === "all" || p.type === type) &&
            (topic === "all" || (p.topic ?? "none") === topic) &&
            (report === "all" ||
              (report === "yes"
                ? p.report_ids.length > 0
                : p.report_ids.length === 0)) &&
            p.message
              .toLocaleLowerCase("vi")
              .includes(deferred.trim().toLocaleLowerCase("vi")),
        )
        .sort((a, b) =>
          sort === "growth"
            ? (interactionGrowth(b) ?? -Infinity) -
              (interactionGrowth(a) ?? -Infinity)
            : sort === "likes"
              ? (b.current.likes ?? -Infinity) - (a.current.likes ?? -Infinity)
              : b.published_at.localeCompare(a.published_at),
        ),
    [data, type, report, topic, sort, deferred],
  );
  const count = Math.max(1, Math.ceil(rows.length / 25));
  const currentPage = Math.min(page, count);
  const displayed = rows.slice((currentPage - 1) * 25, currentPage * 25);
  function exportData() {
    const content =
      "\uFEFF" +
      [
        [t("title"), data.page_name, data.from_date, data.to_date],
        [
          t("content"),
          t("date"),
          t("type"),
          t("metrics.likes"),
          t("metrics.comments"),
          t("metrics.media_views"),
          t("growth"),
        ],
        ...rows.map((p) => [
          p.message,
          p.published_at,
          t(`types.${p.type}`),
          p.current.likes,
          p.current.comments,
          p.current.media_views,
          interactionGrowth(p),
        ]),
      ]
        .map((r) => r.map(csvCell).join(","))
        .join("\r\n");
    const url = URL.createObjectURL(
      new Blob([content], { type: "text/csv;charset=utf-8" }),
    );
    const a = document.createElement("a");
    a.href = url;
    a.download = `KPS-Facebook-${data.from_date}-${data.to_date}.csv`;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
  const selectClass =
    "h-11 w-full min-w-0 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200";
  return (
    <div className="space-y-4">
      <div className="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 sm:grid-cols-2 xl:grid-cols-5 dark:border-gray-800 dark:bg-white/3">
        <Input
          aria-label={t("search")}
          placeholder={t("search")}
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            setPage(1);
          }}
        />
        <select
          aria-label={t("type")}
          value={type}
          onChange={(e) => {
            setType(e.target.value);
            setPage(1);
          }}
          className={selectClass}
        >
          <option value="all">{t("allTypes")}</option>
          {["reel", "video", "photo", "post"].map((k) => (
            <option key={k} value={k}>
              {t(`types.${k}`)}
            </option>
          ))}
        </select>
        <select
          aria-label={t("topic")}
          value={topic}
          onChange={(e) => {
            setTopic(e.target.value);
            setPage(1);
          }}
          className={selectClass}
        >
          <option value="all">{t("allTopics")}</option>
          <option value="none">{t("topics.none")}</option>
          {facebookTopics.map((k) => (
            <option key={k} value={k}>
              {t(`topics.${k}`)}
            </option>
          ))}
        </select>
        <select
          aria-label={t("reportFilter")}
          value={report}
          onChange={(e) => {
            setReport(e.target.value);
            setPage(1);
          }}
          className={selectClass}
        >
          {["all", "yes", "no"].map((k) => (
            <option key={k} value={k}>
              {t(`reportFilters.${k}`)}
            </option>
          ))}
        </select>
        <Button
          variant="outline"
          size="sm"
          onClick={() => {
            setQuery("");
            setType("all");
            setTopic("all");
            setReport("all");
            setPage(1);
          }}
        >
          {t("reset")}
        </Button>
      </div>
      <section className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
        <div className="flex flex-wrap items-center justify-between gap-3 p-5">
          <div>
            <h2 className="font-semibold text-gray-800 dark:text-white">
              {t("library")}
            </h2>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              {t("libraryPolicy")}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <select
              aria-label={t("sort")}
              value={sort}
              onChange={(e) => {
                setSort(e.target.value);
                setPage(1);
              }}
              className={`${selectClass} sm:w-auto`}
            >
              {["date", "growth", "likes"].map((k) => (
                <option key={k} value={k}>
                  {t(`sorts.${k}`)}
                </option>
              ))}
            </select>
            <Button
              variant="outline"
              size="sm"
              onClick={exportData}
              disabled={rows.length === 0}
            >
              {t("export")}
            </Button>
          </div>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-start text-sm">
            <thead className="bg-gray-50 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
              <tr>
                {[
                  "content",
                  "type",
                  "likesCurrent",
                  "commentsCurrent",
                  "viewsCurrent",
                  "growth",
                  "reports",
                ].map((k) => (
                  <th
                    key={k}
                    scope="col"
                    className="px-4 py-3 text-start font-medium whitespace-nowrap"
                  >
                    {t(k)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {displayed.map((p) => (
                <PostRow key={p.id} post={p} onPost={onPost} />
              ))}
            </tbody>
          </table>
        </div>
        {rows.length === 0 && (
          <p
            role="status"
            className="p-10 text-center text-sm text-gray-500 dark:text-gray-400"
          >
            {t("noResults")}
          </p>
        )}
        <div className="flex flex-wrap items-center justify-between gap-3 p-4 text-xs text-gray-500 dark:text-gray-400">
          <span>{t("resultCount", { count: rows.length })}</span>
          <div className="flex items-center gap-3">
            <Button
              size="sm"
              variant="outline"
              onClick={() => setPage((n) => n - 1)}
              disabled={currentPage <= 1}
            >
              {t("previous")}
            </Button>
            {t("pageNumber", { current: currentPage, total: count })}
            <Button
              size="sm"
              variant="outline"
              onClick={() => setPage((n) => n + 1)}
              disabled={currentPage >= count}
            >
              {t("next")}
            </Button>
          </div>
        </div>
      </section>
    </div>
  );
}
function PostRow({
  post: p,
  onPost,
}: {
  post: FacebookAnalyticsPost;
  onPost: (id: number) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  return (
    <tr>
      <td className="px-4 py-4">
        <button
          type="button"
          onClick={() => onPost(p.id)}
          className="flex max-w-80 min-w-56 items-center gap-3 text-start text-gray-800 hover:text-kps-primary dark:text-gray-100 dark:hover:text-brand-300"
        >
          {p.picture && (
            <img
              src={p.picture}
              alt=""
              referrerPolicy="no-referrer"
              loading="lazy"
              className="size-10 shrink-0 rounded-lg object-cover"
            />
          )}
          <span className="min-w-0">
            <span className="line-clamp-2 text-sm font-medium">
              {p.message || t("untitled")}
            </span>
            <span className="mt-1 block text-xs text-gray-400">
              {observationDate(p.published_at)} ·{" "}
              {t(`topics.${p.topic ?? "none"}`)}
            </span>
          </span>
        </button>
      </td>
      <td className="px-4 py-4 text-xs whitespace-nowrap text-gray-500 dark:text-gray-400">
        {t(`types.${p.type}`)}
      </td>
      {(["likes", "comments", "media_views"] as const).map((k) => (
        <td
          key={k}
          className="px-4 py-4 text-gray-700 tabular-nums dark:text-gray-200"
        >
          {analyticsNumber(p.current[k])}
        </td>
      ))}
      <td className="px-4 py-4 text-kps-primary tabular-nums dark:text-brand-300">
        {analyticsNumber(interactionGrowth(p))}
      </td>
      <td className="px-4 py-4">
        {p.report_ids.length ? (
          p.report_ids.map((id) => (
            <Link
              key={id}
              to={`/reports/${id}`}
              className="me-2 block text-xs whitespace-nowrap text-kps-primary underline dark:text-brand-300"
            >
              {t("reportNumber", { id })}
            </Link>
          ))
        ) : (
          <span className="text-xs text-gray-400">{t("notLinked")}</span>
        )}
      </td>
    </tr>
  );
}
