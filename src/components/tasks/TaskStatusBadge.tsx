import type { TaskStatus } from "@/types/tasks";
import { cn } from "@/utils";
import { useTranslation } from "react-i18next";

const styles: Record<TaskStatus, string> = {
  TODO: "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
  IN_PROGRESS:
    "bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-400",
  BLOCKED: "bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400",
  COMPLETED:
    "bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400",
};

export default function TaskStatusBadge({ status }: { status: TaskStatus }) {
  const { t } = useTranslation("common", { keyPrefix: "taskManagement.status" });

  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full px-2.5 py-1 text-theme-xs font-semibold",
        styles[status],
      )}
    >
      {t(status)}
    </span>
  );
}
