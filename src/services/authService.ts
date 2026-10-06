import type { AuthUser, LoginCredentials } from "@/types/auth";

type ValidationErrors = Record<string, string[]>;

type ErrorPayload = {
  errors?: ValidationErrors;
};

export class AuthServiceError extends Error {
  readonly status: number;
  readonly validationErrors?: ValidationErrors;

  constructor(status: number, validationErrors?: ValidationErrors) {
    super(`Authentication request failed with status ${status}.`);
    this.name = "AuthServiceError";
    this.status = status;
    this.validationErrors = validationErrors;
  }
}

const configuredApiBaseUrl = import.meta.env.VITE_API_BASE_URL?.trim();

const getApiBaseUrl = () => {
  if (!configuredApiBaseUrl) {
    throw new AuthServiceError(0);
  }

  return configuredApiBaseUrl.replace(/\/+$/, "");
};

const getCookie = (name: string) => {
  const prefix = `${name}=`;
  const cookie = document.cookie
    .split(";")
    .map((value) => value.trim())
    .find((value) => value.startsWith(prefix));

  return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : null;
};

const parseErrorPayload = async (response: Response) => {
  try {
    return (await response.json()) as ErrorPayload;
  } catch {
    return {};
  }
};

const request = async <T>(path: string, init: RequestInit = {}): Promise<T> => {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");

  if (init.body && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }

  const method = init.method?.toUpperCase() ?? "GET";
  if (!["GET", "HEAD"].includes(method)) {
    const csrfToken = getCookie("XSRF-TOKEN");
    if (csrfToken) {
      headers.set("X-XSRF-TOKEN", csrfToken);
    }
  }

  let response: Response;

  try {
    response = await fetch(`${getApiBaseUrl()}${path}`, {
      ...init,
      credentials: "include",
      headers,
    });
  } catch {
    throw new AuthServiceError(0);
  }

  if (!response.ok) {
    const payload = await parseErrorPayload(response);
    throw new AuthServiceError(response.status, payload.errors);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return (await response.json()) as T;
};

let currentUserRequest: Promise<AuthUser | null> | null = null;

export const getCsrfCookie = async () => {
  await request<void>("/sanctum/csrf-cookie");
};

export const login = async (credentials: LoginCredentials) => {
  await getCsrfCookie();

  return request<AuthUser>("/api/auth/login", {
    method: "POST",
    body: JSON.stringify(credentials),
  });
};

export const getCurrentUser = () => {
  if (!currentUserRequest) {
    currentUserRequest = request<AuthUser>("/api/auth/me")
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
  await request<void>("/api/auth/logout", { method: "POST" });
};
