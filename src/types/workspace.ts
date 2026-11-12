export type WorkspaceId = "internal" | "spa";
export type WorkspaceAccess = {
  id: WorkspaceId;
  home: string;
  sections: readonly string[];
};
