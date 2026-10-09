import ComponentCard from "@/components/common/ComponentCard";
import Label from "@/components/form/Label";
import { useTranslation } from "react-i18next";

interface ReportInfoProps {
  employeeName: string;
  department: string;
  reportDate: string;
}

const formatDate = (date: string) => {
  const [year, month, day] = date.split("-");
  return `${day}/${month}/${year}`;
};

export default function ReportInfo({
  employeeName,
  department,
  reportDate,
}: ReportInfoProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "dailyReport.reportInfo",
  });

  return (
    <ComponentCard title={t("title")} compact>
      <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
        {[
          [t("employee"), employeeName],
          [t("department"), department],
          [t("reportDate"), formatDate(reportDate)],
        ].map(([label, value]) => (
          <div key={label}>
            <Label>{label}</Label>
            <div className="flex h-11 items-center rounded-lg border border-gray-200 bg-gray-50 px-4 text-theme-sm font-medium text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-white/90">
              {value}
            </div>
          </div>
        ))}
      </div>
    </ComponentCard>
  );
}
