import { apiRequest } from "./apiClient";
import type { IntegrationEntry } from "@/components/integrations/model";
export interface IntegrationOwner {
  id: string;
  name: string;
}
export interface IntegrationList {
  data: IntegrationEntry[];
  actions: { can_manage: boolean };
}
export const getIntegrations = (signal?: AbortSignal) =>
  apiRequest<IntegrationList>("/api/integrations", { signal });
export const getIntegrationOwners = (signal?: AbortSignal) =>
  apiRequest<{ data: IntegrationOwner[] }>("/api/integrations/references", {
    signal,
  });
export function saveIntegration(entry: IntegrationEntry) {
  const payload = {
    kind: entry.kind,
    platform: entry.kind === "drive" ? null : entry.platform,
    name: entry.name,
    url: entry.url,
    responsible_user_id: entry.responsible_user_id,
    note: entry.note,
    enabled: entry.enabled,
    ...(entry.id ? { version: entry.version } : {}),
  };
  return apiRequest<{ data: IntegrationEntry }>(
    `/api/integrations${entry.id ? `/${entry.id}` : ""}`,
    { method: entry.id ? "PATCH" : "POST", body: JSON.stringify(payload) },
  );
}
export const deleteIntegration = (entry: IntegrationEntry) =>
  apiRequest<void>(`/api/integrations/${entry.id}`, {
    method: "DELETE",
    body: JSON.stringify({ version: entry.version }),
  });
