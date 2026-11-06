import { useEffect, useRef, useState } from "react";
import { ApiServiceError } from "@/services/apiClient";
import {
  processMailTest,
  processMailMessage,
  queueMailTests,
  queueMailMessages,
  type MailHistoryEntry,
} from "@/services/mailService";

type Batch = {
  selectedIds: string[];
  keys: Record<string, string>;
  subject: string;
  body: string;
};
const empty = (): Batch => ({
  selectedIds: [],
  keys: {},
  subject: "",
  body: "",
});
function readBatch(storageKey: string): Batch {
  try {
    const stored = JSON.parse(sessionStorage.getItem(storageKey) || "{}");
    const source = stored.keys ?? stored; // Preserve earlier single-recipient request keys.
    const keys = Object.fromEntries(
      Object.entries(source).filter(
        ([id, key]) =>
          /^\d+$/.test(id) &&
          typeof key === "string" &&
          /^[0-9a-f-]{36}$/i.test(key),
      ),
    ) as Record<string, string>;
    return {
      keys,
      selectedIds: Object.keys(keys).length ? Object.keys(keys) : [],
      subject: typeof stored.subject === "string" ? stored.subject : "",
      body: typeof stored.body === "string" ? stored.body : "",
    };
  } catch {
    return empty();
  }
}

export function useMailTestBatch(storageKey: string) {
  return useMailBatch(storageKey, "test");
}

export function useMailBatch(storageKey: string, mode: "test" | "internal") {
  const [batch, setBatch] = useState<Batch>(() => readBatch(storageKey));
  const [results, setResults] = useState<MailHistoryEntry[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [validationFields, setValidationFields] = useState<string[]>([]);
  const running = useRef(false);
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);
  const started = Object.keys(batch.keys).length > 0;
  const canReset =
    started &&
    results.length === batch.selectedIds.length &&
    results.every((entry) =>
      ["accepted", "failed", "cancelled"].includes(entry.status),
    );
  function persist(next: Batch) {
    setBatch(next);
    try {
      sessionStorage.setItem(storageKey, JSON.stringify(next));
    } catch {
      /* Memory still preserves the keys when storage is unavailable. */
    }
  }
  function select(ids: string[]) {
    if (!running.current && !started) {
      setBatch((current) => ({
        ...current,
        selectedIds: ids.slice(0, 50),
        keys: {},
      }));
      setValidationFields([]);
    }
  }
  function edit(field: "subject" | "body", value: string) {
    if (!running.current && !started) {
      setBatch((current) => ({ ...current, [field]: value }));
      setValidationFields([]);
    }
  }
  function reset() {
    if (!running.current && canReset) {
      persist(empty());
      setResults([]);
      setError("");
      setNotice("");
      setValidationFields([]);
    }
  }
  async function send(onQueued?: () => void): Promise<boolean> {
    if (running.current || batch.selectedIds.length === 0) return false;
    running.current = true;
    setBusy(true);
    setError("");
    setNotice("");
    setValidationFields([]);
    const next = {
      ...batch,
      subject: batch.subject.trim(),
      body: batch.body.trim(),
      keys: { ...batch.keys },
    };
    for (const id of next.selectedIds) next.keys[id] ||= crypto.randomUUID();
    persist(next); // Persist every key before creating any server-side request.
    let confirmed = false;
    try {
      const requests = next.selectedIds.map((id) => ({
        recipient_user_id: Number(id),
        request_key: next.keys[id],
      }));
      const queued =
        mode === "test"
          ? await queueMailTests(requests)
          : await queueMailMessages(requests, next.subject, next.body);
      confirmed = true;
      let updated = queued.data;
      if (!mounted.current) return false;
      setResults(updated);
      onQueued?.();
      for (const message of queued.data) {
        if (!mounted.current) return false;
        if (!["queued", "sending"].includes(message.status)) continue;
        try {
          // Each HTTP request processes at most one mail, including on Render Free.
          const response =
            mode === "test"
              ? await processMailTest(message.id)
              : await processMailMessage(message.id);
          updated = updated.map((entry) =>
            entry.id === response.data.id ? response.data : entry,
          );
          if (mounted.current) setResults(updated);
        } catch {
          if (mounted.current) setError("mailUi.requestUncertain");
        }
      }
      if (mounted.current)
        setNotice(
          updated.every((entry) => entry.status === "accepted")
            ? mode === "test"
              ? "mailUi.acceptedNotice"
              : "mailCompose.acceptedNotice"
            : "mailUi.checkHistory",
        );
      return true;
    } catch (cause) {
      if (mounted.current) {
        if (
          !confirmed &&
          cause instanceof ApiServiceError &&
          cause.status === 422
        ) {
          // Batch validation is atomic: no messages were queued for this invalid selection.
          persist({ ...next, keys: {} });
          setValidationFields(Object.keys(cause.validationErrors ?? {}));
          setError(
            mode === "test"
              ? "mailUi.recipientInvalid"
              : "mailCompose.validationError",
          );
        } else setError("mailUi.requestUncertain");
      }
      return false;
    } finally {
      running.current = false;
      if (mounted.current) setBusy(false);
    }
  }
  return {
    selectedIds: batch.selectedIds,
    subject: batch.subject,
    body: batch.body,
    edit,
    validationFields,
    select,
    results,
    busy,
    error,
    notice,
    started,
    canReset,
    reset,
    send,
  };
}
