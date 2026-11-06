import { useCallback, useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import { useModal } from "@/hooks/useModal";
import { useAuth } from "@/hooks/useAuth";
import {
  connectGmail,
  disconnectGmail,
  getMailRecipients,
  getMailStatus,
  type MailRecipient,
  type MailStatus,
} from "@/services/mailService";
import MailHistory from "./MailHistory";
import MailRecipientPicker from "./MailRecipientPicker";
import MailTestDialog from "./MailTestDialog";
import { useMailTestBatch } from "@/hooks/useMailTestBatch";

export default function MailWorkspace() {
  const { t } = useTranslation();
  const { currentUser: user } = useAuth();
  const requestStorageKey = `kps:mail-test:${user?.organization?.id}:${user?.id}`;
  const [status, setStatus] = useState<MailStatus | null>(null);
  const [recipients, setRecipients] = useState<MailRecipient[]>([]);
  const [connectionBusy, setConnectionBusy] = useState(false);
  const batch = useMailTestBatch(requestStorageKey);
  const busy = connectionBusy || batch.busy;
  const [error, setError] = useState("");
  const [notice, setNotice] = useState(() => {
    const result = new URLSearchParams(window.location.search).get(
      "mail_oauth",
    );
    return result === "connected"
      ? "mailUi.connectedNotice"
      : result === "failed"
        ? "mailUi.oauthFailed"
        : "";
  });
  const mounted = useRef(true);
  const sequence = useRef(0);
  const confirmation = useModal();
  const disconnectConfirmation = useModal();
  const selection = recipients.filter((recipient) =>
    batch.selectedIds.includes(recipient.id),
  );
  const validSelection =
    selection.length > 0 &&
    selection.length === batch.selectedIds.length &&
    selection.every((recipient) => recipient.test_allowed);
  const load = useCallback(async (signal?: AbortSignal) => {
    const ticket = ++sequence.current;
    try {
      const [result, choices] = await Promise.all([
        getMailStatus(signal),
        getMailRecipients(signal),
      ]);
      if (mounted.current && ticket === sequence.current) {
        setStatus(result);
        setRecipients(choices.data);
      }
    } catch (cause) {
      if (
        mounted.current &&
        ticket === sequence.current &&
        !(cause instanceof DOMException && cause.name === "AbortError")
      )
        setError("mailUi.loadError");
    }
  }, []);
  useEffect(() => {
    mounted.current = true;
    const controller = new AbortController();
    void Promise.resolve().then(() => {
      if (!controller.signal.aborted) return load(controller.signal);
    });
    return () => {
      mounted.current = false;
      controller.abort();
    };
  }, [load]);

  async function action(kind: "connect" | "disconnect") {
    if (busy) return;
    setConnectionBusy(true);
    setError("");
    setNotice("");
    try {
      if (kind === "connect") {
        const { authorization_url: value } = await connectGmail();
        const url = new URL(value);
        if (url.protocol !== "https:" || url.hostname !== "accounts.google.com")
          throw new Error();
        if (mounted.current) window.location.assign(url.href);
      } else {
        await disconnectGmail();
        if (!mounted.current) return;
        disconnectConfirmation.closeModal();
        setNotice("mailUi.disconnectedNotice");
        await load();
      }
    } catch {
      if (mounted.current) setError("mailUi.actionError");
    } finally {
      if (mounted.current) setConnectionBusy(false);
    }
  }
  async function sendBatch() {
    const recorded = await batch.send(confirmation.closeModal);
    if (mounted.current) {
      if (recorded) confirmation.closeModal();
      await load();
    }
  }

  if (!status)
    return (
      <p
        role={error ? "alert" : "status"}
        className="text-sm text-gray-600 dark:text-gray-300"
      >
        {t(error || "mailUi.loading")}
      </p>
    );
  const data = status.data;
  const ready = data.enabled && data.configured && data.schema_ready;
  const connected = data.status === "connected";
  const fromName = data.from_name || t("mailUi.defaultFromName");
  return (
    <div className="space-y-5">
      <ComponentCard title={t("mailUi.title")} desc={t("mailUi.description")}>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p className="font-semibold break-all text-gray-900 dark:text-white">
              {data.email || data.expected_email}
            </p>
            <p className="mt-2 text-sm break-all text-gray-600 dark:text-gray-300">
              {t("mailUi.fromName", { name: fromName })}
            </p>
            <p className="mt-2 text-sm text-gray-600 dark:text-gray-300">
              {t(`mailUi.connectionStates.${data.status}`, {
                defaultValue: t("mailUi.connectionStates.disconnected"),
              })}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button
              variant="outline"
              disabled={busy}
              onClick={() => {
                setError("");
                void load();
              }}
            >
              {t("mailUi.refresh")}
            </Button>
            {status.actions.can_configure && (
              <Button
                disabled={busy || !ready}
                onClick={() => void action("connect")}
                className="!bg-kps-primary !text-white hover:!bg-kps-primary-hover"
              >
                {t(connected ? "mailUi.reconnect" : "mailUi.connect")}
              </Button>
            )}
            {status.actions.can_configure &&
              ["connected", "reconnect_required"].includes(data.status) && (
                <Button
                  variant="outline"
                  disabled={busy}
                  onClick={disconnectConfirmation.openModal}
                >
                  {t("mailUi.disconnect")}
                </Button>
              )}
          </div>
        </div>
        {!ready && (
          <p className="rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
            {t(
              !data.enabled
                ? "mailUi.disabledHint"
                : !data.schema_ready
                  ? "mailUi.schemaHint"
                  : "mailUi.configurationHint",
            )}
          </p>
        )}
        {(error || batch.error) && (
          <p
            role="alert"
            className="text-sm text-error-600 dark:text-error-400"
          >
            {t(batch.error || error)}
          </p>
        )}
        {(notice || batch.notice) && (
          <p role="status" className="text-sm text-gray-700 dark:text-gray-200">
            {t(batch.notice || notice)}
          </p>
        )}
      </ComponentCard>
      <ComponentCard
        title={t("mailUi.testTitle")}
        desc={t("mailUi.testDescription")}
      >
        <MailRecipientPicker
          recipients={recipients}
          selectedIds={batch.selectedIds}
          onChange={batch.select}
          disabled={
            busy || batch.started || !ready || !connected || !data.send_enabled
          }
          invalid={batch.error === "mailUi.recipientInvalid"}
        />
        {batch.started && (
          <p className="text-sm text-gray-600 dark:text-gray-300">
            {t("mailUi.resumeHint")}
          </p>
        )}
        {!data.send_enabled && (
          <p className="text-sm text-gray-600 dark:text-gray-300">
            {t("mailUi.sendDisabled")}
          </p>
        )}
        <div className="flex flex-wrap gap-3">
          <Button
            disabled={
              busy ||
              !ready ||
              !connected ||
              !data.send_enabled ||
              (!batch.started && !validSelection) ||
              batch.selectedIds.length === 0
            }
            onClick={() => {
              if (batch.started) void sendBatch();
              else confirmation.openModal();
            }}
            className="max-w-full !bg-kps-primary whitespace-normal !text-white hover:!bg-kps-primary-hover"
          >
            {t(
              batch.busy
                ? "mailUi.sending"
                : batch.started
                  ? "mailUi.resumeBatch"
                  : "mailUi.previewTest",
            )}
          </Button>
          {batch.canReset && (
            <Button
              variant="outline"
              disabled={busy}
              onClick={batch.reset}
              className="max-w-full whitespace-normal"
            >
              {t("mailUi.newBatch")}
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
      <MailHistory entries={data.history} />
      <MailTestDialog
        isOpen={confirmation.isOpen}
        onClose={confirmation.closeModal}
        recipients={selection}
        fromName={fromName}
        busy={busy}
        error={batch.error}
        onSend={() => void sendBatch()}
      />
      <Modal
        isOpen={disconnectConfirmation.isOpen}
        onClose={() => {
          if (!busy) disconnectConfirmation.closeModal();
        }}
        ariaLabelledBy="mail-disconnect-heading"
        className="max-w-xl p-6"
      >
        <div>
          <h2
            id="mail-disconnect-heading"
            className="text-lg font-semibold text-gray-900 dark:text-white"
          >
            {t("mailUi.disconnect")}
          </h2>
          <p className="mt-4 text-sm text-gray-600 dark:text-gray-300">
            {t("mailUi.disconnectHint")}
          </p>
          <div className="mt-6 flex flex-wrap gap-3">
            <Button
              variant="destructive"
              disabled={busy}
              onClick={() => void action("disconnect")}
            >
              {t("mailUi.disconnect")}
            </Button>
            <Button
              variant="outline"
              disabled={busy}
              onClick={disconnectConfirmation.closeModal}
            >
              {t("mailUi.cancel")}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
