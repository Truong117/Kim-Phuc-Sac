import { useCallback, useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { Modal } from "@/components/ui/modal";
import Button from "@/components/ui/button/Button";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import { useModal } from "@/hooks/useModal";
import { useAuth } from "@/hooks/useAuth";
import { ApiServiceError } from "@/services/apiClient";
import {
  getAnalysisRegeneration,
  getWorkAnalysis,
  regenerateWorkAnalysis,
} from "@/services/workReportAnalysisService";
import {
  feedbackLength,
  readPendingRegeneration,
  type PendingRegeneration,
} from "@/utils/analysisRegeneration";
import { formatDateOnly } from "@/utils/dateOnly";
import type { WorkReportAnalysis } from "@/types/workReportAnalysis";

interface Props {
  run: WorkReportAnalysis | null;
  onSelect: (run: WorkReportAnalysis) => void;
}

const storedOperation = (key: string) => {
  try {
    return readPendingRegeneration(sessionStorage, key);
  } catch {
    return null;
  }
};

export default function WorkAnalysisRegenerate({ run, onSelect }: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "workReportAnalysis.regenerate",
  });
  const { currentUser } = useAuth();
  const storageKey = `kps-analysis-regeneration:${currentUser?.organization?.id}:${currentUser?.id}`;
  const [pending, setPending] = useState<PendingRegeneration | null>(() =>
    storedOperation(storageKey),
  );
  const [target, setTarget] = useState<WorkReportAnalysis | null>(null);
  const [summaryOnly, setSummaryOnly] = useState(false);
  const [feedback, setFeedback] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const guard = useRef(false);
  const action = useRef<AbortController | null>(null);
  useEffect(() => () => action.current?.abort(), []);
  const { isOpen, openModal, closeModal } = useModal();
  const onSelected = useRef(onSelect);
  useEffect(() => {
    onSelected.current = onSelect;
  }, [onSelect]);
  const forget = useCallback(() => {
    sessionStorage.removeItem(storageKey);
    setPending(null);
  }, [storageKey]);
  const recover = useCallback(
    async (operation: PendingRegeneration, signal?: AbortSignal) => {
      try {
        const result = await getAnalysisRegeneration(operation.key, signal);
        if (signal?.aborted) return;
        forget();
        closeModal();
        onSelected.current(result.data);
      } catch (err) {
        if (signal?.aborted) return;
        if (err instanceof ApiServiceError && err.status === 404) {
          // A dropped request may never have reached the server. Re-entering feedback uses the SAME key.
          // If the original commits later, the backend either replays it or returns a conflict, never a duplicate.
          try {
            const source = await getWorkAnalysis(operation.sourceId, signal);
            if (signal?.aborted) return;
            setTarget(source);
            setSummaryOnly(operation.summaryOnly ?? false);
            setNotice(t("reenter"));
            openModal();
          } catch {
            if (!signal?.aborted) setError(t("recoveryError"));
          }
        } else setError(t("recoveryError"));
      }
    },
    [closeModal, forget, openModal, t],
  );
  useEffect(() => {
    const operation = storedOperation(storageKey);
    if (!operation) return;
    const controller = new AbortController();
    const timer = setTimeout(
      () => void recover(operation, controller.signal),
      0,
    );
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [recover, storageKey]);
  const submit = async () => {
    if (!target || guard.current || feedbackLength(feedback) > 1000) return;
    guard.current = true;
    setBusy(true);
    setError(null);
    const controller = new AbortController();
    action.current = controller;
    try {
      const operation = pending ?? {
        key: crypto.randomUUID(),
        sourceId: target.id,
        summaryOnly,
      };
      // Fail before POST if recovery cannot be persisted. No feedback is stored here.
      try {
        sessionStorage.setItem(storageKey, JSON.stringify(operation));
      } catch {
        setError(t("storageError"));
        return;
      }
      setPending(operation);
      const result = await regenerateWorkAnalysis(
        operation.sourceId,
        operation.key,
        feedback,
        controller.signal,
        operation.summaryOnly ?? false,
      );
      if (controller.signal.aborted) return;
      forget();
      setFeedback("");
      setNotice(result.operation.reused_existing ? t("reused") : null);
      closeModal();
      onSelected.current(result.data);
    } catch (err) {
      if (controller.signal.aborted) return;
      if (err instanceof ApiServiceError && err.status === 422) forget();
      setError(
        err instanceof ApiServiceError && err.status > 0
          ? Object.values(err.validationErrors ?? {})
              .flat()
              .join(" ") || err.message
          : t("uncertain"),
      );
    } finally {
      guard.current = false;
      setBusy(false);
    }
  };
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-3">
        {run?.can_regenerate && (
          <Button
            variant="outline"
            disabled={busy || pending !== null}
            onClick={() => {
              setTarget(run);
              setSummaryOnly(false);
              setFeedback("");
              setError(null);
              setNotice(null);
              openModal();
            }}
          >
            {t("button")}
          </Button>
        )}
        {run?.can_regenerate_summary && (
          <Button
            variant="outline"
            disabled={busy || pending !== null}
            onClick={() => {
              setTarget(run);
              setSummaryOnly(true);
              setFeedback("");
              setError(null);
              setNotice(null);
              openModal();
            }}
          >
            {t("summaryButton")}
          </Button>
        )}
        {pending && (
          <Button
            variant="outline"
            disabled={busy}
            onClick={() => void recover(pending)}
          >
            {t("recover")}
          </Button>
        )}
      </div>
      {notice && !isOpen && (
        <p role="status" className="text-sm text-gray-600 dark:text-gray-400">
          {notice}
        </p>
      )}
      {error && !isOpen && (
        <p role="alert" className="text-sm text-error-600 dark:text-error-400">
          {error}
        </p>
      )}
      <Modal
        isOpen={isOpen}
        onClose={() => {
          if (!busy) closeModal();
        }}
        ariaLabelledBy="regenerate-title"
        showCloseButton={false}
        className="max-h-dvh max-w-xl overflow-y-auto p-6"
      >
        <div className="space-y-4 text-sm text-gray-700 dark:text-gray-300">
          <h2
            id="regenerate-title"
            className="text-lg font-semibold text-gray-900 dark:text-white"
          >
            {t(summaryOnly ? "summaryButton" : "button")}
          </h2>
          {target && (
            <p>
              {t("snapshot", {
                start: formatDateOnly(target.period.start_date),
                end: formatDateOnly(target.period.end_date),
                scope: target.department_id
                  ? t("department", { id: target.department_id })
                  : t("scope"),
                time: new Date(target.captured_at).toLocaleString("vi-VN", {
                  timeZone: "Asia/Ho_Chi_Minh",
                }),
              })}
            </p>
          )}
          {target && (
            <p>
              {target.scope.restrictions
                .map(([kind, id]) =>
                  t("restriction", {
                    kind: t(`scopeKind.${kind}`),
                    id: id ?? "—",
                  }),
                )
                .join(" · ")}
            </p>
          )}
          <p>{t(summaryOnly ? "summaryPreserved" : "preserved")}</p>
          {target?.source_changed && (
            <p className="text-warning-700 dark:text-warning-300">
              {t("changed")}
            </p>
          )}
          <p>{t("cost")}</p>
          {notice && <p role="status">{notice}</p>}
          <div>
            <Label htmlFor="regenerate-feedback">{t("feedback")}</Label>
            <TextArea
              id="regenerate-feedback"
              value={feedback}
              onChange={setFeedback}
              disabled={busy}
              rows={4}
              placeholder={t("example")}
              error={feedbackLength(feedback) > 1000}
              hint={t("limit", { count: feedbackLength(feedback) })}
            />
            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
              {t("focusOnly")}
            </p>
          </div>
          {error && (
            <p role="alert" className="text-error-600 dark:text-error-400">
              {error}
            </p>
          )}
          <div className="flex flex-wrap gap-3">
            <Button
              disabled={busy || feedbackLength(feedback) > 1000}
              onClick={() => void submit()}
            >
              {t(busy ? "creating" : "confirm")}
            </Button>
            <Button variant="outline" disabled={busy} onClick={closeModal}>
              {t("close")}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
