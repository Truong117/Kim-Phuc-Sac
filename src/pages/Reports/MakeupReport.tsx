import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import { getMakeupReportOptions } from "@/services/reportService";
import type { MakeupReportOptions } from "@/types/reports";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";
import NewReport from "./NewReport";

const formatDate = (date: string) => date.split("-").reverse().join("/");

export default function MakeupReport() {
  const { t } = useTranslation("common", { keyPrefix: "dailyReport.makeup" });
  const [options, setOptions] = useState<MakeupReportOptions | null>(null);
  const [date, setDate] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    getMakeupReportOptions(controller.signal)
      .then((data) => {
        if (controller.signal.aborted) return;
        setOptions(data);
        setDate(data.days.find((day) => day.can_submit)?.date ?? "");
        setError(null);
      })
      .catch(() => {
        if (!controller.signal.aborted) setError(t("loadError"));
      });
    return () => controller.abort();
  }, [attempt, t]);

  const selector = (
    <section className="mb-6 space-y-3 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
      <p className="text-theme-sm text-gray-700 dark:text-gray-300">
        {t("description")}
      </p>
      {options && (
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("period", {
            start: formatDate(options.period_start),
            end: formatDate(options.period_end),
          })}
        </p>
      )}
      {options?.days.some((day) => day.can_submit) ? (
        <div>
          <Label htmlFor="makeup-report-date">{t("date")}</Label>
          <Select
            id="makeup-report-date"
            value={date}
            options={options.days
              .filter((day) => day.can_submit)
              .map((day) => ({
                value: day.date,
                label: t("dateOption", {
                  date: formatDate(day.date),
                  deadline: new Intl.DateTimeFormat("vi-VN", {
                    dateStyle: "short",
                    timeStyle: "short",
                    timeZone: "Asia/Ho_Chi_Minh",
                  }).format(new Date(day.deadline_at)),
                }),
              }))}
            onChange={setDate}
          />
        </div>
      ) : (
        options && (
          <p
            role="status"
            className="text-theme-sm text-gray-700 dark:text-gray-300"
          >
            {t("empty")}
          </p>
        )
      )}
      {options?.days.some((day) => !day.can_submit) && (
        <p className="text-theme-xs text-gray-500 dark:text-gray-400">
          {t("expiredDates", {
            dates: options.days
              .filter((day) => !day.can_submit)
              .map((day) => formatDate(day.date))
              .join(", "),
          })}
        </p>
      )}
      {error && (
        <p
          role="alert"
          className="text-theme-sm text-error-600 dark:text-error-400"
        >
          {error}
        </p>
      )}
      {error && (
        <Button
          variant="outline"
          size="sm"
          onClick={() => setAttempt((value) => value + 1)}
        >
          {t("retry")}
        </Button>
      )}
      {!options && !error && (
        <p
          role="status"
          className="text-theme-sm text-gray-500 dark:text-gray-400"
        >
          {t("loading")}
        </p>
      )}
      <Link
        to="/reports/new"
        className="inline-flex text-theme-sm font-medium text-kps-primary dark:text-sidebar-selected"
      >
        {t("back")}
      </Link>
    </section>
  );
  if (date)
    return <NewReport key={date} makeupDate={date} dateSelection={selector} />;
  return (
    <>
      <PageMeta
        title={`${t("title")} | KIM PHỤC SẮC`}
        description={t("description")}
      />
      <PageBreadCrumb pageTitle={t("title")} />
      {selector}
    </>
  );
}
