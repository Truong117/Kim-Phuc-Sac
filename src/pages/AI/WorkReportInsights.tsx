import { useState } from "react";
import { Link, useSearchParams } from "react-router";
import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import ComponentCard from "@/components/common/ComponentCard";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import WorkAnalysisResult from "@/components/ai/WorkAnalysisResult";
import WorkAnalysisEmployees from "@/components/ai/WorkAnalysisEmployees";
import WorkAnalysisHistory from "@/components/ai/WorkAnalysisHistory";
import WorkAnalysisRegenerate from "@/components/ai/WorkAnalysisRegenerate";
import useWorkReportAnalysis from "@/hooks/useWorkReportAnalysis";
import {
  addDaysToDateOnly,
  dateOnlyInTimeZone,
  formatDateOnly,
} from "@/utils/dateOnly";
import type {
  AnalysisFilters,
  WorkReportAnalysis,
} from "@/types/workReportAnalysis";

type Tab = "overview" | "employees" | "history" | "sources";
const tabs: Tab[] = ["employees", "overview", "history", "sources"];
const time = (value: string) =>
  new Intl.DateTimeFormat("vi-VN", {
    timeZone: "Asia/Ho_Chi_Minh",
    dateStyle: "short",
    timeStyle: "short",
  }).format(new Date(value));

export default function WorkReportInsights() {
  const { t } = useTranslation("common", { keyPrefix: "workReportAnalysis" });
  const [params, setParams] = useSearchParams();
  const [tab, setTab] = useState<Tab>("employees");
  const today = dateOnlyInTimeZone(new Date(), "Asia/Ho_Chi_Minh");
  const filters: AnalysisFilters = {
    end_date: params.get("end") ?? addDaysToDateOnly(today, -1),
    days: Number(params.get("days") ?? 1),
    department_id: params.get("department")
      ? Number(params.get("department"))
      : null,
  };
  const historyId = params.get("analysis")
    ? Number(params.get("analysis"))
    : null;
  const state = useWorkReportAnalysis(filters, historyId);
  const { preview, run, loading, busy, running } = state;
  const selectRun = (selected: WorkReportAnalysis) => {
    state.clear();
    setParams({
      analysis: String(selected.id),
      end: selected.period.end_date,
      days: String(selected.period.days),
      ...(selected.department_id
        ? { department: String(selected.department_id) }
        : {}),
    });
    setTab("employees");
  };
  const updateFilters = (patch: Partial<AnalysisFilters>) => {
    state.clear();
    const next = { ...filters, ...patch };
    setParams({
      end: next.end_date,
      days: String(next.days),
      ...(next.department_id ? { department: String(next.department_id) } : {}),
    });
  };
  const counts = run?.work_counts ?? preview?.work_counts;
  const sources = run?.source_reports ?? preview?.source_reports ?? [];
  const employees =
    run?.employees ??
    preview?.employees.map((employee) => ({
      ...employee,
      status: sources.some(
        (source) => source.employee_id === employee.employee_id,
      )
        ? ("not_analyzed" as const)
        : ("no_data" as const),
      completed_units: 0,
      total_units: 0,
      failed_units: 0,
      source_report_ids: sources
        .filter((source) => source.employee_id === employee.employee_id)
        .map((source) => source.id),
      result: null,
    })) ??
    [];
  const status = run?.status ?? (counts?.reports ? "not_analyzed" : "no_data");
  const isHistory = historyId !== null;
  return (
    <>
      <PageMeta title={t("metaTitle")} description={t("description")} />
      <PageBreadCrumb pageTitle={t("title")} />
      <div className="space-y-5">
        <ComponentCard title={t("title")} desc={t("description")}>
          <p className="text-sm leading-6 text-gray-600 dark:text-gray-400">
            {t("reportBasis")}
          </p>
          <div className="grid gap-4 sm:grid-cols-3">
            <div>
              <Label htmlFor="analysis-period">{t("period")}</Label>
              <Select
                id="analysis-period"
                value={String(filters.days)}
                options={[1, 7, 30].map((days) => ({
                  value: String(days),
                  label: t("periodDays", { days }),
                }))}
                onChange={(value) => updateFilters({ days: Number(value) })}
                disabled={busy}
              />
            </div>
            <div>
              <Label htmlFor="analysis-end">{t("endDate")}</Label>
              <KpsDatePicker
                id="analysis-end"
                value={filters.end_date}
                onChange={(end_date) => updateFilters({ end_date })}
                ariaLabel={t("endDate")}
                placeholder={t("endDate")}
                maxDate={today}
                minDate={addDaysToDateOnly(
                  today,
                  -(preview?.max_days_back ?? 365) + filters.days - 1,
                )}
                disabled={busy}
              />
            </div>
            <div>
              <Label htmlFor="analysis-department">{t("department")}</Label>
              <Select
                id="analysis-department"
                value={
                  filters.department_id ? String(filters.department_id) : ""
                }
                placeholder={t("allDepartments")}
                allowClear
                options={(preview?.departments ?? []).map((department) => ({
                  value: String(department.id),
                  label: department.name,
                }))}
                onChange={(value) =>
                  updateFilters({ department_id: value ? Number(value) : null })
                }
                disabled={busy}
              />
            </div>
          </div>
          <div className="mt-4 flex flex-wrap items-center gap-3">
            {isHistory ? (
              <Button onClick={() => updateFilters({})} disabled={busy}>
                {t("currentSources")}
              </Button>
            ) : (
              <Button
                onClick={() => void state.execute()}
                disabled={
                  loading ||
                  busy ||
                  (running && !run?.requires_new_run) ||
                  !preview?.enabled ||
                  !counts?.reports
                }
              >
                {t(
                  run?.source_changed || run?.config_changed
                    ? "update"
                    : "create",
                )}
              </Button>
            )}
            <Button
              variant="outline"
              onClick={state.refresh}
              disabled={loading || busy}
            >
              {t("refresh")}
            </Button>
            {run?.can_retry && !running && (
              <Button
                variant="outline"
                onClick={() => void state.execute(true)}
                disabled={busy}
              >
                {t("retry")}
              </Button>
            )}
            <span className="text-xs text-gray-500 dark:text-gray-400">
              {t("timezone")}
            </span>
          </div>
          <WorkAnalysisRegenerate run={run} onSelect={selectRun} />
          {preview?.latest_attempt && !isHistory && (
            <p className="mt-3 text-sm text-gray-600 dark:text-gray-400">
              {t("defaultVersion")}{" "}
              <Link
                to={`?${new URLSearchParams({ ...Object.fromEntries(params), analysis: String(preview.latest_attempt.id) })}`}
                className="text-brand-600 underline dark:text-brand-300"
              >
                {t("latestAttempt", {
                  id: preview.latest_attempt.id,
                  status: t(`status.${preview.latest_attempt.status}`),
                })}
              </Link>
            </p>
          )}
          {!preview?.enabled && !isHistory && !loading && (
            <p className="mt-3 text-sm text-warning-700 dark:text-warning-300">
              {t("disabled")}
            </p>
          )}
        </ComponentCard>
        {Boolean(state.error) && (
          <div
            role="alert"
            className="rounded-xl border border-error-200 bg-error-50 p-4 text-sm text-error-700 dark:border-error-800 dark:bg-error-950 dark:text-error-300"
          >
            {state.errorText ?? t("loadError")}
          </div>
        )}
        {loading ? (
          <p role="status" className="text-sm text-gray-500 dark:text-gray-400">
            {t("loading")}
          </p>
        ) : (
          <>
            <div
              className="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
              aria-live="polite"
            >
              <p className="font-medium text-gray-900 dark:text-white">
                {t(`status.${status}`)}
                {run ? ` · ${t("runLabel", { id: run.id })}` : ""}
              </p>
              <p className="mt-2">
                {t("range", {
                  start: formatDateOnly(
                    run?.period.start_date ??
                      addDaysToDateOnly(filters.end_date, 1 - filters.days),
                  ),
                  end: formatDateOnly(run?.period.end_date ?? filters.end_date),
                })}{" "}
                ·{" "}
                {filters.department_id
                  ? t("departmentId", { id: filters.department_id })
                  : t("allDepartments")}
              </p>
              <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {t("lockedOnly")}
              </p>
              {run && (
                <div className="mt-2 space-y-2 text-xs">
                  <p>
                    {t("captured", { time: time(run.captured_at) })} ·{" "}
                    {t("progress", {
                      finished: run.progress.finished,
                      total: run.progress.total,
                    })}
                  </p>
                  <p>
                    {t("revisionInfo", {
                      number: run.revision.number,
                      name: run.requested_by_name ?? t("unknown"),
                      time: time(run.created_at),
                    })}
                  </p>
                  {run.revision.parent_id && (
                    <Link
                      to={`?${new URLSearchParams({ ...Object.fromEntries(params), analysis: String(run.revision.parent_id) })}`}
                      className="text-brand-600 underline dark:text-brand-300"
                    >
                      {t("parentVersion", { id: run.revision.parent_id })}
                    </Link>
                  )}
                  <p>
                    {t(run.is_mock ? "mock" : "live")} · {run.model} ·{" "}
                    {t("cost", {
                      cost:
                        run.usage.cost_microusd === null
                          ? t("unknown")
                          : new Intl.NumberFormat("vi-VN", {
                              style: "currency",
                              currency: "USD",
                              maximumFractionDigits: 6,
                            }).format(run.usage.cost_microusd / 1_000_000),
                    })}
                  </p>
                  {run.source_changed && (
                    <p className="text-warning-700 dark:text-warning-300">
                      {t("changed")}
                    </p>
                  )}
                  {run.requires_new_run && (
                    <p className="text-warning-700 dark:text-warning-300">
                      {t("standardChanged")}
                    </p>
                  )}
                  {run.config_changed && !run.requires_new_run && (
                    <p className="text-warning-700 dark:text-warning-300">
                      {t("configChanged")}
                    </p>
                  )}
                  {run.status === "partial" && (
                    <p className="text-warning-700 dark:text-warning-300">
                      {t("partialHint")}
                    </p>
                  )}
                  {run.errors.map((error) => (
                    <p
                      key={error.unit_key}
                      className="text-error-600 dark:text-error-400"
                    >
                      {t("unitError", { unit: error.unit_key })}:{" "}
                      {t(`errors.${error.code}`, {
                        defaultValue: t("unitFailure"),
                      })}
                    </p>
                  ))}
                </div>
              )}
            </div>
            {counts && (
              <section aria-label={t("statsTitle")} className="space-y-3">
                <h2 className="text-sm font-medium text-gray-800 dark:text-gray-200">
                  {t("statsTitle")}
                </h2>
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                  {(
                    [
                      "reports",
                      "items",
                      "completed",
                      "in_progress",
                      "blocked",
                    ] as const
                  ).map((key) => (
                    <div
                      key={key}
                      className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
                    >
                      <p className="text-title-sm font-semibold text-gray-900 dark:text-white">
                        {counts[key]}
                      </p>
                      <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t(`stats.${key}`)}
                      </p>
                    </div>
                  ))}
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  {t("statsHint")}
                </p>
              </section>
            )}
          </>
        )}
        <ComponentCard title={t("analysisTitle")}>
          <div
            role="tablist"
            aria-label={t("analysisTitle")}
            className="mb-5 flex flex-wrap gap-2"
          >
            {tabs.map((value) => (
              <button
                key={value}
                id={`tab-${value}`}
                role="tab"
                aria-selected={tab === value}
                aria-controls={`panel-${value}`}
                tabIndex={tab === value ? 0 : -1}
                onClick={() => setTab(value)}
                onKeyDown={(event) => {
                  if (
                    ["ArrowRight", "ArrowLeft", "Home", "End"].includes(
                      event.key,
                    )
                  ) {
                    event.preventDefault();
                    const index =
                      event.key === "Home"
                        ? 0
                        : event.key === "End"
                          ? tabs.length - 1
                          : (tabs.indexOf(value) +
                              (event.key === "ArrowRight" ? 1 : -1) +
                              tabs.length) %
                            tabs.length;
                    setTab(tabs[index]);
                    document.getElementById(`tab-${tabs[index]}`)?.focus();
                  }
                }}
                className={`rounded-lg px-4 py-2 text-sm font-medium ${tab === value ? "bg-brand-500 text-white dark:bg-brand-600 dark:text-white" : "bg-gray-50 text-gray-600 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"}`}
              >
                {t(`tabs.${value}`)}
              </button>
            ))}
          </div>
          <div
            role="tabpanel"
            id={`panel-${tab}`}
            aria-labelledby={`tab-${tab}`}
          >
            {tab === "overview" &&
              (run?.result ? (
                <WorkAnalysisResult result={run.result} sources={sources} />
              ) : (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  {t(
                    run?.status === "partial"
                      ? "summaryMissing"
                      : `status.${status}`,
                  )}
                </p>
              ))}
            {tab === "employees" && (
              <WorkAnalysisEmployees employees={employees} sources={sources} />
            )}
            {tab === "history" && <WorkAnalysisHistory onSelect={selectRun} />}
            {tab === "sources" && (
              <div className="space-y-3">
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  {t(run ? "frozenSources" : "currentSourceHint")}
                </p>
                {sources.map((source) => (
                  <Link
                    key={source.id}
                    to={`/reports/${source.id}`}
                    className="block rounded-lg border border-gray-200 p-3 text-sm text-brand-600 underline dark:border-gray-700 dark:text-brand-300"
                  >
                    {source.employee_name} ·{" "}
                    {source.department_name ?? t("noDepartment")} ·{" "}
                    {formatDateOnly(source.date)} ·{" "}
                    {t("report", { id: source.id })}
                  </Link>
                ))}
                {sources.length === 0 && (
                  <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t("status.no_data")}
                  </p>
                )}
              </div>
            )}
          </div>
        </ComponentCard>
      </div>
    </>
  );
}
