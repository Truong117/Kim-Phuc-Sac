import type { DashboardPeriod } from "@/types/dashboard";
import { useTranslation } from "react-i18next";

interface DashboardHeaderProps {
  period: DashboardPeriod;
  onPeriodChange: (period: DashboardPeriod) => void;
}

const periods: DashboardPeriod[] = ["today", "week", "month"];

export default function DashboardHeader({
  period,
  onPeriodChange,
}: DashboardHeaderProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "managementDashboard",
  });

  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h1 className="text-title-sm font-bold text-gray-900 dark:text-white">
          {t("title")}
        </h1>
        <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
          {t("subtitle")}
        </p>
      </div>

      <div className="inline-flex w-fit rounded-lg border border-gray-200 bg-white p-1 shadow-theme-xs dark:border-gray-800 dark:bg-gray-900">
        {periods.map((item) => (
          <button
            key={item}
            type="button"
            aria-pressed={period === item}
            onClick={() => onPeriodChange(item)}
            className={`rounded-md px-3 py-2 text-theme-sm font-medium transition-colors sm:px-4 ${
              period === item
                ? "bg-brand-500 text-white"
                : "text-gray-500 hover:bg-gray-100 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
            }`}
          >
            {t(`periods.${item}`)}
          </button>
        ))}
      </div>
    </div>
  );
}
