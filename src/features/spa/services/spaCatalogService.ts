import { apiRequest } from "@/services/apiClient";

export type SpaCatalogKind = "service" | "product";
export interface SpaCatalogItem {
  id: string;
  kind: SpaCatalogKind;
  name: string;
  price: number;
  unit: string | null;
  duration_minutes: number | null;
  group: string | null;
  description: string | null;
  requires_skin_photo: boolean;
  version: number;
}
export interface SpaCatalogPage {
  data: SpaCatalogItem[];
  meta: { current_page: number; last_page: number; total: number };
}
export type SpaCatalogWrite = {
  id?: string;
  version?: number;
  kind: SpaCatalogKind;
  name: string;
  price: number;
  unit?: string;
  duration_minutes?: number;
  group?: string;
  description?: string;
  requires_skin_photo?: boolean;
};
export const getSpaCatalogStatus = (signal?: AbortSignal) =>
  apiRequest<{ enabled: boolean; can_manage: boolean }>(
    "/api/spa/catalog/status",
    { signal, cache: "no-store" },
  );
export const getSpaCatalog = (
  kind: SpaCatalogKind,
  search: string,
  page: number,
  signal?: AbortSignal,
) =>
  apiRequest<SpaCatalogPage>(
    `/api/spa/catalog?${new URLSearchParams({ kind, search, page: String(page) })}`,
    { signal, cache: "no-store" },
  );
export const saveSpaCatalogItem = (input: SpaCatalogWrite, id?: string) =>
  apiRequest<{ data: SpaCatalogItem }>(
    `/api/spa/catalog${id ? `/${encodeURIComponent(id)}` : ""}`,
    {
      method: id ? "PATCH" : "POST",
      body: JSON.stringify(input),
    },
  );
