import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import { ApiServiceError } from "@/services/apiClient";
import { listDailyDocuments } from "@/services/dailyDocumentService";
import {
  getWorkAnalysis,
  getWorkAnalysisHistory,
} from "@/services/workReportAnalysisService";
import {
  addDaysToDateOnly,
  dateOnlyInTimeZone,
  formatDateOnly,
} from "@/utils/dateOnly";
import {
  dailyMailReady,
  dailyMailTemplate,
  DAILY_SUMMARY_SOURCE,
  dailySummaryMailReady,
  dailySummaryMailTemplate,
  mailTemplateIds,
  staticMailTemplate,
  weeklyMailReady,
  weeklyMailTemplate,
  type MailTemplateId,
  type MailTemplateContent,
} from "@/utils/mailTemplates";

interface Props {
  fromName: string;
  disabled: boolean;
  onApply: (content: MailTemplateContent) => void;
  onBusyChange: (busy: boolean) => void;
  onReadyChange: (ready: boolean) => void;
}
export default function MailTemplatePicker({
  fromName,
  disabled,
  onApply,
  onBusyChange,
  onReadyChange,
}: Props) {
  const { t } = useTranslation();
  const [kind, setKind] = useState<MailTemplateId | "">("");
  const [date, setDate] = useState(() =>
    addDaysToDateOnly(dateOnlyInTimeZone(new Date(), "Asia/Ho_Chi_Minh"), -1),
  );
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [choices, setChoices] = useState<{ value: string; label: string }[]>(
    [],
  );
  const [selected, setSelected] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [loaded, setLoaded] = useState(false);
  const [tooLong, setTooLong] = useState(false);
  const request = useRef<AbortController | null>(null);
  const mounted = useRef(true);
  const ai = kind === "daily" || kind === "weekly";
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      request.current?.abort();
    };
  }, []);
  useEffect(() => {
    if (disabled) request.current?.abort();
  }, [disabled]);
  useEffect(() => {
    onBusyChange(loading);
    return () => onBusyChange(false);
  }, [loading, onBusyChange]);

  function apply(content: MailTemplateContent) {
    onApply(content);
    setTooLong(Array.from(content.body).length > 10000);
    onReadyChange(true);
  }
  function clear() {
    request.current?.abort();
    request.current = null;
    setChoices([]);
    setSelected("");
    setError("");
    setLoaded(false);
    setLoading(false);
    setTooLong(false);
  }
  async function loadAI(
    nextKind: "daily" | "weekly",
    nextDate: string,
    nextPage: number,
    sourceId = "",
  ) {
    request.current?.abort();
    const controller = new AbortController();
    request.current = controller;
    const signal = controller.signal;
    setLoading(true);
    setError("");
    setTooLong(false);
    setSelected(sourceId);
    setPage(nextPage);
    onReadyChange(false);
    // A new date/source must never retain the previous report's body.
    onApply({
      subject:
        nextKind === "daily"
          ? t(
              sourceId === DAILY_SUMMARY_SOURCE
                ? "mailTemplates.dailySummarySubject"
                : "mailTemplates.dailyDateSubject",
              {
                date: formatDateOnly(nextDate),
              },
            )
          : t("mailTemplates.weeklyDefaultSubject"),
      body: "",
    });
    if (!sourceId) {
      setChoices([]);
      setLoaded(false);
    }
    try {
      if (nextKind === "daily") {
        const fresh = await listDailyDocuments(nextDate, nextPage, signal);
        if (signal.aborted) return;
        const rows = fresh.reports.filter(
          (row) =>
            dailyMailReady(row) && row.document!.source.date === nextDate,
        );
        setLastPage(fresh.last_page);
        setChoices(
          rows.map((row) => ({
            value: String(row.document!.id),
            label: `${row.employee_name} · ${formatDateOnly(row.document!.source.date)} · #${row.document!.id}${row.document!.mode === "fake" ? ` · ${t("mailTemplates.mock")}` : ""}`,
          })),
        );
        if (sourceId === DAILY_SUMMARY_SOURCE) {
          const history = await getWorkAnalysisHistory(signal);
          if (signal.aborted) return;
          const candidate = history
            .filter((run) => dailySummaryMailReady(run, nextDate))
            .sort((a, b) => b.id - a.id)[0];
          if (!candidate) throw new Error("MAIL_DAILY_SUMMARY_MISSING");
          const summary = await getWorkAnalysis(candidate.id, signal);
          if (signal.aborted) return;
          if (summary.id !== candidate.id)
            throw new Error("MAIL_AI_SOURCE_UNAVAILABLE");
          apply(
            dailySummaryMailTemplate(
              summary,
              nextDate,
              fromName,
              window.location.origin,
              t,
            ),
          );
        } else {
          const row = sourceId
            ? rows.find((entry) => String(entry.document!.id) === sourceId)
            : rows.length === 1
              ? rows[0]
              : undefined;
          if (sourceId && !row) throw new Error("MAIL_AI_SOURCE_UNAVAILABLE");
          if (row) {
            setSelected(String(row.document!.id));
            apply(
              dailyMailTemplate(
                row,
                fromName,
                window.location.origin,
                t,
                nextPage,
              ),
            );
          }
        }
      } else {
        let id = sourceId;
        if (!id) {
          const history = await getWorkAnalysisHistory(signal);
          if (signal.aborted) return;
          const runs = history.filter(weeklyMailReady);
          setChoices(
            runs.map((entry) => ({
              value: String(entry.id),
              label: `#${entry.id} · ${formatDateOnly(entry.period.start_date)} – ${formatDateOnly(entry.period.end_date)} · ${entry.department_id ? t("mailTemplates.department", { id: entry.department_id }) : t("mailTemplates.savedScope")}${entry.is_mock ? ` · ${t("mailTemplates.mock")}` : ""}`,
            })),
          );
          if (runs.length === 1) id = String(runs[0].id);
        }
        if (id) {
          const fresh = await getWorkAnalysis(Number(id), signal);
          if (signal.aborted) return;
          if (String(fresh.id) !== id)
            throw new Error("MAIL_AI_SOURCE_UNAVAILABLE");
          setSelected(id);
          apply(weeklyMailTemplate(fresh, fromName, window.location.origin, t));
        }
      }
      if (!signal.aborted) setLoaded(true);
    } catch (cause) {
      if (!signal.aborted && mounted.current)
        setError(
          cause instanceof ApiServiceError &&
            [401, 403, 404].includes(cause.status)
            ? "mailTemplates.accessError"
            : cause instanceof Error &&
                cause.message === "MAIL_DAILY_SUMMARY_MISSING"
              ? "mailTemplates.dailySummaryMissing"
              : "mailTemplates.sourceError",
        );
    } finally {
      if (mounted.current && request.current === controller) setLoading(false);
    }
  }
  function chooseTemplate(value: string) {
    if (disabled) return;
    clear();
    setKind(value as MailTemplateId | "");
    setPage(1);
    setLastPage(1);
    if (value === "daily" || value === "weekly") void loadAI(value, date, 1);
    else if (value)
      apply(
        staticMailTemplate(
          value as Exclude<MailTemplateId, "daily" | "weekly">,
          fromName,
          t,
        ),
      );
    else onReadyChange(true);
  }
  return (
    <div className="min-w-0 space-y-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
      <Label htmlFor="mail-template">{t("mailTemplates.label")}</Label>
      <Select
        id="mail-template"
        value={kind}
        placeholder={t("mailTemplates.choose")}
        allowClear
        disabled={disabled}
        options={mailTemplateIds.map((value) => ({
          value,
          label: t(`mailTemplates.names.${value}`),
        }))}
        onChange={chooseTemplate}
      />
      {kind && (
        <p className="text-sm text-gray-600 dark:text-gray-300">
          {t("mailTemplates.autoHint")}
        </p>
      )}
      {ai && (
        <>
          <p className="text-sm text-gray-600 dark:text-gray-300">
            {t(
              kind === "daily"
                ? "mailTemplates.dailyHint"
                : "mailTemplates.weeklyHint",
            )}
          </p>
          {kind === "daily" && (
            <KpsDatePicker
              value={date}
              ariaLabel={t("mailTemplates.date")}
              placeholder={t("mailTemplates.date")}
              disabled={disabled}
              onChange={(value) => {
                clear();
                setDate(value);
                setLastPage(1);
                void loadAI(
                  "daily",
                  value,
                  1,
                  selected === DAILY_SUMMARY_SOURCE ? DAILY_SUMMARY_SOURCE : "",
                );
              }}
            />
          )}
          <div className="flex flex-wrap items-center gap-2">
            <Button
              variant="outline"
              disabled={disabled || loading}
              onClick={() =>
                void loadAI(
                  kind as "daily" | "weekly",
                  date,
                  page,
                  selected === DAILY_SUMMARY_SOURCE ? DAILY_SUMMARY_SOURCE : "",
                )
              }
            >
              {t(loading ? "mailTemplates.loading" : "mailTemplates.reload")}
            </Button>
            {kind === "daily" && selected !== DAILY_SUMMARY_SOURCE && (
              <>
                <Button
                  variant="outline"
                  disabled={disabled || loading || page <= 1}
                  onClick={() => {
                    clear();
                    void loadAI("daily", date, page - 1);
                  }}
                >
                  {t("mailTemplates.previous")}
                </Button>
                <span className="text-sm text-gray-600 dark:text-gray-300">
                  {t("mailTemplates.page", { page, total: lastPage })}
                </span>
                <Button
                  variant="outline"
                  disabled={disabled || loading || page >= lastPage}
                  onClick={() => {
                    clear();
                    void loadAI("daily", date, page + 1);
                  }}
                >
                  {t("mailTemplates.next")}
                </Button>
              </>
            )}
          </div>
          <Label htmlFor="mail-template-source">
            {t("mailTemplates.source")}
          </Label>
          <Select
            id="mail-template-source"
            value={selected}
            options={
              kind === "daily"
                ? [
                    {
                      value: DAILY_SUMMARY_SOURCE,
                      label: t("mailTemplates.dailySummaryOption"),
                    },
                    ...choices,
                  ]
                : choices
            }
            placeholder={t("mailTemplates.chooseSource")}
            disabled={
              disabled || loading || (kind !== "daily" && !choices.length)
            }
            onChange={(id) =>
              void loadAI(kind as "daily" | "weekly", date, page, id)
            }
          />
          {loading && (
            <p
              role="status"
              className="text-sm text-gray-600 dark:text-gray-300"
            >
              {t("mailTemplates.loading")}
            </p>
          )}
          {loaded && !loading && choices.length === 0 && (
            <p
              role="status"
              className="text-sm text-gray-600 dark:text-gray-300"
            >
              {t(
                kind === "daily"
                  ? "mailTemplates.dailyIndividualsEmpty"
                  : "mailTemplates.empty",
              )}
            </p>
          )}
          {loaded && !loading && choices.length > 1 && !selected && (
            <p
              role="status"
              className="text-sm text-gray-600 dark:text-gray-300"
            >
              {t("mailTemplates.chooseSourceHint")}
            </p>
          )}
        </>
      )}
      {error && (
        <p role="alert" className="text-sm text-error-600 dark:text-error-400">
          {t(error, { date: formatDateOnly(date) })}
        </p>
      )}
      {error === "mailTemplates.dailySummaryMissing" && (
        <Link
          to={`/ai/insights?end=${encodeURIComponent(date)}&days=1`}
          className="inline-block text-sm font-medium text-kps-primary underline dark:text-sidebar-selected"
        >
          {t("mailTemplates.openDailySummary")}
        </Link>
      )}
      {tooLong && (
        <p
          role="alert"
          className="text-sm text-warning-700 dark:text-warning-400"
        >
          {t("mailTemplates.tooLong")}
        </p>
      )}
    </div>
  );
}
