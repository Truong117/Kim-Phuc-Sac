import { useEffect, useRef, useState } from "react";
import {
  createWorkAnalysis,
  getWorkAnalysis,
  previewWorkAnalysis,
  retryWorkAnalysis,
} from "@/services/workReportAnalysisService";
import type {
  AnalysisFilters,
  AnalysisPreview,
  WorkReportAnalysis,
} from "@/types/workReportAnalysis";
import { ApiServiceError } from "@/services/apiClient";

export default function useWorkReportAnalysis(
  filters: AnalysisFilters,
  historyId: number | null,
) {
  const [preview, setPreview] = useState<AnalysisPreview | null>(null);
  const [run, setRun] = useState<WorkReportAnalysis | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [revision, setRevision] = useState(0);
  const [loadedKey, setLoadedKey] = useState("");
  const action = useRef<AbortController | null>(null);
  const { end_date, days, department_id } = filters;
  const requestKey = JSON.stringify([
    end_date,
    days,
    department_id,
    historyId,
    revision,
  ]);
  useEffect(() => {
    const controller = new AbortController();
    action.current?.abort();
    const request =
      historyId === null
        ? previewWorkAnalysis(
            { end_date, days, department_id },
            controller.signal,
          ).then((next) => {
            if (!controller.signal.aborted) {
              setPreview(next);
              setRun(next.run);
            }
          })
        : getWorkAnalysis(historyId, controller.signal).then((next) => {
            if (!controller.signal.aborted) setRun(next);
          });
    request
      .catch((err: unknown) => {
        if (!controller.signal.aborted) {
          setRun(null);
          setPreview(null);
          setError(err);
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) {
          setLoading(false);
          setLoadedKey(requestKey);
        }
      });
    return () => controller.abort();
  }, [end_date, days, department_id, historyId, revision, requestKey]);

  const runId = run?.id;
  const running = run?.status === "pending" || run?.status === "running";
  useEffect(() => {
    if (!runId || !running) return;
    const controller = new AbortController();
    let timeout: ReturnType<typeof setTimeout>;
    const poll = async () => {
      try {
        const next = await getWorkAnalysis(runId, controller.signal);
        if (controller.signal.aborted) return;
        setRun(next);
        setError(null);
        if (next.status === "pending" || next.status === "running")
          timeout = setTimeout(poll, 2500);
      } catch (err) {
        if (!controller.signal.aborted) {
          setError(err);
          if (
            err instanceof ApiServiceError &&
            [401, 403, 404].includes(err.status)
          ) {
            setRun(null);
            setPreview(null);
          } else {
            timeout = setTimeout(poll, 5000);
          }
        }
      }
    };
    timeout = setTimeout(poll, 1500);
    return () => {
      controller.abort();
      clearTimeout(timeout);
    };
  }, [runId, running]);

  useEffect(() => () => action.current?.abort(), []);
  const execute = async (retry = false) => {
    if (busy) return;
    const controller = new AbortController();
    action.current = controller;
    setBusy(true);
    setError(null);
    try {
      const next =
        retry && run
          ? await retryWorkAnalysis(run.id, controller.signal)
          : await createWorkAnalysis(filters, controller.signal);
      if (!controller.signal.aborted) setRun(next);
    } catch (err) {
      if (!controller.signal.aborted) setError(err);
    } finally {
      setBusy(false);
    }
  };
  const refresh = () => {
    setError(null);
    setLoading(true);
    setRevision((n) => n + 1);
  };
  const clear = () => {
    action.current?.abort();
    setLoading(true);
    setRun(null);
    setPreview(null);
    setError(null);
  };
  const errorText =
    error instanceof ApiServiceError
      ? Object.values(error.validationErrors ?? {})
          .flat()
          .join(" ") || (error.status > 0 ? error.message : null)
      : null;
  return {
    preview: loadedKey === requestKey ? preview : null,
    run: loadedKey === requestKey ? run : null,
    loading: loading || loadedKey !== requestKey,
    busy,
    running,
    error,
    errorText,
    execute,
    refresh,
    clear,
  };
}
