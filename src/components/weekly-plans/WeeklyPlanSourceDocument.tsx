import ComponentCard from "@/components/common/ComponentCard";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import WeeklyPlanEntryForm from "@/components/weekly-plans/WeeklyPlanEntryForm";
import WeeklyPlanAiExtractionPreview from "@/components/weekly-plans/WeeklyPlanAiExtractionPreview";
import { ChevronDownIcon } from "@/icons";
import type {
  WeeklyPlan,
  WeeklyPlanAiSuggestion,
  WeeklyPlanEmployee,
  WeeklyPlanEntryInput,
  WeeklyPlanGoogleDocsEmployeeMappings,
  WeeklyPlanSourceEntryInput,
  WeeklyPlanSourcePassage,
} from "@/types/weeklyPlanning";
import {
  allSelectedGoogleDocsTabsVerified,
  googleDocsTabId,
  remainingGoogleDocsPassages,
  selectedGoogleDocsTabs,
  sourceBlockSelectionState,
  spansMultipleGoogleDocsTabs,
} from "@/utils/weeklyPlanSourceSelection";
import { memo, useMemo, useState } from "react";
import { useTranslation } from "react-i18next";

type Props = {
  plan: WeeklyPlan;
  employees: WeeklyPlanEmployee[];
  googleDocsMappings: WeeklyPlanGoogleDocsEmployeeMappings | null;
  busy: boolean;
  onExtract: () => void;
  onAiExtract: (
    sourcePassageIds?: number[],
  ) => Promise<WeeklyPlanAiSuggestion[]>;
  onApply: (
    requestId: string,
    entries: WeeklyPlanSourceEntryInput[],
  ) => Promise<{
    succeeded: boolean;
    validationErrors?: Record<string, string[]>;
  }>;
  onAssignGroup: (passageIds: number[], groupTitle: string) => Promise<boolean>;
};

type BlockOrigin = "MANUAL" | "GOOGLE_DOCS_TAB" | "DOCX_SECTION" | "UNGROUPED";

type PassageBlock = {
  key: string;
  baseKey: string;
  title: string;
  origin: BlockOrigin;
  evidence: Record<string, unknown>;
  isSourceGroup: boolean;
  passages: WeeklyPlanSourcePassage[];
};

const createRequestId = () => crypto.randomUUID();

const evidenceTitle = (evidence: Record<string, unknown>) => {
  for (const key of ["title", "heading_text", "section_title", "heading"]) {
    const value = evidence[key];
    if (typeof value === "string" && value.trim()) return value.trim();
  }

  return null;
};

const evidenceSummary = (evidence: Record<string, unknown>) => {
  const values = [
    evidence.type,
    evidence.section,
    evidence.start_sequence,
    evidence.end_sequence,
    evidence.source_key,
    evidence.start_source_key,
    evidence.end_source_key,
    evidence.tab_id,
  ].filter(
    (value): value is string | number =>
      (typeof value === "string" && value.trim() !== "") ||
      typeof value === "number",
  );

  return [...new Set(values.map(String))].join(" · ");
};

const buildPassageBlocks = (
  passages: WeeklyPlanSourcePassage[],
  contextTitle: (order: number) => string,
  ungroupedTitle: string,
): PassageBlock[] => {
  const ordered = [...passages].sort(
    (first, second) => first.sequence - second.sequence,
  );

  return ordered.reduce<PassageBlock[]>((blocks, passage) => {
    const sourceGroup = passage.source_group;
    const sourceContext = passage.source_context;
    const descriptor = sourceGroup
      ? {
          baseKey: `source-group:${sourceGroup.key}`,
          title: sourceGroup.title,
          origin: sourceGroup.origin,
          evidence: sourceGroup.evidence,
          isSourceGroup: true,
        }
      : sourceContext
        ? {
            baseKey: `source-context:${sourceContext.key}`,
            title:
              evidenceTitle(sourceContext.evidence) ??
              contextTitle(sourceContext.order),
            origin: sourceContext.origin,
            evidence: sourceContext.evidence,
            isSourceGroup: false,
          }
        : {
            baseKey: "ungrouped",
            title: ungroupedTitle,
            origin: "UNGROUPED" as const,
            evidence: {},
            isSourceGroup: false,
          };
    const previous = blocks.at(-1);

    // Only adjacent passages are folded together. Repeated keys separated by
    // another section remain separate blocks so document order is never lost.
    if (previous?.baseKey === descriptor.baseKey) {
      previous.passages.push(passage);
      return blocks;
    }

    blocks.push({
      key: `${descriptor.baseKey}:${passage.sequence}`,
      baseKey: descriptor.baseKey,
      title: descriptor.title,
      origin: descriptor.origin,
      evidence: descriptor.evidence,
      isSourceGroup: descriptor.isSourceGroup,
      passages: [passage],
    });

    return blocks;
  }, []);
};

function WeeklyPlanSourceDocument({
  plan,
  employees,
  googleDocsMappings,
  busy,
  onExtract,
  onAiExtract,
  onApply,
  onAssignGroup,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.sourceDocument",
  });
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [expandedBlockKeys, setExpandedBlockKeys] = useState<Set<string>>(
    new Set(),
  );
  const [groupDraft, setGroupDraft] = useState<{
    passageIds: number[];
    title: string;
  } | null>(null);
  const [groupError, setGroupError] = useState<string | null>(null);
  const [grouping, setGrouping] = useState(false);
  const [draft, setDraft] = useState<{
    requestId: string;
    passageIds: number[];
    initialInput: Partial<WeeklyPlanEntryInput>;
    assigneeId: number | null;
    aiSuggestionIndex?: number;
  } | null>(null);
  const [aiLoading, setAiLoading] = useState(false);
  const [aiPreviewOpen, setAiPreviewOpen] = useState(false);
  const [aiSuggestions, setAiSuggestions] = useState<WeeklyPlanAiSuggestion[]>(
    [],
  );
  const [aiPreviewVersion, setAiPreviewVersion] = useState(0);
  const [aiError, setAiError] = useState<string | null>(null);
  const [localError, setLocalError] = useState<string | null>(null);
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
  const [formErrorVersion, setFormErrorVersion] = useState(0);
  const orderedPassages = useMemo(
    () => {
      const passages =
        plan.source_type === "GOOGLE_DOCS"
          ? remainingGoogleDocsPassages(
              plan.source_document.passages,
              plan.source_document.google_docs?.remaining_tab_ids ?? [],
            )
          : plan.source_document.passages;

      return [...passages].sort(
        (first, second) => first.sequence - second.sequence,
      );
    },
    [
      plan.source_document.google_docs?.remaining_tab_ids,
      plan.source_document.passages,
      plan.source_type,
    ],
  );
  const selectedIdSet = useMemo(() => new Set(selectedIds), [selectedIds]);
  const selectedPassages = useMemo(
    () => orderedPassages.filter((passage) => selectedIdSet.has(passage.id)),
    [orderedPassages, selectedIdSet],
  );
  const selectedGoogleTabs = useMemo(
    () => selectedGoogleDocsTabs(selectedPassages, googleDocsMappings),
    [googleDocsMappings, selectedPassages],
  );
  const hasMultipleSelectedGoogleTabs =
    plan.source_type === "GOOGLE_DOCS" && selectedGoogleTabs.length > 1;
  const selectedGoogleTabsVerified =
    allSelectedGoogleDocsTabsVerified(selectedGoogleTabs);
  const unresolvedSelectedGoogleTabs = selectedGoogleTabs.filter(
    (tab) => tab.status !== "VERIFIED" || tab.employee === null,
  );
  const mappingsByTabId = useMemo(
    () =>
      new Map(
        (googleDocsMappings?.tabs ?? []).map((mapping) => [
          mapping.tab_id,
          mapping,
        ]),
      ),
    [googleDocsMappings],
  );
  const blocks = useMemo(
    () =>
      buildPassageBlocks(
        orderedPassages,
        (order) => t("contextTitle", { order }),
        t("ungroupedTitle"),
      ),
    [orderedPassages, t],
  );
  const canSelect =
    plan.actions.can_apply_source || plan.actions.can_group_source;

  const toggleBlock = (key: string) => {
    setExpandedBlockKeys((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  const toggleBlockSelection = (block: PassageBlock) => {
    setLocalError(null);
    setSelectedIds((current) => {
      const next = new Set(current);
      const allSelected = block.passages.every((passage) =>
        next.has(passage.id),
      );
      block.passages.forEach((passage) => {
        if (allSelected) next.delete(passage.id);
        else next.add(passage.id);
      });
      return [...next];
    });
  };

  const openGroupDraft = () => {
    setLocalError(null);
    setGroupError(null);
    if (selectedPassages.length === 0) {
      setLocalError(t("selectRequired"));
      return;
    }

    const selectedIndexes = selectedPassages.map((passage) =>
      orderedPassages.findIndex((candidate) => candidate.id === passage.id),
    );
    const isContiguous = selectedIndexes.every(
      (index, position) =>
        position === 0 || index === selectedIndexes[position - 1] + 1,
    );
    if (!isContiguous) {
      setLocalError(t("groupRangeRequired"));
      return;
    }

    const firstGroup = selectedPassages[0].source_group;
    const hasOneExistingGroup =
      firstGroup !== null &&
      selectedPassages.every(
        (passage) => passage.source_group?.key === firstGroup.key,
      );
    setGroupDraft({
      passageIds: selectedPassages.map((passage) => passage.id),
      title: hasOneExistingGroup ? firstGroup.title : "",
    });
  };

  const saveGroup = async () => {
    if (!groupDraft || grouping || busy) return;
    const title = groupDraft.title.trim();
    if (!title) {
      setGroupError(t("groupTitleRequired"));
      return;
    }

    setGrouping(true);
    setGroupError(null);
    try {
      const succeeded = await onAssignGroup(groupDraft.passageIds, title);
      if (succeeded) {
        setGroupDraft(null);
        setSelectedIds([]);
      } else {
        setGroupError(t("groupSaveFailed"));
      }
    } finally {
      setGrouping(false);
    }
  };

  const openDraft = () => {
    setLocalError(null);
    setFormErrors({});
    if (selectedPassages.length === 0) {
      setLocalError(t("selectRequired"));
      return;
    }

    if (
      plan.source_type === "GOOGLE_DOCS" &&
      spansMultipleGoogleDocsTabs(selectedPassages)
    ) {
      return;
    }

    const selectedGroupKeys = selectedPassages.map(
      (passage) => passage.source_group?.key ?? null,
    );
    const includesGroupedPassage = selectedGroupKeys.some(
      (groupKey) => groupKey !== null,
    );
    if (
      includesGroupedPassage &&
      (selectedGroupKeys.some((groupKey) => groupKey === null) ||
        new Set(selectedGroupKeys).size !== 1)
    ) {
      setLocalError(t("entryGroupSelectionRequired"));
      return;
    }
    if (
      selectedPassages.length >
      plan.source_document.limits.max_passages_per_entry
    ) {
      setLocalError(
        t("selectionLimit", {
          count: plan.source_document.limits.max_passages_per_entry,
        }),
      );
      return;
    }

    const content = selectedPassages
      .map((passage) => passage.text)
      .join("\n\n");
    if (content.length > 10000) {
      setLocalError(t("selectionTooLong"));
      return;
    }

    setDraft({
      requestId: createRequestId(),
      passageIds: selectedPassages.map((passage) => passage.id),
      initialInput: {
        title: selectedPassages[0].text.slice(0, 255),
        content,
        start_date: plan.week_start,
        due_date: plan.week_end,
        priority: "MEDIUM",
      },
      assigneeId: null,
    });
  };

  const runAiExtraction = async (sourcePassageIds?: number[]) => {
    if (busy || aiLoading) return;
    setAiLoading(true);
    setAiError(null);
    setAiSuggestions([]);
    setAiPreviewOpen(false);
    setAiPreviewVersion((current) => current + 1);
    try {
      const suggestions =
        sourcePassageIds === undefined
          ? await onAiExtract()
          : await onAiExtract(sourcePassageIds);
      setAiSuggestions(suggestions);
      setAiPreviewOpen(true);
    } catch (error) {
      setAiError(error instanceof Error ? error.message : t("aiFailed"));
    } finally {
      setAiLoading(false);
    }
  };

  const reviewAiSuggestion = (
    suggestion: WeeklyPlanAiSuggestion,
    passageIds: number[],
    index: number,
  ) => {
    setFormErrors({});
    setAiPreviewOpen(false);
    setDraft({
      requestId: createRequestId(),
      passageIds,
      initialInput: {
        title: suggestion.title,
        content: suggestion.content,
        expected_result: suggestion.expected_result,
        start_date: suggestion.start_date ?? plan.week_start,
        due_date: suggestion.due_date ?? plan.week_end,
        priority: suggestion.priority ?? "MEDIUM",
        note: suggestion.note,
      },
      assigneeId: null,
      aiSuggestionIndex: index,
    });
  };

  return (
    <>
      <ComponentCard title={t("title")} desc={t("description")} compact>
        <div className="flex flex-col gap-3 rounded-xl bg-gray-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:bg-gray-800/60">
          <div>
            <p className="text-theme-sm font-semibold text-gray-900 dark:text-white">
              {t("status." + plan.source_document.status)}
            </p>
            {!plan.source_document.ai.configured && (
              <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                {t("aiNotConnected")}
              </p>
            )}
            {aiError && (
              <p className="mt-1 text-theme-xs text-error-600 dark:text-error-400">
                {aiError}
              </p>
            )}
          </div>
          <div className="flex flex-wrap gap-2">
            {plan.actions.can_extract_source && (
              <Button
                type="button"
                variant="outline"
                disabled={busy}
                onClick={onExtract}
              >
                {busy ? t("extracting") : t("extract")}
              </Button>
            )}
            {plan.actions.can_ai_extract_source && (
              <Button
                type="button"
                variant="outline"
                disabled={busy || aiLoading}
                onClick={() => void runAiExtraction()}
              >
                {aiLoading ? t("aiExtracting") : t("extractWithAi")}
              </Button>
            )}
          </div>
        </div>

        {localError && (
          <p
            role="alert"
            className="rounded-lg bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
          >
            {localError}
          </p>
        )}

        {orderedPassages.length > 0 ? (
          <div className="space-y-3">
            <div className="flex flex-wrap justify-end gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() =>
                  setExpandedBlockKeys(
                    new Set(blocks.map((block) => block.key)),
                  )
                }
              >
                {t("expandAll")}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => setExpandedBlockKeys(new Set())}
              >
                {t("collapseAll")}
              </Button>
            </div>

            {blocks.map((block, blockIndex) => {
              const isExpanded = expandedBlockKeys.has(block.key);
              const selectedInBlock = block.passages.filter((passage) =>
                selectedIdSet.has(passage.id),
              ).length;
              const selectionState = sourceBlockSelectionState(
                block.passages.map((passage) => passage.id),
                selectedIdSet,
              );
              const allSelected = selectionState === "ALL";
              const partiallySelected = selectionState === "PARTIAL";
              const blockTabId = googleDocsTabId(block.passages[0]);
              const persistedMapping = blockTabId
                ? mappingsByTabId.get(blockTabId)
                : undefined;
              const headerId = `weekly-source-block-${blockIndex}-header`;
              const regionId = `weekly-source-block-${blockIndex}-content`;
              const evidence = evidenceSummary(block.evidence);

              return (
                <section
                  key={block.key}
                  data-selection-state={selectionState}
                  className={`overflow-hidden rounded-xl border transition ${
                    {
                      ALL: "border-brand-500 bg-brand-25 shadow-theme-xs ring-1 ring-brand-500/30 dark:border-brand-400 dark:bg-brand-500/10 dark:ring-brand-400/30",
                      PARTIAL:
                        "border-warning-400 bg-warning-50/70 ring-1 ring-warning-400/20 dark:border-warning-500 dark:bg-warning-500/10 dark:ring-warning-400/20",
                      NONE: "border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900",
                    }[selectionState]
                  }`}
                >
                  <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
                    <button
                      id={headerId}
                      type="button"
                      aria-expanded={isExpanded}
                      aria-controls={regionId}
                      onClick={() => toggleBlock(block.key)}
                      className="flex min-w-0 flex-1 items-start gap-3 rounded-lg text-start focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none"
                    >
                      <ChevronDownIcon
                        aria-hidden="true"
                        className={`mt-0.5 size-5 shrink-0 text-gray-500 transition-transform ${isExpanded ? "" : "-rotate-90 rtl:rotate-90"}`}
                      />
                      <span className="min-w-0">
                        <span className="flex flex-wrap items-center gap-2">
                          <span className="font-semibold text-gray-900 dark:text-white">
                            {block.title}
                          </span>
                          {allSelected && (
                            <span className="rounded-full bg-brand-500 px-2 py-0.5 text-theme-xs font-semibold text-white dark:bg-brand-400 dark:text-gray-950">
                              {t("selectedIndicator")}
                            </span>
                          )}
                          {partiallySelected && (
                            <span className="rounded-full bg-warning-100 px-2 py-0.5 text-theme-xs font-semibold text-warning-800 dark:bg-warning-500/20 dark:text-warning-300">
                              {t("partialIndicator")}
                            </span>
                          )}
                          <span className="rounded-full bg-gray-100 px-2 py-0.5 text-theme-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            {t("origin." + block.origin)}
                          </span>
                          {block.isSourceGroup &&
                            block.origin !== "GOOGLE_DOCS_TAB" && (
                              <span className="rounded-full bg-warning-50 px-2 py-0.5 text-theme-xs font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                                {t("identityUnverified")}
                              </span>
                            )}
                          {block.origin === "GOOGLE_DOCS_TAB" && (
                            <span
                              className={`rounded-full px-2 py-0.5 text-theme-xs font-semibold ${
                                persistedMapping?.status === "VERIFIED" &&
                                persistedMapping.employee
                                  ? "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400"
                                  : persistedMapping?.status ===
                                      "NOT_APPLICABLE"
                                    ? "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                                    : "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400"
                              }`}
                            >
                              {persistedMapping?.status === "VERIFIED" &&
                              persistedMapping.employee
                                ? t("googleDocsMapping.verified", {
                                    name: persistedMapping.employee.name,
                                  })
                                : persistedMapping?.status === "NOT_APPLICABLE"
                                  ? t("googleDocsMapping.notApplicable")
                                  : t("googleDocsMapping.unverified")}
                            </span>
                          )}
                        </span>
                        <span className="mt-1 block text-theme-xs text-gray-500 dark:text-gray-400">
                          {t("passageCount", {
                            count: block.passages.length,
                          })}
                          {canSelect
                            ? ` · ${t("blockSelectedCount", {
                                selected: selectedInBlock,
                                total: block.passages.length,
                              })}`
                            : ""}
                          {evidence
                            ? ` · ${t("groupEvidence", { evidence })}`
                            : ""}
                        </span>
                        <span className="mt-1 block text-theme-xs text-gray-500 dark:text-gray-400">
                          {block.origin === "GOOGLE_DOCS_TAB"
                            ? t("googleDocsMapping.help")
                            : block.isSourceGroup
                              ? t("groupIdentityHelp")
                              : t("contextIdentityHelp")}
                        </span>
                      </span>
                    </button>
                    {canSelect && (
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        aria-pressed={allSelected}
                        disabled={busy || grouping}
                        className={
                          allSelected
                            ? "border-brand-500 bg-brand-50 text-brand-700 hover:bg-brand-100 dark:border-brand-400 dark:bg-brand-500/15 dark:text-brand-300"
                            : partiallySelected
                              ? "border-warning-400 bg-warning-50 text-warning-800 hover:bg-warning-100 dark:border-warning-500 dark:bg-warning-500/10 dark:text-warning-300"
                              : undefined
                        }
                        onClick={() => toggleBlockSelection(block)}
                      >
                        {allSelected
                          ? t("selectedGroup")
                          : partiallySelected
                            ? t("selectRemainingGroup")
                            : t("selectGroup")}
                      </Button>
                    )}
                  </div>

                  {isExpanded && (
                    <div
                      id={regionId}
                      role="region"
                      aria-labelledby={headerId}
                      className="space-y-3 border-t border-gray-200 bg-gray-50/60 p-4 dark:border-gray-700 dark:bg-gray-800/30"
                    >
                      {block.passages.map((passage) => {
                        const selected = selectedIdSet.has(passage.id);
                        return (
                          <label
                            key={passage.id}
                            className={`block rounded-xl border p-4 transition ${
                              selected
                                ? "border-kps-primary bg-kps-primary/5"
                                : "border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900"
                            } ${canSelect ? "cursor-pointer" : "cursor-default"}`}
                          >
                            <span className="flex items-start gap-3">
                              {canSelect && (
                                <input
                                  type="checkbox"
                                  checked={selected}
                                  disabled={busy || grouping}
                                  onChange={() => {
                                    setLocalError(null);
                                    setSelectedIds((current) =>
                                      current.includes(passage.id)
                                        ? current.filter(
                                            (id) => id !== passage.id,
                                          )
                                        : [...current, passage.id],
                                    );
                                  }}
                                  className="mt-0.5 size-4 rounded border-gray-300 text-kps-primary focus:ring-kps-primary/30 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800"
                                />
                              )}
                              <span className="min-w-0 flex-1">
                                <span className="flex flex-wrap items-center gap-2">
                                  <span className="rounded-full bg-gray-100 px-2 py-0.5 text-theme-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                    {t("kind." + passage.kind)}
                                  </span>
                                  <span className="text-theme-xs text-gray-400">
                                    {t("position", {
                                      position: passage.sequence,
                                    })}
                                  </span>
                                  <span className="text-theme-xs text-gray-400">
                                    {t("sourceKey", {
                                      sourceKey: passage.source_key,
                                    })}
                                  </span>
                                </span>
                                <span className="mt-2 block text-theme-sm whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                                  {passage.text}
                                </span>
                              </span>
                            </span>
                          </label>
                        );
                      })}
                    </div>
                  )}
                </section>
              );
            })}

            {canSelect && (
              <div className="flex flex-col gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 sm:flex-row sm:items-end sm:justify-between dark:border-gray-700 dark:bg-gray-800/50">
                <div className="min-w-0">
                  <p className="text-theme-sm font-semibold text-gray-900 dark:text-white">
                    {plan.source_type === "GOOGLE_DOCS"
                      ? t("googleDocsSelectionSummary", {
                          passages: selectedIds.length,
                          groups: selectedGoogleTabs.length,
                        })
                      : t("selectedCount", { count: selectedIds.length })}
                  </p>
                  {plan.source_type === "GOOGLE_DOCS" &&
                    selectedGoogleTabs.length > 0 && (
                      <p className="mt-1 text-theme-xs text-gray-600 dark:text-gray-300">
                        {selectedGoogleTabs
                          .map((tab) =>
                            tab.employee
                              ? `${tab.title} — ${tab.employee.name}`
                              : tab.title,
                          )
                          .join(" · ")}
                      </p>
                    )}
                  {hasMultipleSelectedGoogleTabs &&
                    unresolvedSelectedGoogleTabs.length > 0 && (
                      <p className="mt-2 text-theme-xs font-medium text-warning-700 dark:text-warning-400">
                        {t("unresolvedSelectedTabs", {
                          tabs: unresolvedSelectedGoogleTabs
                            .map((tab) => tab.title)
                            .join(", "),
                        })}
                      </p>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                  {plan.actions.can_group_source && (
                    <Button
                      type="button"
                      variant="outline"
                      disabled={busy || grouping || selectedIds.length === 0}
                      onClick={openGroupDraft}
                    >
                      {t("assignGroup")}
                    </Button>
                  )}
                  {plan.actions.can_apply_source && (
                    <>
                      {!hasMultipleSelectedGoogleTabs && (
                        <Button
                          type="button"
                          disabled={
                            busy || grouping || selectedIds.length === 0
                          }
                          className="bg-kps-primary hover:bg-kps-primary-hover"
                          onClick={openDraft}
                        >
                          {plan.source_type === "GOOGLE_DOCS"
                            ? t("createManualEntry")
                            : t("createFromSelection")}
                        </Button>
                      )}
                      {hasMultipleSelectedGoogleTabs && (
                        <Button
                          type="button"
                          disabled={
                            busy ||
                            grouping ||
                            aiLoading ||
                            !plan.actions.can_ai_extract_selected_source ||
                            !selectedGoogleTabsVerified
                          }
                          className="bg-kps-primary hover:bg-kps-primary-hover"
                          onClick={() =>
                            void runAiExtraction(
                              selectedPassages.map((passage) => passage.id),
                            )
                          }
                        >
                          {aiLoading
                            ? t("aiExtracting")
                            : t("extractSelectedGroupsWithAi", {
                                count: selectedGoogleTabs.length,
                              })}
                        </Button>
                      )}
                    </>
                  )}
                </div>
              </div>
            )}
          </div>
        ) : (
          <p className="rounded-xl bg-gray-50 px-4 py-8 text-center text-theme-sm text-gray-500 dark:bg-gray-800/50 dark:text-gray-400">
            {t("empty." + plan.source_document.status)}
          </p>
        )}
      </ComponentCard>

      <WeeklyPlanAiExtractionPreview
        isOpen={aiPreviewOpen}
        suggestions={aiSuggestions}
        passages={orderedPassages}
        sourceType={plan.source_type}
        weekStart={plan.week_start}
        weekEnd={plan.week_end}
        reviewVersion={aiPreviewVersion}
        busy={busy}
        onClose={() => setAiPreviewOpen(false)}
        onReview={reviewAiSuggestion}
        onApplyAll={onApply}
        onApplied={() => {
          setAiSuggestions([]);
          setAiPreviewOpen(false);
          setSelectedIds([]);
        }}
      />

      <Modal
        isOpen={groupDraft !== null}
        onClose={() => {
          if (!busy && !grouping) setGroupDraft(null);
        }}
        className="max-w-lg p-6"
        ariaLabelledBy="weekly-source-group-title"
      >
        <h2
          id="weekly-source-group-title"
          className="text-lg font-semibold text-gray-900 dark:text-white"
        >
          {t("groupModalTitle")}
        </h2>
        <p className="mt-2 text-theme-sm text-gray-500 dark:text-gray-400">
          {t("groupModalDescription", {
            count: groupDraft?.passageIds.length ?? 0,
          })}
        </p>
        {groupDraft && (
          <div className="mt-5">
            <Label htmlFor="weekly-source-group-name">
              {t("groupTitleLabel")}
            </Label>
            <Input
              id="weekly-source-group-name"
              value={groupDraft.title}
              maxLength={255}
              disabled={busy || grouping}
              error={groupError !== null}
              hint={groupError ?? t("groupTitleHelp")}
              placeholder={t("groupTitlePlaceholder")}
              onChange={(event) => {
                setGroupError(null);
                setGroupDraft((current) =>
                  current ? { ...current, title: event.target.value } : current,
                );
              }}
              onKeyDown={(event) => {
                if (event.key === "Enter" && !event.nativeEvent.isComposing) {
                  event.preventDefault();
                  void saveGroup();
                }
              }}
            />
          </div>
        )}
        <p className="mt-3 rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
          {t("groupAssigneeWarning")}
        </p>
        <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            disabled={busy || grouping}
            onClick={() => setGroupDraft(null)}
          >
            {t("cancel")}
          </Button>
          <Button
            type="button"
            disabled={busy || grouping}
            className="bg-kps-primary hover:bg-kps-primary-hover"
            onClick={() => void saveGroup()}
          >
            {grouping ? t("groupSaving") : t("groupSave")}
          </Button>
        </div>
      </Modal>

      <Modal
        isOpen={draft !== null}
        onClose={() => {
          if (!busy) {
            const returnToAiPreview = draft?.aiSuggestionIndex !== undefined;
            setDraft(null);
            if (returnToAiPreview) setAiPreviewOpen(true);
          }
        }}
        className="max-h-[90vh] max-w-3xl overflow-y-auto p-6"
        ariaLabelledBy="weekly-source-entry-title"
      >
        <h2
          id="weekly-source-entry-title"
          className="mb-2 text-lg font-semibold text-gray-900 dark:text-white"
        >
          {t("draftTitle")}
        </h2>
        <p className="mb-5 text-theme-sm text-gray-500 dark:text-gray-400">
          {t("draftDescription", { count: draft?.passageIds.length ?? 0 })}
        </p>
        {draft && draft.assigneeId === null && (
          <div className="space-y-5">
            <div>
              <Label htmlFor="weekly-source-assignee">
                {t("assigneeLabel")}
              </Label>
              <Select
                id="weekly-source-assignee"
                value=""
                placeholder={t("assigneePlaceholder")}
                options={employees.map((employee) => ({
                  value: String(employee.id),
                  label: `${employee.name} — ${employee.email}`,
                }))}
                onChange={(value) =>
                  setDraft((current) =>
                    current
                      ? { ...current, assigneeId: Number(value) }
                      : current,
                  )
                }
              />
              <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                {t("assigneeHelp")}
              </p>
            </div>
            <div className="flex justify-end">
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  const returnToAiPreview =
                    draft.aiSuggestionIndex !== undefined;
                  setDraft(null);
                  if (returnToAiPreview) setAiPreviewOpen(true);
                }}
              >
                {t("cancel")}
              </Button>
            </div>
          </div>
        )}
        {draft && draft.assigneeId !== null && (
          <WeeklyPlanEntryForm
            key={draft.requestId}
            initialInput={{
              ...draft.initialInput,
              assignee_id: draft.assigneeId,
            }}
            employees={employees}
            weekStart={plan.week_start}
            weekEnd={plan.week_end}
            isSaving={busy}
            serverErrors={formErrors}
            serverErrorVersion={formErrorVersion}
            submitLabel={t("applyToDraft")}
            onCancel={() => {
              const returnToAiPreview = draft.aiSuggestionIndex !== undefined;
              setDraft(null);
              if (returnToAiPreview) setAiPreviewOpen(true);
            }}
            onSave={(input) => {
              void onApply(draft.requestId, [
                { ...input, source_passage_ids: draft.passageIds },
              ]).then((result) => {
                if (result.succeeded) {
                  const aiSuggestionIndex = draft.aiSuggestionIndex;
                  setDraft(null);
                  setSelectedIds([]);
                  setFormErrors({});
                  if (aiSuggestionIndex !== undefined) {
                    setAiSuggestions((current) =>
                      current.filter((_, index) => index !== aiSuggestionIndex),
                    );
                    setAiPreviewOpen(true);
                  }
                } else if (result.validationErrors) {
                  setFormErrors(result.validationErrors);
                  setFormErrorVersion((current) => current + 1);
                }
              });
            }}
          />
        )}
      </Modal>
    </>
  );
}

export default memo(WeeklyPlanSourceDocument);
