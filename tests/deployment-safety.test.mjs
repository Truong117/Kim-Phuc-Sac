import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { assertNoFrontendCredentials } from "../deployment/frontend-environment-safety.mjs";
import {
  createVercelConfig,
  normalizeBackendOrigin,
  resolveDeploymentEnvironment,
} from "../deployment/vercel-config.mjs";

const productionOptions = {
  vercelEnvironment: "production",
  gitBranch: "main",
  backendOrigin: "https://api.example.test",
  backendEnvironment: "production",
  frontendEnvironment: "production",
  frontendApiBaseUrl: "/",
};

const stagingOptions = {
  vercelEnvironment: "preview",
  gitBranch: "staging",
  backendOrigin: "https://staging-api.example.test",
  backendEnvironment: "staging",
  productionBackendOrigin: "https://api.example.test",
  frontendEnvironment: "staging",
  frontendApiBaseUrl: "/",
};

const expectedProxyConfig = (origin) => ({
  git: {
    deploymentEnabled: {
      "*": false,
      main: true,
      staging: true,
    },
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

test("Vercel config fails closed without deployment identity", () => {
  assert.throws(
    () => createVercelConfig({}),
    /VERCEL_ENV must be production, preview, or development/,
  );
});

test("backend origin rejects unsafe or ambiguous values", () => {
  for (const value of [
    "http://api.example.test",
    "https://user:password@api.example.test",
    "https://api.example.test/base",
    "https://api.example.test?target=production",
    "not-a-url",
  ]) {
    assert.throws(() => normalizeBackendOrigin(value));
  }
});

test("loopback HTTP remains available for local Vercel development", () => {
  assert.equal(
    normalizeBackendOrigin("http://localhost:8000/"),
    "http://localhost:8000",
  );

  assert.deepEqual(
    createVercelConfig({
      vercelEnvironment: "development",
      backendOrigin: "http://localhost:8000",
      backendEnvironment: "development",
      frontendEnvironment: "development",
      frontendApiBaseUrl: "http://localhost:8000",
    }),
    expectedProxyConfig("http://localhost:8000"),
  );
});

test("production is accepted only for the main branch", () => {
  assert.equal(
    resolveDeploymentEnvironment({
      vercelEnvironment: "production",
      gitBranch: "main",
    }),
    "production",
  );
  assert.throws(
    () =>
      resolveDeploymentEnvironment({
        vercelEnvironment: "production",
        gitBranch: "staging",
      }),
    /Production deployments are allowed only from main/,
  );
});

test("preview is accepted only for the staging branch", () => {
  assert.equal(
    resolveDeploymentEnvironment({
      vercelEnvironment: "preview",
      gitBranch: "staging",
    }),
    "staging",
  );

  for (const gitBranch of [
    "feature/example",
    "fix/example",
    "main",
    undefined,
  ]) {
    assert.throws(
      () =>
        createVercelConfig({
          ...stagingOptions,
          gitBranch,
          backendOrigin: "https://api.example.test",
        }),
      /Preview deployments are allowed only from staging/,
    );
  }
});

test("environment markers must match the Vercel deployment target", () => {
  assert.throws(
    () =>
      createVercelConfig({
        ...stagingOptions,
        backendEnvironment: "production",
      }),
    /KPS_BACKEND_ENVIRONMENT must be staging/,
  );
  assert.throws(
    () =>
      createVercelConfig({
        ...stagingOptions,
        frontendEnvironment: "production",
      }),
    /VITE_KPS_ENVIRONMENT must be staging/,
  );
});

test("staging cannot target the declared production backend", () => {
  assert.throws(
    () =>
      createVercelConfig({
        ...stagingOptions,
        backendOrigin: "https://api.example.test",
      }),
    /staging frontend must not use the production backend origin/,
  );
  assert.throws(
    () =>
      createVercelConfig({
        ...stagingOptions,
        productionBackendOrigin: undefined,
      }),
    /KPS_PRODUCTION_BACKEND_ORIGIN must be configured/,
  );
});

test("hosted frontends must use the same-origin API proxy", () => {
  for (const options of [productionOptions, stagingOptions]) {
    assert.throws(
      () =>
        createVercelConfig({
          ...options,
          frontendApiBaseUrl: options.backendOrigin,
        }),
      /VITE_API_BASE_URL must be \/ for Vercel staging and production/,
    );
  }
});

test("production and staging proxy API and Sanctum before the SPA fallback", () => {
  assert.deepEqual(
    createVercelConfig(productionOptions),
    expectedProxyConfig("https://api.example.test"),
  );
  assert.deepEqual(
    createVercelConfig(stagingOptions),
    expectedProxyConfig("https://staging-api.example.test"),
  );
});

test("Vercel entrypoint reads deployment identity and environment markers", async () => {
  const variables = {
    VERCEL_ENV: "preview",
    VERCEL_GIT_COMMIT_REF: "staging",
    KPS_BACKEND_ORIGIN: "https://staging-api.example.test",
    KPS_BACKEND_ENVIRONMENT: "staging",
    KPS_PRODUCTION_BACKEND_ORIGIN: "https://api.example.test",
    VITE_KPS_ENVIRONMENT: "staging",
    VITE_API_BASE_URL: "/",
  };
  const previousValues = Object.fromEntries(
    Object.keys(variables).map((name) => [name, process.env[name]]),
  );
  Object.assign(process.env, variables);

  try {
    const { config } = await import(`../vercel.mjs?test=${Date.now()}`);
    assert.equal(
      config.rewrites[0].destination,
      "https://staging-api.example.test/api/:path*",
    );
  } finally {
    for (const [name, value] of Object.entries(previousValues)) {
      if (value === undefined) {
        delete process.env[name];
      } else {
        process.env[name] = value;
      }
    }
  }
});

test("frontend environment templates expose no credential-like variables", async () => {
  for (const file of [
    ".env.example",
    ".env.staging.example",
    ".env.production.example",
  ]) {
    const source = await readFile(
      new URL(`../${file}`, import.meta.url),
      "utf8",
    );
    const variableNames = source
      .split(/\r?\n/u)
      .filter((line) => line.startsWith("VITE_"))
      .map((line) => line.slice(0, line.indexOf("=")));

    for (const variableName of variableNames) {
      assert.doesNotMatch(
        variableName,
        /(SECRET|TOKEN|PASSWORD|CREDENTIAL|PRIVATE_KEY|ACCESS_KEY)/u,
      );
    }
  }
});

test("frontend builds reject credential-like VITE variables without leaking values", () => {
  assert.doesNotThrow(() =>
    assertNoFrontendCredentials({
      VITE_API_BASE_URL: "/",
      VITE_KPS_ENVIRONMENT: "staging",
      SERVER_ONLY_API_KEY: "safe-because-it-is-not-public",
    }),
  );

  for (const variableName of [
    "VITE_SECRET",
    "VITE_TOKEN_VALUE",
    "VITE_API_KEY",
    "VITE_ACCESS_TOKEN",
    "VITE_DATABASE_PASSWORD",
    "VITE_PRIVATE_KEY_PEM",
    "VITE_STORAGE_CREDENTIAL",
  ]) {
    const secretValue = `sensitive-${variableName}`;

    assert.throws(
      () => assertNoFrontendCredentials({ [variableName]: secretValue }),
      (error) => {
        assert.ok(error instanceof Error);
        assert.match(error.message, /must not use the public VITE_ prefix/);
        assert.doesNotMatch(error.message, new RegExp(secretValue));
        return true;
      },
    );
  }
});

test("frontend API base rejects embedded credentials without logging them", () => {
  for (const apiBaseUrl of [
    "https://user:password@api.example.test",
    "//user:password@api.example.test",
  ]) {
    assert.throws(
      () => assertNoFrontendCredentials({ VITE_API_BASE_URL: apiBaseUrl }),
      (error) => {
        assert.ok(error instanceof Error);
        assert.match(
          error.message,
          /VITE_API_BASE_URL must not contain credentials/,
        );
        assert.doesNotMatch(error.message, /user|password/);
        return true;
      },
    );
  }

  assert.doesNotThrow(() =>
    assertNoFrontendCredentials({ VITE_API_BASE_URL: "/" }),
  );
  assert.doesNotThrow(() =>
    assertNoFrontendCredentials({
      VITE_API_BASE_URL: "http://localhost:8000",
    }),
  );
});

test("frontend API base rejects ambiguous or token-bearing values", () => {
  for (const apiBaseUrl of [
    "/api",
    "not-an-absolute-url",
    "javascript:alert(1)",
    "https://api.example.test/base-path",
    "https://api.example.test?token=sensitive-value",
    "https://api.example.test/#sensitive-value",
  ]) {
    assert.throws(
      () => assertNoFrontendCredentials({ VITE_API_BASE_URL: apiBaseUrl }),
      (error) => {
        assert.ok(error instanceof Error);
        assert.doesNotMatch(error.message, /sensitive-value/);
        return true;
      },
    );
  }

  assert.doesNotThrow(() =>
    assertNoFrontendCredentials({ VITE_API_BASE_URL: "https://api.example.test" }),
  );
});

test("Vite invokes the credential guard against files and process variables", async () => {
  const source = await readFile(
    new URL("../vite.config.ts", import.meta.url),
    "utf8",
  );

  assert.match(source, /assertNoFrontendCredentials/);
  assert.match(source, /loadEnv\(mode, process\.cwd\(\), ["']VITE_["']\)/);
  assert.ok(
    source.indexOf("...loadedEnvironment") < source.indexOf("...process.env"),
    "process environment must keep Vite's documented precedence",
  );
});

test("frontend explicitly accepts the same-origin API base", async () => {
  const source = await readFile(
    new URL("../src/services/apiClient.ts", import.meta.url),
    "utf8",
  );

  assert.match(source, /configuredApiBaseUrl === ["']\/["']/);
  assert.match(source, /credentials: ["']include["']/);
  assert.match(source, /getCookie\(["']XSRF-TOKEN["']\)/);
});
