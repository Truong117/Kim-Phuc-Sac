import { useEffect, useRef, useState } from "react";
import {
  getFacebookMonthlyReport,
  closeFacebookMonthlyReport,
} from "@/services/facebookAnalyticsService";
import { ApiServiceError, type ValidationErrors } from "@/services/apiClient";
import type {
  FacebookMonthlyReportData,
  FacebookReviewAction,
} from "@/types/facebookAnalytics";

export function useFacebookMonthlyReport(
  id: number,
  month: string,
  live: boolean,
) {
  const [report, setReport] = useState<FacebookMonthlyReportData | null>(null);
  const [notes, setNotes] = useState("");
  const [actions, setActions] = useState<FacebookReviewAction[]>([]);
  const [ack, setAck] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState<
    "conflict" | "uncertain" | "validation" | null
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
    let current = true;
    void getFacebookMonthlyReport(
      id,
      month,
      live,
      AbortSignal.any([controller.signal, AbortSignal.timeout(45000)]),
    )
      .then(({ data }) => {
        if (current) {
          setReport(data);
          setFailed(false);
          setError(null);
          if (data.status === "closed") {
            setNotes("");
            setActions([]);
            setAck(false);
          }
        }
      })
      .catch((e: unknown) => {
        if (current) {
          setFailed(true);
          if (
            e instanceof ApiServiceError &&
            [401, 403, 404].includes(e.status)
          ) {
            setReport(null);
            setNotes("");
            setActions([]);
            setAck(false);
          }
        }
      })
      .finally(() => {
        if (current) setLoading(false);
      });
    return () => {
      current = false;
      controller.abort();
    };
  }, [id, month, live, revision]);
  async function close() {
    if (
      !report ||
      saving ||
      !report.can_close ||
      failed ||
      loading ||
      error === "conflict" ||
      error === "uncertain"
    )
      return;
    const controller = new AbortController();
    write.current = controller;
    setSaving(true);
    setErrors({});
    setError(null);
    try {
      const saved = await closeFacebookMonthlyReport(
        id,
        {
          month,
          entry_version: report.entry_version,
          source_hash: report.source_hash,
          notes,
          actions,
          acknowledge_limits: ack,
        },
        AbortSignal.any([controller.signal, AbortSignal.timeout(45000)]),
      );
      if (active.current) {
        setReport(saved.data);
        setNotes("");
        setActions([]);
        setAck(false);
      }
    } catch (e) {
      if (active.current) {
        if (
          e instanceof ApiServiceError &&
          [401, 403, 404].includes(e.status)
        ) {
          setReport(null);
          setNotes("");
          setActions([]);
          setAck(false);
        }
        setError(
          e instanceof ApiServiceError
            ? e.status === 409
              ? "conflict"
              : e.status === 422
                ? "validation"
                : "uncertain"
            : "uncertain",
        );
        setErrors(
          e instanceof ApiServiceError ? (e.validationErrors ?? {}) : {},
        );
      }
    } finally {
      if (active.current) setSaving(false);
    }
  }
  return {
    report,
    notes,
    setNotes,
    actions,
    setActions,
    ack,
    setAck,
    loading,
    saving,
    failed,
    error,
    errors,
    close,
    refresh: () => {
      setLoading(true);
      setRevision((n) => n + 1);
    },
    dirty:
      saving ||
      (report?.status === "preview" &&
        (notes !== "" || actions.length > 0 || ack)),
  };
}
