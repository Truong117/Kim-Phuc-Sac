import type { TaskPriority } from "@/types/tasks";
import type {
  WeeklyPlanAiDetailSuggestions,
  WeeklyPlanAiSuggestion,
  WeeklyPlanSourceEntryInput,
  WeeklyPlanSourcePassage,
} from "@/types/weeklyPlanning";

export type GoogleDocsAiReviewPriority = "MEDIUM" | "HIGH";

export type GoogleDocsAiReviewItem = {
  title: string;
  content: string | null;
  sourceKeys: string[];
  sourcePassageIds: number[];
};

export type GoogleDocsAiReviewDraft = {
  key: string;
  sourceKeys: string[];
  sourcePassageIds: number[];
  sourceTab: { tab_id: string; title: string } | null;
  assignee: { id: number; name: string; email: string } | null;
  assignmentSource: string | null;
  title: string;
  content: string;
  expectedResult: string;
  priority: GoogleDocsAiReviewPriority;
  note: string;
  included: boolean;
  detailSuggestions: WeeklyPlanAiDetailSuggestions | null;
  verificationFields: string[];
  groupingBasis: "SOURCE_STRUCTURE" | "AI_GROUPED" | "ATOMIC";
  items: GoogleDocsAiReviewItem[];
};

export type GoogleDocsAiReviewField =
  | "assignee_id"
  | "title"
  | "priority"
  | "source_passage_ids"
  | "source_tab"
  | "items";

export type GoogleDocsAiReviewErrors = Partial<
  Record<GoogleDocsAiReviewField, string>
>;

export type GoogleDocsAiReviewChanges = Partial<
  Pick<
    GoogleDocsAiReviewDraft,
    "title" | "content" | "expectedResult" | "priority" | "note" | "included"
  >
>;

const priorities = new Set<GoogleDocsAiReviewPriority>(["MEDIUM", "HIGH"]);

export const googleDocsAiPriority = (
  priority: TaskPriority | null,
): GoogleDocsAiReviewPriority =>
  priority === "HIGH" || priority === "URGENT" ? "HIGH" : "MEDIUM";

export const createGoogleDocsAiReviewDrafts = (
  suggestions: WeeklyPlanAiSuggestion[],
  passages: WeeklyPlanSourcePassage[],
): GoogleDocsAiReviewDraft[] => {
  const passageIdsByKey = new Map(
    passages.map((passage) => [passage.source_key, passage.id]),
  );

  return suggestions.map((suggestion, index) => ({
    key: `${suggestion.source_keys.join(":")}:${index}`,
    sourceKeys: [...suggestion.source_keys],
    sourcePassageIds: suggestion.source_keys
      .map((sourceKey) => passageIdsByKey.get(sourceKey))
      .filter((id): id is number => id !== undefined),
    sourceTab: suggestion.source_tab ?? null,
    assignee: suggestion.assignee ?? null,
    assignmentSource: suggestion.assignment_source ?? null,
    title: suggestion.title,
    content: suggestion.content ?? "",
    expectedResult: suggestion.expected_result ?? "",
    priority: googleDocsAiPriority(suggestion.priority),
    note: suggestion.note ?? "",
    included: true,
    detailSuggestions: suggestion.detail_suggestions ?? null,
    verificationFields: [...suggestion.verification_fields],
    groupingBasis: suggestion.grouping_basis ?? "ATOMIC",
    items: (suggestion.items ?? []).map((item) => ({
      title: item.title,
      content: item.content,
      sourceKeys: [...item.source_keys],
      sourcePassageIds: item.source_keys
        .map((sourceKey) => passageIdsByKey.get(sourceKey))
        .filter((id): id is number => id !== undefined),
    })),
  }));
};

export const updateGoogleDocsAiReviewDraft = (
  drafts: GoogleDocsAiReviewDraft[],
  index: number,
  changes: GoogleDocsAiReviewChanges,
): GoogleDocsAiReviewDraft[] =>
  drafts.map((draft, draftIndex) =>
    draftIndex === index
      ? {
          ...draft,
          title: changes.title ?? draft.title,
          content: changes.content ?? draft.content,
          expectedResult: changes.expectedResult ?? draft.expectedResult,
          priority: changes.priority ?? draft.priority,
          note: changes.note ?? draft.note,
          included: changes.included ?? draft.included,
        }
      : draft,
  );

export const updateGoogleDocsAiReviewItem = (
  drafts: GoogleDocsAiReviewDraft[],
  draftIndex: number,
  itemIndex: number,
  changes: Partial<Pick<GoogleDocsAiReviewItem, "title" | "content">>,
): GoogleDocsAiReviewDraft[] =>
  drafts.map((draft, currentDraftIndex) =>
    currentDraftIndex !== draftIndex
      ? draft
      : {
          ...draft,
          items: draft.items.map((item, currentItemIndex) =>
            currentItemIndex === itemIndex ? { ...item, ...changes } : item,
          ),
        },
  );

export const addGoogleDocsAiReviewItem = (
  drafts: GoogleDocsAiReviewDraft[],
  draftIndex: number,
): GoogleDocsAiReviewDraft[] =>
  drafts.map((draft, currentDraftIndex) =>
    currentDraftIndex !== draftIndex
      ? draft
      : {
          ...draft,
          items: [
            ...draft.items,
            {
              title: "",
              content: null,
              sourceKeys: [...draft.sourceKeys],
              sourcePassageIds: [...draft.sourcePassageIds],
            },
          ],
        },
  );

export const removeGoogleDocsAiReviewItem = (
  drafts: GoogleDocsAiReviewDraft[],
  draftIndex: number,
  itemIndex: number,
): GoogleDocsAiReviewDraft[] =>
  drafts.map((draft, currentDraftIndex) =>
    currentDraftIndex !== draftIndex
      ? draft
      : {
          ...draft,
          items: draft.items.filter((_, index) => index !== itemIndex),
        },
  );

export const moveGoogleDocsAiReviewItem = (
  drafts: GoogleDocsAiReviewDraft[],
  draftIndex: number,
  itemIndex: number,
  direction: -1 | 1,
): GoogleDocsAiReviewDraft[] =>
  drafts.map((draft, currentDraftIndex) => {
    if (currentDraftIndex !== draftIndex) return draft;
    const destination = itemIndex + direction;
    if (destination < 0 || destination >= draft.items.length) return draft;
    const items = [...draft.items];
    [items[itemIndex], items[destination]] = [
      items[destination],
      items[itemIndex],
    ];
    return { ...draft, items };
  });

export const splitGoogleDocsAiReviewDraft = (
  drafts: GoogleDocsAiReviewDraft[],
  draftIndex: number,
): GoogleDocsAiReviewDraft[] => {
  const draft = drafts[draftIndex];
  if (!draft || draft.groupingBasis === "ATOMIC" || draft.items.length === 0) {
    return drafts;
  }

  const promoted = draft.items.map((item, itemIndex) => ({
    ...draft,
    key: `${draft.key}:split:${itemIndex}`,
    title: item.title,
    content: item.content ?? "",
    note: "",
    groupingBasis: "ATOMIC" as const,
    sourceKeys: [...item.sourceKeys],
    sourcePassageIds: [...item.sourcePassageIds],
    items: [],
  }));

  return [
    ...drafts.slice(0, draftIndex),
    ...promoted,
    ...drafts.slice(draftIndex + 1),
  ];
};

export const validateGoogleDocsAiReviewDraft = (
  draft: GoogleDocsAiReviewDraft,
): GoogleDocsAiReviewErrors => {
  const errors: GoogleDocsAiReviewErrors = {};
  if (!draft.assignee?.id) errors.assignee_id = "assigneeRequired";
  if (!draft.title.trim()) errors.title = "titleRequired";
  if (!priorities.has(draft.priority)) {
    errors.priority = "priorityRequired";
  }
  if (
    draft.sourcePassageIds.length === 0 ||
    draft.sourcePassageIds.length !== draft.sourceKeys.length
  ) {
    errors.source_passage_ids = "sourceRequired";
  }
  if (
    !draft.sourceTab?.tab_id ||
    draft.assignmentSource !== "VERIFIED_GOOGLE_DOCS_TAB_MAPPING"
  ) {
    errors.source_tab = "verifiedMappingRequired";
  }
  if (
    draft.items.some(
      (item) => !item.title.trim() || item.sourcePassageIds.length === 0,
    )
  ) {
    errors.items = "sourceRequired";
  }

  return errors;
};

export const buildGoogleDocsAiApplyAllEntries = (
  drafts: GoogleDocsAiReviewDraft[],
  weekStart: string,
  weekEnd: string,
): {
  entries: WeeklyPlanSourceEntryInput[] | null;
  errors: GoogleDocsAiReviewErrors[];
} => {
  const errors = drafts.map((draft) =>
    draft.included ? validateGoogleDocsAiReviewDraft(draft) : {},
  );
  const includedDrafts = drafts.filter((draft) => draft.included);
  if (
    includedDrafts.length === 0 ||
    errors.some((draftErrors) => Object.keys(draftErrors).length > 0)
  ) {
    return { entries: null, errors };
  }

  return {
    errors,
    entries: includedDrafts.map((draft) => ({
      assignee_id: draft.assignee!.id,
      title: draft.title.trim(),
      content: draft.content.trim() || null,
      expected_result: draft.expectedResult.trim() || null,
      start_date: weekStart,
      due_date: weekEnd,
      priority: draft.priority,
      note: draft.note.trim() || null,
      source_passage_ids: [...draft.sourcePassageIds],
      items: draft.items.map((item) => ({
        title: item.title.trim(),
        content: item.content?.trim() || null,
        source_passage_ids: [...item.sourcePassageIds],
      })),
    })),
  };
};

export type GoogleDocsAiReviewGroup = {
  key: string;
  employee: GoogleDocsAiReviewDraft["assignee"];
  tabTitles: string[];
  draftIndexes: number[];
};

export const groupGoogleDocsAiReviewDrafts = (
  drafts: GoogleDocsAiReviewDraft[],
): GoogleDocsAiReviewGroup[] => {
  const groups = new Map<string, GoogleDocsAiReviewGroup>();

  drafts.forEach((draft, index) => {
    const key = draft.assignee?.id
      ? `employee:${draft.assignee.id}`
      : `tab:${draft.sourceTab?.tab_id ?? draft.key}`;
    const current = groups.get(key) ?? {
      key,
      employee: draft.assignee,
      tabTitles: [],
      draftIndexes: [],
    };
    if (
      draft.sourceTab?.title &&
      !current.tabTitles.includes(draft.sourceTab.title)
    ) {
      current.tabTitles.push(draft.sourceTab.title);
    }
    current.draftIndexes.push(index);
    groups.set(key, current);
  });

  return [...groups.values()];
};

export const submitGoogleDocsAiApplyAll = async <T>(
  request: (
    requestId: string,
    entries: WeeklyPlanSourceEntryInput[],
  ) => Promise<T>,
  requestId: string,
  drafts: GoogleDocsAiReviewDraft[],
  weekStart: string,
  weekEnd: string,
): Promise<
  | { submitted: false; errors: GoogleDocsAiReviewErrors[] }
  | { submitted: true; result: T; entries: WeeklyPlanSourceEntryInput[] }
> => {
  const built = buildGoogleDocsAiApplyAllEntries(drafts, weekStart, weekEnd);
  if (built.entries === null) {
    return { submitted: false, errors: built.errors };
  }

  return {
    submitted: true,
    entries: built.entries,
    result: await request(requestId, built.entries),
  };
};
