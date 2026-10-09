export type ValidationErrors = Record<string, string[]>;

type ErrorPayload = {
  message?: unknown;
  errors?: ValidationErrors;
};

type SessionExpirationListener = () => void;

const sessionExpirationListeners = new Set<SessionExpirationListener>();

export class ApiServiceError extends Error {
  readonly status: number;
  readonly validationErrors?: ValidationErrors;

  constructor(
    status: number,
    message?: string,
    validationErrors?: ValidationErrors,
  ) {
    super(message ?? `API request failed with status ${status}.`);
    this.name = "ApiServiceError";
    this.status = status;
    this.validationErrors = validationErrors;
  }
}

// Backwards-compatible name for Authentication V1 consumers.
export { ApiServiceError as AuthServiceError };

export const subscribeToSessionExpiration = (
  listener: SessionExpirationListener,
) => {
  sessionExpirationListeners.add(listener);

  return () => {
    sessionExpirationListeners.delete(listener);
  };
};

const notifySessionExpiration = () => {
  sessionExpirationListeners.forEach((listener) => listener());
};

const configuredApiBaseUrl = import.meta.env.VITE_API_BASE_URL?.trim();

const getApiBaseUrl = () => {
  if (!configuredApiBaseUrl) {
    throw new ApiServiceError(0);
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

export const apiRequest = async <T>(
  path: string,
  init: RequestInit = {},
): Promise<T> => {
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
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") {
      throw error;
    }

    throw new ApiServiceError(0);
  }

  if (!response.ok) {
    const payload = await parseErrorPayload(response);
    if ([401, 419].includes(response.status)) {
      notifySessionExpiration();
    }

    throw new ApiServiceError(
      response.status,
      typeof payload.message === "string" ? payload.message : undefined,
      payload.errors,
    );
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return (await response.json()) as T;
};
