import type { TaskPriority } from "@/types/tasks";
import { cn } from "@/utils";
import { useTranslation } from "react-i18next";

const styles: Record<TaskPriority, string> = {
  LOW: "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300",
  MEDIUM:
    "bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-400",
  HIGH: "bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400",
  URGENT: "bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400",
};

export default function TaskPriorityBadge({
  priority,
}: {
  priority: TaskPriority;
}) {
  const { t } = useTranslation("common", {
    keyPrefix: "taskManagement.priority",
  });

  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full px-2.5 py-1 text-theme-xs font-semibold",
        styles[priority],
      )}
    >
      {t(priority)}
    </span>
  );
}
