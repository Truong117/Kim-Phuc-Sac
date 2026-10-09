import ReportStatusBadge from "@/components/reports/ReportStatusBadge";
import Badge from "@/components/ui/badge/Badge";
import { ArrowRightIcon, DocsIcon } from "@/icons";
import type {
  DashboardSelfReport,
  DashboardSelfReportState,
} from "@/types/dashboard";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

interface SelfReportCardProps {
  report: DashboardSelfReport;
  title?: string;
}

const stateColor: Record<
  DashboardSelfReportState,
  "success" | "warning" | "light"
> = {
  not_submitted_open: "warning",
  submitted_editable: "success",
  locked: "light",
  not_submitted_closed: "light",
};

const formatTime = (dateTime: string) =>
  new Intl.DateTimeFormat("vi-VN", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
    timeZone: "Asia/Ho_Chi_Minh",
  }).format(new Date(dateTime));

export default function SelfReportCard({ report, title }: SelfReportCardProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "overview.selfReport",
  });
  const action = (() => {
    if (report.state === "not_submitted_open" && report.actions.can_create) {
      return { to: "/reports/new", label: t("actions.create") };
    }

    if (report.state === "submitted_editable" && report.actions.can_edit) {
      return { to: "/reports/new", label: t("actions.update") };
    }

    if (report.report_id && report.actions.can_view) {
      return {
        to: `/reports/${report.report_id}`,
        label: t("actions.view"),
      };
    }

    return null;
  })();

  return (
    <section className="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-xs sm:p-6 dark:border-gray-800 dark:bg-white/3">
      <div className="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex min-w-0 items-start gap-4">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-kps-primary/10 text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected">
            <DocsIcon className="size-6" />
          </span>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
                {title ?? t("title")}
              </h2>
              <Badge size="sm" color={stateColor[report.state]}>
                {t(`states.${report.state}`)}
              </Badge>
              {report.overall_status && (
                <ReportStatusBadge status={report.overall_status} />
              )}
            </div>
            <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
              {report.state === "not_submitted_closed"
                ? t("descriptions.closed", {
                    cutoff: formatTime(report.cutoff_at),
                  })
                : report.state === "locked"
                  ? t("descriptions.locked", {
                      cutoff: formatTime(report.cutoff_at),
                    })
                  : t("descriptions.open", {
                      cutoff: formatTime(report.cutoff_at),
                    })}
            </p>
            {report.report_id && (
              <p className="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
                {t("workSummary", {
                  total: report.work.total,
                  completed: report.work.completed,
                  inProgress: report.work.in_progress,
                  blocked: report.work.blocked,
                })}
              </p>
            )}
          </div>
        </div>

        {action && (
          <Link
            to={action.to}
            className="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-kps-primary px-4 py-2.5 text-theme-sm font-semibold text-white transition-colors hover:bg-kps-primary-hover focus-visible:ring-4 focus-visible:ring-kps-primary/20 focus-visible:outline-none"
          >
            {action.label}
            <ArrowRightIcon className="size-4 rtl:rotate-180" />
          </Link>
        )}
      </div>
    </section>
  );
}
