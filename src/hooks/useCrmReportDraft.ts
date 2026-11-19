import { useEffect, useState } from "react";
import {
  getCrmReportDraft,
  type CrmDraft,
  type CrmDraftSelection,
} from "@/services/crmService";

type Preference = { excluded: number[]; task?: number };
type LoadedDraft = { scope: string; draft: CrmDraft; preference: Preference };

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
  const [revision, setRevision] = useState(0);

  useEffect(() => {
    if (!scope) return;
    const controller = new AbortController();
    getCrmReportDraft(controller.signal)
      .then((draft) => {
        if (controller.signal.aborted) return;
        if (draft.business_date !== date) {
          setFailure(scope);
          return;
        }
        setLoaded({ scope, draft, preference: readPreference(scope) });
        setFailure(null);
      })
      .catch(() => {
        if (!controller.signal.aborted) setFailure(scope);
      });
    const refresh = () => setRevision((version) => version + 1);
    window.addEventListener("focus", refresh);
    return () => {
      controller.abort();
      window.removeEventListener("focus", refresh);
    };
  }, [date, revision, scope]);

  const state = loaded?.scope === scope ? loaded : null;
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
    excluded: state?.preference.excluded ?? [],
    error: scope !== null && failure === scope,
    hasConsultation,
    hasPlan: selected.some(({ kind }) => kind === "FOLLOW_UP"),
    selection,
    taskId: task?.id ?? 0,
    tasks,
    refresh: () => setRevision((version) => version + 1),
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
