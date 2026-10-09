import AIManagementSummary from "@/components/dashboard/AIManagementSummary";
import DashboardHeader from "@/components/dashboard/DashboardHeader";
import DashboardStats from "@/components/dashboard/DashboardStats";
import EmployeePerformance from "@/components/dashboard/EmployeePerformance";
import RecentReports from "@/components/dashboard/RecentReports";
import WorkCategoryChart from "@/components/dashboard/WorkCategoryChart";
import PageMeta from "@/components/common/PageMeta";
import {
  aiManagementInsight,
  dashboardStats,
  employeePerformance,
  workCategoryStats,
} from "@/mocks/dashboard";
import { getReports } from "@/services/reportService";
import type { DashboardPeriod } from "@/types/dashboard";
import type { ReportListItem } from "@/types/reports";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";

export default function Management() {
  const [period, setPeriod] = useState<DashboardPeriod>("today");
  const [recentReports, setRecentReports] = useState<ReportListItem[]>([]);
  const [isRecentReportsLoading, setIsRecentReportsLoading] = useState(true);
  const { t } = useTranslation("common", {
    keyPrefix: "managementDashboard",
  });

  useEffect(() => {
    const abortController = new AbortController();
    getReports(
      {
        fromDate: "",
        toDate: "",
        employeeId: "",
        departmentId: "",
        locationId: "",
        status: "",
        search: "",
        page: 1,
        perPage: 5,
      },
      abortController.signal,
    )
      .then((response) => setRecentReports(response.data))
      .catch((error: unknown) => {
        if (!(error instanceof DOMException && error.name === "AbortError")) {
          setRecentReports([]);
        }
      })
      .finally(() => {
        if (!abortController.signal.aborted) setIsRecentReportsLoading(false);
      });

    return () => abortController.abort();
  }, []);

  return (
    <>
      <PageMeta
        title={`${t("title")} | KIM PHỤC SẮC`}
        description={t("metaDescription")}
      />

      <div className="space-y-6">
        <DashboardHeader period={period} onPeriodChange={setPeriod} />
        <DashboardStats stats={dashboardStats} />

        <div className="grid grid-cols-12 gap-6">
          <div className="col-span-12 xl:col-span-5">
            <WorkCategoryChart data={workCategoryStats} />
          </div>
          <div className="col-span-12 xl:col-span-7">
            <EmployeePerformance employees={employeePerformance} />
          </div>
          <div className="col-span-12 xl:col-span-5">
            <AIManagementSummary {...aiManagementInsight} />
          </div>
          <div className="col-span-12 xl:col-span-7">
            <RecentReports
              reports={recentReports}
              isLoading={isRecentReportsLoading}
            />
          </div>
        </div>
      </div>
    </>
  );
}
