import { useId, useState } from "react";
import { useTranslation } from "react-i18next";
import Input from "@/components/form/input/InputField";
import Select from "@/components/form/Select";
import WorkAnalysisResult from "./WorkAnalysisResult";
import WorkAnalysisWeeklyWork from "./WorkAnalysisWeeklyWork";
import WorkAnalysisSources from "./WorkAnalysisSources";
import {
  analysisSections,
  type AnalysisEmployee,
  type AnalysisSource,
} from "@/types/workReportAnalysis";

export default function WorkAnalysisEmployees({
  employees,
  sources,
  selectedEmployeeId,
  onSelect,
  frozen = true,
}: {
  employees: AnalysisEmployee[];
  sources: AnalysisSource[];
  selectedEmployeeId?: number;
  onSelect?: (id: number) => void;
  frozen?: boolean;
}) {
  const { t } = useTranslation("common", { keyPrefix: "workReportAnalysis" });
  const [query, setQuery] = useState("");
  const [localSelection, setLocalSelection] = useState<number>();
  const selectId = useId();
  const panelId = useId();
  const sorted = [...employees]
    .filter((person) =>
      `${person.name} ${person.department_name ?? ""}`
        .toLocaleLowerCase("vi")
        .includes(query.toLocaleLowerCase("vi")),
    )
    .sort((a, b) => a.name.localeCompare(b.name, "vi"));
  const selected =
    sorted.find(
      (person) => person.employee_id === (selectedEmployeeId ?? localSelection),
    ) ?? sorted[0];
  const select = (id: number) => {
    setLocalSelection(id);
    onSelect?.(id);
  };
  return (
    <div className="space-y-4">
      <Input
        aria-label={t("searchEmployees")}
        placeholder={t("searchEmployees")}
        value={query}
        onChange={(event) => setQuery(event.target.value)}
      />
      {!selected ? (
        <p className="text-sm text-gray-500 dark:text-gray-400">
          {t("noEmployees")}
        </p>
      ) : (
        <div className="min-w-0 rounded-xl border border-gray-200 dark:border-gray-700">
          <div className="border-b border-gray-200 p-4 lg:hidden dark:border-gray-700">
            <label
              htmlFor={selectId}
              className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300"
            >
              {t("reader.selectEmployee")}
            </label>
            <Select
              id={selectId}
              value={String(selected.employee_id)}
              options={sorted.map((person) => ({
                value: String(person.employee_id),
                label: `${person.name} · ${t(`status.${person.status}`)}`,
              }))}
              onChange={(value) => select(Number(value))}
            />
          </div>
          <div className="grid min-w-0 lg:grid-cols-[16rem_minmax(0,1fr)]">
            <nav
              aria-label={t("reader.employeeList")}
              className="hidden border-e border-gray-200 p-3 lg:block dark:border-gray-700"
            >
              <ul className="space-y-1">
                {sorted.map((person) => (
                  <li key={person.employee_id}>
                    <button
                      type="button"
                      aria-pressed={selected.employee_id === person.employee_id}
                      aria-controls={panelId}
                      onClick={() => select(person.employee_id)}
                      className={`w-full rounded-lg p-3 text-start transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 ${selected.employee_id === person.employee_id ? "bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300" : "text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800"}`}
                    >
                      <span className="block text-sm font-medium break-words">
                        {person.name}
                      </span>
                      <span className="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                        {person.department_name ?? t("noDepartment")}
                      </span>
                      <span
                        className={`mt-2 block text-xs ${person.status === "completed" ? "text-success-700 dark:text-success-300" : person.status === "failed" || person.status === "partial" ? "text-warning-700 dark:text-warning-300" : "text-gray-500 dark:text-gray-400"}`}
                      >
                        {t(`status.${person.status}`)}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            </nav>
            <section
              id={panelId}
              aria-label={t("reader.employeeReport", { name: selected.name })}
              className="min-w-0 p-4 sm:p-6"
            >
              <span role="status" className="sr-only">
                {t("reader.employeeReport", { name: selected.name })}
              </span>
              <div className="mb-6 flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 pb-4 dark:border-gray-700">
                <div>
                  <h2 className="text-lg font-semibold break-words text-gray-900 dark:text-white">
                    {selected.name}
                  </h2>
                  <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {selected.department_name ?? t("noDepartment")}
                  </p>
                </div>
                <span className="text-xs text-gray-500 dark:text-gray-400">
                  {t(`status.${selected.status}`)}
                  {selected.total_units > 0 &&
                    ` · ${selected.completed_units}/${selected.total_units}`}
                </span>
              </div>
              <div key={selected.employee_id} className="space-y-6">
                {selected.status !== "completed" && (
                  <p className="text-sm leading-6 text-gray-600 dark:text-gray-400">
                    {t(selected.result ? "reader.partial" : "reader.pending")}
                  </p>
                )}
                {selected.failed_units > 0 && (
                  <p
                    role="status"
                    className="text-sm text-warning-700 dark:text-warning-300"
                  >
                    {t("employeePartial", { count: selected.failed_units })}
                  </p>
                )}
                {selected.result && (
                  <>
                    <WorkAnalysisWeeklyWork
                      groups={selected.work_groups ?? []}
                      totals={selected.work_totals ?? []}
                      partial={selected.status !== "completed"}
                    />
                    <WorkAnalysisResult
                      result={selected.result}
                      sources={sources}
                      totals={selected.work_totals ?? []}
                      employee
                    />
                  </>
                )}
                <WorkAnalysisSources
                  sources={sources}
                  frozen={frozen}
                  inventory={selected.work_inventory ?? []}
                  reportIds={selected.source_report_ids}
                  evidence={[
                    ...(selected.work_groups ?? []).flatMap(
                      (group) => group.evidence,
                    ),
                    ...(selected.work_totals ?? []).flatMap(
                      (total) => total.entries,
                    ),
                    ...(selected.result
                      ? analysisSections.flatMap((section) =>
                          selected.result![section].flatMap(
                            (claim) => claim.evidence,
                          ),
                        )
                      : []),
                  ]}
                />
              </div>
            </section>
          </div>
        </div>
      )}
    </div>
  );
}
