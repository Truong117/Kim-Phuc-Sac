import type { WorkItemFormData } from "@/types/reports";
import { useTranslation } from "react-i18next";
export default function ReportIssueSummary({
  items,
}: {
  items: WorkItemFormData[];
}) {
  const { t } = useTranslation("common", { keyPrefix: "dailyReport.v3" });
  const issues = items.filter(
    (item) => item.status !== "BLOCKED" && item.issue?.trim(),
  );
  if (!issues.length) return null;
  return (
    <div className="mt-4 rounded-xl border border-warning-200 p-4 dark:border-warning-800">
      <h3 className="text-theme-sm font-medium text-gray-900 dark:text-white">
        {t("otherIssues")}
      </h3>
      <ul className="mt-2 space-y-3">
        {issues.map((item) => (
          <li
            key={item.clientId}
            className="text-theme-sm break-words whitespace-pre-wrap text-gray-700 dark:text-gray-300"
          >
            <span className="font-medium">{item.content}</span>
            <p>{item.issue}</p>
          </li>
        ))}
      </ul>
    </div>
  );
}
