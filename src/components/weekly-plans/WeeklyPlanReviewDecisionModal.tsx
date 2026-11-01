import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import type { WeeklyPlanReviewDecision } from "@/types/weeklyPlanning";
import { useTranslation } from "react-i18next";

export type WeeklyPlanDecisionConfirmation = {
  decision: Exclude<WeeklyPlanReviewDecision["status"], "PENDING">;
  assigneeId: number;
  employeeName: string;
};

type Props = {
  isOpen: boolean;
  confirmation: WeeklyPlanDecisionConfirmation | null;
  note: string;
  busy: boolean;
  error: string | null;
  onNoteChange: (note: string) => void;
  onClose: () => void;
  onConfirm: () => void;
};

export default function WeeklyPlanReviewDecisionModal({
  isOpen,
  confirmation,
  note,
  busy,
  error,
  onNoteChange,
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
      ariaLabelledBy="weekly-review-decision-title"
    >
      {confirmation && (
        <div>
          <h2
            id="weekly-review-decision-title"
            className="pe-12 text-title-sm font-semibold text-gray-900 dark:text-white"
          >
            {confirmation.decision === "CHANGES_REQUESTED"
              ? t("decisionModal.changeTitle", {
                  employee: confirmation.employeeName,
                })
              : t("decisionModal.acceptTitle", {
                  employee: confirmation.employeeName,
                })}
          </h2>
          <p className="mt-3 text-theme-sm text-gray-600 dark:text-gray-300">
            {confirmation.decision === "CHANGES_REQUESTED"
              ? t("decisionModal.changeDescription")
              : t("decisionModal.acceptDescription")}
          </p>
          {confirmation.decision === "CHANGES_REQUESTED" && (
            <div className="mt-5">
              <Label htmlFor="weekly-review-change-note">
                {t("decisionModal.noteLabel")}
              </Label>
              <TextArea
                id="weekly-review-change-note"
                rows={4}
                value={note}
                onChange={onNoteChange}
                disabled={busy}
              />
              <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                {t("decisionModal.noteHelp")}
              </p>
            </div>
          )}
          {error && (
            <p
              role="alert"
              className="mt-4 rounded-lg bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
            >
              {error}
            </p>
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
              {confirmation.decision === "CHANGES_REQUESTED"
                ? t("decisionModal.confirmChange")
                : t("decisionModal.confirmAccept")}
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
