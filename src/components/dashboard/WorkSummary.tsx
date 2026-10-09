import { AlertHexaIcon, CheckCircleIcon, DocsIcon, TimeIcon } from "@/icons";
import type { DashboardWork } from "@/types/dashboard";
import { useTranslation } from "react-i18next";

interface WorkSummaryProps {
  work: DashboardWork;
  title?: string;
}

const cards = [
  {
    key: "total",
    icon: DocsIcon,
    iconClass:
      "bg-kps-primary/10 text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected",
  },
  {
    key: "completed",
    icon: CheckCircleIcon,
    iconClass:
      "bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500",
  },
  {
    key: "in_progress",
    icon: TimeIcon,
    iconClass:
      "bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500",
  },
  {
    key: "blocked",
    icon: AlertHexaIcon,
    iconClass:
      "bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500",
  },
] as const;

export default function WorkSummary({ work, title }: WorkSummaryProps) {
  const { t } = useTranslation("common", { keyPrefix: "overview.work" });

  return (
    <section aria-labelledby="work-summary-title">
      <h2
        id="work-summary-title"
        className="mb-3 text-lg font-semibold text-gray-900 dark:text-white"
      >
        {title ?? t("title")}
      </h2>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {cards.map(({ key, icon: Icon, iconClass }) => (
          <article
            key={key}
            className="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-xs dark:border-gray-800 dark:bg-white/3"
          >
            <div className="flex items-center justify-between gap-4">
              <div
                className={`flex size-11 items-center justify-center rounded-xl ${iconClass}`}
              >
                <Icon className="size-6" />
              </div>
              <span className="text-title-sm font-bold text-gray-900 dark:text-white">
                {work[key]}
              </span>
            </div>
            <p className="mt-4 text-theme-sm font-medium text-gray-600 dark:text-gray-300">
              {t(key)}
            </p>
          </article>
        ))}
      </div>
    </section>
  );
}
