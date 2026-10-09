import ComponentCard from "@/components/common/ComponentCard";
import ReportWorkItemDetails from "@/components/reports/ReportWorkItemDetails";
import SelfReportCard from "@/components/dashboard/SelfReportCard";
import WorkSummary from "@/components/dashboard/WorkSummary";
import type { DashboardSelfReport, DashboardWork } from "@/types/dashboard";
import { useTranslation } from "react-i18next";

interface PersonalReportOverviewProps {
  report: DashboardSelfReport | null;
  work: DashboardWork;
}

export default function PersonalReportOverview({
  report,
  work,
}: PersonalReportOverviewProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "overview.personal",
  });

  return (
    <div className="space-y-6">
      {report && <SelfReportCard report={report} title={t("todayReport")} />}
      <WorkSummary work={work} title={t("myWork")} />
      {report && report.items.length > 0 && (
        <ComponentCard title={t("reportedItems")} compact>
          <ReportWorkItemDetails items={report.items} />
        </ComponentCard>
      )}
    </div>
  );
}
