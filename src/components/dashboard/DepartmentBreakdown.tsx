import ComponentCard from "@/components/common/ComponentCard";
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { DashboardGroupBreakdown } from "@/types/dashboard";
import { useTranslation } from "react-i18next";

interface DepartmentBreakdownProps {
  rows: DashboardGroupBreakdown[];
}

export default function DepartmentBreakdown({
  rows,
}: DepartmentBreakdownProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "overview.departmentBreakdown",
  });

  return (
    <ComponentCard title={t("title")} compact>
      <div className="max-w-full overflow-x-auto">
        <Table className="min-w-180">
          <TableHeader className="border-b border-gray-100 dark:border-gray-800">
            <TableRow>
              {["department", "reporting", "total", "completed", "inProgress", "blocked"].map(
                (key) => (
                  <TableCell
                    key={key}
                    isHeader
                    className="px-3 pb-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400"
                  >
                    {t(key)}
                  </TableCell>
                ),
              )}
            </TableRow>
          </TableHeader>
          <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
            {rows.length === 0 && (
              <TableRow>
                <TableCell
                  colSpan={6}
                  className="px-4 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400"
                >
                  {t("empty")}
                </TableCell>
              </TableRow>
            )}
            {rows.map((row) => (
              <TableRow key={row.group?.id ?? "unassigned"}>
                <TableCell className="px-3 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                  {row.group?.name ?? t("unassigned")}
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-sm text-gray-600 dark:text-gray-300">
                  <span className="font-semibold text-gray-900 dark:text-white">
                    {row.reporting.submitted}/{row.reporting.expected}
                  </span>
                  {row.reporting.missing > 0 && (
                    <span className="ms-2 text-theme-xs text-warning-600 dark:text-warning-500">
                      {t("missing", { count: row.reporting.missing })}
                    </span>
                  )}
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-sm text-gray-600 dark:text-gray-300">
                  {row.work.total}
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-sm font-medium text-success-600 dark:text-success-500">
                  {row.work.completed}
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-sm font-medium text-warning-600 dark:text-warning-500">
                  {row.work.in_progress}
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-sm font-medium text-error-600 dark:text-error-500">
                  {row.work.blocked}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </ComponentCard>
  );
}
