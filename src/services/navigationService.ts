import { apiRequest } from "@/services/apiClient";
import { isNavigationKey, type NavigationKey } from "@/types/authorization";

type NavigationPayload = {
  items: unknown[];
};

const isNavigationPayload = (value: unknown): value is NavigationPayload =>
  typeof value === "object" &&
  value !== null &&
  "items" in value &&
  Array.isArray(value.items);

export const getNavigation = async (
  signal?: AbortSignal,
): Promise<NavigationKey[]> => {
  const payload = await apiRequest<unknown>("/api/navigation", { signal });

  if (!isNavigationPayload(payload)) {
    return [];
  }

  return [...new Set(payload.items.filter(isNavigationKey))];
};
