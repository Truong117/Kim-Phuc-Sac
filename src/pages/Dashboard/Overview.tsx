import AttentionItems from "@/components/dashboard/AttentionItems";
import DashboardHeader from "@/components/dashboard/DashboardHeader";
import DepartmentBreakdown from "@/components/dashboard/DepartmentBreakdown";
import PeopleProgressTable from "@/components/dashboard/PeopleProgressTable";
import PersonalFeedback from "@/components/dashboard/PersonalFeedback";
import PersonalReportOverview from "@/components/dashboard/PersonalReportOverview";
import RecentReports from "@/components/dashboard/RecentReports";
import ReportingSummary from "@/components/dashboard/ReportingSummary";
import SelfReportCard from "@/components/dashboard/SelfReportCard";
import WorkSummary from "@/components/dashboard/WorkSummary";
import PageMeta from "@/components/common/PageMeta";
import { ApiServiceError } from "@/services/apiClient";
import { getDashboard } from "@/services/dashboardService";
import type { DashboardData } from "@/types/dashboard";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";

type LoadError = "network" | "forbidden" | "load";

export default function Overview() {
  const { t } = useTranslation("common", { keyPrefix: "overview" });
  const [dashboard, setDashboard] = useState<DashboardData | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<LoadError | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    const abortController = new AbortController();

    getDashboard(abortController.signal)
      .then(setDashboard)
      .catch((loadError: unknown) => {
        if (loadError instanceof DOMException && loadError.name === "AbortError") {
          return;
        }

        setDashboard(null);
        setError(
          loadError instanceof ApiServiceError && loadError.status === 0
            ? "network"
            : loadError instanceof ApiServiceError && loadError.status === 403
              ? "forbidden"
              : "load",
        );
      })
      .finally(() => {
        if (!abortController.signal.aborted) setIsLoading(false);
      });

    return () => abortController.abort();
  }, [reloadKey]);

  return (
    <>
      <PageMeta
        title={`${t("metaTitle")} | KIM PHỤC SẮC`}
        description={t("metaDescription")}
      />

      {isLoading && !dashboard ? (
        <div
          className="flex min-h-[50vh] flex-col items-center justify-center gap-3 text-center"
          role="status"
          aria-live="polite"
        >
          <span
            className="size-9 animate-spin rounded-full border-4 border-gray-200 border-t-kps-primary dark:border-gray-800 dark:border-t-kps-primary"
            aria-hidden="true"
          />
          <p className="text-theme-sm text-gray-500 dark:text-gray-400">
            {t("loading")}
          </p>
        </div>
      ) : error || !dashboard ? (
        <div className="rounded-2xl border border-error-200 bg-error-50 p-6 text-center dark:border-error-500/20 dark:bg-error-500/10">
          <h1 className="text-lg font-semibold text-error-700 dark:text-error-400">
            {t("errors.title")}
          </h1>
          <p className="mt-2 text-theme-sm text-error-600 dark:text-error-400">
            {t(`errors.${error ?? "load"}`)}
          </p>
          <button
            type="button"
            onClick={() => {
              setIsLoading(true);
              setError(null);
              setReloadKey((current) => current + 1);
            }}
            className="mt-4 rounded-lg bg-kps-primary px-4 py-2.5 text-theme-sm font-semibold text-white transition-colors hover:bg-kps-primary-hover focus-visible:ring-4 focus-visible:ring-kps-primary/20 focus-visible:outline-none"
          >
            {t("errors.retry")}
          </button>
        </div>
      ) : (
        <div className="space-y-6">
          <DashboardHeader
            mode={dashboard.mode}
            contextName={dashboard.context?.name}
            businessDate={dashboard.business_date}
          />

          {dashboard.mode === "personal" ? (
            <>
              <PersonalReportOverview
                report={dashboard.self_report}
                work={dashboard.work}
              />
              <PersonalFeedback />
            </>
          ) : (
            <>
              {dashboard.reporting && (
                <ReportingSummary reporting={dashboard.reporting} />
              )}
              <WorkSummary work={dashboard.work} />
              {dashboard.self_report && (
                <SelfReportCard report={dashboard.self_report} />
              )}
              <AttentionItems items={dashboard.attention_items} />
              {dashboard.mode === "organization" ? (
                <DepartmentBreakdown rows={dashboard.group_breakdown} />
              ) : (
                <PeopleProgressTable rows={dashboard.people_progress} />
              )}
              <RecentReports reports={dashboard.recent_reports} />
            </>
          )}
        </div>
      )}
    </>
  );
}
