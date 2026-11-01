import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import {
  analysisSections,
  type AnalysisResult,
  type AnalysisSource,
} from "@/types/workReportAnalysis";
import { formatDateOnly } from "@/utils/dateOnly";

interface WorkAnalysisResultProps {
  result: AnalysisResult;
  sources: AnalysisSource[];
}

export default function WorkAnalysisResult({
  result,
  sources,
}: WorkAnalysisResultProps) {
  const { t } = useTranslation("common", { keyPrefix: "workReportAnalysis" });
  const identities = new Map(sources.map((source) => [source.id, source]));
  return (
    <div className="space-y-6">
      <p className="text-xs leading-6 text-gray-500 dark:text-gray-400">
        {t("commentKindsHint")}
      </p>
      {analysisSections.map((section) => (
        <section key={section} className="space-y-3">
          <h3 className="font-semibold text-gray-900 dark:text-white">
            {t(`sections.${section}`)}
          </h3>
          {result[section].length === 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {t("noFinding")}
            </p>
          ) : (
            result[section].map((claim, index) => (
              <article
                key={index}
                className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900"
              >
                <p className="text-sm leading-7 whitespace-pre-wrap text-gray-800 dark:text-gray-200">
                  {claim.text}
                </p>
                <ul className="mt-3 space-y-2">
                  {claim.evidence.map((ref, refIndex) => {
                    const source = identities.get(ref.report_id);
                    return (
                      <li
                        key={refIndex}
                        className="text-xs leading-6 text-gray-600 dark:text-gray-400"
                      >
                        <Link
                          to={`/reports/${ref.report_id}#work-item-${ref.item_id}`}
                          className="font-medium text-brand-600 underline dark:text-brand-300"
                        >
                          {t("evidence", {
                            employee: source?.employee_name ?? t("source"),
                            date: source ? formatDateOnly(source.date) : "",
                            report: ref.report_id,
                            item: ref.item_id,
                          })}
                        </Link>
                        <p className="break-words whitespace-pre-wrap">
                          {t("quote", { quote: ref.quote })}
                        </p>
                      </li>
                    );
                  })}
                </ul>
              </article>
            ))
          )}
        </section>
      ))}
      <p className="border-t border-gray-200 pt-4 text-xs leading-6 text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {t("disclaimer")}
      </p>
    </div>
  );
}
