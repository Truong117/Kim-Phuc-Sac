import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import type { DraftDialogState } from "@/hooks/useWorkReportDraft";
import { useState } from "react";
import { useTranslation } from "react-i18next";

interface ReportDraftDialogProps {
  state: DraftDialogState;
  lockedDraftText: string;
  onRestore: () => void;
  onUseServer: () => void;
  onUseExternal: () => void;
  onKeepCurrent: () => void;
  onDiscard: () => void;
  onCancelDiscard: () => void;
  onDiscardLocked: () => void;
  onCloseLocked: () => void;
}

const preventClose = () => undefined;

const formatUpdatedAt = (value: string) =>
  new Intl.DateTimeFormat("vi-VN", {
    dateStyle: "short",
    timeStyle: "short",
    timeZone: "Asia/Ho_Chi_Minh",
  }).format(new Date(value));

export default function ReportDraftDialog({
  state,
  lockedDraftText,
  onRestore,
  onUseServer,
  onUseExternal,
  onKeepCurrent,
  onDiscard,
  onCancelDiscard,
  onDiscardLocked,
  onCloseLocked,
}: ReportDraftDialogProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "dailyReport.draft.dialog",
  });
  const [copyResult, setCopyResult] = useState<{
    revision: string | null;
    status: "copied" | "error";
  }>({ revision: null, status: "copied" });
  const lockedRevision = state?.kind === "locked" ? state.draft.revision : null;
  const copyState =
    copyResult.revision === lockedRevision ? copyResult.status : "idle";

  const copyLockedDraft = async () => {
    try {
      await navigator.clipboard.writeText(lockedDraftText);
      setCopyResult({ revision: lockedRevision, status: "copied" });
    } catch {
      setCopyResult({ revision: lockedRevision, status: "error" });
    }
  };

  const isLocked = state?.kind === "locked";

  return (
    <Modal
      isOpen={state !== null}
      onClose={
        isLocked
          ? onCloseLocked
          : state?.kind === "discard"
            ? onCancelDiscard
            : preventClose
      }
      showCloseButton={isLocked}
      ariaLabelledBy="report-draft-dialog-title"
      className="mx-4 max-w-xl p-6 sm:p-8"
    >
      {state?.kind === "restore" && (
        <div>
          <h2
            id="report-draft-dialog-title"
            className="text-xl font-semibold text-gray-900 dark:text-white"
          >
            {t(state.serverConflict ? "restore.conflictTitle" : "restore.title")}
          </h2>
          <p className="mt-2 text-theme-sm text-gray-600 dark:text-gray-300">
            {t(
              state.serverConflict
                ? "restore.conflictDescription"
                : "restore.description",
              { time: formatUpdatedAt(state.draft.updatedAt) },
            )}
          </p>
          <p className="mt-3 rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">
            {t("localNotice")}
          </p>
          <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Button type="button" variant="outline" onClick={onUseServer}>
              {t("restore.useServer")}
            </Button>
            <Button
              type="button"
              className="!bg-kps-primary hover:!bg-kps-primary-hover"
              onClick={onRestore}
            >
              {t("restore.useDraft")}
            </Button>
          </div>
        </div>
      )}

      {state?.kind === "locked" && (
        <div>
          <h2
            id="report-draft-dialog-title"
            className="pe-12 text-xl font-semibold text-gray-900 dark:text-white"
          >
            {t("locked.title")}
          </h2>
          <p className="mt-2 text-theme-sm text-gray-600 dark:text-gray-300">
            {t("locked.description", {
              time: formatUpdatedAt(state.draft.updatedAt),
            })}
          </p>
          <label htmlFor="locked-draft-content" className="sr-only">
            {t("locked.contentLabel")}
          </label>
          <textarea
            id="locked-draft-content"
            readOnly
            value={lockedDraftText}
            rows={9}
            onFocus={(event) => event.currentTarget.select()}
            className="custom-scrollbar mt-4 w-full resize-y rounded-lg border border-gray-300 bg-gray-50 px-4 py-3 text-theme-sm whitespace-pre-wrap text-gray-800 shadow-theme-xs focus:border-kps-primary focus:ring-3 focus:ring-kps-primary/20 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
          />
          <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <Button type="button" variant="outline" onClick={onDiscardLocked}>
              {t("locked.discard")}
            </Button>
            <Button type="button" variant="outline" onClick={onCloseLocked}>
              {t("locked.close")}
            </Button>
            <Button
              type="button"
              className="!bg-kps-primary hover:!bg-kps-primary-hover"
              onClick={() => void copyLockedDraft()}
            >
              {t(
                copyState === "copied"
                  ? "locked.copied"
                  : copyState === "error"
                    ? "locked.copyFailed"
                    : "locked.copy",
              )}
            </Button>
          </div>
        </div>
      )}

      {state?.kind === "external" && (
        <div>
          <h2
            id="report-draft-dialog-title"
            className="text-xl font-semibold text-gray-900 dark:text-white"
          >
            {t("external.title")}
          </h2>
          <p className="mt-2 text-theme-sm text-gray-600 dark:text-gray-300">
            {t(
              state.incoming
                ? "external.description"
                : "external.deletedDescription",
              state.incoming
                ? { time: formatUpdatedAt(state.incoming.updatedAt) }
                : undefined,
            )}
          </p>
          <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Button type="button" variant="outline" onClick={onUseExternal}>
              {t(
                state.incoming
                  ? "external.useExternal"
                  : "external.acceptDeletion",
              )}
            </Button>
            <Button
              type="button"
              className="!bg-kps-primary hover:!bg-kps-primary-hover"
              onClick={onKeepCurrent}
            >
              {t("external.keepCurrent")}
            </Button>
          </div>
        </div>
      )}

      {state?.kind === "discard" && (
        <div>
          <h2
            id="report-draft-dialog-title"
            className="text-xl font-semibold text-gray-900 dark:text-white"
          >
            {t("discard.title")}
          </h2>
          <p className="mt-2 text-theme-sm text-gray-600 dark:text-gray-300">
            {t("discard.description")}
          </p>
          <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Button type="button" variant="outline" onClick={onCancelDiscard}>
              {t("discard.cancel")}
            </Button>
            <Button
              type="button"
              className="!bg-error-600 hover:!bg-error-700"
              onClick={onDiscard}
            >
              {t("discard.confirm")}
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
