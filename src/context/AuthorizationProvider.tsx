import {
  AuthorizationContext,
  type AuthorizationContextValue,
} from "@/context/AuthorizationContext";
import { useAuth } from "@/hooks/useAuth";
import { getWorkspaces } from "@/services/workspaceService";
import type { WorkspaceAccess } from "@/types/workspace";
import { spaUiPreviewEnabled } from "@/features/spa/config";
import { getNavigation } from "@/services/navigationService";
import type { NavigationKey } from "@/types/authorization";
import { useCallback, useEffect, useMemo, useState } from "react";

type AuthorizationState = {
  userId: string | null;
  workspaces: readonly WorkspaceAccess[];
  allowedNavigation: readonly NavigationKey[];
};

const emptyNavigation: readonly NavigationKey[] = [];

const initialState: AuthorizationState = {
  userId: null,
  workspaces: [],
  allowedNavigation: emptyNavigation,
};

export default function AuthorizationProvider({
  children,
}: React.PropsWithChildren) {
  const { currentUser, isAuthenticated } = useAuth();
  const currentUserId = currentUser ? String(currentUser.id) : null;
  const [authorizationState, setAuthorizationState] =
    useState<AuthorizationState>(initialState);

  useEffect(() => {
    let isActive = true;
    const abortController = new AbortController();

    const navigationRequest: Promise<AuthorizationState> = currentUserId
      ? Promise.all([
          getNavigation(abortController.signal),
          getWorkspaces(abortController.signal),
        ])
          .then(([allowedNavigation, workspaces]) => ({
            userId: currentUserId,
            allowedNavigation,
            workspaces: workspaces.filter(
              (item) => item.id !== "spa" || spaUiPreviewEnabled,
            ),
          }))
          .catch(() => ({
            userId: currentUserId,
            allowedNavigation: emptyNavigation,
            workspaces: [],
          }))
      : Promise.resolve(initialState);

    void navigationRequest.then((nextState) => {
      if (isActive) {
        setAuthorizationState(nextState);
      }
    });

    return () => {
      isActive = false;
      abortController.abort();
    };
  }, [currentUserId]);

  const hasCurrentAuthorization = authorizationState.userId === currentUserId;
  const allowedNavigation =
    isAuthenticated && hasCurrentAuthorization
      ? authorizationState.allowedNavigation
      : emptyNavigation;
  const isAuthorizationLoading = isAuthenticated && !hasCurrentAuthorization;

  const canNavigate = useCallback(
    (key: NavigationKey) => allowedNavigation.includes(key),
    [allowedNavigation],
  );

  const workspaces =
    isAuthenticated && hasCurrentAuthorization
      ? authorizationState.workspaces
      : initialState.workspaces;

  const value = useMemo<AuthorizationContextValue>(
    () => ({
      allowedNavigation,
      workspaces,
      isAuthorizationLoading,
      canNavigate,
    }),
    [allowedNavigation, canNavigate, isAuthorizationLoading, workspaces],
  );

  return (
    <AuthorizationContext.Provider value={value}>
      {children}
    </AuthorizationContext.Provider>
  );
}
