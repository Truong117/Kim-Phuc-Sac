// UI preview only; backend workspace capabilities gate screens. Business APIs are not connected.
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

// Keep new Spa forms aligned with KPS brown rather than the template's brand focus.
export const spaFieldClasses =
  "!text-base focus:!border-kps-primary focus:!ring-kps-primary/20 dark:focus:!border-kps-primary dark:focus:!ring-kps-primary/30";
