import ComponentCard from "@/components/common/ComponentCard";
import type {
  AiExecutiveAttentionItem,
  AiExecutiveDashboardData,
} from "@/types/aiExecutiveDashboard";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

interface ExecutiveInsightsProps {
  analysis: AiExecutiveDashboardData["analysis"];
  attentionItems: AiExecutiveAttentionItem[];
}

export default function ExecutiveInsights({
  analysis,
  attentionItems,
}: ExecutiveInsightsProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "aiExecutiveDashboard.insights",
  });
  const attentionByReport = new Map(
    attentionItems.map((item) => [item.report_id, item]),
  );
  const sourceLabel = (reportId: number) => {
    const source = attentionByReport.get(reportId);

    if (!source) return t("sourceFallback", { id: reportId });

    return source.department
      ? t("sourceDetailed", {
          employee: source.employee_name,
          department: source.department.name,
          id: reportId,
        })
      : t("sourceWithoutDepartment", {
          employee: source.employee_name,
          id: reportId,
        });
  };

  return (
    <ComponentCard title={t("title")} desc={t("methodLabel")} compact>
      {analysis.status === "insufficient_data" ? (
        <div className="py-6 text-center">
          <p className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {t("emptyTitle")}
          </p>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {t("emptyDescription")}
          </p>
        </div>
      ) : (
        <div className="space-y-4">
          {analysis.observations.length === 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {t("noObservations")}
            </p>
          ) : (
            <div className="space-y-3">
              {analysis.observations.map((observation) => (
                <article
                  key={observation.code}
                  className="rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-500/20 dark:bg-warning-500/10"
                >
                  <p className="text-sm font-medium text-warning-800 dark:text-warning-300">
                    {observation.message}
                  </p>
                  <div className="mt-3 flex flex-wrap gap-2">
                    {observation.source_report_ids.map((reportId) => (
                      <Link
                        key={reportId}
                        to={`/reports/${reportId}`}
                        className="rounded-lg border border-warning-300 bg-white px-3 py-2 text-xs font-semibold text-kps-primary transition-colors hover:border-kps-primary hover:text-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/20 focus-visible:outline-none dark:border-warning-700 dark:bg-gray-900 dark:text-sidebar-selected"
                      >
                        {sourceLabel(reportId)}
                      </Link>
                    ))}
                  </div>
                </article>
              ))}
            </div>
          )}

          <p className="border-t border-gray-200 pt-4 text-xs leading-5 text-gray-500 dark:border-gray-700 dark:text-gray-400">
            {analysis.disclaimer}
          </p>
        </div>
      )}
    </ComponentCard>
  );
}
