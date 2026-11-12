import type { WorkspaceAccess, WorkspaceId } from "@/types/workspace";

export const workspaceForPath = (pathname: string): WorkspaceId =>
  pathname === "/spa" || pathname.startsWith("/spa/") ? "spa" : "internal";

export const workspacePreferenceKey = (userId: string) =>
  `kps.workspace.${userId}`;

export const readWorkspacePreference = (userId: string): string | null => {
  try {
    return localStorage.getItem(workspacePreferenceKey(userId));
  } catch {
    return null;
  }
};
export const rememberWorkspace = (userId: string, workspace: WorkspaceId) => {
  try {
    localStorage.setItem(workspacePreferenceKey(userId), workspace);
  } catch {
    /* Storage is optional; permissions always come from the server. */
  }
};

export const defaultWorkspacePath = (
  workspaces: readonly WorkspaceAccess[],
  preferred: string | null,
): string | null =>
  (
    workspaces.find((item) => item.id === preferred) ??
    workspaces.find((item) => item.id === "internal") ??
    workspaces[0]
  )?.home ?? null;

export const canOpenWorkspacePath = (
  workspaces: readonly WorkspaceAccess[],
  path: string,
): boolean => {
  if (
    !path.startsWith("/") ||
    path.startsWith("//") ||
    path.includes("\\") ||
    [...path].some((char) => char.charCodeAt(0) <= 32)
  )
    return false;
  const pathname = path.split(/[?#]/)[0];
  const access = workspaces.find(
    (item) => item.id === workspaceForPath(pathname),
  );
  if (!access || ["/login", "/workspace"].includes(pathname)) return false;
  if (access.id === "internal") return true; // Per-page AuthorizationRoute still applies.
  const section = pathname.split("/")[2] || "overview";
  return (
    ["profile", "account"].includes(section) ||
    access.sections.includes(section)
  );
};

export const parseWorkspaces = (payload: unknown): WorkspaceAccess[] => {
  if (
    !payload ||
    typeof payload !== "object" ||
    !("workspaces" in payload) ||
    !Array.isArray(payload.workspaces)
  )
    return [];
  const result: WorkspaceAccess[] = [];
  for (const item of payload.workspaces) {
    if (
      !item ||
      typeof item !== "object" ||
      !["internal", "spa"].includes(item.id) ||
      typeof item.home !== "string" ||
      !Array.isArray(item.sections) ||
      !item.sections.every((key: unknown) => typeof key === "string") ||
      result.some((old) => old.id === item.id)
    )
      continue;
    const access: WorkspaceAccess = {
      id: item.id,
      home: item.home,
      sections: item.sections,
    };
    const validHome =
      access.id === "internal"
        ? access.home === "/"
        : access.home === "/spa" || /^\/spa\/[a-z-]+$/.test(access.home);
    if (validHome && canOpenWorkspacePath([access], access.home))
      result.push(access);
  }
  return result;
};
