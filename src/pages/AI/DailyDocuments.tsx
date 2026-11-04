import { useState } from "react";
import { useSearchParams } from "react-router";
import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import ComponentCard from "@/components/common/ComponentCard";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import DailyDocumentEmployee from "@/components/ai/DailyDocumentEmployee";
import Button from "@/components/ui/button/Button";
import Badge from "@/components/ui/badge/Badge";
import useDailyDocuments from "@/hooks/useDailyDocuments";
import {
  requestDailyDocumentBatch,
  retryDailyDocument,
} from "@/services/dailyDocumentService";
import type { DailyDocumentBatch } from "@/types/dailyDocument";
import { dateOnlyInTimeZone, isDateOnly } from "@/utils/dateOnly";

export default function DailyDocuments() {
  const { t } = useTranslation("common", { keyPrefix: "dailyDocuments" });
  const [params, setParams] = useSearchParams();
  const today = dateOnlyInTimeZone(new Date(), "Asia/Ho_Chi_Minh");
  const date = isDateOnly(params.get("date") ?? "")
    ? params.get("date")!
    : today;
  const page = Math.max(1, Math.min(10000, Number(params.get("page")) || 1));
  // Remount on date/page selection to discard action error state from the prior view.
  return (
    <DailyDocumentsView
      key={`${date}:${page}`}
      date={date}
      today={today}
      page={page}
      onSelect={(next, nextPage) =>
        setParams({ view: "daily", date: next, page: String(nextPage) })
      }
      t={t}
    />
  );
}
function DailyDocumentsView({
  date,
  today,
  page,
  onSelect,
  t,
}: {
  date: string;
  today: string;
  page: number;
  onSelect: (date: string, page: number) => void;
  t: ReturnType<typeof useTranslation>["t"];
}) {
  const [revision, setRevision] = useState(0);
  const [busy, setBusy] = useState(false);
  const [batchResult, setBatchResult] = useState<DailyDocumentBatch>();
  const [actionError, setActionError] = useState<string>();
  const { data, error } = useDailyDocuments(date, page, revision);
  async function act(request: () => Promise<unknown>) {
    setBusy(true);
    setActionError(undefined);
    try {
      await request();
    } catch (failure) {
      setActionError(failure instanceof Error ? failure.message : t("failed"));
    } finally {
      setRevision((value) => value + 1);
      setBusy(false);
    }
  }
  return (
    <>
      <PageMeta title={t("title")} description={t("description")} />
      <PageBreadCrumb pageTitle={t("title")} />
      <div className="space-y-5">
        <ComponentCard title={t("title")} desc={t("description")}>
          <label
            htmlFor="daily-doc-date"
            className="mb-2 block text-sm text-gray-700 dark:text-gray-300"
          >
            {t("date")}
          </label>
          <KpsDatePicker
            id="daily-doc-date"
            value={date}
            onChange={(next) => onSelect(next, 1)}
            ariaLabel={t("date")}
            placeholder={t("date")}
            maxDate={today}
            disabled={busy}
          />
          <p className="mt-3 text-sm text-gray-600 dark:text-gray-400">
            {t("coverage")}
          </p>
          <div className="flex flex-wrap items-center gap-3">
            {data?.enabled && (
              <Button
                onClick={() =>
                  void act(async () => {
                    setBatchResult(undefined);
                    setBatchResult(await requestDailyDocumentBatch(date));
                  })
                }
                disabled={busy || data.total === 0}
              >
                {t(
                  busy
                    ? "batchScheduling"
                    : data.company_scope
                      ? "batchCompany"
                      : "batchScope",
                )}
              </Button>
            )}
            <Button
              variant="outline"
              onClick={() => setRevision((value) => value + 1)}
              disabled={busy}
            >
              {t("reload")}
            </Button>
          </div>
          {data?.enabled && (
            <p className="mt-3 text-sm text-gray-600 dark:text-gray-400">
              {t("batchHint", { count: data.total })}
            </p>
          )}
          {data?.summary && (
            <div role="status" className="mt-2 flex flex-wrap gap-2">
              <Badge color="success">
                {t("summaryCompleted", data.summary)}
              </Badge>
              <Badge color="info">{t("summaryProcessing", data.summary)}</Badge>
              <Badge color="error">{t("summaryFailed", data.summary)}</Badge>
              <Badge color="light">
                {t("summaryNotStarted", data.summary)}
              </Badge>
            </div>
          )}
        </ComponentCard>
        {batchResult && (
          <p role="status" className="text-sm text-gray-700 dark:text-gray-300">
            {t("batchAccepted", { ...batchResult })}
            {batchResult.failed > 0 && <> {t("batchFailuresKept")}</>}
          </p>
        )}
        {(error || actionError) && (
          <p
            role="alert"
            className="rounded-lg bg-error-50 p-4 text-error-600 dark:bg-error-950 dark:text-error-400"
          >
            {error || actionError}
          </p>
        )}
        {!data && !error && <p role="status">{t("loading")}</p>}
        {data && !data.enabled && <p role="status">{t("disabled")}</p>}
        {data?.enabled && (
          <>
            <p className="text-sm text-gray-600 dark:text-gray-400">
              {t("coverageCount", { count: data.total })}
            </p>
            {data.reports.length === 0 && <p>{t("empty")}</p>}
            {data.reports.map((row) => (
              <DailyDocumentEmployee
                key={row.report_id}
                row={row}
                busy={busy}
                onRetry={() =>
                  row.document &&
                  void act(() => retryDailyDocument(row.document!.id))
                }
              />
            ))}
            {data.last_page > 1 && (
              <nav
                aria-label={t("pagination")}
                className="flex items-center gap-4"
              >
                <Button
                  variant="outline"
                  onClick={() => onSelect(date, page - 1)}
                  disabled={page <= 1 || busy}
                >
                  {t("previous")}
                </Button>
                <span>{t("page", { page, total: data.last_page })}</span>
                <Button
                  variant="outline"
                  onClick={() => onSelect(date, page + 1)}
                  disabled={page >= data.last_page || busy}
                >
                  {t("next")}
                </Button>
              </nav>
            )}
          </>
        )}
      </div>
    </>
  );
}
