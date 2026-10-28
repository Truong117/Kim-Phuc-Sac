import type { TaskPriority } from "@/types/tasks";
import type {
  WeeklyPlanAiSuggestion,
  WeeklyPlanSourceEntryInput,
  WeeklyPlanSourcePassage,
} from "@/types/weeklyPlanning";

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
  startDate: string;
  dueDate: string;
  priority: TaskPriority | "";
  note: string;
  verificationFields: string[];
  evidence: string;
  provenance: WeeklyPlanAiSuggestion["provenance"];
};

export type GoogleDocsAiReviewField =
  | "assignee_id"
  | "title"
  | "start_date"
  | "due_date"
  | "priority"
  | "source_passage_ids"
  | "source_tab";

export type GoogleDocsAiReviewErrors = Partial<
  Record<GoogleDocsAiReviewField, string>
>;

const priorities = new Set<TaskPriority>(["LOW", "MEDIUM", "HIGH", "URGENT"]);

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
    startDate: suggestion.start_date ?? "",
    dueDate: suggestion.due_date ?? "",
    priority: suggestion.priority ?? "",
    note: suggestion.note ?? "",
    verificationFields: [...suggestion.verification_fields],
    evidence: suggestion.evidence,
    provenance: { ...suggestion.provenance },
  }));
};

export const updateGoogleDocsAiReviewDraft = (
  drafts: GoogleDocsAiReviewDraft[],
  index: number,
  changes: Partial<
    Pick<
      GoogleDocsAiReviewDraft,
      | "title"
      | "content"
      | "expectedResult"
      | "startDate"
      | "dueDate"
      | "priority"
      | "note"
    >
  >,
): GoogleDocsAiReviewDraft[] =>
  drafts.map((draft, draftIndex) =>
    draftIndex === index
      ? {
          ...draft,
          title: changes.title ?? draft.title,
          content: changes.content ?? draft.content,
          expectedResult: changes.expectedResult ?? draft.expectedResult,
          startDate: changes.startDate ?? draft.startDate,
          dueDate: changes.dueDate ?? draft.dueDate,
          priority: changes.priority ?? draft.priority,
          note: changes.note ?? draft.note,
        }
      : draft,
  );

export const validateGoogleDocsAiReviewDraft = (
  draft: GoogleDocsAiReviewDraft,
  weekStart: string,
  weekEnd: string,
): GoogleDocsAiReviewErrors => {
  const errors: GoogleDocsAiReviewErrors = {};
  if (!draft.assignee?.id) errors.assignee_id = "assigneeRequired";
  if (!draft.title.trim()) errors.title = "titleRequired";
  if (!draft.startDate) errors.start_date = "startRequired";
  if (!draft.dueDate) errors.due_date = "dueRequired";
  if (
    draft.startDate &&
    (draft.startDate < weekStart || draft.startDate > weekEnd)
  ) {
    errors.start_date = "dateOutsideWeek";
  }
  if (draft.dueDate && (draft.dueDate < weekStart || draft.dueDate > weekEnd)) {
    errors.due_date = "dateOutsideWeek";
  }
  if (draft.startDate && draft.dueDate && draft.dueDate < draft.startDate) {
    errors.due_date = "dateOrder";
  }
  if (!draft.priority || !priorities.has(draft.priority)) {
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
    validateGoogleDocsAiReviewDraft(draft, weekStart, weekEnd),
  );
  if (
    drafts.length === 0 ||
    errors.some((draftErrors) => Object.keys(draftErrors).length > 0)
  ) {
    return { entries: null, errors };
  }

  return {
    errors,
    entries: drafts.map((draft) => ({
      assignee_id: draft.assignee!.id,
      title: draft.title.trim(),
      content: draft.content.trim() || null,
      expected_result: draft.expectedResult.trim() || null,
      start_date: draft.startDate,
      due_date: draft.dueDate,
      priority: draft.priority as TaskPriority,
      note: draft.note.trim() || null,
      source_passage_ids: [...draft.sourcePassageIds],
    })),
  };
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
