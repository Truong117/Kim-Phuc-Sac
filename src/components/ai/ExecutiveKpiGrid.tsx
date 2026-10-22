import {
  AlertHexaIcon,
  CheckCircleIcon,
  DocsIcon,
  GridIcon,
  MultiUserIcon,
  TimeIcon,
} from "@/icons";
import type { AiExecutiveDashboardData } from "@/types/aiExecutiveDashboard";
import {
  buildExecutiveKpis,
  type ExecutiveKpiKey,
} from "@/utils/aiExecutiveDashboard";
import type { ComponentType, SVGProps } from "react";
import { useTranslation } from "react-i18next";

interface ExecutiveKpiGridProps {
  dashboard: AiExecutiveDashboardData;
}

const iconByKey: Record<
  ExecutiveKpiKey,
  ComponentType<SVGProps<SVGSVGElement>>
> = {
  reports: DocsIcon,
  workItems: GridIcon,
  completed: CheckCircleIcon,
  inProgress: TimeIcon,
  blocked: AlertHexaIcon,
  reportingRate: MultiUserIcon,
};

const toneByKey: Record<ExecutiveKpiKey, string> = {
  reports:
    "bg-kps-primary/10 text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected",
  workItems:
    "bg-blue-light-50 text-blue-light-600 dark:bg-blue-light-500/15 dark:text-blue-light-400",
  completed:
    "bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500",
  inProgress:
    "bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500",
  blocked:
    "bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500",
  reportingRate:
    "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
};

export default function ExecutiveKpiGrid({ dashboard }: ExecutiveKpiGridProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "aiExecutiveDashboard.kpis",
  });
  const numberFormatter = new Intl.NumberFormat("vi-VN", {
    maximumFractionDigits: 2,
  });

  return (
    <section aria-labelledby="executive-kpi-title">
      <h2
        id="executive-kpi-title"
        className="mb-3 text-lg font-semibold text-gray-900 dark:text-white"
      >
        {t("title")}
      </h2>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        {buildExecutiveKpis(dashboard).map(({ key, value, percentage }) => {
          const Icon = iconByKey[key];

          return (
            <article
              key={key}
              className="rounded-xl border border-gray-200 bg-white p-4 shadow-theme-xs dark:border-gray-800 dark:bg-white/3"
            >
              <div className="flex items-center justify-between gap-4">
                <span
                  className={`flex size-9 items-center justify-center rounded-lg ${toneByKey[key]}`}
                >
                  <Icon className="size-5" aria-hidden="true" />
                </span>
                <strong className="text-xl font-bold text-gray-900 tabular-nums dark:text-white">
                  {value === null
                    ? t("notEnoughData")
                    : percentage
                      ? t("percentageValue", {
                          value: numberFormatter.format(value),
                        })
                      : numberFormatter.format(value)}
                </strong>
              </div>
              <p className="mt-3 text-xs leading-5 font-medium text-gray-600 dark:text-gray-300">
                {t(`items.${key}`)}
              </p>
            </article>
          );
        })}
      </div>
      <p className="mt-3 text-xs leading-5 text-gray-500 dark:text-gray-400">
        {t("reportingMethod", {
          submitted: dashboard.reporting.submitted,
          expected: dashboard.reporting.expected_submissions,
          workingDays: dashboard.reporting.working_days,
        })}
      </p>
    </section>
  );
}
