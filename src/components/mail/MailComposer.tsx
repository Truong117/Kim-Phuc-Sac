import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import { useMailComposeFlow } from "@/hooks/useMailComposeFlow";
import { useModal } from "@/hooks/useModal";
import { hasMailDraft } from "@/utils/mailComposeState";
import {
  INTERNAL_MAIL_LAYOUT,
  previewMailMessage,
  readMailContent,
  type MailHistoryEntry,
  type MailRecipient,
} from "@/services/mailService";
import MailSendRuns from "./MailSendRuns";
import MailBodyPreview from "./MailBodyPreview";
import MailHistory from "./MailHistory";
import MailRecipientPicker from "./MailRecipientPicker";
import MailTemplatePicker from "./MailTemplatePicker";

interface Props {
  storageKey: string;
  history: MailHistoryEntry[];
  actorId: string;
  recipients: MailRecipient[];
  fromName: string;
  fromEmail: string;
  disabled: boolean;
  onBusyChange: (busy: boolean) => void;
  onComplete: () => Promise<void>;
}

export default function MailComposer({
  storageKey,
  history,
  actorId,
  recipients,
  fromName,
  fromEmail,
  disabled,
  onBusyChange,
  onComplete,
}: Props) {
  const { t } = useTranslation();
  const batch = useMailComposeFlow(storageKey);
  const discard = useModal();
  const historyRoot = useRef<HTMLDivElement>(null);
  const [reuseBusy, setReuseBusy] = useState(false);
  const [reuseError, setReuseError] = useState(false);
  const [replacement, setReplacement] = useState<
    { subject: string; body: string } | undefined
  >();
  const reuseAbort = useRef<AbortController | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [previewBusy, setPreviewBusy] = useState(false);
  const [previewHtml, setPreviewHtml] = useState("");
  const [previewError, setPreviewError] = useState(false);
  const previewAbort = useRef<AbortController | null>(null);
  const [invalid, setInvalid] = useState<string[]>([]);
  const [templateBusy, setTemplateBusy] = useState(false);
  const [templateReady, setTemplateReady] = useState(
    batch.template?.ready ?? true,
  );
  const root = useRef<HTMLDivElement>(null);
  const mounted = useRef(true);
  const selection = recipients.filter((recipient) =>
    batch.selectedIds.includes(recipient.id),
  );
  const baseLocked = disabled || batch.busy || reuseBusy;
  const locked = baseLocked || templateBusy || previewBusy || confirming;
  useEffect(() => {
    onBusyChange(batch.busy || templateBusy || previewBusy || reuseBusy);
    return () => onBusyChange(false);
  }, [batch.busy, templateBusy, previewBusy, reuseBusy, onBusyChange]);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      previewAbort.current?.abort();
      reuseAbort.current?.abort();
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
  async function preview() {
    if (disabled || previewBusy || templateBusy || !templateReady) return;
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
    } else {
      const controller = new AbortController();
      previewAbort.current = controller;
      setPreviewBusy(true);
      setPreviewError(false);
      setPreviewHtml("");
      const timeout = setTimeout(() => controller.abort(), 15000);
      try {
        const response = await previewMailMessage(
          batch.subject.trim(),
          batch.body.trim(),
          controller.signal,
        );
        if (!mounted.current || controller.signal.aborted) return;
        if (
          response.data.layout !== INTERNAL_MAIL_LAYOUT ||
          typeof response.data.html !== "string" ||
          !response.data.html
        )
          throw new Error("MAIL_PREVIEW_UNAVAILABLE");
        setPreviewHtml(response.data.html);
        setConfirming(true);
      } catch {
        if (mounted.current) setPreviewError(true);
      } finally {
        clearTimeout(timeout);
        if (mounted.current) setPreviewBusy(false);
      }
    }
  }
  async function send() {
    if (disabled || previewBusy || templateBusy || !templateReady) return;
    await batch.send(() => setConfirming(false));
    if (mounted.current) {
      setConfirming(false);
      await onComplete();
    }
  }
  function newDraft(content?: { subject: string; body: string }) {
    setReplacement(content);
    if (hasMailDraft(batch)) discard.openModal();
    else {
      batch.reset(content);
      setInvalid([]);
      setPreviewError(false);
    }
  }
  async function reuse(id: string) {
    if (locked) return;
    const controller = new AbortController();
    reuseAbort.current = controller;
    setReuseBusy(true);
    setReuseError(false);
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await readMailContent(id, controller.signal);
      if (!mounted.current || controller.signal.aborted) return;
      newDraft(response.data);
      root.current?.scrollIntoView({ behavior: "smooth", block: "start" });
    } catch {
      if (mounted.current) setReuseError(true);
    } finally {
      clearTimeout(timeout);
      if (mounted.current) setReuseBusy(false);
    }
  }
  return (
    <div ref={root} className="min-w-0 space-y-5">
      <MailSendRuns
        runs={batch.runs}
        busy={batch.busy}
        reading={batch.reading}
        disabled={disabled || locked}
        onRefresh={batch.refresh}
        onResume={async (id) => {
          await batch.resume(id);
          await onComplete();
        }}
      />
      <ComponentCard
        title={t("mailCompose.title")}
        desc={t("mailCompose.description")}
      >
        <p role="status" className="text-sm text-gray-500 dark:text-gray-400">
          {t(
            batch.storageError
              ? "mailFlow.storageError"
              : hasMailDraft(batch)
                ? "mailFlow.draftSaved"
                : "mailFlow.freshDraft",
          )}
        </p>
        <MailTemplatePicker
          key={batch.generation}
          initialState={batch.template}
          onStateChange={batch.saveTemplate}
          fromName={fromName}
          disabled={baseLocked || previewBusy || confirming}
          onBusyChange={setTemplateBusy}
          onReadyChange={setTemplateReady}
          onApply={(content) => {
            batch.replaceContent(content);
            setInvalid([]);
          }}
        />
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
        {reuseError && (
          <p
            role="alert"
            className="text-sm text-error-600 dark:text-error-400"
          >
            {t("mailFlow.reuseError")}
          </p>
        )}
        {previewError && (
          <p
            role="alert"
            className="text-sm text-error-600 dark:text-error-400"
          >
            {t("mailCompose.previewError")}
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
        <div className="flex flex-wrap gap-3">
          <Button
            disabled={
              disabled ||
              batch.busy ||
              templateBusy ||
              previewBusy ||
              !templateReady ||
              reuseBusy
            }
            onClick={() => void preview()}
            className="max-w-full !bg-kps-primary whitespace-normal !text-white hover:!bg-kps-primary-hover"
          >
            {t(
              previewBusy
                ? "mailCompose.previewLoading"
                : batch.busy
                  ? "mailUi.sending"
                  : "mailCompose.preview",
            )}
          </Button>
          {hasMailDraft(batch) && (
            <Button
              variant="outline"
              disabled={locked}
              onClick={() => newDraft()}
            >
              {t("mailFlow.discard")}
            </Button>
          )}
        </div>
      </ComponentCard>
      {batch.results.length > 0 && (
        <div className="space-y-3">
          {batch.notice && (
            <p
              role="status"
              className="text-sm text-gray-700 dark:text-gray-200"
            >
              {t(batch.notice)}
            </p>
          )}
          <MailHistory
            entries={batch.results}
            title={t("mailUi.batchResults")}
            desc={t("mailFlow.resultHint")}
          />
          <div className="flex flex-wrap gap-3">
            <Button
              variant="outline"
              disabled={locked}
              onClick={() => {
                newDraft();
                root.current?.scrollIntoView({
                  behavior: "smooth",
                  block: "start",
                });
              }}
            >
              {t("mailCompose.newMessage")}
            </Button>
            <Button
              variant="outline"
              onClick={() =>
                historyRoot.current?.scrollIntoView({
                  behavior: "smooth",
                  block: "start",
                })
              }
            >
              {t("mailFlow.history")}
            </Button>
          </div>
        </div>
      )}
      <div ref={historyRoot}>
        <MailHistory
          entries={history}
          actorId={actorId}
          onReuse={(id) => void reuse(id)}
          reuseDisabled={locked}
        />
      </div>
      <Modal
        isOpen={discard.isOpen}
        onClose={discard.closeModal}
        aboveNavigation
        ariaLabelledBy="mail-discard-heading"
        className="max-w-lg p-4 sm:p-6"
      >
        <div className="space-y-4">
          <h2
            id="mail-discard-heading"
            className="pe-10 text-lg font-semibold text-gray-900 dark:text-white"
          >
            {t("mailFlow.replaceTitle")}
          </h2>
          <p className="text-sm text-gray-700 dark:text-gray-200">
            {t("mailFlow.replaceHint")}
          </p>
          <div className="flex flex-wrap gap-3">
            <Button
              onClick={() => {
                batch.reset(replacement);
                setInvalid([]);
                setPreviewError(false);
                discard.closeModal();
              }}
            >
              {t("mailFlow.replace")}
            </Button>
            <Button variant="outline" onClick={discard.closeModal}>
              {t("mailFlow.keep")}
            </Button>
          </div>
        </div>
      </Modal>
      <Modal
        isOpen={confirming}
        onClose={() => {
          if (!batch.busy) setConfirming(false);
        }}
        ariaLabelledBy="mail-compose-heading"
        aboveNavigation
        className="max-w-4xl p-4 sm:p-6"
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
            <MailBodyPreview html={previewHtml} text={batch.body.trim()} />
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
