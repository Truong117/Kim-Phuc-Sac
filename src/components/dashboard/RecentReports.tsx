import Badge from "@/components/ui/badge/Badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { ArrowRightIcon } from "@/icons";
import type { RecentReport, ReportStatus } from "@/types/dashboard";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

interface RecentReportsProps {
  reports: RecentReport[];
}

const statusColor = {
  COMPLETED: "success",
  IN_PROGRESS: "warning",
  BLOCKED: "error",
} as const;

const statusKey: Record<ReportStatus, string> = {
  COMPLETED: "completed",
  IN_PROGRESS: "inProgress",
  BLOCKED: "blocked",
};

export default function RecentReports({ reports }: RecentReportsProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "managementDashboard.recentReports",
  });

  return (
    <section className="h-full overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-xs dark:border-gray-800 dark:bg-white/3">
      <div className="flex items-center justify-between gap-4 px-5 py-5 sm:px-6">
        <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
          {t("title")}
        </h2>
        <Link
          to="/reports"
          className="inline-flex items-center gap-1.5 text-theme-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400"
        >
          {t("viewAll")}
          <ArrowRightIcon className="size-4 rtl:rotate-180" />
        </Link>
      </div>

      <div className="max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
        <Table className="min-w-220">
          <TableHeader>
            <TableRow className="border-b border-gray-100 dark:border-gray-800">
              {[
                "date",
                "employee",
                "department",
                "taskCount",
                "completed",
                "status",
                "action",
              ].map((key) => (
                <TableCell
                  key={key}
                  isHeader
                  className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400"
                >
                  {t(key)}
                </TableCell>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
            {reports.map((report) => (
              <TableRow
                key={report.id}
                className="transition-colors hover:bg-gray-50 dark:hover:bg-white/2"
              >
                <TableCell className="px-5 py-4 text-theme-sm whitespace-nowrap text-gray-600 sm:px-6 dark:text-gray-300">
                  {report.reportDate}
                </TableCell>
                <TableCell className="px-5 py-4 text-theme-sm font-medium whitespace-nowrap text-gray-800 sm:px-6 dark:text-white/90">
                  {report.employeeName}
                </TableCell>
                <TableCell className="px-5 py-4 text-theme-sm whitespace-nowrap text-gray-600 sm:px-6 dark:text-gray-300">
                  {report.department}
                </TableCell>
                <TableCell className="px-5 py-4 text-theme-sm text-gray-600 sm:px-6 dark:text-gray-300">
                  {report.totalTasks}
                </TableCell>
                <TableCell className="px-5 py-4 text-theme-sm text-gray-600 sm:px-6 dark:text-gray-300">
                  {report.completedTasks}
                </TableCell>
                <TableCell className="px-5 py-4 sm:px-6">
                  <Badge size="sm" color={statusColor[report.status]}>
                    {t(`statuses.${statusKey[report.status]}`)}
                  </Badge>
                </TableCell>
                <TableCell className="px-5 py-4 sm:px-6">
                  <Link
                    to={`/reports/${report.id}`}
                    className="text-theme-sm font-medium whitespace-nowrap text-brand-500 hover:text-brand-600 dark:text-brand-400"
                  >
                    {t("viewDetail")}
                  </Link>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </section>
  );
}
