import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import { formatDateOnly } from "@/utils/dateOnly";
import type {
  AnalysisClaim,
  AnalysisSource,
  WeeklyWorkGroup,
} from "@/types/workReportAnalysis";

type Evidence = AnalysisClaim["evidence"][number];

export default function WorkAnalysisSources({
  evidence,
  sources,
  inventory = [],
  reportIds = [],
}: {
  evidence: Evidence[];
  sources: AnalysisSource[];
  inventory?: WeeklyWorkGroup[];
  reportIds?: number[];
}) {
  const { t } = useTranslation("common", { keyPrefix: "workReportAnalysis" });
  const identities = new Map(sources.map((source) => [source.id, source]));
  const refs = [
    ...new Map(
      evidence.map((ref) => [
        `${ref.report_id}:${ref.item_id}:${ref.quote}`,
        ref,
      ]),
    ).values(),
  ];
  return (
    <footer className="space-y-3 border-t border-gray-200 pt-5 dark:border-gray-700">
      <details className="text-sm text-gray-700 dark:text-gray-300">
        <summary className="cursor-pointer font-medium text-brand-600 dark:text-brand-300">
          {t("reader.sources")}
        </summary>
        <div className="mt-4 space-y-4">
          <p className="text-xs leading-6 text-gray-500 dark:text-gray-400">
            {t("frozenSources")}
          </p>
          {refs.length > 0 && (
            <ul className="space-y-3">
              {refs.map((ref, index) => {
                const source = identities.get(ref.report_id);
                return (
                  <li
                    key={index}
                    className="border-s-2 border-gray-200 ps-3 text-xs leading-6 dark:border-gray-700"
                  >
                    <Link
                      to={`/reports/${ref.report_id}#work-item-${ref.item_id}`}
                      className="text-brand-600 underline dark:text-brand-300"
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
          )}
          {inventory.length > 0 && (
            <details>
              <summary className="cursor-pointer font-medium">
                {t("weeklyWork.sourceDetails")}
              </summary>
              <ul className="mt-3 space-y-4">
                {inventory.map((group, index) => (
                  <li key={index}>
                    <h4 className="font-medium text-gray-900 dark:text-white">
                      {group.content}
                    </h4>
                    <ul className="mt-2 space-y-2">
                      {group.observations.map((observation) => (
                        <li
                          key={`${observation.report_id}:${observation.item_id}`}
                          className="border-s-2 border-gray-200 ps-3 text-xs leading-6 dark:border-gray-700"
                        >
                          <p>
                            <time dateTime={observation.date}>
                              {formatDateOnly(observation.date)}
                            </time>{" "}
                            · {t(`weeklyWork.status.${observation.status}`)}
                          </p>
                          {observation.result && (
                            <p className="break-words whitespace-pre-wrap">
                              {t("weeklyWork.result")}: {observation.result}
                            </p>
                          )}
                          {observation.note && (
                            <p className="break-words whitespace-pre-wrap">
                              {t("weeklyWork.note")}: {observation.note}
                            </p>
                          )}
                          <Link
                            to={`/reports/${observation.report_id}#work-item-${observation.item_id}`}
                            className="text-brand-600 underline dark:text-brand-300"
                          >
                            {t("weeklyWork.source", {
                              report: observation.report_id,
                              item: observation.item_id,
                            })}
                          </Link>
                        </li>
                      ))}
                    </ul>
                  </li>
                ))}
              </ul>
            </details>
          )}
          {reportIds.length > 0 && (
            <div className="flex flex-wrap gap-3">
              {reportIds.map((id) => (
                <Link
                  key={id}
                  to={`/reports/${id}`}
                  className="text-xs text-brand-600 underline dark:text-brand-300"
                >
                  {t("report", { id })}
                </Link>
              ))}
            </div>
          )}
        </div>
      </details>
      <p className="text-xs leading-6 text-gray-500 dark:text-gray-400">
        {t("disclaimer")}
      </p>
    </footer>
  );
}
