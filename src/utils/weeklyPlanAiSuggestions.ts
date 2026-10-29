import type { TaskPriority } from "@/types/tasks";
import type {
  WeeklyPlanAiDetailSuggestions,
  WeeklyPlanAiSuggestion,
} from "@/types/weeklyPlanning";

const priorities = new Set<TaskPriority>(["LOW", "MEDIUM", "HIGH", "URGENT"]);
const groupingBases = new Set(["SOURCE_STRUCTURE", "AI_GROUPED", "ATOMIC"]);
const detailKeys = [
  "channel",
  "account",
  "frequency",
  "format",
  "content_direction",
  "objective",
  "notes",
] as const;

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === "object" && value !== null && !Array.isArray(value);

const stringValue = (value: unknown, fallback = ""): string =>
  typeof value === "string" ? value : fallback;

const nullableString = (value: unknown): string | null =>
  typeof value === "string" ? value : null;

const stringList = (value: unknown): string[] =>
  Array.isArray(value)
    ? value.filter((item): item is string => typeof item === "string")
    : [];

const normalizeDetailSuggestions = (
  value: unknown,
): WeeklyPlanAiDetailSuggestions | null => {
  if (!isRecord(value)) return null;

  return Object.fromEntries(
    detailKeys.map((key) => {
      const detail = value[key];
      if (!isRecord(detail)) return [key, null];
      const basis = detail.basis;
      if (
        typeof detail.value !== "string" ||
        (basis !== "SOURCE_DERIVED" && basis !== "AI_SUGGESTION")
      ) {
        return [key, null];
      }

      return [key, { value: detail.value, basis }];
    }),
  ) as WeeklyPlanAiDetailSuggestions;
};

const trustedSourceTab = (value: unknown) => {
  if (!isRecord(value)) return null;
  if (typeof value.tab_id !== "string" || typeof value.title !== "string") {
    return null;
  }

  return { tab_id: value.tab_id, title: value.title };
};

const trustedAssignee = (value: unknown) => {
  if (!isRecord(value)) return null;
  if (
    typeof value.id !== "number" ||
    !Number.isInteger(value.id) ||
    value.id <= 0 ||
    typeof value.name !== "string" ||
    typeof value.email !== "string"
  ) {
    return null;
  }

  return { id: value.id, name: value.name, email: value.email };
};

const normalizeSuggestion = (value: unknown): WeeklyPlanAiSuggestion => {
  const suggestion = isRecord(value) ? value : {};
  const priority = priorities.has(suggestion.priority as TaskPriority)
    ? (suggestion.priority as TaskPriority)
    : null;
  const groupingBasis = groupingBases.has(String(suggestion.grouping_basis))
    ? (suggestion.grouping_basis as
        "SOURCE_STRUCTURE" | "AI_GROUPED" | "ATOMIC")
    : "ATOMIC";

  return {
    source_keys: stringList(suggestion.source_keys),
    title: stringValue(suggestion.title),
    content: stringValue(suggestion.content),
    expected_result: stringValue(suggestion.expected_result),
    employee_identifier: nullableString(suggestion.employee_identifier),
    start_date: nullableString(suggestion.start_date),
    due_date: nullableString(suggestion.due_date),
    priority,
    note: stringValue(suggestion.note),
    grouping_basis: groupingBasis,
    items: Array.isArray(suggestion.items)
      ? suggestion.items.map((item) => {
          const normalizedItem = isRecord(item) ? item : {};
          return {
            title: stringValue(normalizedItem.title),
            content: stringValue(normalizedItem.content),
            source_keys: stringList(normalizedItem.source_keys),
          };
        })
      : [],
    source_tab: trustedSourceTab(suggestion.source_tab),
    assignee: trustedAssignee(suggestion.assignee),
    assignment_source:
      suggestion.assignment_source === "VERIFIED_GOOGLE_DOCS_TAB_MAPPING"
        ? "VERIFIED_GOOGLE_DOCS_TAB_MAPPING"
        : null,
    detail_suggestions: normalizeDetailSuggestions(
      suggestion.detail_suggestions,
    ),
    verification_fields: stringList(suggestion.verification_fields),
    evidence: stringValue(suggestion.evidence),
    provenance: isRecord(suggestion.provenance)
      ? (suggestion.provenance as WeeklyPlanAiSuggestion["provenance"])
      : ({} as WeeklyPlanAiSuggestion["provenance"]),
  };
};

export type WeeklyPlanAiNormalizationResult =
  | { ok: true; suggestions: WeeklyPlanAiSuggestion[] }
  | { ok: false; reason: "INVALID_RESPONSE" | "UNTRUSTED_GOOGLE_ASSIGNMENT" };

export const normalizeWeeklyPlanAiSuggestions = (
  value: unknown,
  sourceType: "UPLOADED_FILE" | "GOOGLE_DOCS",
): WeeklyPlanAiNormalizationResult => {
  if (!Array.isArray(value)) return { ok: false, reason: "INVALID_RESPONSE" };

  const suggestions = value.map(normalizeSuggestion);
  if (
    sourceType === "GOOGLE_DOCS" &&
    suggestions.some(
      (suggestion) =>
        suggestion.source_tab === null ||
        suggestion.assignee === null ||
        suggestion.assignment_source !== "VERIFIED_GOOGLE_DOCS_TAB_MAPPING",
    )
  ) {
    return { ok: false, reason: "UNTRUSTED_GOOGLE_ASSIGNMENT" };
  }

  return { ok: true, suggestions };
};
