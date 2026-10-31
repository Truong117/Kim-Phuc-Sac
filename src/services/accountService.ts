import { apiRequest } from "@/services/apiClient";
import type { AuthUser } from "@/types/auth";

export const updateProfile = (name: string, expectedName: string) =>
  apiRequest<AuthUser>("/api/account/profile", {
    method: "PATCH",
    body: JSON.stringify({ name, expected_name: expectedName }),
  });

export const updateEmail = (
  email: string,
  currentPassword: string,
  expectedEmail: string,
) =>
  apiRequest<AuthUser>("/api/account/email", {
    method: "PATCH",
    body: JSON.stringify({
      email,
      current_password: currentPassword,
      expected_email: expectedEmail,
    }),
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
