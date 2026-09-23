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
  recentReports,
  workCategoryStats,
} from "@/mocks/dashboard";
import type { DashboardPeriod } from "@/types/dashboard";
import { useState } from "react";
import { useTranslation } from "react-i18next";

export default function Management() {
  const [period, setPeriod] = useState<DashboardPeriod>("today");
  const { t } = useTranslation("common", {
    keyPrefix: "managementDashboard",
  });

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
            <RecentReports reports={recentReports} />
          </div>
        </div>
      </div>
    </>
  );
}
