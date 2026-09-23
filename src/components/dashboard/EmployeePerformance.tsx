import ComponentCard from "@/components/common/ComponentCard";
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { EmployeePerformance as EmployeePerformanceData } from "@/types/dashboard";
import { useTranslation } from "react-i18next";

interface EmployeePerformanceProps {
  employees: EmployeePerformanceData[];
}

export default function EmployeePerformance({
  employees,
}: EmployeePerformanceProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "managementDashboard.employeePerformance",
  });

  return (
    <ComponentCard title={t("title")} className="h-full">
      <div className="max-w-full overflow-x-auto">
        <Table className="min-w-185">
          <TableHeader className="border-b border-gray-100 dark:border-gray-800">
            <TableRow>
              {[
                "employee",
                "total",
                "completed",
                "inProgress",
                "blocked",
                "completionRate",
              ].map((key) => (
                <TableCell
                  key={key}
                  isHeader
                  className="px-3 pb-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400"
                >
                  {t(key)}
                </TableCell>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
            {employees.map((employee) => {
              const completionRate = Math.round(
                (employee.completedTasks / employee.totalTasks) * 100,
              );

              return (
                <TableRow key={employee.id}>
                  <TableCell className="px-3 py-4 text-theme-sm font-medium whitespace-nowrap text-gray-800 dark:text-white/90">
                    {employee.name}
                  </TableCell>
                  <TableCell className="px-3 py-4 text-theme-sm text-gray-600 dark:text-gray-300">
                    {employee.totalTasks}
                  </TableCell>
                  <TableCell className="px-3 py-4 text-theme-sm font-medium text-success-600 dark:text-success-500">
                    {employee.completedTasks}
                  </TableCell>
                  <TableCell className="px-3 py-4 text-theme-sm font-medium text-warning-600 dark:text-warning-500">
                    {employee.inProgressTasks}
                  </TableCell>
                  <TableCell className="px-3 py-4 text-theme-sm font-medium text-error-600 dark:text-error-500">
                    {employee.blockedTasks}
                  </TableCell>
                  <TableCell className="px-3 py-4">
                    <div className="flex min-w-28 items-center gap-3">
                      <div className="h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                        <div
                          className="h-full rounded-full bg-success-500"
                          style={{ width: `${completionRate}%` }}
                        />
                      </div>
                      <span className="text-theme-xs font-semibold text-gray-700 dark:text-gray-300">
                        {completionRate}%
                      </span>
                    </div>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </div>
    </ComponentCard>
  );
}
