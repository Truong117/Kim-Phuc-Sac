import { assertNoFrontendCredentials } from "./deployment/frontend-environment-safety.mjs";
import { createVercelConfig } from "./deployment/vercel-config.mjs";

assertNoFrontendCredentials(process.env);

export const config = createVercelConfig({
  vercelEnvironment: process.env.VERCEL_ENV,
  gitBranch: process.env.VERCEL_GIT_COMMIT_REF,
  backendOrigin: process.env.KPS_BACKEND_ORIGIN,
  backendEnvironment: process.env.KPS_BACKEND_ENVIRONMENT,
  productionBackendOrigin: process.env.KPS_PRODUCTION_BACKEND_ORIGIN,
  frontendEnvironment: process.env.VITE_KPS_ENVIRONMENT,
  frontendApiBaseUrl: process.env.VITE_API_BASE_URL,
});
