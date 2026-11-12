import type { WorkspaceAccess } from "@/types/workspace";
import type { NavigationKey } from "@/types/authorization";
import { createContext } from "react";

export type AuthorizationContextValue = {
  workspaces: readonly WorkspaceAccess[];
  allowedNavigation: readonly NavigationKey[];
  isAuthorizationLoading: boolean;
  canNavigate: (key: NavigationKey) => boolean;
};

export const AuthorizationContext = createContext<
  AuthorizationContextValue | undefined
>(undefined);
