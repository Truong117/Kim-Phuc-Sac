import type {
  WeeklyPlanGoogleDocsEmployeeMappingTab,
  WeeklyPlanGoogleDocsEmployeeMappings,
  WeeklyPlanSourcePassage,
} from "@/types/weeklyPlanning";

type GoogleDocsSourceReference = {
  key: string;
  title?: string;
  origin: string;
  evidence: Record<string, unknown>;
};

type SourcePassageReference = {
  source_group: GoogleDocsSourceReference | null;
  source_context: GoogleDocsSourceReference | null;
};

export const googleDocsTabId = (
  passage: SourcePassageReference,
): string | null => {
  const source = [passage.source_group, passage.source_context].find(
    (candidate) => candidate?.origin === "GOOGLE_DOCS_TAB",
  );
  if (!source) return null;

  const tabId = source.evidence.tab_id;
  return typeof tabId === "string" && tabId.trim() !== "" ? tabId : null;
};

export const spansMultipleGoogleDocsTabs = (
  passages: readonly SourcePassageReference[],
): boolean => {
  const tabKeys = new Set(
    passages
      .map(googleDocsTabId)
      .filter((value): value is string => value !== null),
  );

  return tabKeys.size > 1;
};

export const remainingGoogleDocsPassages = (
  passages: readonly WeeklyPlanSourcePassage[],
  remainingTabIds: readonly string[],
): WeeklyPlanSourcePassage[] => {
  const remaining = new Set(remainingTabIds);

  return passages.filter((passage) => {
    const tabId = googleDocsTabId(passage);

    return tabId !== null && remaining.has(tabId);
  });
};

export const hasRemainingGoogleDocsTabs = (
  remainingTabIds: readonly string[],
): boolean => remainingTabIds.length > 0;

export type SourceBlockSelectionState = "NONE" | "PARTIAL" | "ALL";

export const sourceBlockSelectionState = (
  passageIds: readonly number[],
  selectedIds: ReadonlySet<number>,
): SourceBlockSelectionState => {
  const selectedCount = passageIds.filter((id) => selectedIds.has(id)).length;
  if (selectedCount === 0) return "NONE";
  if (selectedCount === passageIds.length) return "ALL";

  return "PARTIAL";
};

export type GoogleDocsSelectedTab = Pick<
  WeeklyPlanGoogleDocsEmployeeMappingTab,
  "tab_id" | "title" | "status" | "employee"
>;

export type VerifiedGoogleDocsSelectedTab = GoogleDocsSelectedTab & {
  status: "VERIFIED";
  employee: NonNullable<GoogleDocsSelectedTab["employee"]>;
};

export const selectedGoogleDocsTabs = (
  passages: readonly WeeklyPlanSourcePassage[],
  mappings: WeeklyPlanGoogleDocsEmployeeMappings | null,
): GoogleDocsSelectedTab[] => {
  const mappingsByTab = new Map(
    (mappings?.tabs ?? []).map((mapping) => [mapping.tab_id, mapping]),
  );
  const seen = new Set<string>();

  return passages.flatMap((passage) => {
    const tabId = googleDocsTabId(passage);
    if (tabId === null || seen.has(tabId)) return [];
    seen.add(tabId);
    const mapping = mappingsByTab.get(tabId);
    const source = [passage.source_group, passage.source_context].find(
      (candidate) => candidate?.origin === "GOOGLE_DOCS_TAB",
    );
    const evidenceTitle = (source?.evidence as Record<string, unknown>)?.title;
    const sourceTitle =
      source && "title" in source && typeof source.title === "string"
        ? source.title
        : typeof evidenceTitle === "string"
          ? evidenceTitle
          : tabId;

    return [
      {
        tab_id: tabId,
        title: mapping?.title ?? sourceTitle,
        status: mapping?.status ?? "UNVERIFIED",
        employee: mapping?.employee ?? null,
      },
    ];
  });
};

export const allSelectedGoogleDocsTabsVerified = (
  tabs: readonly GoogleDocsSelectedTab[],
): boolean =>
  tabs.length > 0 &&
  tabs.every((tab) => tab.status === "VERIFIED" && tab.employee !== null);

export const verifiedSingleGoogleDocsTab = (
  tabs: readonly GoogleDocsSelectedTab[],
): VerifiedGoogleDocsSelectedTab | null => {
  const tab = tabs.length === 1 ? tabs[0] : null;
  if (tab?.status !== "VERIFIED" || tab.employee === null) return null;

  return {
    ...tab,
    status: "VERIFIED",
    employee: tab.employee,
  };
};

export type GoogleDocsSelectionActionState = {
  canAnalyzeWithAi: boolean;
  canCreateManually: boolean;
  manualLimitExceeded: boolean;
};

export const googleDocsSelectionActionState = ({
  selectedPassageCount,
  selectedTabs,
  maxPassagesPerEntry,
  canAiExtractSelectedSource,
}: {
  selectedPassageCount: number;
  selectedTabs: readonly GoogleDocsSelectedTab[];
  maxPassagesPerEntry: number;
  canAiExtractSelectedSource: boolean;
}): GoogleDocsSelectionActionState => {
  const hasSelection = selectedPassageCount > 0;
  const allTabsVerified = allSelectedGoogleDocsTabsVerified(selectedTabs);
  const hasSingleVerifiedTab =
    verifiedSingleGoogleDocsTab(selectedTabs) !== null;
  const manualLimitExceeded =
    hasSingleVerifiedTab && selectedPassageCount > maxPassagesPerEntry;

  return {
    canAnalyzeWithAi:
      hasSelection &&
      selectedTabs.length > 0 &&
      allTabsVerified &&
      canAiExtractSelectedSource,
    canCreateManually:
      hasSelection && hasSingleVerifiedTab && !manualLimitExceeded,
    manualLimitExceeded,
  };
};

export const sourcePassageIdsForAi = (
  passages: readonly Pick<WeeklyPlanSourcePassage, "id">[],
): number[] => passages.map((passage) => passage.id);
