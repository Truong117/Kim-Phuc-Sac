import { useCallback, useEffect, useRef, useState } from "react";
import {
  getFacebookPeriodReview,
  saveFacebookReviewPlan,
} from "@/services/facebookAnalyticsService";
import { ApiServiceError, type ValidationErrors } from "@/services/apiClient";
import type {
  FacebookAnalyticsData,
  FacebookPeriodReviewData,
  FacebookMetric,
  FacebookReviewAction,
} from "@/types/facebookAnalytics";

export interface FacebookReviewDraft {
  expected_posts: string;
  actions: FacebookReviewAction[];
  version: number;
}
const draftOf = (data: FacebookPeriodReviewData): FacebookReviewDraft => ({
  expected_posts: data.plan.expected_posts?.toString() ?? "",
  actions: data.plan.actions,
  version: data.plan.version,
});
export function useFacebookPeriodReview(
  data: FacebookAnalyticsData,
  metric: FacebookMetric,
) {
  const [response, setResponse] = useState<{
    metric: FacebookMetric;
    value: FacebookPeriodReviewData;
  } | null>(null);
  const [draft, setDraft] = useState<FacebookReviewDraft | null>(null);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const [saving, setSaving] = useState(false);
  const [saveState, setSaveState] = useState<
    "saved" | "conflict" | "failed" | null
  >(null);
  const [errors, setErrors] = useState<ValidationErrors>({});
  const [revision, setRevision] = useState(0);
  const active = useRef(false),
    write = useRef<AbortController | null>(null);
  useEffect(() => {
    active.current = true;
    return () => {
      active.current = false;
      write.current?.abort();
    };
  }, []);
  useEffect(() => {
    const controller = new AbortController();
    let current = true,
      fetching = false;
    async function load() {
      if (fetching) return;
      fetching = true;
      setLoading(true);
      try {
        const result = await getFacebookPeriodReview(
          data.entry_id,
          data.from_date,
          data.to_date,
          metric,
          AbortSignal.any([controller.signal, AbortSignal.timeout(45000)]),
        );
        if (current) {
          setResponse({ metric, value: result.data });
          setDraft((old) => old ?? draftOf(result.data));
          setFailed(false);
        }
      } catch (error) {
        if (current) {
          setFailed(true);
          if (
            error instanceof ApiServiceError &&
            [401, 403, 404].includes(error.status)
          ) {
            setResponse(null);
            setDraft(null);
          }
        }
      } finally {
        fetching = false;
        if (current) setLoading(false);
      }
    }
    void load();
    const timer = window.setInterval(() => {
      if (document.visibilityState === "visible") void load();
    }, 60000);
    return () => {
      current = false;
      controller.abort();
      clearInterval(timer);
    };
  }, [data, metric, revision]);
  const result = response?.metric === metric ? response.value : null;
  const dirty =
    draft !== null &&
    response !== null &&
    JSON.stringify(draft) !== JSON.stringify(draftOf(response.value));
  const conflict =
    draft !== null && result !== null && draft.version !== result.plan.version;
  const edit = useCallback(
    (updater: (draft: FacebookReviewDraft) => FacebookReviewDraft) => {
      setDraft((old) => (old === null ? null : updater(old)));
      setSaveState(null);
      setErrors({});
    },
    [],
  );
  function adoptServer() {
    if (!result) return;
    setDraft(draftOf(result));
    setSaveState(null);
    setErrors({});
  }
  async function save() {
    if (!draft || !result || saving || conflict) return;
    const controller = new AbortController();
    write.current = controller;
    setSaving(true);
    setSaveState(null);
    setErrors({});
    try {
      const saved = await saveFacebookReviewPlan(
        data.entry_id,
        {
          from_date: data.from_date,
          to_date: data.to_date,
          metric,
          entry_version: result.entry_version,
          version: draft.version,
          source_hash: result.source_hash,
          expected_posts:
            draft.expected_posts.trim() === ""
              ? null
              : Number(draft.expected_posts),
          actions: draft.actions,
        },
        AbortSignal.any([controller.signal, AbortSignal.timeout(45000)]),
      );
      if (active.current) {
        setResponse({ metric, value: saved.data });
        setDraft(draftOf(saved.data));
        setSaveState("saved");
      }
    } catch (error) {
      if (active.current) {
        if (
          error instanceof ApiServiceError &&
          [401, 403, 404].includes(error.status)
        ) {
          setResponse(null);
          setDraft(null);
        }
        setSaveState(
          error instanceof ApiServiceError && error.status === 409
            ? "conflict"
            : "failed",
        );
        setErrors(
          error instanceof ApiServiceError
            ? (error.validationErrors ?? {})
            : {},
        );
      }
    } finally {
      if (active.current) setSaving(false);
    }
  }
  return {
    result,
    draft,
    dirty,
    conflict,
    loading,
    failed,
    saving,
    saveState,
    errors,
    edit,
    adoptServer,
    save,
    refresh: () => setRevision((n) => n + 1),
  };
}
