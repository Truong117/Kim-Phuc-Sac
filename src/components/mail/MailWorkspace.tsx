import { useCallback, useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import Button from "@/components/ui/button/Button";
import Label from "@/components/form/Label";
import { Modal } from "@/components/ui/modal";
import { useModal } from "@/hooks/useModal";
import { useAuth } from "@/hooks/useAuth";
import { ApiServiceError } from "@/services/apiClient";
import {
  connectGmail,
  disconnectGmail,
  getMailRecipients,
  getMailStatus,
  sendMailTest,
  type MailRecipient,
  type MailStatus,
} from "@/services/mailService";
import MailHistory from "./MailHistory";

export default function MailWorkspace() {
  const { t } = useTranslation();
  const { currentUser: user } = useAuth();
  const requestStorageKey = `kps:mail-test:${user?.organization?.id}:${user?.id}`;
  const [status, setStatus] = useState<MailStatus | null>(null);
  const [recipients, setRecipients] = useState<MailRecipient[]>([]);
  const [recipientId, setRecipientId] = useState("");
  const [busy, setBusy] = useState(false);
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
  // Keep the same idempotency key after a network error/ambiguous response.
  const pending = useRef<Record<string, string> | null>(null);
  function pendingRequests(): Record<string, string> {
    if (!pending.current) {
      try {
        const stored: unknown = JSON.parse(
          sessionStorage.getItem(requestStorageKey) || "{}",
        );
        pending.current =
          stored && typeof stored === "object" && !Array.isArray(stored)
            ? Object.fromEntries(
                Object.entries(stored).filter(
                  ([id, key]) =>
                    /^\d+$/.test(id) &&
                    typeof key === "string" &&
                    /^[0-9a-f-]{36}$/i.test(key),
                ),
              )
            : {};
      } catch {
        pending.current = {};
      }
    }
    return pending.current;
  }
  function savePending() {
    try {
      sessionStorage.setItem(
        requestStorageKey,
        JSON.stringify(pending.current),
      );
    } catch {
      // Retain the in-memory key if this browser disables session storage.
    }
  }
  const mounted = useRef(true);
  const sequence = useRef(0);
  const confirmation = useModal();
  const disconnectConfirmation = useModal();
  const selection = recipients.find(
    (recipient) => recipient.id === recipientId,
  );
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

  async function action(kind: "connect" | "disconnect" | "test") {
    if (busy) return;
    setBusy(true);
    setError("");
    setNotice("");
    try {
      if (kind === "connect") {
        const { authorization_url: value } = await connectGmail();
        const url = new URL(value);
        if (url.protocol !== "https:" || url.hostname !== "accounts.google.com")
          throw new Error();
        if (mounted.current) window.location.assign(url.href);
      } else if (kind === "disconnect") {
        await disconnectGmail();
        if (!mounted.current) return;
        disconnectConfirmation.closeModal();
        setNotice("mailUi.disconnectedNotice");
        await load();
      } else {
        if (!selection?.test_allowed) return;
        const requests = pendingRequests();
        requests[recipientId] ||= crypto.randomUUID();
        savePending();
        const result = await sendMailTest(recipientId, requests[recipientId]);
        if (!mounted.current) return;
        if (!["queued", "sending", "unknown"].includes(result.data.status)) {
          delete requests[recipientId];
          savePending();
        }
        confirmation.closeModal();
        setNotice(
          result.data.status === "accepted"
            ? "mailUi.acceptedNotice"
            : "mailUi.checkHistory",
        );
        await load();
      }
    } catch (cause) {
      if (mounted.current)
        setError(
          cause instanceof ApiServiceError && cause.status === 422
            ? "mailUi.recipientInvalid"
            : kind === "test"
              ? "mailUi.requestUncertain"
              : "mailUi.actionError",
        );
    } finally {
      if (mounted.current) setBusy(false);
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
  return (
    <div className="space-y-5">
      <ComponentCard title={t("mailUi.title")} desc={t("mailUi.description")}>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p className="font-semibold break-all text-gray-900 dark:text-white">
              {data.email || data.expected_email}
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
        {error && (
          <p
            role="alert"
            className="text-sm text-error-600 dark:text-error-400"
          >
            {t(error)}
          </p>
        )}
        {notice && (
          <p role="status" className="text-sm text-gray-700 dark:text-gray-200">
            {t(notice)}
          </p>
        )}
      </ComponentCard>
      <ComponentCard
        title={t("mailUi.testTitle")}
        desc={t("mailUi.testDescription")}
      >
        <div>
          <Label htmlFor="mail-test-recipient">{t("mailUi.recipient")}</Label>
          <select
            id="mail-test-recipient"
            aria-invalid={error === "mailUi.recipientInvalid" || undefined}
            value={recipientId}
            onChange={(event) => setRecipientId(event.target.value)}
            disabled={busy || !ready || !connected || !data.send_enabled}
            className={`min-h-11 w-full rounded-lg border bg-white px-3 py-2 text-base text-gray-800 dark:bg-gray-900 dark:text-gray-200 ${error === "mailUi.recipientInvalid" ? "border-error-500 dark:border-error-400" : "border-gray-300 dark:border-gray-700"}`}
          >
            <option value="">{t("mailUi.selectRecipient")}</option>
            {recipients.map((recipient) => (
              <option
                key={recipient.id}
                value={recipient.id}
                disabled={!recipient.test_allowed}
              >
                {recipient.name} — {recipient.email}
                {!recipient.test_allowed ? ` (${t("mailUi.notAllowed")})` : ""}
              </option>
            ))}
          </select>
        </div>
        {!data.send_enabled && (
          <p className="text-sm text-gray-600 dark:text-gray-300">
            {t("mailUi.sendDisabled")}
          </p>
        )}
        <div>
          <Button
            disabled={
              busy ||
              !ready ||
              !connected ||
              !data.send_enabled ||
              !selection?.test_allowed
            }
            onClick={confirmation.openModal}
            className="!bg-kps-primary !text-white hover:!bg-kps-primary-hover"
          >
            {t("mailUi.previewTest")}
          </Button>
        </div>
      </ComponentCard>
      <MailHistory entries={data.history} />
      <Modal
        isOpen={confirmation.isOpen}
        onClose={() => {
          if (!busy) confirmation.closeModal();
        }}
        ariaLabelledBy="mail-test-heading"
        className="max-w-xl p-6"
      >
        <div>
          <h2
            id="mail-test-heading"
            className="text-lg font-semibold text-gray-900 dark:text-white"
          >
            {t("mailUi.confirmTitle")}
          </h2>
          <p className="mt-4 text-sm break-all text-gray-700 dark:text-gray-200">
            {t("mailUi.confirmTo", {
              name: selection?.name,
              email: selection?.email,
            })}
          </p>
          <p className="mt-3 text-sm text-gray-700 dark:text-gray-200">
            {t("mailUi.testSubject")}
          </p>
          <p className="mt-3 text-sm whitespace-pre-wrap text-gray-600 dark:text-gray-300">
            {t("mailUi.testBody", { name: selection?.name })}
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
              onClick={() => void action("test")}
              className="!bg-kps-primary !text-white hover:!bg-kps-primary-hover"
            >
              {t(busy ? "mailUi.sending" : "mailUi.confirmSend")}
            </Button>
            <Button
              variant="outline"
              disabled={busy}
              onClick={confirmation.closeModal}
            >
              {t("mailUi.cancel")}
            </Button>
          </div>
        </div>
      </Modal>
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
