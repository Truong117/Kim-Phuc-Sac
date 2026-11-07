import { useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import { useModal } from "@/hooks/useModal";
import { resumableMailRun, type MailRun } from "@/utils/mailComposeState";
import MailHistory from "./MailHistory";

export default function MailSendRuns({
  runs,
  busy,
  reading,
  disabled,
  onRefresh,
  onResume,
}: {
  runs: MailRun[];
  busy: boolean;
  reading: boolean;
  disabled: boolean;
  onRefresh: () => Promise<void>;
  onResume: (id: string) => Promise<void>;
}) {
  const { t } = useTranslation();
  const modal = useModal();
  const [id, setId] = useState("");
  const [confirm, setConfirm] = useState(false);
  const run = runs.find((entry) => entry.id === id);
  return (
    <>
      {runs.map((entry) => (
        <div
          key={entry.id}
          className="flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-200"
        >
          <p className="min-w-0 flex-1 break-words">
            {t("mailFlow.pending", { subject: entry.subject })}
          </p>
          <Button
            variant="outline"
            disabled={busy}
            onClick={() => {
              setId(entry.id);
              setConfirm(false);
              modal.openModal();
            }}
          >
            {t("mailFlow.viewRun")}
          </Button>
        </div>
      ))}
      <Modal
        isOpen={modal.isOpen}
        onClose={() => {
          if (!busy) modal.closeModal();
        }}
        aboveNavigation
        ariaLabelledBy="mail-run-heading"
        className="max-w-3xl p-4 sm:p-6"
      >
        <div className="space-y-4">
          <h2
            id="mail-run-heading"
            className="pe-10 text-lg font-semibold text-gray-900 dark:text-white"
          >
            {t("mailFlow.runTitle")}
          </h2>
          <div className="max-h-[60vh] space-y-4 overflow-y-auto text-sm text-gray-700 dark:text-gray-200">
            <p>{t("mailFlow.readOnlyHint")}</p>
            {run ? (
              <>
                <p className="font-semibold break-words">{run.subject}</p>
                <details>
                  <summary className="cursor-pointer">
                    {t("mailFlow.frozenContent")}
                  </summary>
                  <p className="mt-3 break-words whitespace-pre-wrap">
                    {run.body}
                  </p>
                </details>
                {run.readError && <p role="alert">{t("mailFlow.readError")}</p>}
                {Boolean(run.missing?.length) && (
                  <p>{t("mailFlow.missing", { count: run.missing!.length })}</p>
                )}
                {run.results?.some((entry) => entry.status === "unknown") && (
                  <p role="alert">{t("mailFlow.unknown")}</p>
                )}
                {Boolean(run.results?.length) && (
                  <MailHistory
                    entries={run.results!}
                    title={t("mailUi.batchResults")}
                  />
                )}
              </>
            ) : (
              <p>{t("mailFlow.completed")}</p>
            )}
          </div>
          {confirm && run && (
            <div className="space-y-3 rounded-lg border border-warning-200 p-3 dark:border-warning-800">
              <p className="text-sm text-gray-700 dark:text-gray-200">
                {t("mailFlow.confirmResume")}
              </p>
              <Button
                disabled={busy || reading || disabled || !resumableMailRun(run)}
                onClick={() => {
                  setConfirm(false);
                  void onResume(run.id);
                }}
              >
                {t("mailFlow.resumeConfirmed")}
              </Button>
            </div>
          )}
          <div className="flex flex-wrap gap-3">
            {run && (
              <Button
                variant="outline"
                disabled={busy || reading}
                onClick={() => {
                  setConfirm(false);
                  void onRefresh();
                }}
              >
                {t(reading ? "mailFlow.reading" : "mailFlow.refresh")}
              </Button>
            )}
            {run && resumableMailRun(run) && !confirm && (
              <Button
                disabled={busy || reading || disabled}
                onClick={() => setConfirm(true)}
              >
                {t("mailFlow.resume")}
              </Button>
            )}
            <Button
              variant="outline"
              disabled={busy}
              onClick={modal.closeModal}
            >
              {t("mailFlow.close")}
            </Button>
          </div>
        </div>
      </Modal>
    </>
  );
}
