import { apiRequest, AuthServiceError } from "@/services/apiClient";
import type { AuthUser, LoginCredentials } from "@/types/auth";

export { AuthServiceError } from "@/services/apiClient";

let currentUserRequest: Promise<AuthUser | null> | null = null;

export const getCsrfCookie = async () => {
  await apiRequest<void>("/sanctum/csrf-cookie");
};

export const login = async (credentials: LoginCredentials) => {
  await getCsrfCookie();

  return apiRequest<AuthUser>("/api/auth/login", {
    method: "POST",
    body: JSON.stringify(credentials),
  });
};

export const getCurrentUser = () => {
  if (!currentUserRequest) {
    currentUserRequest = apiRequest<AuthUser>("/api/auth/me")
      .catch((error: unknown) => {
        if (
          error instanceof AuthServiceError &&
          [401, 419].includes(error.status)
        ) {
          return null;
        }

        throw error;
      })
      .finally(() => {
        currentUserRequest = null;
      });
  }

  return currentUserRequest;
};

export const logout = async () => {
  await apiRequest<void>("/api/auth/logout", { method: "POST" });
};
