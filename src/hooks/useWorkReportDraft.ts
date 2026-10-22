import {
  createBrowserWorkReportDraftStorage,
  draftDataSignature,
  hasMeaningfulDraftData,
  parseWorkReportDraftValue,
  workReportDraftKey,
  type WorkReportDraftContext,
  type WorkReportDraftData,
  type WorkReportDraftRecord,
  type WorkReportDraftAdapter,
} from "@/services/workReportDraftStorage";
import { useCallback, useEffect, useRef, useState } from "react";

const AUTOSAVE_DELAY_MS = 900;

export type DraftIndicatorState =
  | { status: "idle" | "saving" | "error" | "conflict" }
  | { status: "saved" | "restored"; savedAt: string };

export type DraftDialogState =
  | {
      kind: "restore";
      draft: WorkReportDraftRecord;
      serverConflict: boolean;
    }
  | { kind: "locked"; draft: WorkReportDraftRecord }
  | { kind: "external"; incoming: WorkReportDraftRecord | null }
  | { kind: "discard" }
  | null;

interface UseWorkReportDraftOptions {
  context: WorkReportDraftContext | null;
  data: WorkReportDraftData;
  serverData: WorkReportDraftData;
  serverReportId: number | null;
  serverUpdatedAt: string | null;
  editable: boolean;
  active: boolean;
  applyData: (data: WorkReportDraftData) => void;
}

const createWriterId = () => {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  return `draft-tab-${Date.now()}-${Math.random().toString(36).slice(2)}`;
};

export const useWorkReportDraft = ({
  context,
  data,
  serverData,
  serverReportId,
  serverUpdatedAt,
  editable,
  active,
  applyData,
}: UseWorkReportDraftOptions) => {
  const [storage] = useState<WorkReportDraftAdapter | null>(() =>
    createBrowserWorkReportDraftStorage(),
  );
  const [indicator, setIndicator] = useState<DraftIndicatorState>({
    status: "idle",
  });
  const [dialog, setDialog] = useState<DraftDialogState>(null);
  const [hasStoredDraft, setHasStoredDraft] = useState(false);
  const writerIdRef = useRef(createWriterId());
  const revisionRef = useRef<string | null>(null);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const initializedRef = useRef(false);
  const autosaveEnabledRef = useRef(false);
  const submittedRef = useRef(false);
  const lastPersistedSignatureRef = useRef("");
  const serverSignatureRef = useRef("");
  const latestRef = useRef({
    context,
    data,
    serverReportId,
    serverUpdatedAt,
    active,
  });

  useEffect(() => {
    latestRef.current = {
      context,
      data,
      serverReportId,
      serverUpdatedAt,
      active,
    };
  }, [active, context, data, serverReportId, serverUpdatedAt]);

  const clearTimer = useCallback(() => {
    if (timerRef.current !== null) {
      clearTimeout(timerRef.current);
      timerRef.current = null;
    }
  }, []);

  const enterConflict = useCallback(
    (incoming: WorkReportDraftRecord | null) => {
      clearTimer();
      autosaveEnabledRef.current = false;
      setIndicator({ status: "conflict" });
      setDialog({ kind: "external", incoming });
    },
    [clearTimer],
  );

  const persistLatest = useCallback(
    (force = false) => {
      const latest = latestRef.current;
      if (
        !storage ||
        !latest.context ||
        !initializedRef.current ||
        !autosaveEnabledRef.current ||
        !latest.active ||
        submittedRef.current
      ) {
        return;
      }

      const signature = draftDataSignature(latest.data);
      if (!force && signature === lastPersistedSignatureRef.current) return;

      if (
        !hasMeaningfulDraftData(latest.data) ||
        signature === serverSignatureRef.current
      ) {
        const result = storage.remove(
          latest.context,
          revisionRef.current,
          force,
        );
        if (result.status === "conflict") {
          enterConflict(result.current);
        } else if (result.status === "unavailable") {
          setIndicator({ status: "error" });
        } else {
          revisionRef.current = null;
          lastPersistedSignatureRef.current = signature;
          setHasStoredDraft(false);
          setIndicator({ status: "idle" });
        }
        return;
      }

      const result = storage.save(latest.context, latest.data, {
        writerId: writerIdRef.current,
        expectedRevision: revisionRef.current,
        serverReportId: latest.serverReportId,
        serverUpdatedAt: latest.serverUpdatedAt,
        force,
      });

      if (result.status === "saved") {
        revisionRef.current = result.draft.revision;
        lastPersistedSignatureRef.current = signature;
        setHasStoredDraft(true);
        setIndicator({ status: "saved", savedAt: result.draft.updatedAt });
      } else if (result.status === "conflict") {
        enterConflict(result.current);
      } else {
        setIndicator({ status: "error" });
      }
    },
    [enterConflict, storage],
  );

  const contextKey = context ? workReportDraftKey(context) : null;
  const serverSignature = draftDataSignature(serverData);

  useEffect(() => {
    let cancelled = false;
    let nextDialog: DraftDialogState = null;
    let nextHasStoredDraft = false;
    let nextIndicator: DraftIndicatorState = { status: "idle" };

    clearTimer();
    initializedRef.current = false;
    autosaveEnabledRef.current = false;
    submittedRef.current = false;
    revisionRef.current = null;

    if (context && contextKey) {
      serverSignatureRef.current = serverSignature;
      lastPersistedSignatureRef.current = serverSignature;
      initializedRef.current = true;

      if (!storage) {
        nextIndicator = { status: "error" };
      } else {
        storage.cleanupExpired();
        const result = storage.load(context);
        if (result.status === "found") {
          revisionRef.current = result.draft.revision;
          lastPersistedSignatureRef.current = draftDataSignature(
            result.draft.data,
          );
          nextHasStoredDraft = true;
          nextDialog = editable
            ? {
                kind: "restore",
                draft: result.draft,
                serverConflict:
                  result.draft.serverReportId !== serverReportId ||
                  result.draft.serverUpdatedAt !== serverUpdatedAt,
              }
            : { kind: "locked", draft: result.draft };
        } else if (result.status === "unavailable") {
          nextIndicator = { status: "error" };
        } else {
          autosaveEnabledRef.current = editable;
        }
      }
    }

    queueMicrotask(() => {
      if (cancelled) return;
      setDialog(nextDialog);
      setHasStoredDraft(nextHasStoredDraft);
      setIndicator(nextIndicator);
    });

    return () => {
      cancelled = true;
    };
  }, [
    clearTimer,
    contextKey,
    editable,
    serverSignature,
    storage,
    context,
    serverReportId,
    serverUpdatedAt,
  ]);

  const currentSignature = draftDataSignature(data);

  useEffect(() => {
    if (
      !initializedRef.current ||
      !autosaveEnabledRef.current ||
      !active ||
      submittedRef.current ||
      currentSignature === lastPersistedSignatureRef.current
    ) {
      return;
    }

    clearTimer();
    setIndicator({ status: "saving" });
    timerRef.current = setTimeout(() => {
      timerRef.current = null;
      persistLatest();
    }, AUTOSAVE_DELAY_MS);

    return clearTimer;
  }, [active, clearTimer, currentSignature, persistLatest]);

  useEffect(() => {
    if (!contextKey || !context) return;

    const handleStorage = (event: StorageEvent) => {
      if (event.key !== contextKey) return;

      if (event.newValue === null) {
        enterConflict(null);
        return;
      }

      const parsed = parseWorkReportDraftValue(event.newValue, context);
      if (
        parsed.status === "found" &&
        parsed.draft.revision !== revisionRef.current
      ) {
        enterConflict(parsed.draft);
      }
    };

    window.addEventListener("storage", handleStorage);
    return () => window.removeEventListener("storage", handleStorage);
  }, [context, contextKey, enterConflict]);

  useEffect(() => {
    const flush = () => persistLatest();
    const handleVisibility = () => {
      if (document.visibilityState === "hidden") flush();
    };

    window.addEventListener("pagehide", flush);
    document.addEventListener("visibilitychange", handleVisibility);
    return () => {
      window.removeEventListener("pagehide", flush);
      document.removeEventListener("visibilitychange", handleVisibility);
      flush();
    };
  }, [persistLatest]);

  const restoreDraft = useCallback(() => {
    if (dialog?.kind !== "restore") return;
    applyData(dialog.draft.data);
    revisionRef.current = dialog.draft.revision;
    lastPersistedSignatureRef.current = draftDataSignature(dialog.draft.data);
    autosaveEnabledRef.current = editable;
    setIndicator({ status: "restored", savedAt: dialog.draft.updatedAt });
    setDialog(null);
  }, [applyData, dialog, editable]);

  const useServerData = useCallback(() => {
    if (!context) return;
    if (!storage) {
      applyData(serverData);
      revisionRef.current = null;
      lastPersistedSignatureRef.current = serverSignatureRef.current;
      autosaveEnabledRef.current = editable;
      setHasStoredDraft(false);
      setIndicator({ status: "error" });
      setDialog(null);
      return;
    }
    const result = storage.remove(context, revisionRef.current);
    if (result.status === "conflict") {
      enterConflict(result.current);
      return;
    }
    if (result.status === "unavailable") {
      applyData(serverData);
      revisionRef.current = null;
      lastPersistedSignatureRef.current = serverSignatureRef.current;
      autosaveEnabledRef.current = editable;
      setHasStoredDraft(false);
      setIndicator({ status: "error" });
      setDialog(null);
      return;
    }

    applyData(serverData);
    revisionRef.current = null;
    lastPersistedSignatureRef.current = serverSignatureRef.current;
    autosaveEnabledRef.current = editable;
    setHasStoredDraft(false);
    setIndicator({ status: "idle" });
    setDialog(null);
  }, [applyData, context, editable, enterConflict, serverData, storage]);

  const useExternalData = useCallback(() => {
    if (dialog?.kind !== "external") return;
    if (dialog.incoming) {
      applyData(dialog.incoming.data);
      revisionRef.current = dialog.incoming.revision;
      lastPersistedSignatureRef.current = draftDataSignature(
        dialog.incoming.data,
      );
      setHasStoredDraft(true);
      setIndicator({
        status: "restored",
        savedAt: dialog.incoming.updatedAt,
      });
    } else {
      applyData(serverData);
      revisionRef.current = null;
      lastPersistedSignatureRef.current = serverSignatureRef.current;
      setHasStoredDraft(false);
      setIndicator({ status: "idle" });
    }
    autosaveEnabledRef.current = editable;
    setDialog(null);
  }, [applyData, dialog, editable, serverData]);

  const keepCurrentData = useCallback(() => {
    autosaveEnabledRef.current = editable;
    setDialog(null);
    persistLatest(true);
  }, [editable, persistLatest]);

  const requestDiscard = useCallback(() => {
    clearTimer();
    autosaveEnabledRef.current = false;
    setDialog({ kind: "discard" });
  }, [clearTimer]);

  const cancelDiscard = useCallback(() => {
    autosaveEnabledRef.current = editable;
    setDialog(null);
  }, [editable]);

  const discardLockedDraft = useCallback(() => {
    if (!context || !storage || dialog?.kind !== "locked") return;
    const result = storage.remove(context, revisionRef.current);
    if (result.status === "conflict") {
      enterConflict(result.current);
      return;
    }
    if (result.status === "unavailable") {
      setIndicator({ status: "error" });
      return;
    }
    revisionRef.current = null;
    setHasStoredDraft(false);
    setIndicator({ status: "idle" });
    setDialog(null);
  }, [context, dialog, enterConflict, storage]);

  const closeLockedDraft = useCallback(() => {
    setDialog(null);
  }, []);

  const flushNow = useCallback(() => {
    clearTimer();
    persistLatest();
  }, [clearTimer, persistLatest]);

  const handleSubmitSuccess = useCallback(() => {
    clearTimer();
    submittedRef.current = true;
    autosaveEnabledRef.current = false;

    if (!context || !storage) return;
    const result = storage.remove(context, revisionRef.current);
    if (result.status === "removed") {
      revisionRef.current = null;
      setHasStoredDraft(false);
      setIndicator({ status: "idle" });
    }
  }, [clearTimer, context, storage]);

  const suspend = useCallback(() => {
    clearTimer();
    autosaveEnabledRef.current = false;
  }, [clearTimer]);

  return {
    indicator,
    dialog,
    hasStoredDraft,
    hasUnsavedData: hasStoredDraft || currentSignature !== serverSignature,
    restoreDraft,
    useServerData,
    useExternalData,
    keepCurrentData,
    requestDiscard,
    cancelDiscard,
    discardLockedDraft,
    closeLockedDraft,
    flushNow,
    handleSubmitSuccess,
    suspend,
  };
};
