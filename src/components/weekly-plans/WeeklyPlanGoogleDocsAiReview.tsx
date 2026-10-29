import WeeklyPlanGoogleDocsAiReviewGroup from "@/components/weekly-plans/WeeklyPlanGoogleDocsAiReviewGroup";
import Button from "@/components/ui/button/Button";
import type {
  WeeklyPlanAiSuggestion,
  WeeklyPlanSourceEntryInput,
  WeeklyPlanSourcePassage,
} from "@/types/weeklyPlanning";
import {
  addGoogleDocsAiReviewItem,
  buildGoogleDocsAiApplyAllEntries,
  createGoogleDocsAiReviewDrafts,
  groupGoogleDocsAiReviewDrafts,
  moveGoogleDocsAiReviewItem,
  removeGoogleDocsAiReviewItem,
  splitGoogleDocsAiReviewDraft,
  submitGoogleDocsAiApplyAll,
  updateGoogleDocsAiReviewDraft,
  updateGoogleDocsAiReviewItem,
  type GoogleDocsAiReviewChanges,
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
  const [expandedGroupKeys, setExpandedGroupKeys] = useState<Set<string>>(
    () => {
      const initialGroups = groupGoogleDocsAiReviewDrafts(drafts);
      return new Set(initialGroups.length === 1 ? [initialGroups[0].key] : []);
    },
  );
  const [expandedJobKeys, setExpandedJobKeys] = useState<Set<string>>(
    new Set(),
  );
  const [applying, setApplying] = useState(false);
  const [applyError, setApplyError] = useState<string | null>(null);

  const groups = useMemo(() => groupGoogleDocsAiReviewDrafts(drafts), [drafts]);
  const built = useMemo(
    () => buildGoogleDocsAiApplyAllEntries(drafts, weekStart, weekEnd),
    [drafts, weekEnd, weekStart],
  );
  const includedCount = drafts.filter((draft) => draft.included).length;
  const highPriorityCount = drafts.filter(
    (draft) => draft.included && draft.priority === "HIGH",
  ).length;
  const isComplete = built.entries !== null;
  const canApply = isComplete && !busy && !applying;

  const toggleKey = (
    setter: React.Dispatch<React.SetStateAction<Set<string>>>,
    key: string,
  ) => {
    setter((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  const updateDraft = (index: number, changes: GoogleDocsAiReviewChanges) => {
    setDrafts((current) =>
      updateGoogleDocsAiReviewDraft(current, index, changes),
    );
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

  const applyButton = (placement: "top" | "bottom") => (
    <Button
      type="button"
      disabled={!canApply}
      className="bg-kps-primary hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none"
      onClick={() => void applyAll()}
    >
      {applying
        ? t("googleDocs.applying")
        : t("googleDocs.applyAll", { count: includedCount })}
      <span className="sr-only">
        {t(`googleDocs.applyPlacement.${placement}`)}
      </span>
    </Button>
  );

  return (
    <div className="mt-5">
      <div className="rounded-xl border border-kps-primary/20 bg-kps-primary/5 p-4 dark:border-kps-primary/30 dark:bg-kps-primary/10">
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {[
            [
              t("googleDocs.summary.employees"),
              t("googleDocs.summary.employeeCount", { count: groups.length }),
            ],
            [
              t("googleDocs.summary.jobs"),
              t("googleDocs.jobCount", { count: drafts.length }),
            ],
            [
              t("googleDocs.summary.highPriority"),
              t("googleDocs.summary.highPriorityCount", {
                count: highPriorityCount,
              }),
            ],
          ].map(([label, value]) => (
            <div key={label}>
              <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                {label}
              </p>
              <p className="mt-1 font-semibold text-gray-900 dark:text-white">
                {value}
              </p>
            </div>
          ))}
          <div>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              {t("googleDocs.summary.status")}
            </p>
            <p
              className={`mt-1 font-semibold ${
                isComplete
                  ? "text-success-700 dark:text-success-400"
                  : "text-warning-700 dark:text-warning-400"
              }`}
            >
              {isComplete
                ? t("googleDocs.summary.ready")
                : t("googleDocs.summary.needsReview")}
            </p>
          </div>
        </div>
        <div className="mt-4 flex flex-col gap-3 border-t border-kps-primary/15 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-kps-primary/25">
          <p className="text-theme-xs text-gray-600 dark:text-gray-300">
            {t("googleDocs.dateStrategy")}
          </p>
          {applyButton("top")}
        </div>
      </div>

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

      <div className="mt-4 space-y-3">
        {groups.map((group) => (
          <WeeklyPlanGoogleDocsAiReviewGroup
            key={group.key}
            group={group}
            drafts={drafts}
            errors={built.errors}
            expanded={expandedGroupKeys.has(group.key)}
            expandedJobKeys={expandedJobKeys}
            busy={busy}
            applying={applying}
            onToggle={() => toggleKey(setExpandedGroupKeys, group.key)}
            onToggleJob={(key) => toggleKey(setExpandedJobKeys, key)}
            onChange={updateDraft}
            onItemChange={(draftIndex, itemIndex, changes) =>
              setDrafts((current) =>
                updateGoogleDocsAiReviewItem(
                  current,
                  draftIndex,
                  itemIndex,
                  changes,
                ),
              )
            }
            onAddItem={(draftIndex) =>
              setDrafts((current) =>
                addGoogleDocsAiReviewItem(current, draftIndex),
              )
            }
            onRemoveItem={(draftIndex, itemIndex) =>
              setDrafts((current) =>
                removeGoogleDocsAiReviewItem(current, draftIndex, itemIndex),
              )
            }
            onMoveItem={(draftIndex, itemIndex, direction) =>
              setDrafts((current) =>
                moveGoogleDocsAiReviewItem(
                  current,
                  draftIndex,
                  itemIndex,
                  direction,
                ),
              )
            }
            onSplit={(draftIndex) =>
              setDrafts((current) =>
                splitGoogleDocsAiReviewDraft(current, draftIndex),
              )
            }
          />
        ))}
      </div>

      <div className="mt-6 flex flex-col items-end gap-2">
        {!isComplete && drafts.length > 0 && (
          <p className="text-theme-xs text-warning-700 dark:text-warning-400">
            {includedCount === 0
              ? t("googleDocs.selectAtLeastOne")
              : t("googleDocs.completeRequired")}
          </p>
        )}
        {applyButton("bottom")}
      </div>
    </div>
  );
}
