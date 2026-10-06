import type { AuthUser, LoginCredentials } from "@/types/auth";
import { createContext } from "react";

export type AuthContextValue = {
  currentUser: AuthUser | null;
  isAuthenticated: boolean;
  isInitializing: boolean;
  login: (credentials: LoginCredentials) => Promise<void>;
  logout: () => Promise<void>;
};

export const AuthContext = createContext<AuthContextValue | undefined>(
  undefined,
);
