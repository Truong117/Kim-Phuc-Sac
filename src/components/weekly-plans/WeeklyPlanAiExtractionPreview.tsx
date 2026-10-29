import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import WeeklyPlanAiPreviewErrorBoundary from "@/components/weekly-plans/WeeklyPlanAiPreviewErrorBoundary";
import WeeklyPlanGoogleDocsAiReview from "@/components/weekly-plans/WeeklyPlanGoogleDocsAiReview";
import type {
  WeeklyPlanAiSuggestion,
  WeeklyPlanSourceEntryInput,
  WeeklyPlanSourcePassage,
} from "@/types/weeklyPlanning";
import { formatDateOnly } from "@/utils/dateOnly";
import { useTranslation } from "react-i18next";

type Props = {
  isOpen: boolean;
  suggestions: WeeklyPlanAiSuggestion[];
  passages: WeeklyPlanSourcePassage[];
  sourceType: "UPLOADED_FILE" | "GOOGLE_DOCS";
  weekStart: string;
  weekEnd: string;
  reviewVersion: number;
  busy: boolean;
  onClose: () => void;
  onRetry: () => void;
  onReview: (
    suggestion: WeeklyPlanAiSuggestion,
    passageIds: number[],
    index: number,
  ) => void;
  onApplyAll: (
    requestId: string,
    entries: WeeklyPlanSourceEntryInput[],
  ) => Promise<{
    succeeded: boolean;
    validationErrors?: Record<string, string[]>;
  }>;
  onApplied: () => void;
};

export default function WeeklyPlanAiExtractionPreview({
  isOpen,
  suggestions,
  passages,
  sourceType,
  weekStart,
  weekEnd,
  reviewVersion,
  busy,
  onClose,
  onRetry,
  onReview,
  onApplyAll,
  onApplied,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.sourceDocument.aiPreview",
  });
  const passagesByKey = new Map(
    passages.map((passage) => [passage.source_key, passage]),
  );

  return (
    <Modal
      isOpen={isOpen}
      onClose={() => {
        if (!busy) onClose();
      }}
      className={`max-h-[90vh] overflow-y-auto p-6 ${
        sourceType === "GOOGLE_DOCS" ? "max-w-6xl" : "max-w-4xl"
      }`}
      ariaLabelledBy="weekly-ai-preview-title"
    >
      <h2
        id="weekly-ai-preview-title"
        className="text-lg font-semibold text-gray-900 dark:text-white"
      >
        {sourceType === "GOOGLE_DOCS" ? t("googleDocs.title") : t("title")}
      </h2>
      <p className="mt-2 text-theme-sm text-gray-500 dark:text-gray-400">
        {sourceType === "GOOGLE_DOCS"
          ? t("googleDocs.description")
          : t("description")}
      </p>
      {sourceType !== "GOOGLE_DOCS" && (
        <p className="mt-3 rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
          {t("verificationWarning")}
        </p>
      )}

      {suggestions.length === 0 ? (
        <p className="mt-5 rounded-xl bg-gray-50 px-4 py-8 text-center text-theme-sm text-gray-500 dark:bg-gray-800/50 dark:text-gray-400">
          {t("empty")}
        </p>
      ) : sourceType === "GOOGLE_DOCS" ? (
        <WeeklyPlanAiPreviewErrorBoundary
          key={`google-docs-ai-review-${reviewVersion}`}
          title={t("renderError.title")}
          description={t("renderError.description")}
          closeLabel={t("close")}
          retryLabel={t("renderError.retry")}
          onClose={onClose}
          onRetry={onRetry}
        >
          <WeeklyPlanGoogleDocsAiReview
            suggestions={suggestions}
            passages={passages}
            weekStart={weekStart}
            weekEnd={weekEnd}
            busy={busy}
            onApplyAll={onApplyAll}
            onApplied={onApplied}
          />
        </WeeklyPlanAiPreviewErrorBoundary>
      ) : (
        <div className="mt-5 space-y-4">
          {suggestions.map((suggestion, index) => {
            const passageIds = suggestion.source_keys
              .map((key) => passagesByKey.get(key)?.id)
              .filter((id): id is number => id !== undefined);

            return (
              <article
                key={`${suggestion.source_keys.join(":")}:${index}`}
                className="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
              >
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div className="min-w-0">
                    <h3 className="font-semibold text-gray-900 dark:text-white">
                      {suggestion.title}
                    </h3>
                    <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                      {suggestion.employee_identifier
                        ? t("employeeHint", {
                            identifier: suggestion.employee_identifier,
                          })
                        : t("employeeMissing")}
                    </p>
                  </div>
                  <Button
                    type="button"
                    size="sm"
                    disabled={busy || passageIds.length === 0}
                    className="bg-kps-primary hover:bg-kps-primary-hover"
                    onClick={() => onReview(suggestion, passageIds, index)}
                  >
                    {t("review")}
                  </Button>
                </div>

                <dl className="mt-4 grid gap-3 text-theme-sm sm:grid-cols-2">
                  <div>
                    <dt className="font-medium text-gray-700 dark:text-gray-300">
                      {t("content")}
                    </dt>
                    <dd className="mt-1 whitespace-pre-wrap text-gray-600 dark:text-gray-400">
                      {suggestion.content || t("notProvided")}
                    </dd>
                  </div>
                  <div>
                    <dt className="font-medium text-gray-700 dark:text-gray-300">
                      {t("expectedResult")}
                    </dt>
                    <dd className="mt-1 whitespace-pre-wrap text-gray-600 dark:text-gray-400">
                      {suggestion.expected_result || t("notProvided")}
                    </dd>
                  </div>
                  <div>
                    <dt className="font-medium text-gray-700 dark:text-gray-300">
                      {t("period")}
                    </dt>
                    <dd className="mt-1 text-gray-600 dark:text-gray-400">
                      {suggestion.start_date
                        ? formatDateOnly(suggestion.start_date)
                        : t("notProvided")}{" "}
                      –{" "}
                      {suggestion.due_date
                        ? formatDateOnly(suggestion.due_date)
                        : t("notProvided")}
                    </dd>
                  </div>
                  <div>
                    <dt className="font-medium text-gray-700 dark:text-gray-300">
                      {t("priority")}
                    </dt>
                    <dd className="mt-1 text-gray-600 dark:text-gray-400">
                      {suggestion.priority
                        ? t("priorityValues." + suggestion.priority)
                        : t("notProvided")}
                    </dd>
                  </div>
                </dl>

                <div className="mt-4 rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-800/60">
                  <p className="text-theme-xs font-medium text-gray-700 dark:text-gray-300">
                    {t("evidence")}
                  </p>
                  <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    {suggestion.evidence}
                  </p>
                  <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                    {t("sources", {
                      sources: suggestion.source_keys.join(", "),
                    })}
                  </p>
                  <ul className="mt-2 space-y-2">
                    {suggestion.source_keys.map((sourceKey) => {
                      const passage = passagesByKey.get(sourceKey);

                      return (
                        <li
                          key={sourceKey}
                          className="rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
                        >
                          <p className="text-theme-xs font-medium text-gray-700 dark:text-gray-300">
                            {t("sourceExcerpt", { sourceKey })}
                          </p>
                          <p className="mt-1 max-h-24 overflow-y-auto text-theme-xs whitespace-pre-wrap text-gray-500 dark:text-gray-400">
                            {passage?.text ?? t("sourceUnavailable")}
                          </p>
                        </li>
                      );
                    })}
                  </ul>
                  {suggestion.verification_fields.length > 0 && (
                    <p className="mt-2 text-theme-xs text-warning-700 dark:text-warning-400">
                      {t("verificationFields", {
                        fields: suggestion.verification_fields
                          .map((field) => t("fields." + field))
                          .join(", "),
                      })}
                    </p>
                  )}
                  <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                    {t("provenance", {
                      values: Object.entries(suggestion.provenance)
                        .map(
                          ([field, origin]) =>
                            `${t("fields." + field)}: ${t("origins." + origin)}`,
                        )
                        .join(" · "),
                    })}
                  </p>
                </div>
              </article>
            );
          })}
        </div>
      )}

      <div className="mt-6 flex justify-end">
        <Button
          type="button"
          variant="outline"
          disabled={busy}
          onClick={onClose}
        >
          {t("close")}
        </Button>
      </div>
    </Modal>
  );
}
