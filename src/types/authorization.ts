export const navigationKeys = [
  "dashboard",
  "reports.new",
  "reports.history",
  "tasks",
  "customers",
  "products",
  "orders",
  "ai.insights",
  "ai.assistant",
  "employees",
  "integrations",
  "settings",
] as const;

export type NavigationKey = (typeof navigationKeys)[number];

export const isNavigationKey = (value: unknown): value is NavigationKey =>
  typeof value === "string" && navigationKeys.includes(value as NavigationKey);
