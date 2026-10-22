import TaskPriorityBadge from "@/components/tasks/TaskPriorityBadge";
import TaskStatusBadge from "@/components/tasks/TaskStatusBadge";
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { ManagedTask } from "@/types/tasks";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

const formatDate = (value: string) =>
  new Intl.DateTimeFormat("vi-VN", { timeZone: "Asia/Ho_Chi_Minh" }).format(
    new Date(`${value}T00:00:00+07:00`),
  );

export default function TaskTable({ tasks }: { tasks: ManagedTask[] }) {
  const { t } = useTranslation("common", {
    keyPrefix: "taskManagement.list",
  });

  return (
    <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
      <div className="max-w-full overflow-x-auto">
        <Table>
          <TableHeader className="border-b border-gray-100 bg-gray-50 dark:border-gray-800 dark:bg-white/2">
            <TableRow>
              {[
                "task",
                "assignee",
                "priority",
                "status",
                "deadline",
              ].map((column) => (
                <TableCell
                  key={column}
                  isHeader
                  className="px-5 py-3 text-start text-theme-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400"
                >
                  {t(`columns.${column}`)}
                </TableCell>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
            {tasks.map((task) => (
              <TableRow key={task.id} className="hover:bg-gray-50/80 dark:hover:bg-white/2">
                <TableCell className="min-w-72 px-5 py-4 align-top">
                  <Link
                    to={`/tasks/${task.id}`}
                    className="font-semibold text-gray-900 transition-colors hover:text-kps-primary focus-visible:rounded focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none dark:text-white dark:hover:text-sidebar-selected"
                  >
                    {task.title}
                  </Link>
                  <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    {task.department?.name ?? t("noDepartment")}
                    {task.location ? ` · ${task.location.name}` : ""}
                  </p>
                </TableCell>
                <TableCell className="whitespace-nowrap px-5 py-4 text-theme-sm text-gray-700 dark:text-gray-300">
                  {task.assignee.name}
                </TableCell>
                <TableCell className="whitespace-nowrap px-5 py-4">
                  <TaskPriorityBadge priority={task.priority} />
                </TableCell>
                <TableCell className="whitespace-nowrap px-5 py-4">
                  <TaskStatusBadge status={task.status} />
                </TableCell>
                <TableCell className="whitespace-nowrap px-5 py-4 text-theme-sm">
                  <span
                    className={
                      task.is_overdue
                        ? "font-semibold text-error-600 dark:text-error-400"
                        : "text-gray-700 dark:text-gray-300"
                    }
                  >
                    {formatDate(task.due_date)}
                  </span>
                  {task.is_overdue && (
                    <span className="mt-1 block text-theme-xs font-semibold text-error-600 dark:text-error-400">
                      {t("overdue")}
                    </span>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
