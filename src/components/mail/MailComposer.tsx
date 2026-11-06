import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import { useMailBatch } from "@/hooks/useMailTestBatch";
import type { MailRecipient } from "@/services/mailService";
import MailHistory from "./MailHistory";
import MailRecipientPicker from "./MailRecipientPicker";

interface Props {
  storageKey: string;
  recipients: MailRecipient[];
  fromName: string;
  fromEmail: string;
  disabled: boolean;
  onBusyChange: (busy: boolean) => void;
  onComplete: () => Promise<void>;
}

export default function MailComposer({
  storageKey,
  recipients,
  fromName,
  fromEmail,
  disabled,
  onBusyChange,
  onComplete,
}: Props) {
  const { t } = useTranslation();
  const batch = useMailBatch(storageKey, "internal");
  const [confirming, setConfirming] = useState(false);
  const [invalid, setInvalid] = useState<string[]>([]);
  const root = useRef<HTMLDivElement>(null);
  const mounted = useRef(true);
  const selection = recipients.filter((recipient) =>
    batch.selectedIds.includes(recipient.id),
  );
  const locked = disabled || batch.busy || batch.started;
  useEffect(() => {
    onBusyChange(batch.busy);
    return () => onBusyChange(false);
  }, [batch.busy, onBusyChange]);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);
  useEffect(() => {
    if (batch.validationFields.length === 0) return;
    const frame = requestAnimationFrame(() => {
      const element = root.current?.querySelector<HTMLElement>(
        '[aria-invalid="true"]',
      );
      element?.scrollIntoView({ behavior: "smooth", block: "center" });
      if (element?.tagName === "FIELDSET")
        element
          .querySelector<HTMLInputElement>("input:not(:disabled)")
          ?.focus();
      else element?.focus();
    });
    return () => cancelAnimationFrame(frame);
  }, [batch.validationFields]);
  const isInvalid = (field: string) =>
    invalid.includes(field) ||
    batch.validationFields.some(
      (key) =>
        key === field ||
        key.startsWith(`${field}.`) ||
        (field === "recipients" && key === "recipient_user_id"),
    );
  function preview() {
    const errors = [];
    if (
      !selection.length ||
      selection.length !== batch.selectedIds.length ||
      selection.some((recipient) => !recipient.send_allowed)
    )
      errors.push("recipients");
    if (
      !batch.subject.trim() ||
      Array.from(batch.subject.trim()).length > 200 ||
      /[\r\n\p{Cc}]/u.test(batch.subject)
    )
      errors.push("subject");
    if (
      !batch.body.trim() ||
      Array.from(batch.body.trim()).length > 10000 ||
      /[\p{Cc}]/u.test(batch.body.replace(/[\t\r\n]/g, ""))
    )
      errors.push("body");
    setInvalid(errors);
    if (errors.length) {
      requestAnimationFrame(() => {
        const element = root.current?.querySelector<HTMLElement>(
          '[aria-invalid="true"]',
        );
        element?.scrollIntoView({ behavior: "smooth", block: "center" });
        if (element?.tagName === "FIELDSET")
          element
            .querySelector<HTMLInputElement>("input:not(:disabled)")
            ?.focus();
        else element?.focus();
      });
    } else setConfirming(true);
  }
  async function send() {
    if (disabled) return;
    await batch.send(() => setConfirming(false));
    if (mounted.current) {
      setConfirming(false);
      await onComplete();
    }
  }
  return (
    <div ref={root} className="min-w-0 space-y-5">
      <ComponentCard
        title={t("mailCompose.title")}
        desc={t("mailCompose.description")}
      >
        <MailRecipientPicker
          mode="internal"
          recipients={recipients}
          selectedIds={batch.selectedIds}
          onChange={(ids) => {
            batch.select(ids);
            setInvalid((current) =>
              current.filter((key) => key !== "recipients"),
            );
          }}
          disabled={locked}
          invalid={isInvalid("recipients")}
        />
        {isInvalid("recipients") && (
          <p
            role="alert"
            className="text-sm text-error-600 dark:text-error-400"
          >
            {t("mailCompose.recipientError")}
          </p>
        )}
        <div>
          <Label htmlFor="mail-subject">{t("mailCompose.subject")}</Label>
          <Input
            id="mail-subject"
            value={batch.subject}
            disabled={locked}
            required
            error={isInvalid("subject")}
            aria-invalid={isInvalid("subject") || undefined}
            hint={
              isInvalid("subject") ? t("mailCompose.subjectError") : undefined
            }
            onChange={(event) => {
              batch.edit("subject", event.target.value);
              setInvalid((current) =>
                current.filter((key) => key !== "subject"),
              );
            }}
          />
        </div>
        <div>
          <Label htmlFor="mail-body">{t("mailCompose.body")}</Label>
          <TextArea
            id="mail-body"
            value={batch.body}
            disabled={locked}
            required
            rows={10}
            error={isInvalid("body")}
            hint={
              isInvalid("body")
                ? t("mailCompose.bodyError")
                : t("mailCompose.bodyHint")
            }
            placeholder={t("mailCompose.bodyPlaceholder")}
            onChange={(value) => {
              batch.edit("body", value);
              setInvalid((current) => current.filter((key) => key !== "body"));
            }}
          />
        </div>
        {batch.started && (
          <p className="text-sm text-gray-600 dark:text-gray-300">
            {t("mailCompose.resumeHint")}
          </p>
        )}
        {batch.error && (
          <p
            role="alert"
            className="text-sm text-error-600 dark:text-error-400"
          >
            {t(batch.error)}
          </p>
        )}
        {batch.notice && (
          <p role="status" className="text-sm text-gray-700 dark:text-gray-200">
            {t(batch.notice)}
          </p>
        )}
        <div className="flex flex-wrap gap-3">
          <Button
            disabled={disabled || batch.busy}
            onClick={() => (batch.started ? void send() : preview())}
            className="max-w-full !bg-kps-primary whitespace-normal !text-white hover:!bg-kps-primary-hover"
          >
            {t(
              batch.busy
                ? "mailUi.sending"
                : batch.started
                  ? "mailUi.resumeBatch"
                  : "mailCompose.preview",
            )}
          </Button>
          {batch.canReset && (
            <Button
              variant="outline"
              disabled={disabled || batch.busy}
              onClick={() => {
                batch.reset();
                setInvalid([]);
              }}
            >
              {t("mailCompose.newMessage")}
            </Button>
          )}
        </div>
      </ComponentCard>
      {batch.results.length > 0 && (
        <MailHistory
          entries={batch.results}
          title={t("mailUi.batchResults")}
          desc={t("mailUi.batchResultsHint")}
        />
      )}
      <Modal
        isOpen={confirming}
        onClose={() => {
          if (!batch.busy) setConfirming(false);
        }}
        ariaLabelledBy="mail-compose-heading"
        className="max-w-2xl p-4 sm:p-6"
      >
        <div className="min-w-0 space-y-4">
          <h2
            id="mail-compose-heading"
            className="pe-10 text-lg font-semibold text-gray-900 dark:text-white"
          >
            {t("mailCompose.confirmTitle")}
          </h2>
          <div className="max-h-[60vh] space-y-4 overflow-y-auto text-sm text-gray-700 dark:text-gray-200">
            <p className="break-all">
              {t("mailCompose.from", { name: fromName, email: fromEmail })}
            </p>
            <p>{t("mailUi.confirmCount", { count: selection.length })}</p>
            <ul className="space-y-1">
              {selection.map((recipient) => (
                <li key={recipient.id} className="break-all">
                  {recipient.name} — {recipient.email}
                </li>
              ))}
            </ul>
            <p className="font-semibold break-all">{batch.subject.trim()}</p>
            <p className="break-words whitespace-pre-wrap">
              {batch.body.trim()}
            </p>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t("mailCompose.privacyHint")}
            </p>
          </div>
          {batch.error && (
            <p
              role="alert"
              className="text-sm text-error-600 dark:text-error-400"
            >
              {t(batch.error)}
            </p>
          )}
          <div className="flex flex-wrap gap-3">
            <Button
              disabled={disabled || batch.busy}
              onClick={() => void send()}
              className="!bg-kps-primary !text-white hover:!bg-kps-primary-hover"
            >
              {t(batch.busy ? "mailUi.sending" : "mailCompose.send")}
            </Button>
            <Button
              variant="outline"
              disabled={batch.busy}
              onClick={() => setConfirming(false)}
            >
              {t("mailCompose.backToEdit")}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
