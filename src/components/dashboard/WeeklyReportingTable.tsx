import Badge from "@/components/ui/badge/Badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type {
  DashboardWeeklyReporting,
  WeeklyReportingPerson,
  WeeklyReportingStatus,
} from "@/types/dashboard";
import { formatDateOnly } from "@/utils/dateOnly";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

interface WeeklyReportingTableProps {
  dates: DashboardWeeklyReporting["dates"];
  rows: WeeklyReportingPerson[];
}

const statusClasses: Record<WeeklyReportingStatus, string> = {
  submitted:
    "bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400",
  missing:
    "bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400",
  pending:
    "bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400",
  upcoming: "bg-gray-50 text-gray-500 dark:bg-gray-800 dark:text-gray-400",
  non_working_day: "text-gray-400 dark:text-gray-500",
  not_expected: "text-gray-400 dark:text-gray-500",
};

export default function WeeklyReportingTable({
  dates,
  rows,
}: WeeklyReportingTableProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "overview.weeklyReporting",
  });

  return (
    <div className="max-w-full overflow-x-auto">
      <Table className="min-w-260">
        <TableHeader className="border-b border-gray-100 dark:border-gray-800">
          <TableRow>
            <TableCell
              isHeader
              className="px-3 py-3 text-start text-theme-sm font-medium text-gray-500 dark:text-gray-400"
            >
              {t("employee")}
            </TableCell>
            <TableCell
              isHeader
              className="px-3 py-3 text-center text-theme-sm font-medium text-gray-500 dark:text-gray-400"
            >
              {t("submitted")}
            </TableCell>
            {dates.map((day, index) => (
              <TableCell
                key={day.date}
                isHeader
                className="px-2 py-3 text-center text-theme-sm font-medium text-gray-500 dark:text-gray-400"
              >
                <div>{t(`weekdays.${index}`)}</div>
                <div className="mt-1 text-theme-xs font-normal">
                  {formatDateOnly(day.date).slice(0, 5)}
                </div>
              </TableCell>
            ))}
            <TableCell
              isHeader
              className="px-3 py-3 text-start text-theme-sm font-medium text-gray-500 dark:text-gray-400"
            >
              {t("missingDays")}
            </TableCell>
          </TableRow>
        </TableHeader>
        <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
          {rows.length === 0 && (
            <TableRow>
              <TableCell
                colSpan={dates.length + 3}
                className="px-4 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400"
              >
                {t("empty")}
              </TableCell>
            </TableRow>
          )}
          {rows.map((row) => (
            <TableRow key={row.employee.id}>
              <TableCell className="px-3 py-4 text-theme-sm">
                <p className="font-semibold text-gray-800 dark:text-white/90">
                  {row.employee.name}
                </p>
                <p className="mt-1 text-gray-500 dark:text-gray-400">
                  {row.department?.name ?? t("unassigned")}
                </p>
              </TableCell>
              <TableCell className="px-3 py-4 text-center text-theme-sm">
                <span className="text-lg font-bold text-gray-900 dark:text-white">
                  {row.submitted}
                </span>
                <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                  {t("expected", { count: row.expected })}
                </p>
              </TableCell>
              {row.days.map((day) => {
                const label = t(`states.${day.status}`);
                const title = `${row.employee.name} · ${formatDateOnly(day.date)} · ${label}`;
                const className = `inline-flex min-h-8 items-center justify-center whitespace-nowrap rounded-lg px-2 py-1 text-theme-xs font-medium ${statusClasses[day.status]}`;
                return (
                  <TableCell key={day.date} className="px-2 py-4 text-center">
                    {day.report_id !== null ? (
                      <Link
                        to={`/reports/${day.report_id}`}
                        title={title}
                        aria-label={title}
                        className={`${className} underline decoration-dotted underline-offset-4 focus-visible:ring-3 focus-visible:ring-brand-500/20 focus-visible:outline-none`}
                      >
                        {label}
                      </Link>
                    ) : (
                      <span title={title} className={className}>
                        {label}
                      </span>
                    )}
                  </TableCell>
                );
              })}
              <TableCell className="px-3 py-4 text-theme-sm">
                {row.missing_dates.length > 0 ? (
                  <div className="space-y-1.5">
                    <Badge color="error">
                      {t("missingCount", { count: row.missing_dates.length })}
                    </Badge>
                    <p className="text-error-600 dark:text-error-400">
                      {row.missing_dates
                        .map((date) => formatDateOnly(date).slice(0, 5))
                        .join(", ")}
                    </p>
                  </div>
                ) : (
                  <span className="text-gray-500 dark:text-gray-400">
                    {t("noMissing")}
                  </span>
                )}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
