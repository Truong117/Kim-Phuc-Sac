import WeeklyPlanGoogleDocsAiReviewJobRow from "@/components/weekly-plans/WeeklyPlanGoogleDocsAiReviewJobRow";
import { ChevronDownIcon } from "@/icons";
import type {
  GoogleDocsAiReviewChanges,
  GoogleDocsAiReviewDraft,
  GoogleDocsAiReviewErrors,
  GoogleDocsAiReviewGroup,
  GoogleDocsAiReviewItem,
} from "@/utils/weeklyPlanGoogleDocsAiReview";
import { useTranslation } from "react-i18next";

type Props = {
  group: GoogleDocsAiReviewGroup;
  drafts: GoogleDocsAiReviewDraft[];
  errors: GoogleDocsAiReviewErrors[];
  expanded: boolean;
  expandedJobKeys: ReadonlySet<string>;
  busy: boolean;
  applying: boolean;
  onToggle: () => void;
  onToggleJob: (key: string) => void;
  onChange: (index: number, changes: GoogleDocsAiReviewChanges) => void;
  onItemChange: (
    draftIndex: number,
    itemIndex: number,
    changes: Partial<Pick<GoogleDocsAiReviewItem, "title" | "content">>,
  ) => void;
  onAddItem: (draftIndex: number) => void;
  onRemoveItem: (draftIndex: number, itemIndex: number) => void;
  onMoveItem: (
    draftIndex: number,
    itemIndex: number,
    direction: -1 | 1,
  ) => void;
  onSplit: (draftIndex: number) => void;
};

export default function WeeklyPlanGoogleDocsAiReviewGroup({
  group,
  drafts,
  errors,
  expanded,
  expandedJobKeys,
  busy,
  applying,
  onToggle,
  onToggleJob,
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
  const groupDrafts = group.draftIndexes.map((index) => ({
    draft: drafts[index],
    errors: errors[index] ?? {},
    index,
  }));
  const validIncludedCount = groupDrafts.filter(
    ({ draft, errors: draftErrors }) =>
      draft.included && Object.keys(draftErrors).length === 0,
  ).length;
  const itemCount = groupDrafts.reduce(
    (total, { draft }) => total + draft.items.length,
    0,
  );
  const regionId = `google-ai-group-${group.draftIndexes[0]}`;

  return (
    <section className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
      <button
        type="button"
        className="flex w-full items-center gap-3 p-4 text-start transition hover:bg-kps-primary/5 focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none focus-visible:ring-inset dark:hover:bg-kps-primary/10"
        aria-expanded={expanded}
        aria-controls={regionId}
        onClick={onToggle}
      >
        <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-kps-primary/10 text-theme-sm font-bold text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected">
          {(group.employee?.name ?? group.tabTitles[0] ?? "?")
            .split(/\s+/)
            .slice(-2)
            .map((part) => part[0])
            .join("")
            .toUpperCase()}
        </span>
        <span className="min-w-0 flex-1">
          <span className="flex flex-wrap items-center gap-2">
            <span className="font-semibold text-gray-900 dark:text-white">
              {group.employee?.name ?? t("employeeMissing")}
            </span>
            <span className="rounded-full bg-success-50 px-2.5 py-1 text-theme-xs font-semibold text-success-700 dark:bg-success-500/10 dark:text-success-400">
              {t("googleDocs.verifiedBadge")}
            </span>
            <span className="rounded-full bg-kps-primary/10 px-2.5 py-1 text-theme-xs font-semibold text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected">
              {t("googleDocs.jobCount", { count: group.draftIndexes.length })}
            </span>
            <span className="rounded-full bg-gray-100 px-2.5 py-1 text-theme-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
              {t("googleDocs.itemCount", { count: itemCount })}
            </span>
          </span>
          {group.tabTitles.length > 0 && (
            <span className="mt-1 block truncate text-theme-xs text-gray-500 dark:text-gray-400">
              {group.tabTitles.join(" · ")}
            </span>
          )}
        </span>
        <span className="shrink-0 text-theme-xs font-semibold text-kps-primary dark:text-sidebar-selected">
          {t("googleDocs.validCount", {
            valid: validIncludedCount,
            total: group.draftIndexes.length,
          })}
        </span>
        <ChevronDownIcon
          aria-hidden="true"
          className={`size-5 shrink-0 text-gray-500 transition-transform dark:text-gray-400 ${
            expanded ? "rotate-180" : ""
          }`}
        />
      </button>

      {expanded && (
        <div
          id={regionId}
          className="space-y-2 border-t border-gray-200 p-3 dark:border-gray-700"
        >
          {groupDrafts.map(
            ({ draft, errors: draftErrors, index }, groupIndex) => (
              <WeeklyPlanGoogleDocsAiReviewJobRow
                key={draft.key}
                draft={draft}
                index={index}
                displayIndex={groupIndex + 1}
                errors={draftErrors}
                expanded={expandedJobKeys.has(draft.key)}
                busy={busy}
                applying={applying}
                onToggle={() => onToggleJob(draft.key)}
                onChange={(changes) => onChange(index, changes)}
                onItemChange={(itemIndex, changes) =>
                  onItemChange(index, itemIndex, changes)
                }
                onAddItem={() => onAddItem(index)}
                onRemoveItem={(itemIndex) => onRemoveItem(index, itemIndex)}
                onMoveItem={(itemIndex, direction) =>
                  onMoveItem(index, itemIndex, direction)
                }
                onSplit={() => onSplit(index)}
              />
            ),
          )}
        </div>
      )}
    </section>
  );
}
