import { useEffect, useState } from "react";
import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import { getWorkAnalysisHistory } from "@/services/workReportAnalysisService";
import {
  getAiDailyHistory,
  getAiDailyReport,
} from "@/services/aiDailyReportService";
import type { WorkReportAnalysis } from "@/types/workReportAnalysis";
import type { AiDailyReport } from "@/types/aiDailyReport";
import { formatDateOnly } from "@/utils/dateOnly";

interface WorkAnalysisHistoryProps {
  onSelect: (run: WorkReportAnalysis) => void;
}

export default function WorkAnalysisHistory({
  onSelect,
}: WorkAnalysisHistoryProps) {
  const { t } = useTranslation("common", { keyPrefix: "workReportAnalysis" });
  const [runs, setRuns] = useState<WorkReportAnalysis[]>([]);
  const [legacy, setLegacy] = useState<AiDailyReport[]>([]);
  const [selectedLegacy, setSelectedLegacy] = useState<AiDailyReport | null>(
    null,
  );
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  useEffect(() => {
    const controller = new AbortController();
    Promise.all([
      getWorkAnalysisHistory(controller.signal),
      getAiDailyHistory(controller.signal),
    ])
      .then(([next, old]) => {
        if (!controller.signal.aborted) {
          setRuns(next);
          setLegacy(old);
        }
      })
      .catch(() => {
        if (!controller.signal.aborted) setError(true);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, []);
  if (loading)
    return (
      <p className="text-sm text-gray-500 dark:text-gray-400">{t("loading")}</p>
    );
  if (error)
    return (
      <p role="alert" className="text-sm text-error-600 dark:text-error-400">
        {t("loadError")}
      </p>
    );
  return (
    <div className="space-y-5">
      <p className="text-sm text-gray-500 dark:text-gray-400">
        {t("historyHint")}
      </p>
      <div className="space-y-3">
        {runs.map((run) => (
          <article
            key={run.id}
            className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700"
          >
            <div className="space-y-1 text-sm text-gray-800 dark:text-gray-200">
              <p>
                {t("runLabel", { id: run.id })} ·{" "}
                {formatDateOnly(run.period.start_date)} –{" "}
                {formatDateOnly(run.period.end_date)}
              </p>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {t(`status.${run.status}`)} · {t(run.is_mock ? "mock" : "live")}{" "}
                ·{" "}
                {run.department_id
                  ? t("departmentId", { id: run.department_id })
                  : t("allDepartments")}
                {run.source_changed ? ` · ${t("changed")}` : ""}
              </p>
            </div>
            <Button size="sm" variant="outline" onClick={() => onSelect(run)}>
              {t("view")}
            </Button>
          </article>
        ))}
      </div>
      {runs.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">
          {t("noHistory")}
        </p>
      )}
      <details className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <summary className="cursor-pointer font-medium text-gray-900 dark:text-white">
          {t("legacyTitle")}
        </summary>
        <p className="my-3 text-xs text-gray-500 dark:text-gray-400">
          {t("legacyHint")}
        </p>
        {legacy.map((run) => (
          <div
            key={run.id}
            className="my-2 flex items-center justify-between gap-3 text-sm text-gray-700 dark:text-gray-300"
          >
            <span>
              {formatDateOnly(run.report_date)} · {t(`status.${run.status}`)}
            </span>
            <Button
              size="sm"
              variant="outline"
              onClick={() => {
                getAiDailyReport(run.id)
                  .then(setSelectedLegacy)
                  .catch(() => setError(true));
              }}
            >
              {t("view")}
            </Button>
          </div>
        ))}
        {legacy.length === 0 && (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            {t("noHistory")}
          </p>
        )}
        {selectedLegacy?.result && (
          <div className="mt-4 space-y-3">
            <p className="text-sm whitespace-pre-wrap text-gray-800 dark:text-gray-200">
              {selectedLegacy.result.overview}
            </p>
            {(["highlights", "attention", "recommendations"] as const).map(
              (key) => (
                <section key={key} className="space-y-2">
                  <h3 className="text-sm font-medium text-gray-900 dark:text-white">
                    {t(`sections.${key}`)}
                  </h3>
                  {selectedLegacy.result![key].map((claim, index) => (
                    <div
                      key={index}
                      className="text-sm text-gray-700 dark:text-gray-300"
                    >
                      <p className="whitespace-pre-wrap">{claim.text}</p>
                      {claim.report_ids.map((id) => (
                        <Link
                          key={id}
                          to={`/reports/${id}`}
                          className="me-3 text-xs text-brand-600 underline dark:text-brand-300"
                        >
                          {t("report", { id })}
                        </Link>
                      ))}
                    </div>
                  ))}
                </section>
              ),
            )}
            {selectedLegacy.source_reports.map((source) => (
              <Link
                key={source.id}
                to={`/reports/${source.id}`}
                className="me-3 text-sm text-brand-600 underline dark:text-brand-300"
              >
                {source.employee_name} · {t("report", { id: source.id })}
              </Link>
            ))}
          </div>
        )}
      </details>
    </div>
  );
}
