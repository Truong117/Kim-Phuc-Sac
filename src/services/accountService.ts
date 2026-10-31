import { apiRequest } from "@/services/apiClient";
import type { AuthUser } from "@/types/auth";

export const updateProfile = (name: string, expectedName: string) =>
  apiRequest<AuthUser>("/api/account/profile", {
    method: "PATCH",
    body: JSON.stringify({ name, expected_name: expectedName }),
  });

export type AccountEmailState = {
  email_verified: boolean;
  pending: { email: string; expires_at: string } | null;
};

export const getAccountEmailState = (signal?: AbortSignal) =>
  apiRequest<AccountEmailState>("/api/account/email-change", { signal });

export const requestEmailChange = (email: string, currentPassword: string) =>
  apiRequest<{ message: string }>("/api/account/email-change", {
    method: "POST",
    body: JSON.stringify({ email, current_password: currentPassword }),
  });

export const confirmEmailChange = (token: string, currentPassword: string) =>
  apiRequest<AuthUser>("/api/account/email-change/confirm", {
    method: "POST",
    body: JSON.stringify({ token, current_password: currentPassword }),
  });

export const cancelEmailChange = () =>
  apiRequest<void>("/api/account/email-change", { method: "DELETE" });

export const updatePassword = (payload: {
  current_password: string;
  password: string;
  password_confirmation: string;
}) =>
  apiRequest<AuthUser>("/api/account/password", {
    method: "PATCH",
    body: JSON.stringify(payload),
  });
