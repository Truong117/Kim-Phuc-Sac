import { useCallback, useEffect, useRef, useState } from "react";
import { ApiServiceError } from "@/services/apiClient";
import {
  processMailMessage,
  queueMailMessages,
  readMailStatuses,
  type MailHistoryEntry,
} from "@/services/mailService";
import {
  emptyMailDraft,
  finishedMailRun,
  parseMailComposeState,
  resumableMailRun,
  type MailComposeState,
  type MailDraft,
  type MailRun,
  type MailTemplateState,
} from "@/utils/mailComposeState";

export function useMailComposeFlow(storageKey: string) {
  const [initial] = useState(() => {
    try {
      return {
        state: parseMailComposeState(
          sessionStorage.getItem(`${storageKey}:v2`),
          sessionStorage.getItem(storageKey),
        ),
        error: false,
      };
    } catch {
      return {
        state: { draft: emptyMailDraft(), runs: [] } as MailComposeState,
        error: true,
      };
    }
  });
  const [state, setState] = useState(initial.state);
  const current = useRef(state);
  const [storageError, setStorageError] = useState(initial.error);
  const [busy, setBusy] = useState(false);
  const [reading, setReading] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [validationFields, setValidationFields] = useState<string[]>([]);
  const [results, setResults] = useState<MailHistoryEntry[]>([]);
  const [generation, setGeneration] = useState(0);
  const mounted = useRef(true);
  const running = useRef(false);
  const refreshing = useRef(false);
  const unsafeStorage = useRef(initial.error);
  const persist = useCallback(
    (next: MailComposeState): boolean => {
      // Never overwrite an unreadable snapshot: it may contain live request keys.
      if (unsafeStorage.current) return false;
      try {
        sessionStorage.setItem(`${storageKey}:v2`, JSON.stringify(next));
        sessionStorage.removeItem(storageKey);
        setStorageError(false);
      } catch {
        setStorageError(true);
        return false;
      }
      current.current = next;
      if (mounted.current) setState(next);
      return true;
    },
    [storageKey],
  );
  const replaceRun = useCallback(
    (run: MailRun) => {
      const next = {
        ...current.current,
        runs: current.current.runs.map((entry) =>
          entry.id === run.id ? run : entry,
        ),
      };
      if (finishedMailRun(run))
        next.runs = next.runs.filter((entry) => entry.id !== run.id);
      persist(next);
    },
    [persist],
  );
  const checkRun = useCallback(
    async (run: MailRun): Promise<MailRun | null> => {
      try {
        const response = await readMailStatuses(
          Object.values(run.keys),
          AbortSignal.timeout(15000),
        );
        if (!mounted.current) return null;
        const next = {
          ...run,
          results: response.data,
          missing: response.missing_request_keys,
          checked: true,
          readError: false,
        };
        replaceRun(next);
        return next;
      } catch {
        if (mounted.current)
          replaceRun({ ...run, checked: false, readError: true });
        return null;
      }
    },
    [replaceRun],
  );
  const refresh = useCallback(async () => {
    if (refreshing.current || running.current) return;
    refreshing.current = true;
    setReading(true);
    for (const run of [...current.current.runs]) {
      if (!mounted.current) break;
      await checkRun(run);
    }
    refreshing.current = false;
    if (mounted.current) setReading(false);
  }, [checkRun]);
  useEffect(() => {
    mounted.current = true;
    persist(current.current);
    void refresh();
    return () => {
      mounted.current = false;
    };
  }, [persist, refresh]);
  function changeDraft(draft: MailDraft) {
    if (running.current) return;
    const next = { ...current.current, draft };
    if (!persist(next)) {
      // Editing remains possible; sending is blocked until persistence succeeds.
      current.current = next;
      setState(next);
    }
    setValidationFields([]);
    setError("");
  }
  const saveTemplate = useCallback(
    (template: MailTemplateState) => {
      if (
        running.current ||
        JSON.stringify(current.current.draft.template) ===
          JSON.stringify(template)
      )
        return;
      const next = {
        ...current.current,
        draft: { ...current.current.draft, template },
      };
      if (!persist(next)) {
        current.current = next;
        setState(next);
      }
    },
    [persist],
  );
  function reset(content?: { subject: string; body: string }) {
    if (running.current) return;
    changeDraft({ ...emptyMailDraft(), ...content });
    setGeneration((value) => value + 1);
    setResults([]);
    setNotice("");
  }
  async function processRun(run: MailRun, entries: MailHistoryEntry[]) {
    let updated = entries;
    replaceRun({ ...run, results: updated, checked: true, readError: false });
    for (const entry of entries) {
      if (!mounted.current) break;
      if (entry.status !== "queued") continue;
      try {
        const response = await processMailMessage(entry.id);
        updated = updated.map((item) =>
          item.id === response.data.id ? response.data : item,
        );
      } catch {
        // This request may already have reached Gmail. Only read it again.
        updated = updated.map((item) =>
          item.id === entry.id ? { ...item, status: "unknown" } : item,
        );
        setError("mailUi.requestUncertain");
      }
      if (mounted.current) {
        replaceRun({
          ...run,
          results: updated,
          missing: [],
          checked: true,
          readError: false,
        });
        setResults(updated);
      }
    }
    if (mounted.current) {
      setResults(updated);
      setNotice(
        updated.every((entry) => entry.status === "accepted")
          ? "mailCompose.acceptedNotice"
          : "mailUi.checkHistory",
      );
    }
  }
  async function send(onQueued?: () => void) {
    if (running.current || !current.current.draft.selectedIds.length) return;
    running.current = true;
    setBusy(true);
    setError("");
    setNotice("");
    setValidationFields([]);
    const draft = current.current.draft;
    const run: MailRun = {
      ...draft,
      subject: draft.subject.trim(),
      body: draft.body.trim(),
      id: crypto.randomUUID(),
      keys: Object.fromEntries(
        draft.selectedIds.map((id) => [id, crypto.randomUUID()]),
      ),
    };
    // Freeze both content and keys before the first mutating request.
    if (
      !persist({
        draft: emptyMailDraft(),
        runs: [...current.current.runs, run],
      })
    ) {
      setError("mailFlow.storageError");
      running.current = false;
      setBusy(false);
      return;
    }
    setGeneration((value) => value + 1);
    onQueued?.();
    let queued = false;
    try {
      const response = await queueMailMessages(
        run.selectedIds.map((id) => ({
          recipient_user_id: Number(id),
          request_key: run.keys[id],
        })),
        run.subject,
        run.body,
      );
      queued = true;
      if (!mounted.current) return;
      await processRun({ ...run, missing: [] }, response.data);
    } catch (cause) {
      if (!mounted.current) return;
      if (!queued && cause instanceof ApiServiceError && cause.status === 422) {
        persist({
          ...current.current,
          draft,
          runs: current.current.runs.filter((entry) => entry.id !== run.id),
        });
        setGeneration((value) => value + 1);
        setValidationFields(Object.keys(cause.validationErrors ?? {}));
        setError("mailCompose.validationError");
      } else {
        setError("mailUi.requestUncertain");
      }
    } finally {
      running.current = false;
      if (mounted.current) setBusy(false);
    }
  }
  async function resume(id: string) {
    if (running.current || refreshing.current) return;
    running.current = true;
    setBusy(true);
    setError("");
    setNotice("");
    try {
      const stored = current.current.runs.find((run) => run.id === id);
      if (!stored) return;
      // Recheck immediately before explicit resume; process only queued messages.
      let run = await checkRun(stored);
      if (!run || !resumableMailRun(run)) return;
      if (run.missing?.length) {
        const keys = run.keys;
        const missing = new Set(run.missing);
        const response = await queueMailMessages(
          run.selectedIds
            .filter((recipient) => missing.has(keys[recipient]))
            .map((recipient) => ({
              recipient_user_id: Number(recipient),
              request_key: keys[recipient],
            })),
          run.subject,
          run.body,
        );
        run = {
          ...run,
          results: [...(run.results ?? []), ...response.data],
          missing: [],
        };
      }
      await processRun(run, run.results ?? []);
    } catch {
      if (mounted.current) setError("mailUi.requestUncertain");
    } finally {
      running.current = false;
      if (mounted.current) setBusy(false);
    }
  }
  return {
    ...state.draft,
    runs: state.runs,
    results,
    busy,
    reading,
    storageError,
    error,
    notice,
    validationFields,
    generation,
    refresh,
    resume,
    send,
    reset,
    saveTemplate,
    edit: (field: "subject" | "body", value: string) =>
      changeDraft({ ...current.current.draft, [field]: value }),
    select: (ids: string[]) =>
      changeDraft({ ...current.current.draft, selectedIds: ids.slice(0, 50) }),
    replaceContent: (content: { subject: string; body: string }) =>
      changeDraft({ ...current.current.draft, ...content }),
  };
}
