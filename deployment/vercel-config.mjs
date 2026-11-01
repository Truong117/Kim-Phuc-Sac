const LOOPBACK_HOSTS = new Set(["localhost", "127.0.0.1", "[::1]"]);
const PRODUCTION_BRANCH = "main";
const STAGING_BRANCH = "staging";

const GIT_DEPLOYMENT_POLICY = Object.freeze({
  "*": false,
  [PRODUCTION_BRANCH]: true,
  [STAGING_BRANCH]: true,
});

export const normalizeBackendOrigin = (
  value,
  variableName = "KPS_BACKEND_ORIGIN",
) => {
  if (typeof value !== "string" || value.trim() === "") {
    throw new Error(
      `${variableName} must be configured for this Vercel environment.`,
    );
  }

  let url;

  try {
    url = new URL(value.trim());
  } catch {
    throw new Error(`${variableName} must be a valid absolute URL.`);
  }

  if (url.username || url.password) {
    throw new Error(`${variableName} must not contain credentials.`);
  }

  if (url.pathname !== "/" || url.search || url.hash) {
    throw new Error(
      `${variableName} must contain only the backend origin, without a path, query, or fragment.`,
    );
  }

  const isLoopback = LOOPBACK_HOSTS.has(url.hostname);
  if (url.protocol !== "https:" && !(url.protocol === "http:" && isLoopback)) {
    throw new Error(
      `${variableName} must use HTTPS outside local development.`,
    );
  }

  return url.origin;
};

export const resolveDeploymentEnvironment = ({
  vercelEnvironment,
  gitBranch,
}) => {
  if (vercelEnvironment === "production") {
    if (gitBranch !== PRODUCTION_BRANCH) {
      throw new Error(
        `Production deployments are allowed only from ${PRODUCTION_BRANCH}.`,
      );
    }

    return "production";
  }

  if (vercelEnvironment === "preview") {
    if (gitBranch !== STAGING_BRANCH) {
      throw new Error(
        `Preview deployments are allowed only from ${STAGING_BRANCH}.`,
      );
    }

    return "staging";
  }

  if (vercelEnvironment === "development") {
    return "development";
  }

  throw new Error(
    "VERCEL_ENV must be production, preview, or development before deployment configuration is generated.",
  );
};

const assertEnvironmentMarker = (name, actual, expected) => {
  if (actual !== expected) {
    throw new Error(`${name} must be ${expected} for this deployment.`);
  }
};

const buildProxyConfiguration = (origin) => ({
  git: {
    deploymentEnabled: { ...GIT_DEPLOYMENT_POLICY },
  },
  rewrites: [
    { source: "/api/:path*", destination: `${origin}/api/:path*` },
    {
      source: "/sanctum/:path*",
      destination: `${origin}/sanctum/:path*`,
    },
    { source: "/(.*)", destination: "/index.html" },
  ],
  headers: [
    {
      source: "/api/:path*",
      headers: [{ key: "x-vercel-enable-rewrite-caching", value: "0" }],
    },
    {
      source: "/sanctum/:path*",
      headers: [{ key: "x-vercel-enable-rewrite-caching", value: "0" }],
    },
  ],
});

export const createVercelConfig = ({
  vercelEnvironment,
  gitBranch,
  backendOrigin,
  backendEnvironment,
  productionBackendOrigin,
  frontendEnvironment,
  frontendApiBaseUrl,
}) => {
  const deploymentEnvironment = resolveDeploymentEnvironment({
    vercelEnvironment,
    gitBranch,
  });

  assertEnvironmentMarker(
    "KPS_BACKEND_ENVIRONMENT",
    backendEnvironment,
    deploymentEnvironment,
  );
  assertEnvironmentMarker(
    "VITE_KPS_ENVIRONMENT",
    frontendEnvironment,
    deploymentEnvironment,
  );

  if (deploymentEnvironment !== "development" && frontendApiBaseUrl !== "/") {
    throw new Error(
      "VITE_API_BASE_URL must be / for Vercel staging and production deployments.",
    );
  }

  const origin = normalizeBackendOrigin(backendOrigin);

  if (deploymentEnvironment === "staging") {
    const productionOrigin = normalizeBackendOrigin(
      productionBackendOrigin,
      "KPS_PRODUCTION_BACKEND_ORIGIN",
    );

    if (origin === productionOrigin) {
      throw new Error(
        "The staging frontend must not use the production backend origin.",
      );
    }
  }

  return buildProxyConfiguration(origin);
};
