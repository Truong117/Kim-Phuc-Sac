import KpsDatePicker from "@/components/common/KpsDatePicker";
import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import type { TaskPriority } from "@/types/tasks";
import type {
  WeeklyPlanAiSuggestion,
  WeeklyPlanSourceEntryInput,
  WeeklyPlanSourcePassage,
} from "@/types/weeklyPlanning";
import {
  buildGoogleDocsAiApplyAllEntries,
  createGoogleDocsAiReviewDrafts,
  submitGoogleDocsAiApplyAll,
  updateGoogleDocsAiReviewDraft,
} from "@/utils/weeklyPlanGoogleDocsAiReview";
import { useMemo, useState } from "react";
import { useTranslation } from "react-i18next";

type Props = {
  suggestions: WeeklyPlanAiSuggestion[];
  passages: WeeklyPlanSourcePassage[];
  weekStart: string;
  weekEnd: string;
  busy: boolean;
  onApplyAll: (
    requestId: string,
    entries: WeeklyPlanSourceEntryInput[],
  ) => Promise<{
    succeeded: boolean;
    validationErrors?: Record<string, string[]>;
  }>;
  onApplied: () => void;
};

const requestId = () => crypto.randomUUID();

export default function WeeklyPlanGoogleDocsAiReview({
  suggestions,
  passages,
  weekStart,
  weekEnd,
  busy,
  onApplyAll,
  onApplied,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.sourceDocument.aiPreview",
  });
  const [drafts, setDrafts] = useState(() =>
    createGoogleDocsAiReviewDrafts(suggestions, passages),
  );
  const [applying, setApplying] = useState(false);
  const [applyError, setApplyError] = useState<string | null>(null);
  const passagesByKey = useMemo(
    () => new Map(passages.map((passage) => [passage.source_key, passage])),
    [passages],
  );

  const built = useMemo(
    () => buildGoogleDocsAiApplyAllEntries(drafts, weekStart, weekEnd),
    [drafts, weekEnd, weekStart],
  );
  const isComplete = built.entries !== null;
  const canApply = isComplete && !busy && !applying;
  const errorFor = (index: number, field: string) => {
    const key =
      built.errors[index]?.[field as keyof (typeof built.errors)[number]];
    return key ? t(`googleDocs.validation.${key}`) : undefined;
  };

  const applyAll = async () => {
    if (!canApply) return;
    setApplying(true);
    setApplyError(null);
    try {
      const submission = await submitGoogleDocsAiApplyAll(
        onApplyAll,
        requestId(),
        drafts,
        weekStart,
        weekEnd,
      );
      if (!submission.submitted) return;
      if (submission.result.succeeded) {
        onApplied();
        return;
      }

      const serverMessage = Object.values(
        submission.result.validationErrors ?? {},
      )
        .flat()
        .find((message) => message.trim() !== "");
      setApplyError(serverMessage ?? t("googleDocs.applyConflict"));
    } catch (error) {
      setApplyError(
        error instanceof Error ? error.message : t("googleDocs.applyFailed"),
      );
    } finally {
      setApplying(false);
    }
  };

  return (
    <>
      <p className="mt-3 rounded-lg bg-success-50 px-3 py-2 text-theme-xs text-success-700 dark:bg-success-500/10 dark:text-success-400">
        {t("googleDocs.assignmentAuthority")}
      </p>

      {applyError && (
        <p
          role="alert"
          className="mt-4 rounded-lg bg-error-50 px-3 py-2 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
        >
          {applyError}
        </p>
      )}

      <div className="mt-5 space-y-5">
        {drafts.map((draft, index) => (
          <article
            key={draft.key}
            className="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
          >
            <div className="flex flex-col gap-3 rounded-lg bg-gray-50 p-3 sm:flex-row sm:items-start sm:justify-between dark:bg-gray-800/60">
              <div>
                <p className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  {t("googleDocs.tab")}
                </p>
                <p className="mt-1 font-semibold text-gray-900 dark:text-white">
                  {draft.sourceTab?.title ?? t("sourceUnavailable")}
                </p>
              </div>
              <div className="sm:text-end">
                <p className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  {t("googleDocs.employee")}
                </p>
                <p className="mt-1 font-semibold text-gray-900 dark:text-white">
                  {draft.assignee?.name ?? t("employeeMissing")}
                </p>
                {draft.assignee?.email && (
                  <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                    {draft.assignee.email}
                  </p>
                )}
              </div>
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <span className="rounded-full bg-success-50 px-2.5 py-1 text-theme-xs font-semibold text-success-700 dark:bg-success-500/10 dark:text-success-400">
                {t("googleDocs.verifiedBadge")}
              </span>
              <span className="text-theme-xs text-gray-500 dark:text-gray-400">
                {t("googleDocs.assigneeLocked")}
              </span>
            </div>
            {(errorFor(index, "assignee_id") ||
              errorFor(index, "source_tab")) && (
              <p className="mt-2 text-theme-xs text-error-600 dark:text-error-400">
                {errorFor(index, "assignee_id") ??
                  errorFor(index, "source_tab")}
              </p>
            )}

            <div className="mt-5 space-y-4">
              <div>
                <Label htmlFor={`google-ai-title-${index}`}>
                  {t("fields.title")}
                </Label>
                <Input
                  id={`google-ai-title-${index}`}
                  value={draft.title}
                  maxLength={255}
                  disabled={busy || applying}
                  error={Boolean(errorFor(index, "title"))}
                  hint={errorFor(index, "title")}
                  onChange={(event) =>
                    setDrafts((current) =>
                      updateGoogleDocsAiReviewDraft(current, index, {
                        title: event.target.value,
                      }),
                    )
                  }
                />
              </div>
              <div className="grid gap-4 md:grid-cols-2">
                <div>
                  <Label htmlFor={`google-ai-content-${index}`}>
                    {t("fields.content")}
                  </Label>
                  <TextArea
                    id={`google-ai-content-${index}`}
                    rows={3}
                    value={draft.content}
                    disabled={busy || applying}
                    onChange={(content) =>
                      setDrafts((current) =>
                        updateGoogleDocsAiReviewDraft(current, index, {
                          content,
                        }),
                      )
                    }
                  />
                </div>
                <div>
                  <Label htmlFor={`google-ai-result-${index}`}>
                    {t("fields.expected_result")}
                  </Label>
                  <TextArea
                    id={`google-ai-result-${index}`}
                    rows={3}
                    value={draft.expectedResult}
                    disabled={busy || applying}
                    onChange={(expectedResult) =>
                      setDrafts((current) =>
                        updateGoogleDocsAiReviewDraft(current, index, {
                          expectedResult,
                        }),
                      )
                    }
                  />
                </div>
              </div>
              <div className="grid gap-4 md:grid-cols-3">
                <div>
                  <Label htmlFor={`google-ai-start-${index}`}>
                    {t("fields.start_date")}
                  </Label>
                  <KpsDatePicker
                    id={`google-ai-start-${index}`}
                    value={draft.startDate}
                    minDate={weekStart}
                    maxDate={weekEnd}
                    disabled={busy || applying}
                    required
                    ariaLabel={t("fields.start_date")}
                    placeholder={t("googleDocs.chooseDate")}
                    error={Boolean(errorFor(index, "start_date"))}
                    hint={errorFor(index, "start_date")}
                    onChange={(startDate) =>
                      setDrafts((current) =>
                        updateGoogleDocsAiReviewDraft(current, index, {
                          startDate,
                        }),
                      )
                    }
                  />
                </div>
                <div>
                  <Label htmlFor={`google-ai-due-${index}`}>
                    {t("fields.due_date")}
                  </Label>
                  <KpsDatePicker
                    id={`google-ai-due-${index}`}
                    value={draft.dueDate}
                    minDate={draft.startDate || weekStart}
                    maxDate={weekEnd}
                    disabled={busy || applying}
                    required
                    ariaLabel={t("fields.due_date")}
                    placeholder={t("googleDocs.chooseDate")}
                    error={Boolean(errorFor(index, "due_date"))}
                    hint={errorFor(index, "due_date")}
                    onChange={(dueDate) =>
                      setDrafts((current) =>
                        updateGoogleDocsAiReviewDraft(current, index, {
                          dueDate,
                        }),
                      )
                    }
                  />
                </div>
                <div>
                  <Label htmlFor={`google-ai-priority-${index}`}>
                    {t("fields.priority")}
                  </Label>
                  <Select
                    id={`google-ai-priority-${index}`}
                    value={draft.priority}
                    allowClear
                    disabled={busy || applying}
                    placeholder={t("googleDocs.priorityPlaceholder")}
                    error={Boolean(errorFor(index, "priority"))}
                    hint={errorFor(index, "priority")}
                    options={(
                      ["LOW", "MEDIUM", "HIGH", "URGENT"] as TaskPriority[]
                    ).map((priority) => ({
                      value: priority,
                      label: t(`priorityValues.${priority}`),
                    }))}
                    onChange={(priority) =>
                      setDrafts((current) =>
                        updateGoogleDocsAiReviewDraft(current, index, {
                          priority: priority as TaskPriority | "",
                        }),
                      )
                    }
                  />
                </div>
              </div>
              <div>
                <Label htmlFor={`google-ai-note-${index}`}>
                  {t("fields.note")}
                </Label>
                <TextArea
                  id={`google-ai-note-${index}`}
                  rows={2}
                  value={draft.note}
                  disabled={busy || applying}
                  onChange={(note) =>
                    setDrafts((current) =>
                      updateGoogleDocsAiReviewDraft(current, index, { note }),
                    )
                  }
                />
              </div>
            </div>

            <div className="mt-5 rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-800/60">
              <p className="text-theme-xs font-medium text-gray-700 dark:text-gray-300">
                {t("evidence")}
              </p>
              <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                {draft.evidence}
              </p>
              <ul className="mt-3 space-y-2">
                {draft.sourceKeys.map((sourceKey) => (
                  <li
                    key={sourceKey}
                    className="rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
                  >
                    <p className="text-theme-xs font-medium text-gray-700 dark:text-gray-300">
                      {t("sourceExcerpt", { sourceKey })}
                    </p>
                    <p className="mt-1 max-h-24 overflow-y-auto text-theme-xs whitespace-pre-wrap text-gray-500 dark:text-gray-400">
                      {passagesByKey.get(sourceKey)?.text ??
                        t("sourceUnavailable")}
                    </p>
                  </li>
                ))}
              </ul>
              {errorFor(index, "source_passage_ids") && (
                <p className="mt-2 text-theme-xs text-error-600 dark:text-error-400">
                  {errorFor(index, "source_passage_ids")}
                </p>
              )}
              {draft.verificationFields.length > 0 && (
                <p className="mt-2 text-theme-xs text-warning-700 dark:text-warning-400">
                  {t("verificationFields", {
                    fields: draft.verificationFields
                      .map((field) => t(`fields.${field}`))
                      .join(", "),
                  })}
                </p>
              )}
              <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                {t("provenance", {
                  values: Object.entries(draft.provenance)
                    .map(
                      ([field, origin]) =>
                        `${t(`fields.${field}`)}: ${t(`origins.${origin}`)}`,
                    )
                    .join(" · "),
                })}
              </p>
            </div>
          </article>
        ))}
      </div>

      <div className="mt-6 flex flex-col items-end gap-2">
        {!isComplete && drafts.length > 0 && (
          <p className="text-theme-xs text-warning-700 dark:text-warning-400">
            {t("googleDocs.completeRequired")}
          </p>
        )}
        <Button
          type="button"
          disabled={!canApply}
          className="bg-kps-primary hover:bg-kps-primary-hover"
          onClick={() => void applyAll()}
        >
          {applying ? t("googleDocs.applying") : t("googleDocs.applyAll")}
        </Button>
      </div>
    </>
  );
}
