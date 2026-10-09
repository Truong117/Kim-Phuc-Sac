import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import { CheckCircleIcon } from "@/icons";
import { useTranslation } from "react-i18next";

export type ReportSaveState =
  | { status: "idle" }
  | { status: "saving" }
  | { status: "success"; reportDate: string };

interface ReportSaveModalProps {
  state: ReportSaveState;
  onGoHome: () => void;
  onReview: () => void;
}

const formatDate = (date: string) =>
  new Intl.DateTimeFormat("vi-VN").format(new Date(`${date}T00:00:00`));

const preventClose = () => undefined;

export default function ReportSaveModal({
  state,
  onGoHome,
  onReview,
}: ReportSaveModalProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "dailyReport.saveModal",
  });

  return (
    <Modal
      isOpen={state.status !== "idle"}
      onClose={preventClose}
      showCloseButton={false}
      ariaLabelledBy="report-save-modal-title"
      className="mx-4 max-w-md p-6 sm:p-8"
    >
      {state.status === "saving" ? (
        <div
          role="status"
          aria-live="polite"
          aria-busy="true"
          className="py-4 text-center"
        >
          <span
            aria-hidden="true"
            className="mx-auto block size-12 animate-spin rounded-full border-4 border-gray-200 border-t-kps-primary dark:border-gray-700 dark:border-t-sidebar-selected"
          />
          <h2
            id="report-save-modal-title"
            className="mt-5 text-lg font-semibold text-gray-900 dark:text-white"
          >
            {t("saving")}
          </h2>
        </div>
      ) : state.status === "success" ? (
        <div className="text-center">
          <div role="status" aria-live="polite">
            <span className="mx-auto flex size-14 items-center justify-center rounded-full bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400">
              <CheckCircleIcon className="size-8" aria-hidden="true" />
            </span>
            <h2
              id="report-save-modal-title"
              className="mt-5 text-xl font-semibold text-gray-900 dark:text-white"
            >
              {t("successTitle")}
            </h2>
            <p className="mt-2 text-theme-sm text-gray-600 dark:text-gray-300">
              {t("successDescription", {
                date: formatDate(state.reportDate),
              })}
            </p>
          </div>

          <div className="mt-7 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Button
              type="button"
              variant="outline"
              className="w-full focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none"
              onClick={onGoHome}
            >
              {t("goHome")}
            </Button>
            <Button
              type="button"
              className="w-full !bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none"
              onClick={onReview}
            >
              {t("review")}
            </Button>
          </div>
        </div>
      ) : null}
    </Modal>
  );
}
