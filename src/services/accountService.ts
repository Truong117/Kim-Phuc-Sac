import { apiRequest } from "@/services/apiClient";
import type { AuthUser } from "@/types/auth";

export const updateProfile = (name: string) =>
  apiRequest<AuthUser>("/api/account/profile", {
    method: "PATCH",
    body: JSON.stringify({ name }),
  });

export const updatePassword = (payload: {
  current_password: string;
  password: string;
  password_confirmation: string;
}) =>
  apiRequest<AuthUser>("/api/account/password", {
    method: "PATCH",
    body: JSON.stringify(payload),
  });
