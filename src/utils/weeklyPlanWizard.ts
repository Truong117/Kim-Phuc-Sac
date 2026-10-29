import type {
  WeeklyPlan,
  WeeklyPlanEntry,
  WeeklyPlanEvent,
  WeeklyPlanGoogleDocsEmployeeMappings,
  WeeklyPlanSuggestion,
} from "@/types/weeklyPlanning";

export const WEEKLY_PLAN_RULE_ANALYZER_VERSION = "RULE_BASED_V2";

export const weeklyPlanWizardSteps = [
  "source",
  "structure",
  "work",
  "submit",
] as const;

export type WeeklyPlanWizardStep = (typeof weeklyPlanWizardSteps)[number];

export const isEditableWizardPlan = (plan: WeeklyPlan): boolean =>
  plan.status === "DRAFT" || plan.status === "CHANGES_REQUESTED";

export const googleDocsSourceReady = (
  plan: WeeklyPlan,
  mappings: WeeklyPlanGoogleDocsEmployeeMappings | null,
): boolean => {
  if (plan.source_type !== "GOOGLE_DOCS") return false;
  if (plan.source_document.status !== "EXTRACTED" || !mappings) return false;

  const tabs = mappings.tabs;
  return (
    tabs.some((tab) => tab.status === "VERIFIED" && tab.employee !== null) &&
    tabs.every(
      (tab) =>
        (tab.status === "VERIFIED" && tab.employee !== null) ||
        tab.status === "NOT_APPLICABLE",
    )
  );
};

export const weeklyPlanSourceReady = (
  plan: WeeklyPlan,
  mappings: WeeklyPlanGoogleDocsEmployeeMappings | null,
): boolean =>
  plan.source_type === "GOOGLE_DOCS"
    ? googleDocsSourceReady(plan, mappings)
    : plan.parse_status === "PARSED";

export const hasRemainingSourceWork = (plan: WeeklyPlan): boolean =>
  plan.source_type === "GOOGLE_DOCS"
    ? (plan.source_document.google_docs?.remaining_tab_ids.length ?? 0) > 0
    : plan.entries.length === 0;

export const inferWeeklyPlanWizardStep = (
  plan: WeeklyPlan,
  mappings: WeeklyPlanGoogleDocsEmployeeMappings | null,
): WeeklyPlanWizardStep => {
  if (!isEditableWizardPlan(plan)) return "submit";
  if (!weeklyPlanSourceReady(plan, mappings)) return "source";
  if (hasRemainingSourceWork(plan)) return "structure";
  return plan.entries.length > 0 ? "work" : "structure";
};

export const guardWeeklyPlanWizardStep = (
  requested: WeeklyPlanWizardStep,
  plan: WeeklyPlan,
  mappings: WeeklyPlanGoogleDocsEmployeeMappings | null,
): WeeklyPlanWizardStep => {
  if (!isEditableWizardPlan(plan)) return "submit";
  if (requested === "source") return "source";
  if (!weeklyPlanSourceReady(plan, mappings)) return "source";
  if (requested === "structure") return "structure";
  if (hasRemainingSourceWork(plan)) return "structure";
  if (plan.entries.length === 0) return "structure";
  return requested;
};

export type WeeklyPlanAnalysisState = "NOT_RUN" | "STALE" | "CURRENT";

const latestEventId = (events: WeeklyPlanEvent[], types: string[]): number =>
  events
    .filter((event) => types.includes(event.type))
    .reduce((latest, event) => Math.max(latest, event.id), 0);

export const weeklyPlanAnalysisState = (
  events: WeeklyPlanEvent[],
): WeeklyPlanAnalysisState => {
  const latestAnalysis = events
    .filter((event) => event.type === "ANALYZED")
    .reduce<WeeklyPlanEvent | null>(
      (latest, event) => (!latest || event.id > latest.id ? event : latest),
      null,
    );
  if (!latestAnalysis) return "NOT_RUN";
  if (latestAnalysis.metadata?.analyzer !== WEEKLY_PLAN_RULE_ANALYZER_VERSION) {
    return "STALE";
  }
  const invalidatedId = latestEventId(events, [
    "SUGGESTIONS_INVALIDATED",
    "PLAN_RESET",
  ]);
  return invalidatedId > latestAnalysis.id ? "STALE" : "CURRENT";
};

export const currentWeeklyPlanSuggestions = (
  events: WeeklyPlanEvent[],
  suggestions: WeeklyPlanSuggestion[],
): WeeklyPlanSuggestion[] =>
  weeklyPlanAnalysisState(events) === "CURRENT" ? suggestions : [];

export type WeeklyPlanEmployeeWorkGroup = {
  key: string;
  assignee: WeeklyPlanEntry["assignee"];
  name: string;
  sourceTitles: string[];
  entries: WeeklyPlanEntry[];
  itemCount: number;
};

export const groupWeeklyPlanEntriesByEmployee = (
  entries: WeeklyPlanEntry[],
): WeeklyPlanEmployeeWorkGroup[] => {
  const groups = new Map<string, WeeklyPlanEmployeeWorkGroup>();

  for (const entry of entries) {
    const key = entry.assignee
      ? `employee:${entry.assignee.id}`
      : `unassigned:${entry.employee_identifier ?? entry.employee_name ?? entry.id}`;
    const group = groups.get(key) ?? {
      key,
      assignee: entry.assignee,
      name: entry.assignee?.name ?? entry.employee_name ?? "",
      sourceTitles: [],
      entries: [],
      itemCount: 0,
    };
    group.entries.push(entry);
    group.itemCount += entry.items.length;
    for (const passage of entry.source_passages) {
      const title = passage.source_group?.title;
      if (title && !group.sourceTitles.includes(title)) {
        group.sourceTitles.push(title);
      }
    }
    groups.set(key, group);
  }

  return [...groups.values()];
};

export const weeklyPlanWorkCounts = (plan: WeeklyPlan) => ({
  employeeCount: new Set(
    plan.entries
      .map((entry) => entry.assignee?.id)
      .filter((id): id is number => id !== undefined),
  ).size,
  entryCount: plan.entries.length,
  itemCount: plan.entries.reduce(
    (total, entry) => total + entry.items.length,
    0,
  ),
  processedTabCount: plan.source_document.google_docs?.processed_tab_count ?? 0,
});

export type WeeklyPlanSubmissionReadiness = {
  ready: boolean;
  totalBlocking: number;
  noEntries: number;
  missingAssignee: number;
  invalidEntries: number;
  missingExpectedResult: number;
  missingDates: number;
  validEntries: number;
  assignedEmployees: number;
};

export const weeklyPlanSubmissionReadiness = (
  entries: WeeklyPlanEntry[],
): WeeklyPlanSubmissionReadiness => {
  const missingAssignee = entries.filter((entry) => !entry.assignee).length;
  const invalidEntries = entries.filter(
    (entry) => entry.validation_status !== "VALID",
  ).length;
  const missingExpectedResult = entries.filter(
    (entry) => !entry.expected_result?.trim(),
  ).length;
  const missingDates = entries.filter(
    (entry) => !entry.start_date || !entry.due_date,
  ).length;
  const noEntries = entries.length === 0 ? 1 : 0;
  const totalBlocking =
    noEntries +
    entries.filter(
      (entry) =>
        !entry.assignee ||
        entry.validation_status !== "VALID" ||
        !entry.expected_result?.trim() ||
        !entry.start_date ||
        !entry.due_date,
    ).length;

  return {
    ready: totalBlocking === 0,
    totalBlocking,
    noEntries,
    missingAssignee,
    invalidEntries,
    missingExpectedResult,
    missingDates,
    validEntries: entries.length - invalidEntries,
    assignedEmployees: new Set(
      entries
        .map((entry) => entry.assignee?.id)
        .filter((id): id is number => id !== undefined),
    ).size,
  };
};

export const groupingBasisTranslationKey = (
  basis: "SOURCE_STRUCTURE" | "AI_GROUPED" | "ATOMIC",
): string => `wizard.groupingBasis.${basis}`;
