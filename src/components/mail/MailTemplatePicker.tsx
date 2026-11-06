import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
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
}
export default function MailTemplatePicker({
  fromName,
  disabled,
  onApply,
  onBusyChange,
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
  const [candidate, setCandidate] = useState<MailTemplateContent | null>(null);
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
    onBusyChange(loading || candidate !== null);
    return () => onBusyChange(false);
  }, [loading, candidate, onBusyChange]);
  function clear() {
    request.current?.abort();
    request.current = null;
    setChoices([]);
    setSelected("");
    setError("");
    setLoaded(false);
    setLoading(false);
    setCandidate(null);
  }
  async function run(action: (signal: AbortSignal) => Promise<void>) {
    request.current?.abort();
    const controller = new AbortController();
    request.current = controller;
    setLoading(true);
    setError("");
    try {
      await action(controller.signal);
    } catch (cause) {
      if (!controller.signal.aborted && mounted.current)
        setError(
          cause instanceof ApiServiceError &&
            [401, 403, 404].includes(cause.status)
            ? "mailTemplates.accessError"
            : "mailTemplates.sourceError",
        );
    } finally {
      if (mounted.current && request.current === controller) setLoading(false);
    }
  }
  async function loadSources(nextPage = page) {
    setPage(nextPage);
    setSelected("");
    setChoices([]);
    setLoaded(false);
    await run(async (signal) => {
      if (kind === "daily") {
        const result = await listDailyDocuments(date, nextPage, signal);
        if (signal.aborted) return;
        setLastPage(result.last_page);
        setChoices(
          result.reports.filter(dailyMailReady).map((row) => ({
            value: String(row.document!.id),
            label: `${row.employee_name} · ${formatDateOnly(row.document!.source.date)} · #${row.document!.id}${row.document!.mode === "fake" ? ` · ${t("mailTemplates.mock")}` : ""}`,
          })),
        );
      } else {
        const runs = await getWorkAnalysisHistory(signal);
        if (signal.aborted) return;
        setChoices(
          runs.filter(weeklyMailReady).map((entry) => ({
            value: String(entry.id),
            label: `#${entry.id} · ${formatDateOnly(entry.period.start_date)} – ${formatDateOnly(entry.period.end_date)} · ${entry.department_id ? t("mailTemplates.department", { id: entry.department_id }) : t("mailTemplates.savedScope")}${entry.is_mock ? ` · ${t("mailTemplates.mock")}` : ""}`,
          })),
        );
      }
      setLoaded(true);
    });
  }
  async function previewTemplate() {
    if (!kind || disabled) return;
    if (kind !== "daily" && kind !== "weekly") {
      setCandidate(staticMailTemplate(kind, fromName, t));
      return;
    }
    await run(async (signal) => {
      let content: MailTemplateContent;
      if (kind === "daily") {
        // Refresh the authorized listing before using a previously selected result.
        const fresh = await listDailyDocuments(date, page, signal);
        const row = fresh.reports.find(
          (entry) => String(entry.document?.id) === selected,
        );
        if (signal.aborted) return;
        if (!row) throw new Error("MAIL_AI_SOURCE_UNAVAILABLE");
        content = dailyMailTemplate(row, fromName, window.location.origin, t);
      } else {
        const fresh = await getWorkAnalysis(Number(selected), signal);
        if (signal.aborted) return;
        content = weeklyMailTemplate(
          fresh,
          fromName,
          window.location.origin,
          t,
        );
      }
      if (!signal.aborted) setCandidate(content);
    });
  }
  return (
    <div className="min-w-0 space-y-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
      <Label htmlFor="mail-template">{t("mailTemplates.label")}</Label>
      <Select
        id="mail-template"
        value={kind}
        placeholder={t("mailTemplates.choose")}
        allowClear
        disabled={disabled || loading}
        options={mailTemplateIds.map((value) => ({
          value,
          label: t(`mailTemplates.names.${value}`),
        }))}
        onChange={(value) => {
          clear();
          setKind(value as MailTemplateId | "");
          setPage(1);
          setLastPage(1);
        }}
      />
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
              disabled={disabled || loading}
              onChange={(value) => {
                clear();
                setDate(value);
                setPage(1);
                setLastPage(1);
              }}
            />
          )}
          <div className="flex flex-wrap items-center gap-2">
            <Button
              variant="outline"
              disabled={disabled || loading}
              onClick={() => void loadSources()}
            >
              {t(loading ? "mailTemplates.loading" : "mailTemplates.load")}
            </Button>
            {kind === "daily" && (
              <>
                <Button
                  variant="outline"
                  disabled={disabled || loading || page <= 1}
                  onClick={() => {
                    clear();
                    void loadSources(page - 1);
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
                    void loadSources(page + 1);
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
            options={choices}
            placeholder={t("mailTemplates.chooseSource")}
            disabled={disabled || loading || !choices.length}
            onChange={setSelected}
          />
          {loaded && choices.length === 0 && (
            <p
              role="status"
              className="text-sm text-gray-600 dark:text-gray-300"
            >
              {t("mailTemplates.empty")}
            </p>
          )}
        </>
      )}
      {error && (
        <p role="alert" className="text-sm text-error-600 dark:text-error-400">
          {t(error)}
        </p>
      )}
      <Button
        variant="outline"
        disabled={disabled || loading || !kind || (ai && !selected)}
        onClick={() => void previewTemplate()}
      >
        {t("mailTemplates.preview")}
      </Button>
      <Modal
        isOpen={candidate !== null && !disabled}
        onClose={() => setCandidate(null)}
        ariaLabelledBy="mail-template-heading"
        className="max-w-2xl p-4 sm:p-6"
      >
        {candidate && (
          <div className="min-w-0 space-y-4">
            <h2
              id="mail-template-heading"
              className="pe-10 text-lg font-semibold text-gray-900 dark:text-white"
            >
              {t("mailTemplates.confirmTitle")}
            </h2>
            <p className="text-sm text-gray-600 dark:text-gray-300">
              {t("mailTemplates.overwriteHint")}
            </p>
            <div className="max-h-[55vh] space-y-3 overflow-y-auto text-sm text-gray-800 dark:text-gray-200">
              <p className="font-semibold break-all">{candidate.subject}</p>
              <p className="break-words whitespace-pre-wrap">
                {candidate.body}
              </p>
            </div>
            {Array.from(candidate.body).length > 10000 && (
              <p
                role="alert"
                className="text-sm text-warning-700 dark:text-warning-400"
              >
                {t("mailTemplates.tooLong")}
              </p>
            )}
            <div className="flex flex-wrap gap-2">
              <Button
                disabled={disabled}
                onClick={() => {
                  onApply(candidate);
                  setCandidate(null);
                }}
              >
                {t("mailTemplates.apply")}
              </Button>
              <Button variant="outline" onClick={() => setCandidate(null)}>
                {t("mailUi.cancel")}
              </Button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
