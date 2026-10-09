import { CheckCircleIcon, MultiUserIcon, TimeIcon } from "@/icons";
import type { DashboardReporting } from "@/types/dashboard";
import { useTranslation } from "react-i18next";

interface ReportingSummaryProps {
  reporting: DashboardReporting;
}

const cards = [
  {
    key: "expected",
    icon: MultiUserIcon,
    iconClass:
      "bg-kps-primary/10 text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected",
  },
  {
    key: "submitted",
    icon: CheckCircleIcon,
    iconClass:
      "bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500",
  },
  {
    key: "missing",
    icon: TimeIcon,
    iconClass:
      "bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500",
  },
] as const;

export default function ReportingSummary({ reporting }: ReportingSummaryProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "overview.reporting",
  });

  return (
    <section aria-labelledby="reporting-summary-title">
      <div className="mb-3 flex items-center justify-between gap-4">
        <h2
          id="reporting-summary-title"
          className="text-lg font-semibold text-gray-900 dark:text-white"
        >
          {t("title")}
        </h2>
        <span className="text-theme-sm font-semibold text-kps-primary dark:text-sidebar-selected">
          {t("headline", {
            submitted: reporting.submitted,
            expected: reporting.expected,
          })}
        </span>
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        {cards.map(({ key, icon: Icon, iconClass }) => (
          <article
            key={key}
            className="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-xs dark:border-gray-800 dark:bg-white/3"
          >
            <div
              className={`flex size-11 items-center justify-center rounded-xl ${iconClass}`}
            >
              <Icon className="size-6" />
            </div>
            <p className="mt-4 text-theme-sm text-gray-500 dark:text-gray-400">
              {t(key)}
            </p>
            <p className="mt-1 text-title-sm font-bold text-gray-900 dark:text-white">
              {reporting[key]}
            </p>
          </article>
        ))}
      </div>
    </section>
  );
}
