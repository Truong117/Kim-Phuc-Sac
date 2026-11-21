import { useEffect, useState } from "react";
import {
  getCrmReportDraft,
  type CrmDraft,
  type CrmDraftSelection,
} from "@/services/crmService";

type Preference = { excluded: number[]; task?: number };
type LoadedDraft = { scope: string; draft: CrmDraft; preference: Preference };
type DraftRequest = { revision: number; manualScope: string | null };
const refreshTimeoutMs = 20_000;

function readPreference(scope: string): Preference {
  try {
    const saved = JSON.parse(
      sessionStorage.getItem("kps.crm.report:" + scope) ?? "null",
    ) as Partial<Preference> | null;
    if (saved && Array.isArray(saved.excluded)) {
      return {
        excluded: saved.excluded.filter(Number.isInteger),
        task: Number.isInteger(saved.task) ? saved.task : undefined,
      };
    }
  } catch {
    // The current page can still use the CRM draft when storage is unavailable.
  }
  return { excluded: [] };
}

export function useCrmReportDraft(user: string | null, date: string | null) {
  const scope = user && date ? user + ":" + date : null;
  const [loaded, setLoaded] = useState<LoadedDraft | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [request, setRequest] = useState<DraftRequest>({
    revision: 0,
    manualScope: null,
  });
  const [finishedRequest, setFinishedRequest] = useState<string | null>(null);
  const requestKey = scope ? scope + ":" + request.revision : null;
  const isManualRequest = scope !== null && request.manualScope === scope;

  useEffect(() => {
    if (!scope) return;
    const controller = new AbortController();
    let settled = false;
    const finish = () => {
      settled = true;
      setFinishedRequest(requestKey);
    };
    const deadline = window.setTimeout(() => {
      controller.abort();
      setFailure(scope);
      finish();
    }, refreshTimeoutMs);
    getCrmReportDraft(controller.signal)
      .then((draft) => {
        if (controller.signal.aborted) return;
        if (draft.business_date !== date) {
          setFailure(scope);
          return;
        }
        const savedPreference = readPreference(scope);
        setLoaded((current) => ({
          scope,
          draft,
          preference:
            current?.scope === scope ? current.preference : savedPreference,
        }));
        setFailure(null);
      })
      .catch(() => {
        if (!controller.signal.aborted) setFailure(scope);
      })
      .finally(() => {
        window.clearTimeout(deadline);
        if (!controller.signal.aborted) finish();
      });
    const refreshOnFocus = () => {
      if (isManualRequest && !settled) return;
      setRequest((current) =>
        current === request
          ? { revision: current.revision + 1, manualScope: null }
          : current,
      );
    };
    window.addEventListener("focus", refreshOnFocus);
    return () => {
      controller.abort();
      window.clearTimeout(deadline);
      window.removeEventListener("focus", refreshOnFocus);
    };
  }, [date, isManualRequest, request, requestKey, scope]);

  const state = loaded?.scope === scope ? loaded : null;
  const loading = scope !== null && finishedRequest !== requestKey;
  const error = scope !== null && failure === scope;
  const selected =
    state?.draft.activities.filter(
      ({ id }) => !state.preference.excluded.includes(id),
    ) ?? [];
  const tasks = state?.draft.tasks ?? [];
  const preferredTask = state?.preference.task;
  const task =
    preferredTask === undefined && tasks.length === 1
      ? tasks[0]
      : tasks.find(({ id }) => id === preferredTask);
  const hasConsultation = selected.some(({ kind }) => kind === "CONSULTATION");
  const selection: CrmDraftSelection | null = selected.length
    ? {
        activity_ids: selected.map(({ id }) => id),
        ...(hasConsultation && task
          ? { task_id: task.id, expected_revision: task.revision }
          : {}),
      }
    : null;

  const updatePreference = (preference: Preference) => {
    if (!state) return;
    try {
      sessionStorage.setItem(
        "kps.crm.report:" + state.scope,
        JSON.stringify(preference),
      );
    } catch {
      // Keep the selection for the current page.
    }
    setLoaded({ ...state, preference });
  };

  return {
    activities: state?.draft.activities ?? [],
    selectedActivities: selected,
    excluded: state?.preference.excluded ?? [],
    hasScope: scope !== null,
    error,
    loading,
    saveWaiting: loading && (isManualRequest || selection !== null),
    refreshed: isManualRequest && !loading && !error,
    hasConsultation,
    hasPlan: selected.some(({ kind }) => kind === "FOLLOW_UP"),
    selection,
    taskId: task?.id ?? 0,
    tasks,
    refresh: () => {
      if (!scope) return;
      setRequest((current) => ({
        revision: current.revision + 1,
        manualScope: scope,
      }));
    },
    toggle(id: number) {
      if (!state) return;
      const { excluded } = state.preference;
      updatePreference({
        ...state.preference,
        excluded: excluded.includes(id)
          ? excluded.filter((value) => value !== id)
          : [...excluded, id],
      });
    },
    selectTask(id: number) {
      if (state) updatePreference({ ...state.preference, task: id });
    },
  };
}
