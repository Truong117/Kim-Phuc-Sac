import ComponentCard from "@/components/common/ComponentCard";
import Input from "@/components/form/input/InputField";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import WeeklyReportingTable from "@/components/dashboard/WeeklyReportingTable";
import { getWeeklyReporting } from "@/services/dashboardService";
import type { DashboardWeeklyReporting } from "@/types/dashboard";
import { addDaysToDateOnly, formatDateOnly } from "@/utils/dateOnly";
import { filterWeeklyReporters } from "@/utils/weeklyReporting";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";

const PAGE_SIZE = 10;

export default function WeeklyReporting() {
  const { t } = useTranslation("common", {
    keyPrefix: "overview.weeklyReporting",
  });
  const [weekStart, setWeekStart] = useState<string>();
  const [data, setData] = useState<DashboardWeeklyReporting | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [retryKey, setRetryKey] = useState(0);
  const [search, setSearch] = useState("");
  const [department, setDepartment] = useState("");
  const [missingOnly, setMissingOnly] = useState(false);
  const [page, setPage] = useState(1);

  useEffect(() => {
    const controller = new AbortController();
    getWeeklyReporting(weekStart, controller.signal)
      .then((result) => {
        if (!controller.signal.aborted) setData(result);
      })
      .catch(() => {
        if (!controller.signal.aborted) setFailed(true);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [weekStart, retryKey]);

  const changeWeek = (next?: string) => {
    setLoading(true);
    setFailed(false);
    setPage(1);
    setWeekStart(next);
    // Refresh also works when already on the current week.
    setRetryKey((value) => value + 1);
  };
  const rows = filterWeeklyReporters(
    data?.rows ?? [],
    search,
    department,
    missingOnly,
  );
  const pageCount = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
  const currentPage = Math.min(page, pageCount);
  const departmentOptions = Array.from(
    new Map(
      (data?.rows ?? []).map((row) => [
        String(row.department?.id ?? "unassigned"),
        row.department?.name ?? t("unassigned"),
      ]),
    ),
  ).map(([value, label]) => ({ value, label }));

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      <div aria-busy={loading} className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p
            className="font-semibold text-gray-800 dark:text-white/90"
            aria-live="polite"
          >
            {data
              ? `${formatDateOnly(data.week_start)} – ${formatDateOnly(data.week_end)}`
              : t("currentWeek")}
          </p>
          <div className="flex flex-wrap gap-2">
            <Button
              size="sm"
              variant="outline"
              disabled={loading || !data}
              onClick={() =>
                data && changeWeek(addDaysToDateOnly(data.week_start, -7))
              }
            >
              {t("previousWeek")}
            </Button>
            <Button
              size="sm"
              variant="outline"
              disabled={loading}
              onClick={() => changeWeek()}
            >
              {t("currentWeek")}
            </Button>
            <Button
              size="sm"
              variant="outline"
              disabled={
                loading || !data || data.week_start >= data.current_week_start
              }
              onClick={() =>
                data && changeWeek(addDaysToDateOnly(data.week_start, 7))
              }
            >
              {t("nextWeek")}
            </Button>
          </div>
        </div>
        {loading ? (
          <p
            role="status"
            className="py-8 text-center text-theme-sm text-gray-500 dark:text-gray-400"
          >
            {t("loading")}
          </p>
        ) : failed || !data ? (
          <div
            role="alert"
            className="rounded-xl bg-error-50 p-4 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
          >
            <p>{t("loadError")}</p>
            <Button
              size="sm"
              variant="outline"
              className="mt-3"
              onClick={() => changeWeek(weekStart)}
            >
              {t("retry")}
            </Button>
          </div>
        ) : (
          <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              {(
                [
                  "people",
                  "submitted",
                  "people_missing",
                  "missing_days",
                ] as const
              ).map((key) => (
                <div
                  key={key}
                  className="rounded-xl bg-gray-50 p-4 dark:bg-gray-800/60"
                >
                  <p className="text-theme-sm text-gray-500 dark:text-gray-400">
                    {t(`totals.${key}`)}
                  </p>
                  <p
                    className={`mt-1 text-title-sm font-bold ${key.includes("missing") ? "text-error-600 dark:text-error-400" : "text-gray-900 dark:text-white"}`}
                  >
                    {data.totals[key]}
                  </p>
                </div>
              ))}
            </div>
            <div className="flex flex-wrap items-end gap-4">
              <label className="min-w-48 flex-1 text-theme-sm text-gray-700 dark:text-gray-300">
                <span className="mb-1.5 block">{t("search")}</span>
                <Input
                  type="search"
                  value={search}
                  placeholder={t("searchPlaceholder")}
                  onChange={(event) => {
                    setSearch(event.target.value);
                    setPage(1);
                  }}
                />
              </label>
              <label className="min-w-48 flex-1 text-theme-sm text-gray-700 dark:text-gray-300">
                <span className="mb-1.5 block">{t("department")}</span>
                <Select
                  options={departmentOptions}
                  value={department}
                  allowClear
                  placeholder={t("allDepartments")}
                  onChange={(value) => {
                    setDepartment(value);
                    setPage(1);
                  }}
                />
              </label>
              <label className="flex min-h-11 cursor-pointer items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                <input
                  type="checkbox"
                  checked={missingOnly}
                  onChange={(event) => {
                    setMissingOnly(event.target.checked);
                    setPage(1);
                  }}
                  className="size-4 accent-kps-primary"
                />
                {t("missingOnly")}
              </label>
            </div>
            <p className="text-theme-sm text-gray-500 dark:text-gray-400">
              {t("legend")}
            </p>
            <WeeklyReportingTable
              dates={data.dates}
              rows={rows.slice(
                (currentPage - 1) * PAGE_SIZE,
                currentPage * PAGE_SIZE,
              )}
            />
            <div className="flex flex-wrap items-center justify-between gap-3 text-theme-sm text-gray-500 dark:text-gray-400">
              <p aria-live="polite">
                {t("pagination", {
                  count: rows.length,
                  page: currentPage,
                  pages: pageCount,
                })}
              </p>
              {pageCount > 1 && (
                <div className="flex gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={currentPage === 1}
                    onClick={() => setPage(currentPage - 1)}
                  >
                    {t("previousPage")}
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={currentPage === pageCount}
                    onClick={() => setPage(currentPage + 1)}
                  >
                    {t("nextPage")}
                  </Button>
                </div>
              )}
            </div>
          </>
        )}
      </div>
    </ComponentCard>
  );
}
