// Preview only. Real spa permissions will come from the backend in a later phase.
export const spaUiPreviewEnabled =
  import.meta.env.VITE_SPA_UI_PREVIEW_ENABLED === "true";

export const spaNavigation = [
  { key: "overview", path: "/spa" },
  { key: "appointments", path: "/spa/appointments" },
  { key: "reception", path: "/spa/reception" },
  { key: "customers", path: "/spa/customers" },
  { key: "services", path: "/spa/services" },
  { key: "treatments", path: "/spa/treatments" },
  { key: "cashier", path: "/spa/cashier" },
  { key: "technician", path: "/spa/technician" },
  { key: "rooms", path: "/spa/rooms" },
  { key: "inventory", path: "/spa/inventory" },
  { key: "care", path: "/spa/care" },
  { key: "reports", path: "/spa/reports" },
  { key: "settings", path: "/spa/settings" },
] as const;

export type SpaNavigationKey = (typeof spaNavigation)[number]["key"];
export type SpaSectionKey = Exclude<
  SpaNavigationKey,
  "overview" | "appointments" | "technician"
>;
