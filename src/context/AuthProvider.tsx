import { AuthContext, type AuthContextValue } from "@/context/AuthContext";
import { AuthServiceError } from "@/services/authService";
import * as authService from "@/services/authService";
import type { AuthUser, LoginCredentials } from "@/types/auth";
import { useCallback, useEffect, useMemo, useState } from "react";

export default function AuthProvider({ children }: React.PropsWithChildren) {
  const [currentUser, setCurrentUser] = useState<AuthUser | null>(null);
  const [isInitializing, setIsInitializing] = useState(true);

  useEffect(() => {
    let isActive = true;

    authService
      .getCurrentUser()
      .then((user) => {
        if (isActive) {
          setCurrentUser(user);
        }
      })
      .catch(() => {
        if (isActive) {
          setCurrentUser(null);
        }
      })
      .finally(() => {
        if (isActive) {
          setIsInitializing(false);
        }
      });

    return () => {
      isActive = false;
    };
  }, []);

  const login = useCallback(async (credentials: LoginCredentials) => {
    const user = await authService.login(credentials);
    setCurrentUser(user);
  }, []);

  const logout = useCallback(async () => {
    try {
      await authService.logout();
      setCurrentUser(null);
    } catch (error) {
      if (
        error instanceof AuthServiceError &&
        [401, 419].includes(error.status)
      ) {
        setCurrentUser(null);
        return;
      }

      throw error;
    }
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      currentUser,
      isAuthenticated: currentUser !== null,
      isInitializing,
      login,
      logout,
    }),
    [currentUser, isInitializing, login, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
