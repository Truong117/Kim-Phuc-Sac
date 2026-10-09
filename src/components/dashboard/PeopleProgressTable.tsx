import ComponentCard from "@/components/common/ComponentCard";
import Badge from "@/components/ui/badge/Badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { DashboardPersonProgress } from "@/types/dashboard";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

interface PeopleProgressTableProps {
  rows: DashboardPersonProgress[];
}

export default function PeopleProgressTable({
  rows,
}: PeopleProgressTableProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "overview.peopleProgress",
  });

  return (
    <ComponentCard title={t("title")} compact>
      <div className="max-w-full overflow-x-auto">
        <Table className="min-w-180">
          <TableHeader className="border-b border-gray-100 dark:border-gray-800">
            <TableRow>
              {["employee", "reportState", "total", "completed", "inProgress", "blocked", "action"].map(
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
                  colSpan={7}
                  className="px-4 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400"
                >
                  {t("empty")}
                </TableCell>
              </TableRow>
            )}
            {rows.map((row) => (
              <TableRow key={row.employee.id}>
                <TableCell className="px-3 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                  {row.employee.name}
                </TableCell>
                <TableCell className="px-3 py-4">
                  <Badge
                    size="sm"
                    color={row.report_state === "submitted" ? "success" : "warning"}
                  >
                    {t(`states.${row.report_state}`)}
                  </Badge>
                </TableCell>
                {(["total", "completed", "in_progress", "blocked"] as const).map(
                  (key) => (
                    <TableCell
                      key={key}
                      className="px-3 py-4 text-theme-sm text-gray-600 dark:text-gray-300"
                    >
                      {row.work?.[key] ?? "—"}
                    </TableCell>
                  ),
                )}
                <TableCell className="px-3 py-4">
                  {row.report_id ? (
                    <Link
                      to={`/reports/${row.report_id}`}
                      className="text-theme-xs font-medium text-kps-primary transition-colors hover:text-kps-primary-hover focus-visible:rounded focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none dark:text-sidebar-selected"
                    >
                      {t("view")}
                    </Link>
                  ) : (
                    <span className="text-theme-xs text-gray-400">—</span>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </ComponentCard>
  );
}
