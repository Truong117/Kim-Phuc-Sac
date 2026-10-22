import type { TaskSummary as TaskSummaryData } from "@/types/tasks";
import { cn } from "@/utils";
import { useTranslation } from "react-i18next";

const cards: Array<{
  key: keyof TaskSummaryData;
  label: string;
  className: string;
}> = [
  { key: "todo", label: "todo", className: "text-gray-700 dark:text-gray-200" },
  {
    key: "in_progress",
    label: "inProgress",
    className: "text-blue-light-700 dark:text-blue-light-400",
  },
  {
    key: "blocked",
    label: "blocked",
    className: "text-error-600 dark:text-error-400",
  },
  {
    key: "overdue",
    label: "overdue",
    className: "text-warning-700 dark:text-warning-400",
  },
];

export default function TaskSummary({ summary }: { summary: TaskSummaryData }) {
  const { t } = useTranslation("common", {
    keyPrefix: "taskManagement.summary",
  });

  return (
    <section
      aria-label={t("ariaLabel")}
      className="grid grid-cols-2 gap-3 lg:grid-cols-4"
    >
      {cards.map((card) => (
        <div
          key={card.key}
          className="rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-xs dark:border-gray-800 dark:bg-white/3"
        >
          <p className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
            {t(card.label)}
          </p>
          <p className={cn("mt-2 text-title-sm font-bold", card.className)}>
            {summary[card.key]}
          </p>
        </div>
      ))}
    </section>
  );
}
