import { apiRequest, ApiServiceError } from "@/services/apiClient";
import { parseWorkspaces } from "@/utils/workspace";
import type { WorkspaceAccess } from "@/types/workspace";

export async function getWorkspaces(
  signal?: AbortSignal,
): Promise<WorkspaceAccess[]> {
  try {
    return parseWorkspaces(
      await apiRequest<unknown>("/api/workspaces", { signal }),
    );
  } catch (error) {
    // Rolling deployment compatibility: an old backend can expose only the existing
    // internal shell. A missing endpoint must never grant Spa access.
    if (error instanceof ApiServiceError && error.status === 404)
      return [{ id: "internal", home: "/", sections: [] }];
    throw error;
  }
}
