import type { DashboardMode } from "@/types/dashboard";
import { useTranslation } from "react-i18next";

interface DashboardHeaderProps {
  mode: DashboardMode;
  contextName?: string;
  businessDate: string;
}

const formatDate = (date: string) =>
  new Intl.DateTimeFormat("vi-VN", {
    dateStyle: "full",
    timeZone: "Asia/Ho_Chi_Minh",
  }).format(new Date(`${date}T00:00:00+07:00`));

export default function DashboardHeader({
  mode,
  contextName,
  businessDate,
}: DashboardHeaderProps) {
  const { t } = useTranslation("common", { keyPrefix: "overview" });
  const title =
    mode === "department" && contextName
      ? t("titles.departmentWithName", { name: contextName })
      : mode === "location" && contextName
        ? t("titles.locationWithName", { name: contextName })
        : t(`titles.${mode}`);

  return (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h1 className="text-title-sm font-bold text-gray-900 dark:text-white">
          {title}
        </h1>
        <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
          {t("subtitle")}
        </p>
      </div>
      <p className="text-theme-sm font-medium text-gray-600 dark:text-gray-300">
        {formatDate(businessDate)}
      </p>
    </div>
  );
}
