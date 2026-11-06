import { useEffect, useRef, useState } from "react";
import { ApiServiceError } from "@/services/apiClient";
import {
  processMailTest,
  queueMailTests,
  type MailHistoryEntry,
} from "@/services/mailService";

type Batch = { selectedIds: string[]; keys: Record<string, string> };
const empty = (): Batch => ({ selectedIds: [], keys: {} });
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
    };
  } catch {
    return empty();
  }
}

export function useMailTestBatch(storageKey: string) {
  const [batch, setBatch] = useState<Batch>(() => readBatch(storageKey));
  const [results, setResults] = useState<MailHistoryEntry[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
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
      sessionStorage.setItem(storageKey, JSON.stringify(next.keys));
    } catch {
      /* Memory still preserves the keys when storage is unavailable. */
    }
  }
  function select(ids: string[]) {
    if (!running.current && !started)
      setBatch({ selectedIds: ids.slice(0, 50), keys: {} });
  }
  function reset() {
    if (!running.current && canReset) {
      persist(empty());
      setResults([]);
      setError("");
      setNotice("");
    }
  }
  async function send(onQueued?: () => void): Promise<boolean> {
    if (running.current || batch.selectedIds.length === 0) return false;
    running.current = true;
    setBusy(true);
    setError("");
    setNotice("");
    const next = { ...batch, keys: { ...batch.keys } };
    for (const id of next.selectedIds) next.keys[id] ||= crypto.randomUUID();
    persist(next); // Persist every key before creating any server-side request.
    let confirmed = false;
    try {
      const queued = await queueMailTests(
        next.selectedIds.map((id) => ({
          recipient_user_id: Number(id),
          request_key: next.keys[id],
        })),
      );
      confirmed = true;
      let updated = queued.data;
      if (!mounted.current) return false;
      setResults(updated);
      onQueued?.();
      for (const message of queued.data) {
        if (!mounted.current) return false;
        if (message.status !== "queued") continue;
        try {
          // Each HTTP request processes at most one mail, including on Render Free.
          const response = await processMailTest(message.id);
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
            ? "mailUi.acceptedNotice"
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
          persist({ selectedIds: next.selectedIds, keys: {} });
          setError("mailUi.recipientInvalid");
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
