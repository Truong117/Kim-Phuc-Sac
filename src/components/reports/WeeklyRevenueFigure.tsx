import { useTranslation } from "react-i18next";
import type { WeeklyRevenue } from "@/types/workReportAnalysis";

export default function WeeklyRevenueFigure({
  revenue,
}: {
  revenue?: WeeklyRevenue;
}) {
  const { t } = useTranslation("common");
  if (!revenue) return null;

  return (
    <dl className="mb-4 rounded-xl bg-brand-50 px-4 py-3 dark:bg-brand-500/10">
      <dt className="text-sm font-medium text-gray-700 dark:text-gray-300">
        {t("weeklyWord.revenue")}
      </dt>
      <dd className="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
        {revenue.display}
      </dd>
    </dl>
  );
}
