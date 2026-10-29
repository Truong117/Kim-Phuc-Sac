import type { WeeklyPlan } from "@/types/weeklyPlanning";

export type WeeklyPlanDiscussionScopeOption = {
  value: string;
  label: string;
};

export type WeeklyPlanDiscussionScopeGroups = {
  plan: WeeklyPlanDiscussionScopeOption;
  employees: WeeklyPlanDiscussionScopeOption[];
  works: WeeklyPlanDiscussionScopeOption[];
};

type DiscussionScopePlan = Pick<WeeklyPlan, "employee_reviews" | "entries">;

export const createWeeklyPlanDiscussionScopes = (
  plan: DiscussionScopePlan,
  wholePlanLabel: string,
): WeeklyPlanDiscussionScopeGroups => ({
  plan: { value: "plan", label: wholePlanLabel },
  employees: plan.employee_reviews.map((review) => ({
    value: `employee:${review.assignee.id}`,
    label: review.assignee.name,
  })),
  works: plan.entries.map((entry) => ({
    value: `entry:${entry.id}`,
    label: entry.title,
  })),
});

const normalizeSearchText = (value: string): string =>
  value
    .normalize("NFD")
    .replace(/\p{Diacritic}/gu, "")
    .toLocaleLowerCase("vi-VN")
    .trim();

export const filterWeeklyPlanDiscussionScopes = (
  groups: WeeklyPlanDiscussionScopeGroups,
  query: string,
): WeeklyPlanDiscussionScopeGroups => {
  const normalizedQuery = normalizeSearchText(query);
  const matches = (option: WeeklyPlanDiscussionScopeOption): boolean =>
    normalizeSearchText(option.label).includes(normalizedQuery);

  return {
    plan: groups.plan,
    employees: normalizedQuery
      ? groups.employees.filter(matches)
      : groups.employees,
    works: normalizedQuery ? groups.works.filter(matches) : groups.works,
  };
};

export const findWeeklyPlanDiscussionScope = (
  groups: WeeklyPlanDiscussionScopeGroups,
  value: string,
): WeeklyPlanDiscussionScopeOption =>
  [groups.plan, ...groups.employees, ...groups.works].find(
    (option) => option.value === value,
  ) ?? groups.plan;
