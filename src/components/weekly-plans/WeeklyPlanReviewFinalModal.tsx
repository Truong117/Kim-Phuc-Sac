import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import type { WeeklyPlanReviewSummary } from "@/types/weeklyPlanning";
import { useTranslation } from "react-i18next";

export type WeeklyPlanReviewFinalAction =
  "FINALIZE_ASSISTANT" | "APPROVE" | "PUBLISH";

type Props = {
  isOpen: boolean;
  action: WeeklyPlanReviewFinalAction | null;
  summary: WeeklyPlanReviewSummary;
  workCount: number;
  busy: boolean;
  onClose: () => void;
  onConfirm: () => void;
};

export default function WeeklyPlanReviewFinalModal({
  isOpen,
  action,
  summary,
  workCount,
  busy,
  onClose,
  onConfirm,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.reviewWorkspace",
  });

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      className="m-4 max-w-xl p-6 sm:p-8"
      ariaLabelledBy="weekly-review-final-title"
    >
      {action && (
        <div>
          <h2
            id="weekly-review-final-title"
            className="pe-12 text-title-sm font-semibold text-gray-900 dark:text-white"
          >
            {t(`finalModal.${action}.title`)}
          </h2>
          <p className="mt-3 text-theme-sm text-gray-600 dark:text-gray-300">
            {t(`finalModal.${action}.description`)}
          </p>
          {action === "FINALIZE_ASSISTANT" && (
            <dl className="mt-4 grid gap-3 rounded-xl bg-gray-50 p-4 text-theme-sm sm:grid-cols-2 dark:bg-gray-800/60">
              <div>
                <dt className="text-gray-500 dark:text-gray-400">
                  {t("summary.accepted")}
                </dt>
                <dd className="mt-1 font-semibold text-gray-900 dark:text-white">
                  {summary.accepted}
                </dd>
              </div>
              <div>
                <dt className="text-gray-500 dark:text-gray-400">
                  {t("finalModal.workCount")}
                </dt>
                <dd className="mt-1 font-semibold text-gray-900 dark:text-white">
                  {workCount}
                </dd>
              </div>
              <div>
                <dt className="text-gray-500 dark:text-gray-400">
                  {t("summary.pending")}
                </dt>
                <dd className="mt-1 font-semibold text-gray-900 dark:text-white">
                  {summary.pending}
                </dd>
              </div>
              <div>
                <dt className="text-gray-500 dark:text-gray-400">
                  {t("summary.changesRequested")}
                </dt>
                <dd className="mt-1 font-semibold text-gray-900 dark:text-white">
                  {summary.changes_requested}
                </dd>
              </div>
            </dl>
          )}
          <div className="mt-6 flex flex-wrap justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              disabled={busy}
              onClick={onClose}
            >
              {t("decisionModal.cancel")}
            </Button>
            <Button
              type="button"
              disabled={busy}
              className="bg-kps-primary hover:bg-kps-primary-hover"
              onClick={onConfirm}
            >
              {t(`finalModal.${action}.confirm`)}
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
