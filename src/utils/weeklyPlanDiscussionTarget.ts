import type { WeeklyPlan } from "@/types/weeklyPlanning";

type DiscussionTargetPlan = Pick<WeeklyPlan, "employee_reviews" | "entries">;

export type WeeklyPlanDiscussionTarget = {
  selectedEmployeeId: number | null;
  selectedEntryId: number | null;
};

export type WeeklyPlanDiscussionOption = {
  value: number;
  label: string;
};

export type WeeklyPlanCommentTargetResolution =
  | {
      valid: true;
      assigneeId: number | undefined;
      entryId: number | undefined;
    }
  | {
      valid: false;
      assigneeId: undefined;
      entryId: undefined;
    };

export const normalizeWeeklyPlanDiscussionSearch = (value: string): string =>
  value
    .normalize("NFD")
    .replace(/\p{Diacritic}/gu, "")
    .toLocaleLowerCase("vi-VN")
    .replace(/đ/g, "d")
    .trim();

export const filterWeeklyPlanDiscussionOptions = (
  options: WeeklyPlanDiscussionOption[],
  query: string,
): WeeklyPlanDiscussionOption[] => {
  const normalizedQuery = normalizeWeeklyPlanDiscussionSearch(query);
  if (!normalizedQuery) return options;

  return options.filter((option) =>
    normalizeWeeklyPlanDiscussionSearch(option.label).includes(normalizedQuery),
  );
};

export const createWeeklyPlanDiscussionEmployeeOptions = (
  plan: DiscussionTargetPlan,
): WeeklyPlanDiscussionOption[] => {
  const employees = new Map<number, WeeklyPlanDiscussionOption>();

  plan.employee_reviews.forEach(({ assignee }) => {
    employees.set(assignee.id, { value: assignee.id, label: assignee.name });
  });
  plan.entries.forEach(({ assignee }) => {
    if (assignee && !employees.has(assignee.id)) {
      employees.set(assignee.id, {
        value: assignee.id,
        label: assignee.name,
      });
    }
  });

  return [...employees.values()];
};

export const createWeeklyPlanDiscussionWorkOptions = (
  plan: DiscussionTargetPlan,
  selectedEmployeeId: number,
): WeeklyPlanDiscussionOption[] =>
  plan.entries
    .filter((entry) => entry.assignee?.id === selectedEmployeeId)
    .map((entry) => ({ value: entry.id, label: entry.title }));

export const isWeeklyPlanDiscussionEntryConsistent = (
  plan: Pick<WeeklyPlan, "entries">,
  target: WeeklyPlanDiscussionTarget,
): boolean =>
  target.selectedEntryId === null ||
  (target.selectedEmployeeId !== null &&
    plan.entries.some(
      (entry) =>
        entry.id === target.selectedEntryId &&
        entry.assignee?.id === target.selectedEmployeeId,
    ));

export const isWeeklyPlanDiscussionEmployeeConsistent = (
  plan: DiscussionTargetPlan,
  target: Pick<WeeklyPlanDiscussionTarget, "selectedEmployeeId">,
): boolean =>
  target.selectedEmployeeId === null ||
  createWeeklyPlanDiscussionEmployeeOptions(plan).some(
    (option) => option.value === target.selectedEmployeeId,
  );

export const resolveWeeklyPlanCommentTargetArguments = (
  plan: DiscussionTargetPlan,
  target: WeeklyPlanDiscussionTarget,
): WeeklyPlanCommentTargetResolution => {
  if (!isWeeklyPlanDiscussionEmployeeConsistent(plan, target)) {
    return { valid: false, assigneeId: undefined, entryId: undefined };
  }

  if (target.selectedEntryId !== null) {
    if (!isWeeklyPlanDiscussionEntryConsistent(plan, target)) {
      return { valid: false, assigneeId: undefined, entryId: undefined };
    }

    return {
      valid: true,
      assigneeId: undefined,
      entryId: target.selectedEntryId,
    };
  }

  return {
    valid: true,
    assigneeId: target.selectedEmployeeId ?? undefined,
    entryId: undefined,
  };
};
