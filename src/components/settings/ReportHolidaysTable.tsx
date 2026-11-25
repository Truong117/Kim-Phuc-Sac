import ComponentCard from "@/components/common/ComponentCard";
import Badge from "@/components/ui/badge/Badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { ReportHoliday } from "@/types/reportHolidays";
import { formatDateOnly } from "@/utils/dateOnly";
import { reportHolidayStatus } from "@/utils/reportHolidays";
import { useTranslation } from "react-i18next";

interface ReportHolidaysTableProps {
  rows: ReportHoliday[];
  businessDate: string;
  workingDays: number[];
  canManage: boolean;
  disabled: boolean;
  onEdit: (holiday: ReportHoliday) => void;
  onToggle: (holiday: ReportHoliday) => void;
  onDelete: (holiday: ReportHoliday) => void;
}

export default function ReportHolidaysTable({
  rows,
  businessDate,
  workingDays,
  canManage,
  disabled,
  onEdit,
  onToggle,
  onDelete,
}: ReportHolidaysTableProps) {
  const { t } = useTranslation("common", { keyPrefix: "reportHolidays" });
  const restDays = [1, 2, 3, 4, 5, 6, 7]
    .filter((day) => !workingDays.includes(day))
    .map((day) => t(`weekdays.${day}`))
    .join(", ");
  return (
    <ComponentCard title={t("listTitle", { count: rows.length })} compact>
      <p className="text-theme-sm text-gray-500 dark:text-gray-400">
        {t("weeklyRest", { days: restDays || t("noWeeklyRest") })}
      </p>
      <div className="max-w-full overflow-x-auto">
        <Table className="min-w-180">
          <TableHeader className="border-b border-gray-100 dark:border-gray-800">
            <TableRow>
              {[
                "name",
                "period",
                "reportingDays",
                "status",
                ...(canManage ? ["actions"] : []),
              ].map((key) => (
                <TableCell
                  key={key}
                  isHeader
                  className="px-3 py-3 text-start text-theme-sm font-medium text-gray-500 dark:text-gray-400"
                >
                  {t(key)}
                </TableCell>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
            {rows.length === 0 && (
              <TableRow>
                <TableCell
                  colSpan={canManage ? 5 : 4}
                  className="px-3 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400"
                >
                  {t("empty")}
                </TableCell>
              </TableRow>
            )}
            {rows.map((holiday) => {
              const status = reportHolidayStatus(holiday, businessDate);
              return (
                <TableRow key={holiday.id}>
                  <TableCell className="max-w-xs px-3 py-4 text-theme-sm">
                    <p className="font-semibold text-gray-800 dark:text-white/90">
                      {holiday.name}
                    </p>
                    {holiday.note && (
                      <p className="mt-1 whitespace-pre-wrap text-gray-500 dark:text-gray-400">
                        {holiday.note}
                      </p>
                    )}
                  </TableCell>
                  <TableCell className="px-3 py-4 text-theme-sm text-gray-700 dark:text-gray-300">
                    {formatDateOnly(holiday.start_date)}
                    {holiday.start_date !== holiday.end_date && (
                      <>
                        <br />– {formatDateOnly(holiday.end_date)}
                      </>
                    )}
                  </TableCell>
                  <TableCell className="px-3 py-4 text-theme-sm text-gray-700 dark:text-gray-300">
                    {t("dayCount", { count: holiday.reporting_days })}
                  </TableCell>
                  <TableCell className="px-3 py-4">
                    <Badge
                      color={
                        status === "current"
                          ? "success"
                          : status === "upcoming"
                            ? "warning"
                            : "light"
                      }
                    >
                      {t(`statuses.${status}`)}
                    </Badge>
                  </TableCell>
                  {canManage && (
                    <TableCell className="px-3 py-4">
                      <div className="flex items-center gap-4">
                        <button
                          type="button"
                          disabled={disabled}
                          onClick={() => onEdit(holiday)}
                          aria-label={t("editName", { name: holiday.name })}
                          className="py-2 text-theme-sm font-medium text-kps-primary focus-visible:rounded focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none disabled:opacity-50 dark:text-sidebar-selected"
                        >
                          {t("edit")}
                        </button>
                        <button
                          type="button"
                          disabled={disabled}
                          onClick={() => onDelete(holiday)}
                          aria-label={t("deleteName", { name: holiday.name })}
                          className="py-2 text-theme-sm font-medium text-error-600 focus-visible:rounded focus-visible:ring-3 focus-visible:ring-error-500/20 focus-visible:outline-none disabled:opacity-50 dark:text-error-400"
                        >
                          {t("delete")}
                        </button>
                        <input
                          type="checkbox"
                          role="switch"
                          checked={holiday.is_active}
                          disabled={disabled}
                          onChange={() => onToggle(holiday)}
                          aria-label={t("toggleName", { name: holiday.name })}
                          className="relative h-5 w-9 cursor-pointer appearance-none rounded-full bg-gray-300 transition-colors before:absolute before:start-0.5 before:top-0.5 before:size-4 before:rounded-full before:bg-white before:transition-transform checked:bg-kps-primary checked:before:translate-x-4 focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 rtl:checked:before:-translate-x-4 dark:bg-gray-700 dark:checked:bg-kps-primary"
                        />
                      </div>
                    </TableCell>
                  )}
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </div>
      {canManage && (
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("toggleHelp")}
        </p>
      )}
    </ComponentCard>
  );
}
