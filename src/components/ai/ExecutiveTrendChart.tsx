import ComponentCard from "@/components/common/ComponentCard";
import type { AiExecutiveDailyTrend } from "@/types/aiExecutiveDashboard";
import { formatAiReportDate } from "@/utils/aiReportDate";
import {
  buildExecutiveTrendFacts,
  hasExecutiveTrendData,
} from "@/utils/aiExecutiveDashboard";
import { useTranslation } from "react-i18next";

interface ExecutiveTrendChartProps {
  rows: AiExecutiveDailyTrend[];
}

const series = [
  { key: "reports", className: "bg-kps-primary" },
  { key: "completed", className: "bg-success-500" },
  { key: "inProgress", className: "bg-warning-500" },
  { key: "blocked", className: "bg-error-500" },
] as const;

const valueFor = (
  row: AiExecutiveDailyTrend,
  key: (typeof series)[number]["key"],
) => {
  if (key === "reports") return row.reports_submitted;
  if (key === "completed") return row.work_items.completed;
  if (key === "inProgress") return row.work_items.in_progress;
  return row.work_items.blocked;
};

export default function ExecutiveTrendChart({
  rows,
}: ExecutiveTrendChartProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "aiExecutiveDashboard.trend",
  });

  if (!hasExecutiveTrendData(rows)) {
    return (
      <ComponentCard title={t("title")} desc={t("description")} compact>
        <div className="py-8 text-center">
          <p className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {t("emptyTitle")}
          </p>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {t("emptyDescription")}
          </p>
        </div>
      </ComponentCard>
    );
  }

  const maxValue = Math.max(
    1,
    ...rows.flatMap((row) => series.map(({ key }) => valueFor(row, key))),
  );
  const facts = buildExecutiveTrendFacts(rows);

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      <div className="flex flex-wrap gap-x-5 gap-y-2" aria-label={t("legend")}>
        {series.map(({ key, className }) => (
          <span
            key={key}
            className="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300"
          >
            <span
              className={`size-2.5 rounded-full ${className}`}
              aria-hidden="true"
            />
            {t(`series.${key}`)}
          </span>
        ))}
      </div>

      <div className="overflow-x-auto pb-2">
        <div className="flex h-72 w-max min-w-full items-end gap-2 border-b border-gray-200 pt-12 dark:border-gray-700">
          {rows.map((row) => {
            const formattedDate = formatAiReportDate(row.date);
            const tooltip = t("tooltip", {
              date: formattedDate,
              reports: row.reports_submitted,
              completed: row.work_items.completed,
              inProgress: row.work_items.in_progress,
              blocked: row.work_items.blocked,
            });

            return (
              <button
                key={row.date}
                type="button"
                aria-label={tooltip}
                className="group relative flex h-full min-w-12 flex-1 flex-col justify-end rounded-t-md focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none"
              >
                <span className="pointer-events-none absolute start-1/2 bottom-full z-10 mb-2 w-52 rounded-lg bg-gray-900 px-3 py-2 text-start text-xs leading-5 text-white opacity-0 shadow-theme-md transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100 ltr:-translate-x-1/2 rtl:translate-x-1/2 dark:bg-gray-700">
                  {tooltip}
                </span>
                <span className="flex h-full items-end justify-center gap-1 px-1">
                  {series.map(({ key, className }) => {
                    const value = valueFor(row, key);
                    const height =
                      value === 0 ? 0 : Math.max(4, (value / maxValue) * 100);

                    return (
                      <span
                        key={key}
                        className={`w-1.5 rounded-t-sm transition-[height] sm:w-2 ${className}`}
                        style={{ height: `${height}%` }}
                        aria-hidden="true"
                      />
                    );
                  })}
                </span>
                <span className="mt-2 block pb-2 text-center text-xs whitespace-nowrap text-gray-500 dark:text-gray-400">
                  {formattedDate.slice(0, 5)}
                </span>
              </button>
            );
          })}
        </div>
      </div>

      <section className="rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/3">
        <h4 className="text-sm font-semibold text-gray-900 dark:text-white">
          {t("facts.title")}
        </h4>
        {facts === null ? (
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {t("facts.insufficient")}
          </p>
        ) : (
          <ul className="mt-2 grid gap-2 text-sm text-gray-600 md:grid-cols-3 dark:text-gray-300">
            <li>
              {t("facts.coverage", {
                observed: facts.observedDays,
                total: facts.totalDays,
              })}
            </li>
            <li>
              {t("facts.peak", {
                date: formatAiReportDate(facts.peakDate),
                count: facts.peakWorkItems,
              })}
            </li>
            <li>
              {facts.blockedItems > 0
                ? t("facts.blocked", {
                    items: facts.blockedItems,
                    days: facts.blockedDays,
                  })
                : t("facts.noBlocked")}
            </li>
          </ul>
        )}
        <p className="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">
          {t("facts.limit")}
        </p>
      </section>
    </ComponentCard>
  );
}
