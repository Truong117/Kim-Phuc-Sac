import { useState } from "react";
import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import Input from "@/components/form/input/InputField";
import WorkAnalysisResult from "./WorkAnalysisResult";
import WorkAnalysisWeeklyWork from "./WorkAnalysisWeeklyWork";
import type {
  AnalysisEmployee,
  AnalysisSource,
} from "@/types/workReportAnalysis";

interface WorkAnalysisEmployeesProps {
  employees: AnalysisEmployee[];
  sources: AnalysisSource[];
}

export default function WorkAnalysisEmployees({
  employees,
  sources,
}: WorkAnalysisEmployeesProps) {
  const { t } = useTranslation("common", { keyPrefix: "workReportAnalysis" });
  const [query, setQuery] = useState("");
  const sorted = [...employees]
    .filter((p) =>
      `${p.name} ${p.department_name ?? ""}`
        .toLocaleLowerCase("vi")
        .includes(query.toLocaleLowerCase("vi")),
    )
    .sort((a, b) => a.name.localeCompare(b.name, "vi"));
  return (
    <div className="space-y-4">
      <p className="text-sm text-gray-500 dark:text-gray-400">
        {t("employeeHint")}
      </p>
      <Input
        aria-label={t("searchEmployees")}
        placeholder={t("searchEmployees")}
        value={query}
        onChange={(event) => setQuery(event.target.value)}
      />
      {sorted.map((employee) => (
        <details
          key={employee.employee_id}
          open
          className="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
        >
          <summary className="cursor-pointer text-sm font-medium text-gray-900 dark:text-white">
            {employee.name} · {employee.department_name ?? t("noDepartment")} ·{" "}
            {t(`status.${employee.status}`)}
            {employee.total_units > 0 && (
              <span className="ms-2 text-xs text-gray-500 dark:text-gray-400">
                {employee.completed_units}/{employee.total_units}
              </span>
            )}
          </summary>
          <div className="mt-5 space-y-4">
            {employee.status === "no_data" && (
              <p className="text-sm text-gray-500 dark:text-gray-400">
                {t("status.no_data")}
              </p>
            )}
            {employee.failed_units > 0 && (
              <p className="text-sm text-warning-700 dark:text-warning-300">
                {t("employeePartial", { count: employee.failed_units })}
              </p>
            )}
            {Boolean(employee.work_inventory?.length) && (
              <WorkAnalysisWeeklyWork
                inventory={employee.work_inventory ?? []}
                totals={employee.work_totals ?? []}
                partial={employee.status !== "completed"}
              />
            )}
            {employee.result && (
              <section className="space-y-4 border-t border-gray-200 pt-5 dark:border-gray-700">
                <h3 className="font-semibold text-gray-900 dark:text-white">
                  {t("weeklyWork.review")}
                </h3>
                <WorkAnalysisResult
                  result={employee.result}
                  sources={sources}
                  employee
                />
              </section>
            )}
            <div className="flex flex-wrap gap-3">
              {employee.source_report_ids.map((id) => (
                <Link
                  key={id}
                  to={`/reports/${id}`}
                  className="text-sm text-brand-600 underline dark:text-brand-300"
                >
                  {t("report", { id })}
                </Link>
              ))}
            </div>
          </div>
        </details>
      ))}
      {sorted.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">
          {t("noEmployees")}
        </p>
      )}
    </div>
  );
}
