import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import { CheckCircleIcon, ChevronDownIcon } from "@/icons";
import type { WeeklyPlanAiDetailSuggestions } from "@/types/weeklyPlanning";
import type {
  GoogleDocsAiReviewChanges,
  GoogleDocsAiReviewDraft,
  GoogleDocsAiReviewErrors,
  GoogleDocsAiReviewItem,
  GoogleDocsAiReviewPriority,
} from "@/utils/weeklyPlanGoogleDocsAiReview";
import { useTranslation } from "react-i18next";

type Props = {
  draft: GoogleDocsAiReviewDraft;
  index: number;
  displayIndex: number;
  errors: GoogleDocsAiReviewErrors;
  expanded: boolean;
  busy: boolean;
  applying: boolean;
  onToggle: () => void;
  onChange: (changes: GoogleDocsAiReviewChanges) => void;
  onItemChange: (
    itemIndex: number,
    changes: Partial<Pick<GoogleDocsAiReviewItem, "title" | "content">>,
  ) => void;
  onAddItem: () => void;
  onRemoveItem: (itemIndex: number) => void;
  onMoveItem: (itemIndex: number, direction: -1 | 1) => void;
  onSplit: () => void;
};

const detailSuggestionKeys = [
  "channel",
  "account",
  "frequency",
  "format",
  "content_direction",
  "objective",
  "notes",
] as const satisfies readonly (keyof WeeklyPlanAiDetailSuggestions)[];

export default function WeeklyPlanGoogleDocsAiReviewJobRow({
  draft,
  index,
  displayIndex,
  errors,
  expanded,
  busy,
  applying,
  onToggle,
  onChange,
  onItemChange,
  onAddItem,
  onRemoveItem,
  onMoveItem,
  onSplit,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.sourceDocument.aiPreview",
  });
  const errorFor = (field: keyof GoogleDocsAiReviewErrors) => {
    const key = errors[field];
    return key ? t(`googleDocs.validation.${key}`) : undefined;
  };
  const hasError = draft.included && Object.keys(errors).length > 0;
  const regionId = `google-ai-job-${index}`;
  const details = detailSuggestionKeys.flatMap((key) => {
    const detail = draft.detailSuggestions?.[key];
    return detail ? [{ key, detail }] : [];
  });

  return (
    <article
      className={`overflow-hidden rounded-lg border ${
        hasError
          ? "border-error-300 dark:border-error-800"
          : draft.included
            ? "border-kps-primary/25 dark:border-kps-primary/35"
            : "border-gray-200 opacity-70 dark:border-gray-700"
      }`}
    >
      <div className="grid items-center gap-3 bg-gray-50 px-3 py-2.5 md:grid-cols-12 dark:bg-gray-800/60">
        <span className="flex size-8 items-center justify-center rounded-full bg-kps-primary/10 text-theme-xs font-bold text-kps-primary md:col-span-1 dark:bg-kps-primary/20 dark:text-sidebar-selected">
          {displayIndex}
        </span>
        <div className="min-w-0 md:col-span-6">
          <p className="truncate text-theme-sm font-semibold text-gray-900 dark:text-white">
            {draft.title || t("googleDocs.untitledJob")}
          </p>
          {draft.items.length > 0 && (
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              {t("googleDocs.itemCount", { count: draft.items.length })}
            </p>
          )}
        </div>
        <div className="md:col-span-3">
          <Label htmlFor={`google-ai-priority-${index}`} className="sr-only">
            {t("fields.priority")}
          </Label>
          <Select
            id={`google-ai-priority-${index}`}
            value={draft.priority}
            disabled={busy || applying || !draft.included}
            className="h-9 py-1.5"
            options={[
              { value: "MEDIUM", label: t("googleDocs.priorityNone") },
              { value: "HIGH", label: t("googleDocs.priorityHigh") },
            ]}
            onChange={(priority) =>
              onChange({ priority: priority as GoogleDocsAiReviewPriority })
            }
          />
        </div>
        <label className="flex items-center gap-2 text-theme-xs font-medium text-gray-700 md:col-span-1 dark:text-gray-300">
          <input
            type="checkbox"
            checked={draft.included}
            disabled={busy || applying}
            className="size-5 rounded border-gray-300 text-kps-primary focus:ring-kps-primary/30 dark:border-gray-600 dark:bg-gray-900"
            onChange={(event) => onChange({ included: event.target.checked })}
          />
          {t("googleDocs.include")}
        </label>
        <button
          type="button"
          className="flex size-9 items-center justify-center justify-self-end rounded-lg text-gray-500 transition hover:bg-kps-primary/10 hover:text-kps-primary focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none md:col-span-1 dark:text-gray-400 dark:hover:bg-kps-primary/20 dark:hover:text-sidebar-selected"
          aria-expanded={expanded}
          aria-controls={regionId}
          aria-label={t("googleDocs.toggleJob", { title: draft.title })}
          onClick={onToggle}
        >
          <ChevronDownIcon
            aria-hidden="true"
            className={`size-5 transition-transform ${
              expanded ? "rotate-180" : ""
            }`}
          />
        </button>
      </div>

      {expanded && (
        <div
          id={regionId}
          className="space-y-4 border-t border-gray-200 p-4 dark:border-gray-700"
        >
          <div>
            <Label htmlFor={`google-ai-title-${index}`}>
              {t("fields.title")}
            </Label>
            <Input
              id={`google-ai-title-${index}`}
              value={draft.title}
              maxLength={255}
              disabled={busy || applying || !draft.included}
              error={Boolean(errorFor("title"))}
              hint={errorFor("title")}
              onChange={(event) => onChange({ title: event.target.value })}
            />
          </div>
          <div className="grid items-stretch gap-4 md:grid-cols-2">
            <div className="flex h-full flex-col">
              <Label htmlFor={`google-ai-content-${index}`}>
                {t("fields.content")}
              </Label>
              <TextArea
                id={`google-ai-content-${index}`}
                rows={3}
                containerClassName="flex flex-1"
                className="h-full min-h-32"
                value={draft.content}
                disabled={busy || applying || !draft.included}
                onChange={(content) => onChange({ content })}
              />
            </div>
            <div className="flex h-full flex-col">
              <Label htmlFor={`google-ai-result-${index}`}>
                {t("fields.expected_result")}
              </Label>
              <TextArea
                id={`google-ai-result-${index}`}
                rows={3}
                containerClassName="flex flex-1"
                className="h-full min-h-32"
                value={draft.expectedResult}
                disabled={busy || applying || !draft.included}
                onChange={(expectedResult) => onChange({ expectedResult })}
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
              disabled={busy || applying || !draft.included}
              onChange={(note) => onChange({ note })}
            />
          </div>

          <div className="space-y-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-theme-sm font-semibold text-gray-900 dark:text-white">
                  {t("googleDocs.itemsTitle")}
                </p>
                <span className="rounded-full bg-kps-primary/10 px-2 py-0.5 text-theme-xs font-semibold text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected">
                  {t(`googleDocs.groupingBasis.${draft.groupingBasis}`)}
                </span>
              </div>
              <div className="flex flex-wrap gap-2">
                {draft.groupingBasis !== "ATOMIC" && draft.items.length > 0 && (
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={busy || applying || !draft.included}
                    onClick={onSplit}
                  >
                    {t("googleDocs.splitGroup")}
                  </Button>
                )}
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={busy || applying || !draft.included}
                  onClick={onAddItem}
                >
                  {t("googleDocs.addItem")}
                </Button>
              </div>
            </div>
            {draft.items.length === 0 ? (
              <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                {t("googleDocs.noItems")}
              </p>
            ) : (
              <div className="space-y-3">
                {draft.items.map((item, itemIndex) => (
                  <div
                    key={`${draft.key}:item:${itemIndex}`}
                    className="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60"
                  >
                    <div className="grid gap-3 md:grid-cols-2">
                      <div>
                        <Label
                          htmlFor={`google-ai-item-title-${index}-${itemIndex}`}
                        >
                          {t("googleDocs.itemTitle", { index: itemIndex + 1 })}
                        </Label>
                        <Input
                          id={`google-ai-item-title-${index}-${itemIndex}`}
                          value={item.title}
                          maxLength={255}
                          disabled={busy || applying || !draft.included}
                          onChange={(event) =>
                            onItemChange(itemIndex, {
                              title: event.target.value,
                            })
                          }
                        />
                      </div>
                      <div>
                        <Label
                          htmlFor={`google-ai-item-content-${index}-${itemIndex}`}
                        >
                          {t("googleDocs.itemContent")}
                        </Label>
                        <TextArea
                          id={`google-ai-item-content-${index}-${itemIndex}`}
                          rows={2}
                          value={item.content ?? ""}
                          disabled={busy || applying || !draft.included}
                          onChange={(content) =>
                            onItemChange(itemIndex, { content })
                          }
                        />
                      </div>
                    </div>
                    <div className="mt-2 flex flex-wrap justify-end gap-2">
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={busy || applying || itemIndex === 0}
                        onClick={() => onMoveItem(itemIndex, -1)}
                      >
                        {t("googleDocs.moveItemUp")}
                      </Button>
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={
                          busy ||
                          applying ||
                          itemIndex === draft.items.length - 1
                        }
                        onClick={() => onMoveItem(itemIndex, 1)}
                      >
                        {t("googleDocs.moveItemDown")}
                      </Button>
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={busy || applying}
                        onClick={() => onRemoveItem(itemIndex)}
                      >
                        {t("googleDocs.removeItem")}
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {details.length > 0 && (
            <div className="rounded-lg border border-kps-primary/15 bg-kps-primary/5 p-4 dark:border-kps-primary/25 dark:bg-kps-primary/10">
              <p className="flex items-center gap-2 text-theme-sm font-semibold text-kps-primary dark:text-sidebar-selected">
                <CheckCircleIcon aria-hidden="true" className="size-5" />
                {t("googleDocs.detailSuggestions.title")}
              </p>
              <dl className="mt-3 space-y-2">
                {details.map(({ key, detail }) => (
                  <div
                    key={key}
                    className="grid gap-1 text-theme-sm sm:grid-cols-3"
                  >
                    <dt className="font-medium text-gray-700 dark:text-gray-300">
                      {t(`googleDocs.detailSuggestions.fields.${key}`)}
                    </dt>
                    <dd className="text-gray-600 sm:col-span-2 dark:text-gray-300">
                      {detail.value}
                      <span className="ms-2 inline-flex rounded-full bg-white px-2 py-0.5 text-theme-xs font-semibold text-kps-primary dark:bg-gray-900 dark:text-sidebar-selected">
                        {t(
                          `googleDocs.detailSuggestions.basis.${detail.basis}`,
                        )}
                      </span>
                    </dd>
                  </div>
                ))}
              </dl>
            </div>
          )}

          {(errorFor("assignee_id") ||
            errorFor("source_tab") ||
            errorFor("source_passage_ids") ||
            errorFor("items")) && (
            <p className="text-theme-xs text-error-600 dark:text-error-400">
              {errorFor("assignee_id") ??
                errorFor("source_tab") ??
                errorFor("source_passage_ids") ??
                errorFor("items")}
            </p>
          )}
        </div>
      )}
    </article>
  );
}
