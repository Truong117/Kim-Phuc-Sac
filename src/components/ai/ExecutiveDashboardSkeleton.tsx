import { useTranslation } from "react-i18next";

export default function ExecutiveDashboardSkeleton() {
  const { t } = useTranslation("common", {
    keyPrefix: "aiExecutiveDashboard",
  });

  return (
    <div
      className="space-y-6"
      role="status"
      aria-label={t("loading")}
      aria-live="polite"
    >
      <div className="h-24 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800" />
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {Array.from({ length: 6 }, (_, index) => (
          <div
            key={index}
            className="h-32 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800"
          />
        ))}
      </div>
      <div className="h-96 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800" />
      <span className="sr-only">{t("loading")}</span>
    </div>
  );
}
