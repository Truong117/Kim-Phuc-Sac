import ReportStatusBadge from "@/components/reports/ReportStatusBadge";
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { ArrowRightIcon } from "@/icons";
import type { DashboardRecentReport } from "@/types/dashboard";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

interface RecentReportsProps {
  reports: DashboardRecentReport[];
}

const formatDate = (date: string) => {
  const [year, month, day] = date.split("-");
  return `${day}/${month}/${year}`;
};

export default function RecentReports({ reports }: RecentReportsProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "overview.recentReports",
  });

  return (
    <section className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-xs dark:border-gray-800 dark:bg-white/3">
      <div className="flex items-center justify-between gap-4 px-5 py-5 sm:px-6">
        <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
          {t("title")}
        </h2>
        <Link
          to="/reports"
          className="inline-flex items-center gap-1.5 rounded text-theme-sm font-medium text-kps-primary transition-colors hover:text-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none dark:text-sidebar-selected"
        >
          {t("viewAll")}
          <ArrowRightIcon className="size-4 rtl:rotate-180" />
        </Link>
      </div>

      <div className="max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
        <Table className="min-w-190 table-fixed xl:min-w-full">
          <TableHeader>
            <TableRow className="border-b border-gray-100 dark:border-gray-800">
              {["date", "employee", "department", "total", "completed", "status", "action"].map(
                (key) => (
                  <TableCell
                    key={key}
                    isHeader
                    className="px-3 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400"
                  >
                    {t(key)}
                  </TableCell>
                ),
              )}
            </TableRow>
          </TableHeader>
          <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
            {reports.length === 0 && (
              <TableRow>
                <TableCell
                  colSpan={7}
                  className="px-4 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400"
                >
                  {t("empty")}
                </TableCell>
              </TableRow>
            )}
            {reports.map((report) => (
              <TableRow
                key={report.id}
                className="transition-colors hover:bg-gray-50 dark:hover:bg-white/2"
              >
                <TableCell className="px-3 py-4 text-theme-xs text-gray-600 dark:text-gray-300">
                  {formatDate(report.report_date)}
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-xs font-medium text-gray-800 dark:text-white/90">
                  <span className="line-clamp-2">{report.employee.name}</span>
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-xs text-gray-600 dark:text-gray-300">
                  <span className="line-clamp-2">
                    {report.department?.name ?? report.location?.name ?? "—"}
                  </span>
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-xs text-gray-600 dark:text-gray-300">
                  {report.work.total}
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-xs text-gray-600 dark:text-gray-300">
                  {report.work.completed}
                </TableCell>
                <TableCell className="px-3 py-4">
                  <ReportStatusBadge status={report.overall_status} />
                </TableCell>
                <TableCell className="px-3 py-4">
                  <Link
                    to={`/reports/${report.id}`}
                    className="rounded text-theme-xs font-medium text-kps-primary transition-colors hover:text-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none dark:text-sidebar-selected"
                  >
                    {t("view")}
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
