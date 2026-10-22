import ExecutiveDashboardSkeleton from "@/components/ai/ExecutiveDashboardSkeleton";
import ExecutiveDepartmentTable from "@/components/ai/ExecutiveDepartmentTable";
import ExecutiveInsights from "@/components/ai/ExecutiveInsights";
import ExecutiveKpiGrid from "@/components/ai/ExecutiveKpiGrid";
import ExecutivePriorityIssues from "@/components/ai/ExecutivePriorityIssues";
import ExecutiveTrendChart from "@/components/ai/ExecutiveTrendChart";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import { getAiExecutiveDashboard } from "@/services/aiExecutiveDashboardService";
import { ApiServiceError } from "@/services/apiClient";
import type {
  AiExecutiveDashboardData,
  AiExecutivePeriodKey,
} from "@/types/aiExecutiveDashboard";
import { formatAiReportDate } from "@/utils/aiReportDate";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";

type LoadError = "network" | "unauthorized" | "forbidden" | "load";

const isAbortError = (error: unknown) =>
  error instanceof DOMException && error.name === "AbortError";

export default function AiExecutiveDashboard() {
  const { t } = useTranslation("common", {
    keyPrefix: "aiExecutiveDashboard",
  });
  const [period, setPeriod] = useState<AiExecutivePeriodKey>("7d");
  const [dashboard, setDashboard] = useState<AiExecutiveDashboardData | null>(
    null,
  );
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<LoadError | null>(null);
  const [revision, setRevision] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    let isCurrentRequest = true;

    getAiExecutiveDashboard(period, controller.signal)
      .then((data) => {
        if (isCurrentRequest && !controller.signal.aborted) {
          setDashboard(data);
        }
      })
      .catch((loadError: unknown) => {
        if (
          !isCurrentRequest ||
          controller.signal.aborted ||
          isAbortError(loadError)
        ) {
          return;
        }

        setError(
          loadError instanceof ApiServiceError && loadError.status === 0
            ? "network"
            : loadError instanceof ApiServiceError && loadError.status === 401
              ? "unauthorized"
              : loadError instanceof ApiServiceError && loadError.status === 403
                ? "forbidden"
                : "load",
        );
      })
      .finally(() => {
        if (isCurrentRequest && !controller.signal.aborted) {
          setIsLoading(false);
        }
      });

    return () => {
      isCurrentRequest = false;
      controller.abort();
    };
  }, [period, revision]);

  const formattedGeneratedAt = dashboard
    ? new Intl.DateTimeFormat("vi-VN", {
        timeZone: dashboard.period.timezone,
        dateStyle: "short",
        timeStyle: "short",
      }).format(new Date(dashboard.period.generated_at))
    : "";

  return (
    <>
      <PageMeta
        title={`${t("metaTitle")} | KIM PHỤC SẮC`}
        description={t("subtitle")}
      />
      <PageBreadCrumb pageTitle={t("title")} />

      <div className="space-y-6 pb-8">
        <header className="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-xs sm:flex-row sm:items-end sm:justify-between dark:border-gray-800 dark:bg-white/3">
          <div className="max-w-3xl">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-xl font-bold text-gray-900 dark:text-white">
                {t("title")}
              </h1>
              <span className="rounded-full border border-warning-200 bg-warning-50 px-3 py-1 text-xs font-semibold text-warning-800 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300">
                {t("fakeBadge")}
              </span>
            </div>
            <p className="mt-2 text-sm leading-6 text-gray-500 dark:text-gray-400">
              {t("subtitle")}
            </p>
          </div>

          <div>
            <span className="mb-2 block text-xs font-semibold text-gray-500 dark:text-gray-400">
              {t("period.label")}
            </span>
            <div
              className="inline-flex rounded-xl border border-gray-200 bg-gray-50 p-1 dark:border-gray-700 dark:bg-gray-900"
              role="group"
              aria-label={t("period.label")}
            >
              {(["7d", "30d"] as const).map((value) => (
                <button
                  key={value}
                  type="button"
                  aria-pressed={period === value}
                  onClick={() => {
                    if (period === value) return;
                    setDashboard(null);
                    setError(null);
                    setIsLoading(true);
                    setPeriod(value);
                  }}
                  className={`rounded-lg px-4 py-2 text-sm font-semibold transition-colors focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none ${
                    period === value
                      ? "bg-kps-primary text-white shadow-theme-xs"
                      : "text-gray-600 hover:bg-white hover:text-kps-primary dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-sidebar-selected"
                  }`}
                >
                  {t(`period.${value}`)}
                </button>
              ))}
            </div>
          </div>
        </header>

        {isLoading ? (
          <ExecutiveDashboardSkeleton />
        ) : error || !dashboard ? (
          <section
            role="alert"
            className="rounded-2xl border border-error-200 bg-error-50 p-6 text-center dark:border-error-500/20 dark:bg-error-500/10"
          >
            <h2 className="text-lg font-semibold text-error-700 dark:text-error-400">
              {t("errors.title")}
            </h2>
            <p className="mt-2 text-sm text-error-600 dark:text-error-400">
              {t(`errors.${error ?? "load"}`)}
            </p>
            <button
              type="button"
              onClick={() => {
                setDashboard(null);
                setError(null);
                setIsLoading(true);
                setRevision((value) => value + 1);
              }}
              className="mt-4 rounded-lg bg-kps-primary px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-kps-primary-hover focus-visible:ring-4 focus-visible:ring-kps-primary/20 focus-visible:outline-none"
            >
              {t("errors.retry")}
            </button>
          </section>
        ) : (
          <>
            <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-white/2">
              <p className="text-sm font-medium text-gray-800 dark:text-gray-200">
                {t("actualPeriod", {
                  start: formatAiReportDate(dashboard.period.start_date),
                  end: formatAiReportDate(dashboard.period.end_date),
                })}
              </p>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {t("generatedAt", { time: formattedGeneratedAt })}
              </p>
            </section>

            <ExecutiveInsights
              analysis={dashboard.analysis}
              attentionItems={dashboard.attention_items}
            />
            <ExecutiveTrendChart rows={dashboard.daily_trend} />
            <ExecutivePriorityIssues items={dashboard.attention_items} />
            <ExecutiveDepartmentTable rows={dashboard.departments} />
            <ExecutiveKpiGrid dashboard={dashboard} />
          </>
        )}
      </div>
    </>
  );
}
