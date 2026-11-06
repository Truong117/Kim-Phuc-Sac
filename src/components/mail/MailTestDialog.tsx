import { useTranslation } from "react-i18next";
import { Modal } from "@/components/ui/modal";
import Button from "@/components/ui/button/Button";
import type { MailRecipient } from "@/services/mailService";
interface Props {
  isOpen: boolean;
  onClose: () => void;
  recipients: MailRecipient[];
  fromName: string;
  busy: boolean;
  error: string;
  onSend: () => void;
}
export default function MailTestDialog({
  isOpen,
  onClose,
  recipients,
  fromName,
  busy,
  error,
  onSend,
}: Props) {
  const { t } = useTranslation();
  return (
    <Modal
      isOpen={isOpen}
      onClose={() => {
        if (!busy) onClose();
      }}
      ariaLabelledBy="mail-test-heading"
      className="max-w-xl p-6"
    >
      <div className="min-w-0">
        <h2
          id="mail-test-heading"
          className="text-lg font-semibold text-gray-900 dark:text-white"
        >
          {t("mailUi.confirmTitle")}
        </h2>
        <p className="mt-3 text-sm break-all text-gray-700 dark:text-gray-200">
          {t("mailUi.fromName", { name: fromName })}
        </p>
        <p className="mt-3 text-sm text-gray-700 dark:text-gray-200">
          {t("mailUi.confirmCount", { count: recipients.length })}
        </p>
        <ul className="mt-3 max-h-40 space-y-2 overflow-y-auto text-sm text-gray-700 dark:text-gray-200">
          {recipients.map((recipient) => (
            <li key={recipient.id} className="break-all">
              {recipient.name} — {recipient.email}
            </li>
          ))}
        </ul>
        <p className="mt-3 text-sm text-gray-700 dark:text-gray-200">
          {t("mailUi.testSubject")}
        </p>
        <p className="mt-3 text-sm whitespace-pre-wrap text-gray-600 dark:text-gray-300">
          {t("mailUi.testBody", { name: recipients[0]?.name, fromName })}
        </p>
        <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
          {t("mailUi.personalizedHint")}
        </p>
        {error && (
          <p
            role="alert"
            className="mt-3 text-sm text-error-600 dark:text-error-400"
          >
            {t(error)}
          </p>
        )}
        <div className="mt-6 flex flex-wrap gap-3">
          <Button
            disabled={busy}
            onClick={onSend}
            className="!bg-kps-primary !text-white hover:!bg-kps-primary-hover"
          >
            {t(busy ? "mailUi.sending" : "mailUi.confirmSend")}
          </Button>
          <Button variant="outline" disabled={busy} onClick={onClose}>
            {t("mailUi.cancel")}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
