import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { ChatIcon } from "@/icons";
import type { ReportListItem } from "@/types/reports";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";
import ReportStatusBadge from "./ReportStatusBadge";

interface ReportHistoryTableProps {
  reports: ReportListItem[];
}

const formatDate = (date: string) => {
  const [year, month, day] = date.split("-");
  return `${day}/${month}/${year}`;
};

const formatWeekday = (date: string) =>
  new Intl.DateTimeFormat("vi-VN", { weekday: "long" }).format(
    new Date(`${date}T00:00:00`),
  );

export default function ReportHistoryTable({
  reports,
}: ReportHistoryTableProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "reportHistory.table",
  });

  return (
    <section className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-xs dark:border-gray-800 dark:bg-white/3">
      <div className="px-5 py-5 sm:px-6">
        <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
          {t("title")}
        </h2>
        <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
          {t("description")}
        </p>
      </div>

      <div className="max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
        <Table className="min-w-250 xl:min-w-full">
          <TableHeader>
            <TableRow className="border-b border-gray-100 dark:border-gray-800">
              {[
                "date",
                "employee",
                "department",
                "workItemCount",
                "completed",
                "status",
                "comments",
                "action",
              ].map((column) => (
                <TableCell
                  key={column}
                  isHeader
                  className="px-4 py-3 text-start text-theme-xs font-medium whitespace-nowrap text-gray-500 dark:text-gray-400"
                >
                  {t(column)}
                </TableCell>
              ))}
            </TableRow>
          </TableHeader>

          <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
            {reports.map((report) => {
              const completionPercentage = report.counts.items
                ? Math.round(
                    (report.counts.completed / report.counts.items) * 100,
                  )
                : 0;

              return (
                <TableRow
                  key={report.id}
                  className="transition-colors hover:bg-gray-50 dark:hover:bg-white/2"
                >
                  <TableCell className="px-4 py-4 whitespace-nowrap">
                    <span className="block text-theme-sm font-medium text-gray-800 dark:text-white/90">
                      {formatDate(report.report_date)}
                    </span>
                    <span className="mt-0.5 block text-theme-xs text-gray-500 capitalize dark:text-gray-400">
                      {formatWeekday(report.report_date)}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-4 text-theme-sm font-medium whitespace-nowrap text-gray-800 dark:text-white/90">
                    {report.employee.name}
                  </TableCell>
                  <TableCell className="px-4 py-4 text-theme-sm whitespace-nowrap text-gray-600 dark:text-gray-300">
                    {report.department?.name ?? "—"}
                  </TableCell>
                  <TableCell className="px-4 py-4 text-theme-sm text-gray-600 dark:text-gray-300">
                    {report.counts.items}
                  </TableCell>
                  <TableCell className="px-4 py-4 whitespace-nowrap">
                    <span className="block text-theme-sm font-medium text-gray-700 dark:text-gray-200">
                      {report.counts.completed} / {report.counts.items}
                    </span>
                    <span className="mt-0.5 block text-theme-xs text-gray-500 dark:text-gray-400">
                      {completionPercentage}%
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-4 whitespace-nowrap">
                    <ReportStatusBadge status={report.overall_status} />
                  </TableCell>
                  <TableCell className="px-4 py-4 whitespace-nowrap">
                    {report.counts.comments > 0 ? (
                      <span className="inline-flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-300">
                        <ChatIcon className="size-4.5" />
                        {report.counts.comments}
                        {report.counts.unread_comments > 0 && (
                          <span className="rounded-full bg-kps-primary/10 px-2 py-0.5 text-theme-xs font-semibold text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected">
                            {t("unread", {
                              count: report.counts.unread_comments,
                            })}
                          </span>
                        )}
                      </span>
                    ) : (
                      <span className="text-gray-400">—</span>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-4 whitespace-nowrap">
                    <Link
                      to={`/reports/${report.id}`}
                      className="text-theme-sm font-medium text-kps-primary transition-colors hover:text-kps-primary-hover focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none dark:text-sidebar-selected"
                    >
                      {t("viewDetail")}
                    </Link>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </div>
    </section>
  );
}
