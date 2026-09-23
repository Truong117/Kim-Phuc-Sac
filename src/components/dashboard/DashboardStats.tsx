import Badge from "@/components/ui/badge/Badge";
import { AlertHexaIcon, CheckCircleIcon, DocsIcon, TimeIcon } from "@/icons";
import type { DashboardStat, DashboardStatKind } from "@/types/dashboard";
import { useTranslation } from "react-i18next";

interface DashboardStatsProps {
  stats: DashboardStat[];
}

const iconByKind: Record<DashboardStatKind, React.ReactNode> = {
  total: <DocsIcon className="size-6" />,
  completed: <CheckCircleIcon className="size-6" />,
  inProgress: <TimeIcon className="size-6" />,
  blocked: <AlertHexaIcon className="size-6" />,
};

const iconStyleByKind: Record<DashboardStatKind, string> = {
  total: "bg-brand-50 text-brand-500 dark:bg-brand-500/15 dark:text-brand-400",
  completed:
    "bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500",
  inProgress:
    "bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500",
  blocked:
    "bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500",
};

const badgeColorByKind = {
  total: "primary",
  completed: "success",
  inProgress: "warning",
  blocked: "error",
} as const;

export default function DashboardStats({ stats }: DashboardStatsProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "managementDashboard.stats",
  });

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      {stats.map((stat) => (
        <div
          key={stat.kind}
          className="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-xs dark:border-gray-800 dark:bg-white/3"
        >
          <div className="flex items-start justify-between gap-4">
            <div
              className={`flex size-12 shrink-0 items-center justify-center rounded-xl ${iconStyleByKind[stat.kind]}`}
            >
              {iconByKind[stat.kind]}
            </div>
            {stat.percentage !== undefined && (
              <Badge size="sm" color={badgeColorByKind[stat.kind]}>
                {stat.percentage}%
              </Badge>
            )}
          </div>
          <p className="mt-5 text-theme-sm text-gray-500 dark:text-gray-400">
            {t(stat.kind)}
          </p>
          <p className="mt-1 text-title-sm font-bold text-gray-900 dark:text-white">
            {stat.value}
          </p>
        </div>
      ))}
    </div>
  );
}
