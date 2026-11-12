import type { PropsWithChildren } from "react";
import { useId } from "react";
import { useTranslation } from "react-i18next";
import { Modal } from "@/components/ui/modal";
import { CloseIcon } from "@/icons";

export default function SpaDialog({
  title,
  onClose,
  children,
}: PropsWithChildren<{ title: string; onClose: () => void }>) {
  const id = useId();
  const { t } = useTranslation();
  return (
    <Modal
      isOpen
      onClose={onClose}
      aboveNavigation
      showCloseButton={false}
      ariaLabelledBy={id}
      className="mx-3 max-h-[90dvh] max-w-2xl overflow-y-auto p-5 sm:p-6"
    >
      <div className="mb-5 flex items-start justify-between gap-3">
        <div>
          <h2
            id={id}
            className="text-lg font-semibold text-gray-900 dark:text-white"
          >
            {title}
          </h2>
          <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
            {t("spa.flow.formHint")}
          </p>
        </div>
        <button
          type="button"
          aria-label={t("spa.flow.close")}
          onClick={onClose}
          className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
        >
          <CloseIcon className="size-5" />
        </button>
      </div>
      {children}
    </Modal>
  );
}
