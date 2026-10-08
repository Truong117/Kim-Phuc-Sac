import {
  AuthorizationContext,
  type AuthorizationContextValue,
} from "@/context/AuthorizationContext";
import { useAuth } from "@/hooks/useAuth";
import { getNavigation } from "@/services/navigationService";
import type { NavigationKey } from "@/types/authorization";
import { useCallback, useEffect, useMemo, useState } from "react";

type AuthorizationState = {
  userId: string | null;
  allowedNavigation: readonly NavigationKey[];
};

const emptyNavigation: readonly NavigationKey[] = [];

const initialState: AuthorizationState = {
  userId: null,
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
      ? getNavigation(abortController.signal)
          .then((allowedNavigation) => ({
            userId: currentUserId,
            allowedNavigation,
          }))
          .catch(() => ({
            userId: currentUserId,
            allowedNavigation: emptyNavigation,
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

  const value = useMemo<AuthorizationContextValue>(
    () => ({
      allowedNavigation,
      isAuthorizationLoading,
      canNavigate,
    }),
    [allowedNavigation, canNavigate, isAuthorizationLoading],
  );

  return (
    <AuthorizationContext.Provider value={value}>
      {children}
    </AuthorizationContext.Provider>
  );
}
