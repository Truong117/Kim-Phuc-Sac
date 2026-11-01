import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import { ChevronDownIcon, PlusIcon, TrashBinIcon } from "@/icons";
import type { WeeklyPlanAiDetailSuggestions } from "@/types/weeklyPlanning";
import type {
  GoogleDocsAiReviewChanges,
  GoogleDocsAiReviewDraft,
  GoogleDocsAiReviewErrors,
  GoogleDocsAiReviewItem,
  GoogleDocsAiReviewPriority,
} from "@/utils/weeklyPlanGoogleDocsAiReview";
import { useState } from "react";
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

const primaryDetailKeys = [
  "channel",
  "account",
  "frequency",
  "format",
] as const satisfies readonly (keyof WeeklyPlanAiDetailSuggestions)[];

const secondaryDetailKeys = [
  "content_direction",
  "objective",
  "notes",
] as const satisfies readonly (keyof WeeklyPlanAiDetailSuggestions)[];

const detailEntries = (
  suggestions: WeeklyPlanAiDetailSuggestions | null,
  keys: readonly (keyof WeeklyPlanAiDetailSuggestions)[],
) =>
  keys.flatMap((key) => {
    const detail = suggestions?.[key];
    return detail ? [{ key, detail }] : [];
  });

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
  const [generalInfoOpen, setGeneralInfoOpen] = useState(false);
  const [aiDetailsOpen, setAiDetailsOpen] = useState(false);
  const errorFor = (field: keyof GoogleDocsAiReviewErrors) => {
    const key = errors[field];
    return key ? t(`googleDocs.validation.${key}`) : undefined;
  };
  const hasError = draft.included && Object.keys(errors).length > 0;
  const regionId = `google-ai-job-${index}`;
  const primaryDetails = detailEntries(
    draft.detailSuggestions,
    primaryDetailKeys,
  );
  const secondaryDetails = detailEntries(
    draft.detailSuggestions,
    secondaryDetailKeys,
  );
  const hasGroupedItems =
    draft.items.length > 0 && draft.groupingBasis !== "ATOMIC";
  const controlsDisabled = busy || applying || !draft.included;

  const basisMarker = (
    basis: "SOURCE_DERIVED" | "AI_SUGGESTION",
    compact = false,
  ) =>
    basis === "AI_SUGGESTION" ? (
      <span
        title={t("googleDocs.detailSuggestions.basis.AI_SUGGESTION")}
        className={
          compact
            ? "ms-1 font-semibold text-kps-primary dark:text-sidebar-selected"
            : "ms-2 inline-flex rounded-full bg-kps-primary/10 px-2 py-0.5 text-theme-xs font-semibold text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected"
        }
      >
        {compact
          ? t("googleDocs.detailSuggestions.aiMarker")
          : t("googleDocs.detailSuggestions.basis.AI_SUGGESTION")}
      </span>
    ) : (
      <span className="ms-2 text-theme-xs text-gray-400 dark:text-gray-500">
        {t("googleDocs.detailSuggestions.basis.SOURCE_DERIVED")}
      </span>
    );

  const generalFields = (
    <div className="space-y-4">
      <div>
        <Label htmlFor={`google-ai-title-${index}`}>{t("fields.title")}</Label>
        <Input
          id={`google-ai-title-${index}`}
          value={draft.title}
          maxLength={255}
          disabled={controlsDisabled}
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
            className="h-full min-h-28"
            value={draft.content}
            disabled={controlsDisabled}
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
            className="h-full min-h-28"
            value={draft.expectedResult}
            disabled={controlsDisabled}
            onChange={(expectedResult) => onChange({ expectedResult })}
          />
        </div>
      </div>
      <div>
        <Label htmlFor={`google-ai-note-${index}`}>{t("fields.note")}</Label>
        <TextArea
          id={`google-ai-note-${index}`}
          rows={2}
          value={draft.note}
          disabled={controlsDisabled}
          onChange={(note) => onChange({ note })}
        />
      </div>
    </div>
  );

  const itemsEditor = (
    <section className="space-y-2" aria-label={t("googleDocs.itemsTitle")}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <h4 className="text-theme-sm font-semibold text-gray-900 dark:text-white">
            {t("googleDocs.itemsTitle")}
          </h4>
          <span className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
            {t(`googleDocs.groupingBasis.${draft.groupingBasis}`)}
          </span>
        </div>
        <div className="flex flex-wrap gap-2">
          {hasGroupedItems && (
            <button
              type="button"
              disabled={controlsDisabled}
              className="rounded-lg border border-gray-300 px-2.5 py-1.5 text-theme-xs font-semibold text-gray-600 transition hover:border-kps-primary/40 hover:text-kps-primary focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:text-sidebar-selected"
              onClick={onSplit}
            >
              {t("googleDocs.splitGroup")}
            </button>
          )}
          <button
            type="button"
            disabled={controlsDisabled}
            className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-2.5 py-1.5 text-theme-xs font-semibold text-gray-600 transition hover:border-kps-primary/40 hover:text-kps-primary focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:text-sidebar-selected"
            onClick={onAddItem}
          >
            <PlusIcon aria-hidden="true" className="size-3.5" />
            {t("googleDocs.addItem")}
          </button>
        </div>
      </div>

      {draft.items.length === 0 ? (
        <p className="rounded-lg bg-gray-50 px-3 py-4 text-theme-xs text-gray-500 dark:bg-gray-800/60 dark:text-gray-400">
          {t("googleDocs.noItems")}
        </p>
      ) : (
        <div className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
          <div className="hidden grid-cols-[2rem_minmax(0,0.9fr)_minmax(0,1.1fr)_6.5rem] gap-2 border-b border-gray-200 bg-gray-50 px-2.5 py-2 text-theme-xs font-semibold text-gray-500 md:grid dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-400">
            <span aria-hidden="true" />
            <span>{t("googleDocs.itemTitleColumn")}</span>
            <span>{t("googleDocs.itemContentColumn")}</span>
            <span className="sr-only">{t("googleDocs.itemActions")}</span>
          </div>
          {draft.items.map((item, itemIndex) => (
            <div
              key={`${draft.key}:item:${itemIndex}`}
              className="grid items-start gap-2 border-b border-gray-200 p-2.5 last:border-b-0 md:grid-cols-[2rem_minmax(0,0.9fr)_minmax(0,1.1fr)_6.5rem] dark:border-gray-700"
            >
              <span className="flex size-7 items-center justify-center rounded-full bg-gray-100 text-theme-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                {itemIndex + 1}
              </span>
              <div>
                <Label
                  htmlFor={`google-ai-item-title-${index}-${itemIndex}`}
                  className="sr-only"
                >
                  {t("googleDocs.itemTitleAria", { index: itemIndex + 1 })}
                </Label>
                <Input
                  id={`google-ai-item-title-${index}-${itemIndex}`}
                  value={item.title}
                  maxLength={255}
                  disabled={controlsDisabled}
                  placeholder={t("googleDocs.itemTitleColumn")}
                  onChange={(event) =>
                    onItemChange(itemIndex, { title: event.target.value })
                  }
                />
              </div>
              <div>
                <Label
                  htmlFor={`google-ai-item-content-${index}-${itemIndex}`}
                  className="sr-only"
                >
                  {t("googleDocs.itemContentAria", { index: itemIndex + 1 })}
                </Label>
                <TextArea
                  id={`google-ai-item-content-${index}-${itemIndex}`}
                  rows={1}
                  className="min-h-11 resize-y"
                  value={item.content ?? ""}
                  disabled={controlsDisabled}
                  placeholder={t("googleDocs.itemContentColumn")}
                  onChange={(content) => onItemChange(itemIndex, { content })}
                />
              </div>
              <div className="flex items-center justify-end gap-1">
                <button
                  type="button"
                  aria-label={t("googleDocs.moveItemUpAria", {
                    index: itemIndex + 1,
                  })}
                  disabled={controlsDisabled || itemIndex === 0}
                  className="flex size-8 items-center justify-center rounded-md text-gray-500 transition hover:bg-gray-100 hover:text-kps-primary focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-30 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-sidebar-selected"
                  onClick={() => onMoveItem(itemIndex, -1)}
                >
                  <ChevronDownIcon
                    aria-hidden="true"
                    className="size-4 rotate-180"
                  />
                </button>
                <button
                  type="button"
                  aria-label={t("googleDocs.moveItemDownAria", {
                    index: itemIndex + 1,
                  })}
                  disabled={
                    controlsDisabled || itemIndex === draft.items.length - 1
                  }
                  className="flex size-8 items-center justify-center rounded-md text-gray-500 transition hover:bg-gray-100 hover:text-kps-primary focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-30 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-sidebar-selected"
                  onClick={() => onMoveItem(itemIndex, 1)}
                >
                  <ChevronDownIcon aria-hidden="true" className="size-4" />
                </button>
                <button
                  type="button"
                  aria-label={t("googleDocs.removeItemAria", {
                    index: itemIndex + 1,
                  })}
                  disabled={busy || applying}
                  className="flex size-8 items-center justify-center rounded-md text-gray-400 transition hover:bg-error-50 hover:text-error-600 focus-visible:ring-2 focus-visible:ring-error-500/30 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-30 dark:text-gray-500 dark:hover:bg-error-500/10 dark:hover:text-error-400"
                  onClick={() => onRemoveItem(itemIndex)}
                >
                  <TrashBinIcon aria-hidden="true" className="size-4" />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </section>
  );

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
      <div className="grid items-center gap-3 bg-gray-50 px-3 py-2.5 md:grid-cols-[auto_minmax(0,1fr)_11rem_auto_auto] dark:bg-gray-800/60">
        <span className="flex size-8 items-center justify-center rounded-full bg-kps-primary/10 text-theme-xs font-bold text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected">
          {displayIndex}
        </span>
        <div className="min-w-0">
          <p className="text-theme-sm font-semibold text-gray-900 dark:text-white">
            {draft.title || t("googleDocs.untitledJob")}
          </p>
          <p className="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
            {t("googleDocs.itemCount", { count: draft.items.length })}
            <span aria-hidden="true"> · </span>
            {t(`googleDocs.groupingBasis.${draft.groupingBasis}`)}
          </p>
          {primaryDetails.length > 0 && (
            <div className="mt-2 flex flex-wrap gap-1.5">
              {primaryDetails.map(({ key, detail }) => (
                <span
                  key={key}
                  className="rounded-full border border-gray-200 bg-white px-2 py-1 text-theme-xs text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                >
                  <span className="font-medium">
                    {t(`googleDocs.detailSuggestions.compactFields.${key}`)}:
                  </span>{" "}
                  {detail.value}
                  {basisMarker(detail.basis, true)}
                </span>
              ))}
            </div>
          )}
        </div>
        <div>
          <Label htmlFor={`google-ai-priority-${index}`} className="sr-only">
            {t("fields.priority")}
          </Label>
          <Select
            id={`google-ai-priority-${index}`}
            value={draft.priority}
            disabled={controlsDisabled}
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
        <label className="flex items-center gap-2 text-theme-xs font-medium text-gray-700 dark:text-gray-300">
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
          className="flex size-9 items-center justify-center justify-self-end rounded-lg text-gray-500 transition hover:bg-kps-primary/10 hover:text-kps-primary focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none dark:text-gray-400 dark:hover:bg-kps-primary/20 dark:hover:text-sidebar-selected"
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
          className="space-y-3 border-t border-gray-200 p-3 dark:border-gray-700"
        >
          {hasGroupedItems ? itemsEditor : generalFields}
          {!hasGroupedItems && itemsEditor}

          {hasGroupedItems && (
            <div className="rounded-lg border border-gray-200 dark:border-gray-700">
              <button
                type="button"
                aria-expanded={generalInfoOpen}
                onClick={() => setGeneralInfoOpen((open) => !open)}
                className="flex w-full items-center justify-between gap-3 px-3 py-2 text-start text-theme-sm font-semibold text-gray-700 transition hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none focus-visible:ring-inset dark:text-gray-300 dark:hover:bg-gray-800/60"
              >
                {t("googleDocs.generalInfo")}
                <ChevronDownIcon
                  aria-hidden="true"
                  className={`size-4 transition-transform ${
                    generalInfoOpen ? "rotate-180" : ""
                  }`}
                />
              </button>
              {generalInfoOpen && (
                <div className="border-t border-gray-200 p-3 dark:border-gray-700">
                  {generalFields}
                </div>
              )}
            </div>
          )}

          {secondaryDetails.length > 0 && (
            <div className="rounded-lg border border-gray-200 dark:border-gray-700">
              <button
                type="button"
                aria-expanded={aiDetailsOpen}
                onClick={() => setAiDetailsOpen((open) => !open)}
                className="flex w-full items-center justify-between gap-3 px-3 py-2 text-start text-theme-sm font-semibold text-gray-700 transition hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none focus-visible:ring-inset dark:text-gray-300 dark:hover:bg-gray-800/60"
              >
                {t("googleDocs.detailSuggestions.secondaryTitle")}
                <ChevronDownIcon
                  aria-hidden="true"
                  className={`size-4 transition-transform ${
                    aiDetailsOpen ? "rotate-180" : ""
                  }`}
                />
              </button>
              {aiDetailsOpen && (
                <dl className="space-y-2 border-t border-gray-200 p-3 dark:border-gray-700">
                  {secondaryDetails.map(({ key, detail }) => (
                    <div
                      key={key}
                      className="grid gap-1 text-theme-sm sm:grid-cols-3"
                    >
                      <dt className="font-medium text-gray-700 dark:text-gray-300">
                        {t(`googleDocs.detailSuggestions.fields.${key}`)}
                      </dt>
                      <dd className="text-gray-600 sm:col-span-2 dark:text-gray-300">
                        {detail.value}
                        {basisMarker(detail.basis)}
                      </dd>
                    </div>
                  ))}
                </dl>
              )}
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
