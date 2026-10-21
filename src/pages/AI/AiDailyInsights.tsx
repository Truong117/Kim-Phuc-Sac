import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import AiReportDatePicker from "@/components/ai/AiReportDatePicker";
import { ApiServiceError } from "@/services/apiClient";
import {
  createAiDailyReport,
  findAiDailyReport,
  getAiDailyAvailableDates,
  getAiDailyHistory,
  getAiDailyReport,
  getAiDailySummary,
} from "@/services/aiDailyReportService";
import type {
  AiDailyReport,
  AiDailySummary,
  AiEvidence,
} from "@/types/aiDailyReport";
import { formatAiReportDate } from "@/utils/aiReportDate";
import { useCallback, useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

type Tab = "overview" | "history" | "sources";

const isAbortError = (error: unknown) =>
  error instanceof DOMException && error.name === "AbortError";

const readableTime = (iso: string | null, empty: string) =>
  iso
    ? new Intl.DateTimeFormat("vi-VN", {
        timeZone: "Asia/Ho_Chi_Minh",
        dateStyle: "short",
        timeStyle: "short",
      }).format(new Date(iso))
    : empty;

function StatCard({
  label,
  value,
  variant = "default",
}: {
  label: string;
  value: number;
  variant?: "default" | "success" | "warning";
}) {
  const color =
    variant === "success"
      ? "text-success-700 dark:text-success-300"
      : variant === "warning"
        ? "text-warning-700 dark:text-warning-300"
        : "text-gray-900 dark:text-white";

  return (
    <div className="rounded-2xl border border-gray-200 bg-white px-4 py-5 shadow-theme-xs dark:border-gray-800 dark:bg-white/3">
      <div className={`text-2xl font-semibold tabular-nums ${color}`}>
        {value}
      </div>
      <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{label}</p>
    </div>
  );
}

function EvidenceList({
  items,
  empty,
  sourceLabel,
}: {
  items: AiEvidence[];
  empty: string;
  sourceLabel: (id: number) => string;
}) {
  if (items.length === 0) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">{empty}</p>;
  }

  return (
    <div className="space-y-3">
      {items.map((item, index) => (
        <div
          key={`${index}-${item.text}`}
          className="rounded-xl border border-gray-200 bg-gray-50/50 px-4 py-3 dark:border-gray-700 dark:bg-white/2"
        >
          <p className="text-sm leading-6 whitespace-pre-wrap text-gray-800 dark:text-gray-200">
            {item.text}
          </p>
          <div className="mt-2 flex flex-wrap gap-2">
            {item.report_ids.map((id) => (
              <Link
                key={id}
                to={`/reports/${id}`}
                className="text-xs font-medium text-kps-primary underline underline-offset-2 transition-colors hover:text-kps-primary-hover dark:text-sidebar-selected"
              >
                {sourceLabel(id)}
              </Link>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}

export default function AiDailyInsights() {
  const { t } = useTranslation("common", { keyPrefix: "aiDailyReport" });
  const [date, setDate] = useState("");
  const selectedDateRef = useRef("");
  const reviewingHistoryRef = useRef(false);
  const [availableDates, setAvailableDates] = useState<string[]>([]);
  const [availableDatesLoading, setAvailableDatesLoading] = useState(true);
  const [availableDatesError, setAvailableDatesError] = useState<string | null>(
    null,
  );
  const [availabilityRevision, setAvailabilityRevision] = useState(0);
  const [tab, setTab] = useState<Tab>("overview");
  const [summary, setSummary] = useState<AiDailySummary | null>(null);
  const [run, setRun] = useState<AiDailyReport | null>(null);
  const [history, setHistory] = useState<AiDailyReport[]>([]);
  const [historyRevision, setHistoryRevision] = useState(0);
  const [loading, setLoading] = useState(false);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const activeRunId = run?.id;
  const activeRunStatus = run?.status;

  const selectDate = useCallback((nextDate: string) => {
    if (selectedDateRef.current === nextDate) return;
    selectedDateRef.current = nextDate;
    setSummary(null);
    setRun(null);
    setError(null);
    setLoading(Boolean(nextDate));
    setDate(nextDate);
  }, []);

  const refreshAvailableDates = useCallback(() => {
    setAvailableDatesLoading(true);
    setAvailableDatesError(null);
    setAvailabilityRevision((revision) => revision + 1);
  }, []);

  const selectTab = (nextTab: Tab) => {
    setTab(nextTab);
    setError(null);
    if (nextTab === "history") setHistoryLoading(true);
  };

  useEffect(() => {
    const controller = new AbortController();
    getAiDailyAvailableDates(controller.signal)
      .then(({ dates }) => {
        if (controller.signal.aborted) return;
        const sortedDates = [...dates].sort((first, second) =>
          second.localeCompare(first),
        );
        setAvailableDates(sortedDates);
        setAvailableDatesError(null);
        const selectedDate = selectedDateRef.current;
        if (
          !reviewingHistoryRef.current &&
          !sortedDates.includes(selectedDate)
        ) {
          selectDate(sortedDates[0] ?? "");
        }
      })
      .catch((loadError: unknown) => {
        if (controller.signal.aborted || isAbortError(loadError)) return;
        setAvailableDates([]);
        selectDate("");
        setAvailableDatesError(
          loadError instanceof ApiServiceError && loadError.status === 403
            ? loadError.message || t("forbidden")
            : t("availableDatesError"),
        );
      })
      .finally(() => {
        if (!controller.signal.aborted) setAvailableDatesLoading(false);
      });

    return () => controller.abort();
  }, [availabilityRevision, selectDate, t]);

  useEffect(() => {
    const refreshWhenVisible = () => {
      if (document.visibilityState === "visible") refreshAvailableDates();
    };

    window.addEventListener("focus", refreshAvailableDates);
    document.addEventListener("visibilitychange", refreshWhenVisible);

    return () => {
      window.removeEventListener("focus", refreshAvailableDates);
      document.removeEventListener("visibilitychange", refreshWhenVisible);
    };
  }, [refreshAvailableDates]);

  useEffect(() => {
    if (!date) return;
    const controller = new AbortController();
    Promise.all([
      getAiDailySummary(date, controller.signal),
      findAiDailyReport(date, controller.signal),
    ])
      .then(([nextSummary, nextRun]) => {
        if (controller.signal.aborted) return;
        setSummary(nextSummary);
        setRun(nextRun);
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted || isAbortError(err)) return;
        if (err instanceof ApiServiceError && [403, 422].includes(err.status)) {
          setError(err.message || t("forbidden"));
        } else {
          setError(t("loadError"));
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [date, t]);

  useEffect(() => {
    if (tab !== "history") return;
    const controller = new AbortController();
    getAiDailyHistory(controller.signal)
      .then((data) => {
        if (!controller.signal.aborted) setHistory(data);
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted || isAbortError(err)) return;
        setError(t("loadError"));
      })
      .finally(() => {
        if (!controller.signal.aborted) setHistoryLoading(false);
      });
    return () => controller.abort();
  }, [tab, historyRevision, t]);

  useEffect(() => {
    if (
      !activeRunId ||
      !activeRunStatus ||
      !["pending", "running"].includes(activeRunStatus)
    )
      return;
    const controller = new AbortController();
    const timer = window.setInterval(() => {
      if (document.visibilityState !== "visible") return;
      getAiDailyReport(activeRunId, controller.signal)
        .then((next) => {
          if (controller.signal.aborted) return;
          setRun(next);
          if (!["pending", "running"].includes(next.status)) {
            setHistoryLoading(true);
            setHistoryRevision((value) => value + 1);
          }
        })
        .catch((err: unknown) => {
          if (!controller.signal.aborted && !isAbortError(err)) {
            setError(t("loadError"));
          }
        });
    }, 3000);
    return () => {
      controller.abort();
      window.clearInterval(timer);
    };
  }, [activeRunId, activeRunStatus, t]);

  const generate = async () => {
    if (!date || !availableDates.includes(date) || !summary || busy) return;
    setBusy(true);
    setError(null);
    try {
      setRun(await createAiDailyReport(date));
      setTab("overview");
      setHistoryRevision((value) => value + 1);
    } catch (err: unknown) {
      if (err instanceof ApiServiceError && err.status === 422) {
        refreshAvailableDates();
      }
      setError(
        err instanceof ApiServiceError && [403, 422, 429].includes(err.status)
          ? err.message
          : t("createError"),
      );
    } finally {
      setBusy(false);
    }
  };

  const tabItems: { value: Tab; label: string }[] = [
    { value: "overview", label: t("tabOverview") },
    { value: "history", label: t("tabHistory") },
    { value: "sources", label: t("tabSources") },
  ];

  const sourceLabel = (id: number) => {
    const source = run?.source_reports.find((report) => report.id === id);
    if (!source) return t("sourceLink", { id });

    return source.department_name
      ? t("sourceLinkDetailed", {
          employee: source.employee_name,
          department: source.department_name,
          id,
        })
      : t("sourceLinkWithoutDepartment", {
          employee: source.employee_name,
          id,
        });
  };

  return (
    <>
      <PageMeta
        title={`${t("title")} | KIM PHỤC SẮC`}
        description={t("subtitle")}
      />
      <PageBreadCrumb pageTitle={t("title")} />
      <div className="space-y-5 pb-8">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <p className="max-w-3xl text-sm leading-6 text-gray-500 dark:text-gray-400">
            {t("subtitle")}
          </p>
          <span className="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-900 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-300">
            {run?.is_mock ? t("fakeBadge") : t("versionBadge")}
          </span>
        </div>

        <section className="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-xs sm:flex-row sm:items-end dark:border-gray-800 dark:bg-white/3">
          <div className="w-full text-sm font-medium text-gray-700 sm:max-w-xs dark:text-gray-200">
            <span>{t("date")}</span>
            <AiReportDatePicker
              value={date}
              availableDates={availableDates}
              onChange={(nextDate) => {
                reviewingHistoryRef.current = false;
                selectDate(nextDate);
              }}
              ariaLabel={t("calendar.open")}
              placeholder={
                availableDatesLoading
                  ? t("calendar.loadingPlaceholder")
                  : availableDatesError
                    ? t("calendar.errorPlaceholder")
                    : t("calendar.emptyPlaceholder")
              }
              disabled={
                availableDatesLoading ||
                Boolean(availableDatesError) ||
                availableDates.length === 0
              }
            />
          </div>
          <button
            type="button"
            onClick={generate}
            disabled={
              busy ||
              availableDatesLoading ||
              Boolean(availableDatesError) ||
              !availableDates.includes(date) ||
              loading ||
              !summary ||
              Boolean(run && run.status !== "failed")
            }
            className="rounded-lg bg-kps-primary px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
          >
            {busy
              ? t("creating")
              : run?.status === "failed"
                ? t("retry")
                : t("create")}
          </button>
        </section>

        {availableDatesLoading && (
          <p role="status" className="text-sm text-gray-500 dark:text-gray-400">
            {t("availableDatesLoading")}
          </p>
        )}
        {availableDatesError && (
          <div
            role="alert"
            className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-sm text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-400"
          >
            <span>{availableDatesError}</span>
            <button
              type="button"
              onClick={refreshAvailableDates}
              className="rounded font-semibold text-kps-primary transition-colors hover:text-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none dark:text-sidebar-selected"
            >
              {t("retry")}
            </button>
          </div>
        )}
        {!availableDatesLoading &&
          !availableDatesError &&
          availableDates.length === 0 && (
            <div
              role="status"
              className="rounded-xl border border-gray-200 bg-gray-50 px-4 py-4 dark:border-gray-800 dark:bg-white/2"
            >
              <p className="text-sm font-semibold text-gray-900 dark:text-white">
                {t("noAvailableDatesTitle")}
              </p>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {t("noAvailableDatesDescription")}
              </p>
            </div>
          )}

        {error && (
          <div
            role="alert"
            className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-900/20 dark:text-red-300"
          >
            {error}
          </div>
        )}
        {loading && (
          <p role="status" className="text-sm text-gray-500">
            {t("loading")}
          </p>
        )}

        {summary && (
          <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
              <StatCard
                label={t("statReports")}
                value={summary.work_counts.reports}
              />
              <StatCard
                label={t("statItems")}
                value={summary.work_counts.items}
              />
              <StatCard
                label={t("statCompleted")}
                value={summary.work_counts.completed}
                variant="success"
              />
              <StatCard
                label={t("statInProgress")}
                value={summary.work_counts.in_progress}
              />
              <StatCard
                label={t("statBlocked")}
                value={summary.work_counts.blocked}
                variant="warning"
              />
            </div>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t("summaryHint")}
            </p>
          </>
        )}

        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-xs dark:border-gray-800 dark:bg-white/3">
          <div
            role="tablist"
            aria-label={t("title")}
            className="flex flex-wrap gap-1 border-b border-gray-200 p-3 dark:border-gray-800"
          >
            {tabItems.map((item) => (
              <button
                key={item.value}
                type="button"
                role="tab"
                aria-selected={tab === item.value}
                onClick={() => selectTab(item.value)}
                className={`rounded-lg px-4 py-2 text-sm font-medium transition-colors ${
                  tab === item.value
                    ? "bg-kps-primary text-white"
                    : "text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5"
                }`}
              >
                {item.label}
              </button>
            ))}
          </div>

          <div className="space-y-6 p-5">
            {tab === "overview" &&
              (!run ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  {t("empty")}
                </p>
              ) : (
                <>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
                      {t("resultTitle")}
                    </h2>
                    <span className="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                      {t(`status.${run.status}`)}
                    </span>
                  </div>
                  {run.status === "failed" && (
                    <p className="text-sm text-red-600 dark:text-red-400">
                      {t("failedHint")}
                    </p>
                  )}
                  {["pending", "running"].includes(run.status) && (
                    <p role="status" className="text-sm text-gray-500">
                      {t("pendingHint")}
                    </p>
                  )}
                  {run.status === "completed" && run.result && (
                    <>
                      {run.is_mock && (
                        <p className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-300">
                          {t("fakeWarning")}
                        </p>
                      )}
                      <section className="space-y-2">
                        <h3 className="font-semibold text-gray-900 dark:text-white">
                          {t("overview")}
                        </h3>
                        <p className="text-sm leading-7 whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                          {run.result.overview}
                        </p>
                      </section>
                      <section className="space-y-2">
                        <h3 className="font-semibold text-gray-900 dark:text-white">
                          {t("highlights")}
                        </h3>
                        <EvidenceList
                          items={run.result.highlights}
                          empty={t("empty")}
                          sourceLabel={sourceLabel}
                        />
                      </section>
                      <section className="space-y-2">
                        <h3 className="font-semibold text-gray-900 dark:text-white">
                          {t("attention")}
                        </h3>
                        <EvidenceList
                          items={run.result.attention}
                          empty={t("empty")}
                          sourceLabel={sourceLabel}
                        />
                      </section>
                      <section className="space-y-2">
                        <h3 className="font-semibold text-gray-900 dark:text-white">
                          {t("recommendations")}
                        </h3>
                        <EvidenceList
                          items={run.result.recommendations}
                          empty={t("empty")}
                          sourceLabel={sourceLabel}
                        />
                      </section>
                      <p className="border-t border-gray-200 pt-4 text-xs leading-5 text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        {t("disclaimer")}
                      </p>
                    </>
                  )}
                </>
              ))}

            {tab === "history" &&
              (historyLoading ? (
                <p className="text-sm text-gray-500">{t("loading")}</p>
              ) : history.length === 0 ? (
                <p className="text-sm text-gray-500">{t("noHistory")}</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-full text-start text-sm">
                    <thead className="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                      <tr>
                        <th className="px-3 py-3">{t("historyDate")}</th>
                        <th className="px-3 py-3">{t("historyStatus")}</th>
                        <th className="px-3 py-3">{t("historyCompleted")}</th>
                        <th className="px-3 py-3" />
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                      {history.map((item) => (
                        <tr key={item.id}>
                          <td className="px-3 py-3 text-gray-900 dark:text-white">
                            {formatAiReportDate(item.report_date)}
                          </td>
                          <td className="px-3 py-3 text-gray-600 dark:text-gray-300">
                            {t(`status.${item.status}`)}
                            {item.is_mock ? ` · ${t("fakeSuffix")}` : ""}
                          </td>
                          <td className="px-3 py-3 text-gray-500 dark:text-gray-400">
                            {readableTime(item.completed_at, t("notAvailable"))}
                          </td>
                          <td className="px-3 py-3 text-end">
                            <button
                              type="button"
                              className="rounded font-medium text-kps-primary underline transition-colors hover:text-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none dark:text-sidebar-selected"
                              onClick={() => {
                                reviewingHistoryRef.current = true;
                                selectDate(item.report_date);
                                selectTab("overview");
                              }}
                            >
                              {t("viewRun")}
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ))}

            {tab === "sources" &&
              (!summary || summary.source_report_ids.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  {t("noSources")}
                </p>
              ) : (
                <section className="space-y-3">
                  <h3 className="font-semibold text-gray-900 dark:text-white">
                    {t("sourceTitle")}
                  </h3>
                  <div className="flex flex-wrap gap-2">
                    {summary.source_report_ids.map((id) => (
                      <Link
                        key={id}
                        to={`/reports/${id}`}
                        className="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-kps-primary transition-colors hover:border-kps-primary hover:text-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none dark:border-gray-700 dark:text-sidebar-selected"
                      >
                        {sourceLabel(id)}
                      </Link>
                    ))}
                  </div>
                </section>
              ))}
          </div>
        </div>
      </div>
    </>
  );
}
