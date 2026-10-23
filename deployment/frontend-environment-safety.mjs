const CREDENTIAL_LIKE_FRONTEND_VARIABLE =
  /(?:^|_)(?:SECRET|TOKEN|PASSWORD|CREDENTIALS?|PRIVATE_KEY|ACCESS_KEY|API_KEY)(?:_|$)/u;

export const assertNoFrontendCredentials = (environment) => {
  const hasCredentialLikeVariable = Object.keys(environment).some(
    (name) =>
      name.startsWith("VITE_") &&
      CREDENTIAL_LIKE_FRONTEND_VARIABLE.test(name.slice("VITE_".length)),
  );

  if (hasCredentialLikeVariable) {
    throw new Error(
      "Credential-like variables must not use the public VITE_ prefix.",
    );
  }

  const apiBaseUrl = environment.VITE_API_BASE_URL?.trim();

  if (!apiBaseUrl || apiBaseUrl === "/") {
    return;
  }

  let resolvedApiBaseUrl;

  try {
    resolvedApiBaseUrl = new URL(apiBaseUrl, "http://kps-local.invalid");
  } catch {
    throw new Error("VITE_API_BASE_URL must be / or a valid absolute URL.");
  }

  if (resolvedApiBaseUrl.username || resolvedApiBaseUrl.password) {
    throw new Error("VITE_API_BASE_URL must not contain credentials.");
  }

  let parsedApiBaseUrl;

  try {
    parsedApiBaseUrl = new URL(apiBaseUrl);
  } catch {
    throw new Error("VITE_API_BASE_URL must be / or a valid absolute URL.");
  }

  if (!["http:", "https:"].includes(parsedApiBaseUrl.protocol)) {
    throw new Error("VITE_API_BASE_URL must use HTTP or HTTPS.");
  }

  if (
    parsedApiBaseUrl.pathname !== "/" ||
    parsedApiBaseUrl.search ||
    parsedApiBaseUrl.hash
  ) {
    throw new Error(
      "VITE_API_BASE_URL must contain only an origin, without a path, query string, or fragment.",
    );
  }
};
