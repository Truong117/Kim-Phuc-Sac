import { useId } from "react";
import { useTranslation } from "react-i18next";
import DailyDocumentEmployee, {
  DailyDocumentStatus,
} from "@/components/ai/DailyDocumentEmployee";
import Select from "@/components/form/Select";
import { CheckCircleIcon } from "@/icons";
import type { DailyDocumentsPage } from "@/types/dailyDocument";

type Row = DailyDocumentsPage["reports"][number];

export default function DailyDocumentReader({
  reports,
  selectedReportId,
  onSelect,
  busy,
  onRetry,
  onRebuild,
}: {
  reports: Row[];
  selectedReportId?: number;
  onSelect: (reportId: number) => void;
  busy: boolean;
  onRetry: (documentId: number) => void;
  onRebuild: (reportId: number) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "dailyDocuments" });
  const selectId = useId();
  const panelId = useId();
  const selected =
    reports.find((row) => row.report_id === selectedReportId) ?? reports[0];
  if (!selected) return null;
  return (
    <div className="min-w-0 rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
      <div className="border-b border-gray-100 p-4 lg:hidden dark:border-gray-800">
        <label
          htmlFor={selectId}
          className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300"
        >
          {t("selectEmployee")}
        </label>
        <Select
          id={selectId}
          value={String(selected.report_id)}
          options={reports.map((row) => ({
            value: String(row.report_id),
            label: row.employee_name,
          }))}
          onChange={(value) => onSelect(Number(value))}
          placeholder={t("selectEmployee")}
        />
      </div>
      <div className="grid min-w-0 lg:grid-cols-[16rem_minmax(0,1fr)]">
        <nav
          aria-label={t("employeeList")}
          className="hidden border-e border-gray-100 p-3 lg:block dark:border-gray-800"
        >
          <p className="mb-3 flex items-center justify-between px-2 text-xs font-medium text-gray-500 dark:text-gray-400">
            {t("employeeList")} <span>{reports.length}</span>
          </p>
          <ul className="space-y-1">
            {reports.map((row) => {
              const source = row.document?.source ?? row.source;
              const completed = source.items.filter(
                (item) => item.status === "COMPLETED",
              ).length;
              const inProgress = source.items.filter(
                (item) => item.status === "IN_PROGRESS",
              ).length;
              const needsClarification = row.document?.result?.work_totals.some(
                (total) => total.status !== "confirmed" || total.total === null,
              );
              return (
                <li key={row.report_id}>
                  <button
                    type="button"
                    aria-pressed={selected.report_id === row.report_id}
                    aria-controls={panelId}
                    onClick={() => onSelect(row.report_id)}
                    className={`w-full rounded-xl p-3 text-start transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 ${selected.report_id === row.report_id ? "bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300" : "text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5"}`}
                  >
                    <span className="flex items-start justify-between gap-2">
                      <span className="min-w-0 text-sm font-medium break-words">
                        {row.employee_name}
                      </span>
                      {row.document?.status === "completed" && (
                        <CheckCircleIcon
                          className="mt-0.5 h-4 w-4 shrink-0 text-success-600 dark:text-success-400"
                          aria-label={t("status.completed")}
                        />
                      )}
                    </span>
                    <span className="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                      {t("employeeCounts", { completed, inProgress })}
                    </span>
                    {row.document?.status !== "completed" && (
                      <span className="mt-2 block">
                        <DailyDocumentStatus document={row.document} />
                      </span>
                    )}
                    {needsClarification && (
                      <span className="mt-1 block text-xs text-warning-700 dark:text-warning-400">
                        {t("hasClarifications")}
                      </span>
                    )}
                  </button>
                </li>
              );
            })}
          </ul>
        </nav>
        <section
          id={panelId}
          aria-label={t("employeeReport", { name: selected.employee_name })}
          className="min-w-0"
        >
          <span role="status" className="sr-only">
            {t("selectedEmployee", { name: selected.employee_name })}
          </span>
          <DailyDocumentEmployee
            key={selected.report_id}
            row={selected}
            busy={busy}
            onRetry={() => selected.document && onRetry(selected.document.id)}
            onRebuild={() => onRebuild(selected.report_id)}
          />
        </section>
      </div>
    </div>
  );
}
