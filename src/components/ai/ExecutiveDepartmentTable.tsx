import ComponentCard from "@/components/common/ComponentCard";
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { AiExecutiveDepartment } from "@/types/aiExecutiveDashboard";
import { useTranslation } from "react-i18next";

interface ExecutiveDepartmentTableProps {
  rows: AiExecutiveDepartment[];
}

export default function ExecutiveDepartmentTable({
  rows,
}: ExecutiveDepartmentTableProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "aiExecutiveDashboard.departments",
  });
  const highestBlocked = Math.max(
    0,
    ...rows.map((row) => row.work_items.blocked),
  );
  const highlightedDepartments = rows
    .filter(
      (row) => highestBlocked > 0 && row.work_items.blocked === highestBlocked,
    )
    .map((row) => row.department?.name ?? t("unassigned"));

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      {rows.length > 0 && (
        <p className="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-white/3 dark:text-gray-300">
          {highestBlocked > 0
            ? t("attentionSummary", {
                departments: highlightedDepartments.join(", "),
                count: highestBlocked,
              })
            : t("noBlockedSummary")}
        </p>
      )}
      <div className="max-w-full overflow-x-auto">
        <Table className="min-w-180">
          <TableHeader className="border-b border-gray-100 dark:border-gray-800">
            <TableRow>
              {[
                "department",
                "reports",
                "total",
                "completed",
                "inProgress",
                "blocked",
              ].map((key) => (
                <TableCell
                  key={key}
                  isHeader
                  className="px-3 pb-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400"
                >
                  {t(`columns.${key}`)}
                </TableCell>
              ))}
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
              <TableRow
                key={row.department?.id ?? "unassigned"}
                className={
                  highestBlocked > 0 &&
                  row.work_items.blocked === highestBlocked
                    ? "bg-error-25/60 dark:bg-error-500/5"
                    : undefined
                }
              >
                <TableCell className="px-3 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                  {row.department?.name ?? t("unassigned")}
                  {highestBlocked > 0 &&
                    row.work_items.blocked === highestBlocked && (
                      <span className="ms-2 inline-flex rounded-full bg-error-50 px-2 py-0.5 text-xs font-semibold text-error-700 dark:bg-error-500/10 dark:text-error-400">
                        {t("attentionBadge")}
                      </span>
                    )}
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-sm text-gray-600 tabular-nums dark:text-gray-300">
                  {row.reports_submitted}
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-sm text-gray-600 tabular-nums dark:text-gray-300">
                  {row.work_items.total}
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-sm font-medium text-success-600 tabular-nums dark:text-success-500">
                  {row.work_items.completed}
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-sm font-medium text-warning-600 tabular-nums dark:text-warning-500">
                  {row.work_items.in_progress}
                </TableCell>
                <TableCell className="px-3 py-4 text-theme-sm font-medium text-error-600 tabular-nums dark:text-error-500">
                  {row.work_items.blocked}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </ComponentCard>
  );
}
