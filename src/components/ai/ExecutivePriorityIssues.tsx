import ComponentCard from "@/components/common/ComponentCard";
import type { AiExecutiveAttentionItem } from "@/types/aiExecutiveDashboard";
import { formatAiReportDate } from "@/utils/aiReportDate";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

interface ExecutivePriorityIssuesProps {
  items: AiExecutiveAttentionItem[];
}

export default function ExecutivePriorityIssues({
  items,
}: ExecutivePriorityIssuesProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "aiExecutiveDashboard.priorityIssues",
  });

  const sourceLabel = (item: AiExecutiveAttentionItem) =>
    item.department
      ? t("sourceDetailed", {
          employee: item.employee_name,
          department: item.department.name,
          id: item.report_id,
        })
      : t("sourceWithoutDepartment", {
          employee: item.employee_name,
          id: item.report_id,
        });

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      {items.length === 0 ? (
        <div className="py-6 text-center">
          <p className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {t("emptyTitle")}
          </p>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {t("emptyDescription")}
          </p>
        </div>
      ) : (
        <div className="grid gap-3 lg:grid-cols-2">
          {items.map((item) => (
            <article
              key={item.item_id}
              className="rounded-xl border border-error-200 bg-error-50/50 p-4 dark:border-error-500/20 dark:bg-error-500/5"
            >
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="rounded-full bg-error-100 px-2.5 py-1 text-xs font-semibold text-error-700 dark:bg-error-500/15 dark:text-error-400">
                  {t("blockedBadge")}
                </span>
                <span className="text-xs text-gray-500 dark:text-gray-400">
                  {formatAiReportDate(item.report_date)}
                </span>
              </div>
              <p className="mt-3 text-sm leading-6 text-gray-800 dark:text-gray-200">
                {item.content}
              </p>
              {item.note && (
                <p className="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">
                  {t("note", { note: item.note })}
                </p>
              )}
              <Link
                to={`/reports/${item.report_id}`}
                className="mt-3 inline-flex rounded text-sm font-semibold text-kps-primary underline underline-offset-2 transition-colors hover:text-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none dark:text-sidebar-selected"
              >
                {sourceLabel(item)}
              </Link>
            </article>
          ))}
        </div>
      )}
    </ComponentCard>
  );
}
