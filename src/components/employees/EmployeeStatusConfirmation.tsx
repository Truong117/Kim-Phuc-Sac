import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import type { ManagedUser } from "@/types/userManagement";
import { useTranslation } from "react-i18next";

interface EmployeeStatusConfirmationProps {
  user: ManagedUser;
  isOpen: boolean;
  isSubmitting: boolean;
  onClose: () => void;
  onConfirm: () => void;
}

export default function EmployeeStatusConfirmation({
  user,
  isOpen,
  isSubmitting,
  onClose,
  onConfirm,
}: EmployeeStatusConfirmationProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "userManagement.statusConfirmation",
  });
  const nextIsActive = !user.is_active;

  return (
    <Modal
      isOpen={isOpen}
      onClose={isSubmitting ? () => undefined : onClose}
      showCloseButton={false}
      ariaLabelledBy="employee-status-confirmation-title"
      className="mx-4 max-w-lg p-6 sm:p-8"
    >
      <h2
        id="employee-status-confirmation-title"
        className="text-xl font-semibold text-gray-900 dark:text-white"
      >
        {t(nextIsActive ? "activateTitle" : "deactivateTitle")}
      </h2>
      <p className="mt-3 text-theme-sm text-gray-600 dark:text-gray-300">
        {t(nextIsActive ? "activateDescription" : "deactivateDescription", {
          name: user.name,
        })}
      </p>

      <div className="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <Button
          type="button"
          variant="outline"
          className="w-full sm:w-auto"
          disabled={isSubmitting}
          onClick={onClose}
        >
          {t("cancel")}
        </Button>
        <Button
          type="button"
          className={
            nextIsActive
              ? "w-full !bg-kps-primary hover:!bg-kps-primary-hover sm:w-auto"
              : "w-full !bg-error-600 hover:!bg-error-700 disabled:!bg-error-600 sm:w-auto"
          }
          disabled={isSubmitting}
          onClick={onConfirm}
        >
          {isSubmitting
            ? t("submitting")
            : t(nextIsActive ? "activate" : "deactivate")}
        </Button>
      </div>
    </Modal>
  );
}
