import { useId } from "react";
import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import DailyDocumentResult from "@/components/ai/DailyDocumentResult";
import Badge from "@/components/ui/badge/Badge";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import { useModal } from "@/hooks/useModal";
import { formatDateOnly } from "@/utils/dateOnly";
import type { DailyDocumentsPage } from "@/types/dailyDocument";

type Row = DailyDocumentsPage["reports"][number];
const statusColors = {
  pending: "info",
  running: "info",
  completed: "success",
  partial: "warning",
  failed: "error",
} as const;

export default function DailyDocumentEmployee({
  row,
  busy,
  onRetry,
  onRebuild,
}: {
  row: Row;
  busy: boolean;
  onRetry: () => void;
  onRebuild?: () => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "dailyDocuments" });
  const { isOpen, openModal, closeModal } = useModal();
  const modalTitle = useId();
  const document = row.document;
  const canRebuild = Boolean(
    onRebuild &&
    document?.requires_new_document &&
    ["failed", "partial"].includes(document.status),
  );
  // Counts must refer to the same frozen source as the AI result.
  const source = document?.source ?? row.source;
  const result = document?.result;
  const references = [
    ...new Map(
      [
        ...(result?.work_groups?.flatMap((group) => group.evidence) ?? []),
        ...[
          ...(result?.comments ?? []),
          ...(result?.recommendations ?? []),
        ].map((entry) => ({
          report_id: source.report_id,
          item_id: entry.item_id,
          quote: entry.quote,
        })),
      ].map((ref) => [
        JSON.stringify([ref.report_id, ref.item_id, ref.quote]),
        ref,
      ]),
    ).values(),
  ];
  const errors = document?.units.filter((unit) => unit.error_code) ?? [];
  const canRetry = document?.units.some((unit) => unit.can_retry) ?? false;
  const retryUnavailable =
    errors.length > 0 && !canRetry && !document?.requires_new_document;
  return (
    <article className="rounded-2xl border border-gray-200 bg-white text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300">
      <details className="group">
        <summary className="flex cursor-pointer list-none flex-wrap items-center gap-3 rounded-2xl px-5 py-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 [&::-webkit-details-marker]:hidden">
          <span className="min-w-0 flex-1 font-semibold break-words text-gray-800 dark:text-white/90">
            {row.employee_name}
          </span>
          <Badge
            color={document ? statusColors[document.status] : "light"}
            size="sm"
          >
            {document ? t(`status.${document.status}`) : t("notStarted")}
          </Badge>
          {document && (
            <span className="text-xs text-gray-500 dark:text-gray-400">
              {t("progress", {
                count: document.completed_units,
                total: document.total_units,
              })}
            </span>
          )}
          <span className="text-xs font-medium text-brand-600 dark:text-brand-300">
            <span className="group-open:hidden">{t("expand")}</span>
            <span className="hidden group-open:inline">{t("collapse")}</span>
          </span>
        </summary>
        <div className="space-y-5 border-t border-gray-100 p-5 dark:border-gray-800">
          {document && (
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t(document.mode === "fake" ? "fake" : "model", {
                model: document.model,
              })}
            </p>
          )}
          {!result ? (
            <p>{t("noAiResult")}</p>
          ) : (
            <DailyDocumentResult result={result} />
          )}
          {errors.length > 0 && (
            <details className="text-xs text-error-600 dark:text-error-400">
              <summary className="cursor-pointer">{t("errorDetails")}</summary>
              {errors.map((unit) => (
                <p key={unit.id} className="mt-2 break-words">
                  {t("unitError", {
                    part: unit.part + 1,
                    code: unit.error_code,
                  })}
                </p>
              ))}
            </details>
          )}
        </div>
      </details>
      <div className="space-y-3 border-t border-gray-100 px-5 py-3 dark:border-gray-800">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 text-xs">
          <Link
            to={`/reports/${source.report_id}`}
            className="text-brand-600 underline dark:text-brand-300"
          >
            {t("source", {
              id: source.report_id,
              date: formatDateOnly(source.date),
            })}
          </Link>
          {source.is_makeup && (
            <Badge size="sm" color="warning">
              {t("makeup")}
            </Badge>
          )}
          {(["COMPLETED", "IN_PROGRESS", "BLOCKED"] as const).map((status) => (
            <span key={status}>
              {t(`groups.${status}`)}:{" "}
              {source.items.filter((item) => item.status === status).length}
            </span>
          ))}
        </div>
        <p className="text-xs text-gray-500 dark:text-gray-400">
          {t("selfReported")}
        </p>
        {references.length > 0 && (
          <details className="text-xs">
            <summary className="cursor-pointer text-brand-600 dark:text-brand-300">
              {t("referenceSources")}
            </summary>
            <ul className="mt-2 space-y-2">
              {references.map((ref) => (
                <li
                  key={JSON.stringify([ref.report_id, ref.item_id, ref.quote])}
                  className="break-words"
                >
                  <Link
                    to={`/reports/${ref.report_id}#work-item-${ref.item_id}`}
                    className="text-brand-600 underline dark:text-brand-300"
                  >
                    {t("quote", { id: ref.item_id, quote: ref.quote })}
                  </Link>
                </li>
              ))}
            </ul>
          </details>
        )}
        {row.source_changed && (
          <p role="status" className="text-warning-700 dark:text-warning-400">
            {t("sourceChanged")}
          </p>
        )}
        {document?.requires_new_document && document.status !== "completed" && (
          <p role="status" className="text-warning-700 dark:text-warning-400">
            {t("contractChanged")}
          </p>
        )}
        {errors.length > 0 && (
          <div
            role="status"
            className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-error-50 p-3 text-error-700 dark:bg-error-500/10 dark:text-error-400"
          >
            <p className="min-w-0 flex-1">
              {t(retryUnavailable ? "retryExhausted" : "partialError")}
            </p>
            {canRetry && (
              <Button variant="outline" onClick={onRetry} disabled={busy}>
                {t("retry")}
              </Button>
            )}
          </div>
        )}
        {canRebuild && (
          <Button variant="outline" onClick={openModal} disabled={busy}>
            {t("rebuild")}
          </Button>
        )}
      </div>
      <Modal
        isOpen={isOpen}
        onClose={closeModal}
        className="max-w-lg p-6"
        ariaLabelledBy={modalTitle}
        showCloseButton={false}
      >
        <h3
          id={modalTitle}
          className="font-semibold text-gray-900 dark:text-gray-100"
        >
          {t("rebuild")}
        </h3>
        <p className="mt-3 text-sm text-gray-700 dark:text-gray-300">
          {t("rebuildConfirm")}
        </p>
        <div className="mt-5 flex flex-wrap gap-3">
          <Button variant="outline" onClick={closeModal} disabled={busy}>
            {t("cancel")}
          </Button>
          <Button
            disabled={busy || !canRebuild}
            onClick={() => {
              closeModal();
              onRebuild?.();
            }}
          >
            {t("rebuildConfirmAction")}
          </Button>
        </div>
      </Modal>
    </article>
  );
}
