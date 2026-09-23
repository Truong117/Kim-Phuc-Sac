import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import { CheckCircleIcon } from "@/icons";
import type { ProcessingStage } from "@/types/reports";
import { useTranslation } from "react-i18next";

interface ReportProcessingModalProps {
  isOpen: boolean;
  stage: ProcessingStage;
  onContinue: () => void;
}

const preventClose = () => undefined;

export default function ReportProcessingModal({
  isOpen,
  stage,
  onContinue,
}: ReportProcessingModalProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "dailyReport.processing",
  });
  const isCompleted = stage === "completed";

  return (
    <Modal
      isOpen={isOpen}
      onClose={preventClose}
      showCloseButton={false}
      className="mx-4 max-w-md p-6 sm:p-8"
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="report-processing-title"
        aria-describedby="report-processing-description"
        className="text-center"
      >
        <div className="mb-5 flex justify-center" aria-hidden="true">
          {isCompleted ? (
            <span className="flex size-16 items-center justify-center rounded-full bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400">
              <CheckCircleIcon className="size-9" />
            </span>
          ) : (
            <span className="flex size-16 items-center justify-center rounded-full bg-brand-50 dark:bg-brand-500/15">
              <span className="size-9 animate-spin rounded-full border-4 border-brand-100 border-t-brand-500 dark:border-brand-500/25 dark:border-t-brand-400" />
            </span>
          )}
        </div>

        <div role="status" aria-live="polite" aria-atomic="true">
          <h2
            id="report-processing-title"
            className="text-title-sm font-semibold text-gray-900 dark:text-white"
          >
            {t(`${stage}.title`)}
          </h2>
          <p
            id="report-processing-description"
            className="mt-3 text-theme-sm text-gray-500 dark:text-gray-400"
          >
            {t(`${stage}.description`)}
          </p>
        </div>

        {isCompleted && (
          <Button type="button" className="mt-7 w-full" onClick={onContinue}>
            {t("continue")}
          </Button>
        )}
      </div>
    </Modal>
  );
}
