import { useEffect, useState } from "react";
import { useSearchParams } from "react-router";
import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import ComponentCard from "@/components/common/ComponentCard";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import Button from "@/components/ui/button/Button";
import Select from "@/components/form/Select";
import Label from "@/components/form/Label";
import PersonalWeeklyReportContent from "@/components/reports/PersonalWeeklyReportContent";
import { ApiServiceError } from "@/services/apiClient";
import {
  getPersonalWeeklyPeriods,
  getPersonalWeeklyReport,
} from "@/services/personalWeeklyReportService";
import type { PersonalWeeklyReport as Report } from "@/types/personalWeeklyReport";
import {
  addDaysToDateOnly,
  dateOnlyInTimeZone,
  formatDateOnly,
} from "@/utils/dateOnly";
import {
  lastReportSaturday,
  personalReportWeek,
} from "@/utils/personalReportWeek";

export default function PersonalWeeklyReport() {
  const { t } = useTranslation("common", { keyPrefix: "personalWeeklyReport" });
  const [params, setParams] = useSearchParams();
  const [periods, setPeriods] = useState<Report["period"][] | null>(null);
  const [periodError, setPeriodError] = useState(false);
  const [periodVersion, setPeriodVersion] = useState(0);
  const today = dateOnlyInTimeZone();
  const endDate = personalReportWeek(
    params.get("end_date") ?? periods?.[0]?.end_date ?? null,
    today,
  );
  const lastSaturday = lastReportSaturday(today);
  const maxWeek = addDaysToDateOnly(
    lastSaturday,
    today === lastSaturday ? 0 : 7,
  );
  const changeWeek = (date: string) => setParams({ end_date: date });
  const reloadPeriods = () => setPeriodVersion((value) => value + 1);
  useEffect(() => {
    const controller = new AbortController();
    getPersonalWeeklyPeriods(controller.signal)
      .then((available) => {
        if (controller.signal.aborted) return;
        setPeriods(available);
        setPeriodError(false);
      })
      .catch((error) => {
        if (controller.signal.aborted) return;
        if (
          error instanceof ApiServiceError &&
          [401, 403].includes(error.status)
        )
          setPeriods([]);
        setPeriodError(true);
      });
    return () => controller.abort();
  }, [periodVersion]);
  return (
    <>
      <PageMeta
        title={`${t("title")} | KIM PHỤC SẮC`}
        description={t("subtitle")}
      />
      <PageBreadCrumb pageTitle={t("title")} />
      <div className="space-y-5">
        <ComponentCard title={t("chooseWeek")}>
          <p className="mb-4 text-sm leading-6 text-gray-600 dark:text-gray-300">
            {t("subtitle")}
          </p>
          {periods && periods.length > 0 && (
            <div className="mb-4 max-w-lg">
              <Label htmlFor="personal-saved-period">{t("savedPeriods")}</Label>
              <Select
                id="personal-saved-period"
                value={
                  periods.some((period) => period.end_date === endDate)
                    ? endDate
                    : ""
                }
                placeholder={t("selectPeriod")}
                onChange={changeWeek}
                options={periods.map((period) => ({
                  value: period.end_date,
                  label: t("period", {
                    start: formatDateOnly(period.start_date),
                    end: formatDateOnly(period.end_date),
                  }),
                }))}
              />
            </div>
          )}
          {periodError && (
            <div
              role="alert"
              className="mb-4 space-y-2 text-sm text-warning-700 dark:text-warning-300"
            >
              <p>{t("periodsError")}</p>
              <Button variant="outline" onClick={reloadPeriods}>
                {t("reloadPeriods")}
              </Button>
            </div>
          )}
          <div className="flex flex-wrap items-center gap-3">
            <Button
              variant="outline"
              onClick={() => changeWeek(addDaysToDateOnly(endDate, -7))}
            >
              {t("previous")}
            </Button>
            <div className="w-full sm:w-64">
              <KpsDatePicker
                value={endDate}
                onChange={changeWeek}
                ariaLabel={t("weekEnding")}
                placeholder={t("weekEnding")}
                maxDate={maxWeek}
              />
            </div>
            <Button
              variant="outline"
              disabled={addDaysToDateOnly(endDate, 7) > maxWeek}
              onClick={() => changeWeek(addDaysToDateOnly(endDate, 7))}
            >
              {t("next")}
            </Button>
          </div>
          <p className="mt-3 text-sm font-medium text-gray-800 dark:text-gray-200">
            {t("period", {
              start: formatDateOnly(addDaysToDateOnly(endDate, -6)),
              end: formatDateOnly(endDate),
            })}
          </p>
        </ComponentCard>
        {periods === null && !periodError && !params.has("end_date") ? (
          <p role="status" className="text-sm text-gray-500 dark:text-gray-400">
            {t("loading")}
          </p>
        ) : (
          <WeeklyResult
            key={endDate}
            endDate={endDate}
            onReloadPeriods={reloadPeriods}
          />
        )}
      </div>
    </>
  );
}

function WeeklyResult({
  endDate,
  onReloadPeriods,
}: {
  endDate: string;
  onReloadPeriods: () => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "personalWeeklyReport" });
  const [report, setReport] = useState<Report | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [version, setVersion] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    let timer: ReturnType<typeof setTimeout> | undefined;
    const load = async () => {
      try {
        const next = await getPersonalWeeklyReport(endDate, controller.signal);
        if (controller.signal.aborted) return;
        setReport(next);
        setError(null);
        if (next.status === "running" || next.updating)
          timer = setTimeout(load, 10_000);
      } catch (failure) {
        if (controller.signal.aborted) return;
        if (
          failure instanceof ApiServiceError &&
          [401, 403].includes(failure.status)
        ) {
          setReport(null);
        }
        setError(
          t(
            failure instanceof ApiServiceError && failure.status === 403
              ? "forbidden"
              : "loadError",
          ),
        );
      } finally {
        if (!controller.signal.aborted) setLoading(false);
      }
    };
    void load();
    return () => {
      controller.abort();
      clearTimeout(timer);
    };
  }, [endDate, version, t]);
  return (
    <ComponentCard title={t("result")}>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <p
          role="status"
          className={`text-sm ${report?.status === "completed" ? "text-success-700 dark:text-success-300" : "text-gray-600 dark:text-gray-300"}`}
        >
          {loading ? t("loading") : report ? t(`status.${report.status}`) : ""}
        </p>
        <Button
          variant="outline"
          disabled={loading}
          onClick={() => {
            onReloadPeriods();
            setLoading(true);
            setVersion((value) => value + 1);
          }}
        >
          {t("reload")}
        </Button>
      </div>
      {error && (
        <p
          role="alert"
          className="mb-4 text-sm text-error-600 dark:text-error-400"
        >
          {error}
        </p>
      )}
      {report && (
        <div className="space-y-5">
          {(report.source_changed || report.updating) && (
            <p
              role="status"
              className="rounded-lg bg-warning-50 p-3 text-sm leading-6 text-warning-800 dark:bg-warning-950/40 dark:text-warning-200"
            >
              {t(report.updating ? "updating" : "sourceChanged")}
            </p>
          )}
          {report.is_mock && (
            <p className="text-sm text-warning-700 dark:text-warning-300">
              {t("mock")}
            </p>
          )}
          {report.captured_at && (
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t("captured", {
                time: new Intl.DateTimeFormat("vi-VN", {
                  timeZone: "Asia/Ho_Chi_Minh",
                  dateStyle: "short",
                  timeStyle: "short",
                }).format(new Date(report.captured_at)),
              })}
            </p>
          )}
          <PersonalWeeklyReportContent report={report} />
        </div>
      )}
    </ComponentCard>
  );
}
