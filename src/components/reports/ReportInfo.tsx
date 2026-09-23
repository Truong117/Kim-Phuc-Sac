import ComponentCard from "@/components/common/ComponentCard";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import { useTranslation } from "react-i18next";

interface ReportInfoProps {
  employeeName: string;
  department: string;
  reportDate: string;
  onReportDateChange: (reportDate: string) => void;
}

export default function ReportInfo({
  employeeName,
  department,
  reportDate,
  onReportDateChange,
}: ReportInfoProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "dailyReport.reportInfo",
  });

  return (
    <ComponentCard title={t("title")}>
      <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
        <div>
          <Label>{t("employee")}</Label>
          <div className="flex h-11 items-center rounded-lg border border-gray-200 bg-gray-50 px-4 text-theme-sm font-medium text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white/90">
            {employeeName}
          </div>
        </div>

        <div>
          <Label>{t("department")}</Label>
          <div className="flex h-11 items-center rounded-lg border border-gray-200 bg-gray-50 px-4 text-theme-sm font-medium text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white/90">
            {department}
          </div>
        </div>

        <div>
          <Label htmlFor="report-date">{t("reportDate")}</Label>
          <Input
            id="report-date"
            name="reportDate"
            type="date"
            value={reportDate}
            onChange={(event) => onReportDateChange(event.target.value)}
          />
        </div>
      </div>
    </ComponentCard>
  );
}
