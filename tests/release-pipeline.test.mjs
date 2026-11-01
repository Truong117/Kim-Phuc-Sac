import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import test from "node:test";
import { assertIdle, assertRenderService, releaseConfig, runRelease } from "../deployment/release.mjs";
import { inspectorEnvironment, verifyMigrationPolicy } from "../deployment/staging-database.mjs";

const sha = "a".repeat(40);
const env = {
  GITHUB_SHA: sha, GITHUB_REF: "refs/heads/staging", GITHUB_REPOSITORY: "Truong117/Kim-Phuc-Sac",
  GH_TOKEN: "fake-gh", RENDER_API_KEY: "fake-render", VERCEL_TOKEN: "fake-vercel",
  RENDER_SERVICE_ID: "srv-staging", RENDER_PRODUCTION_SERVICE_ID: "srv-production",
  BACKEND_ORIGIN: "https://staging-api.example.test", PRODUCTION_BACKEND_ORIGIN: "https://production-api.example.test",
  FRONTEND_ORIGIN: "https://kim-phuc-sac-git-staging-tristan-7343.vercel.app", VERCEL_PROJECT_ID: "prj-test", VERCEL_TEAM_ID: "team-test",
  STAGING_DB_INSPECTOR_ENV: "{}", STAGING_DB_CA_PEM: "fake-ca", STAGING_SMOKE_EMAIL: "fake@example.test",
  STAGING_SMOKE_PASSWORD: "fake-password", AUTO_DEPLOY_DISABLED_ACK: "verified",
};
const service = {
  id: env.RENDER_SERVICE_ID, ownerId: "tea-db31pf8m7kps73cnjl30", branch: "staging",
  repo: "https://github.com/Truong117/Kim-Phuc-Sac", autoDeploy: "no", autoDeployTrigger: "off",
  suspended: "not_suspended", rootDir: "backend/",
  serviceDetails: { plan: "free", numInstances: 1, url: env.BACKEND_ORIGIN, env: "docker",
    envSpecificDetails: { dockerCommand: "" }, healthCheckPath: "/up" },
};
const db = { status: "PASS", ledger: { pending_migrations: [], unexpected_migrations: [], applied_count: 22 }, writes_executed: false };
const result = (body, status = 200, headers = {}) => new Response(status === 204 ? null : typeof body === "string" ? body : JSON.stringify(body), { status, headers });

function fixture({ failedPhase, existingLock = false, active = false, stale = false } = {}) {
  const calls = [];
  let loggedIn = false;
  const fetcher = async (url, options = {}) => {
    const { hostname, pathname: path } = new URL(url);
    const method = options.method ?? "GET";
    const body = options.body ? JSON.parse(options.body) : null;
    calls.push({ hostname, path, method, body });
    if (hostname === "api.github.com") {
      if (path.endsWith("/branches/staging")) return result({ commit: { sha: stale ? "b".repeat(40) : sha } });
      if (path.endsWith("/git/refs") && method === "POST") return result({}, existingLock ? 422 : 201);
      if (method === "DELETE") return result(null, 204);
      if (path.endsWith("/git/ref/tags/kps-release-lock")) return result({ object: { sha } });
    }
    if (hostname === "api.render.com") {
      if (path.endsWith("/services/srv-staging")) return result(service);
      if (path.endsWith("/deploys") && method === "GET") return result([{ deploy: { id: "old", status: active ? "build_in_progress" : "live", commit: { id: "b".repeat(40) } } }]);
      if (path.endsWith("/deploys") && method === "POST") return result({ id: "dep-new" }, failedPhase === "backend-create" ? 503 : 201);
      if (path.endsWith("/dep-new")) return result({ id: "dep-new", status: failedPhase === "timeout" ? "build_in_progress" : "live", commit: { id: sha } });
    }
    if (hostname === "api.vercel.com") {
      if (path.includes("/projects/")) return result({ id: env.VERCEL_PROJECT_ID, accountId: env.VERCEL_TEAM_ID, name: "test", autoExposeSystemEnvs: true, link: { type: "github", repoId: 1383601023 }, gitProviderOptions: { createDeployments: "disabled" } });
      if (path === "/v6/deployments") return result({ deployments: [] });
      if (path.startsWith("/v4/aliases/")) return result({ deploymentId: "dpl-old" });
      if (path.endsWith("/dpl-old")) return result({ readyState: "READY", meta: { githubCommitSha: "b".repeat(40) } });
      if (path === "/v13/deployments") return result({ id: "dpl-new" });
      if (path.endsWith("/dpl-new")) return result({ readyState: failedPhase === "frontend" ? "ERROR" : "READY", meta: { githubCommitSha: sha } });
      if (path.endsWith("/aliases")) return result({});
    }
    if (path === "/api/health") return result({ status: "ok", service: "kps-backend", revision: sha, environment: "staging" });
    if (path === "/login") return result('<div id="root"></div>');
    if (path === "/release.json") return result({ sha, environment: "staging" });
    if (path === "/sanctum/csrf-cookie") return result(null, 204, { "set-cookie": "XSRF-TOKEN=test; Secure" });
    if (path === "/api/auth/login") {
      assert.equal(body.email, env.STAGING_SMOKE_EMAIL);
      assert.equal(options.headers["X-XSRF-TOKEN"], "test");
      loggedIn = true;
      return result({});
    }
    if (path === "/api/auth/logout") { loggedIn = false; return result(null, 204); }
    if (["/api/auth/me", "/api/navigation", "/api/reports/today"].includes(path)) return result({}, loggedIn ? 200 : 401);
    throw new Error(`Unexpected fixture request ${method} ${path}`);
  };
  const inspect = async (_root, _env, save) => {
    calls.push({ method: "INSPECT" });
    await save(db);
    if (failedPhase === "database") throw new Error("Pending migration; stop");
  };
  return { calls, fetcher, inspect, sleep: async () => {} };
}

test("rejects Production, missing config, unsafe target and non-Free plans before mutation", () => {
  assert.throws(() => releaseConfig(env, "production"), /disabled/);
  assert.throws(() => releaseConfig({ ...env, VERCEL_TOKEN: "" }, "staging"), /VERCEL_TOKEN/);
  assert.throws(() => releaseConfig({ ...env, GITHUB_REF: "refs/heads/main" }, "staging"), /trusted/);
  assert.throws(() => releaseConfig({ ...env, BACKEND_ORIGIN: env.PRODUCTION_BACKEND_ORIGIN }, "staging"), /distinct/);
  assert.throws(() => assertRenderService({ ...service, autoDeploy: "yes" }, env), /mismatch/);
  assert.throws(() => assertRenderService({ ...service, serviceDetails: { ...service.serviceDetails, plan: "starter" } }, env), /Free/);
  assert.throws(() => assertIdle([{ deploy: { status: "build_in_progress" } }], []), /active/);
  assert.throws(() => assertIdle([], [{ state: "QUEUED" }]), /active/);
});

test("orders DB gate → backend exact SHA → frontend exact SHA → authenticated smoke, releases lock only after postflight", async () => {
  const mock = fixture();
  await runRelease(env, mock);
  const backend = mock.calls.findIndex((call) => call.hostname === "api.render.com" && call.method === "POST");
  const frontend = mock.calls.findIndex((call) => call.path === "/v13/deployments" && call.method === "POST");
  assert.ok(mock.calls.findIndex((call) => call.method === "INSPECT") < backend);
  assert.ok(backend < frontend);
  assert.equal(mock.calls[backend].body.commitId, sha);
  assert.equal(mock.calls[frontend].body.gitSource.sha, sha);
  assert.equal(mock.calls.filter((call) => call.method === "INSPECT").length, 2);
  assert.equal(mock.calls.at(-1).method, "DELETE");
  const evidence = JSON.parse(await readFile(new URL("../release-evidence.json", import.meta.url)));
  assert.equal(evidence.status, "success");
  assert.equal(evidence.backend.sha, sha);
  assert.equal(evidence.frontend.sha, sha);
  assert.equal(evidence.migration.writes_executed, false);
  assert.ok(!JSON.stringify(evidence).includes("fake-password"));
});

test("holds durable lock after DB/backend/frontend failure and timeout, never retries POST or rolls DB back", async () => {
  for (const failedPhase of ["database", "backend-create", "frontend", "timeout"]) {
    const mock = fixture({ failedPhase });
    await assert.rejects(runRelease(env, mock));
    assert.ok(!mock.calls.some((call) => call.method === "DELETE"));
    assert.ok(mock.calls.filter((call) => call.hostname === "api.render.com" && call.method === "POST").length <= 1);
    const evidence = JSON.parse(await readFile(new URL("../release-evidence.json", import.meta.url)));
    assert.equal(evidence.lock, "held");
    assert.equal(evidence.status, "failed");
    if (failedPhase === "database") assert.ok(!mock.calls.some((call) => call.hostname !== "api.github.com" && call.method === "POST"));
  }
});

test("existing lock, in-flight external deployment and stale SHA stop before DB or deploy", async () => {
  for (const options of [{ existingLock: true }, { active: true }, { stale: true }]) {
    const mock = fixture(options);
    await assert.rejects(runRelease(env, mock));
    assert.ok(!mock.calls.some((call) => call.method === "INSPECT"));
    assert.ok(!mock.calls.some((call) => call.hostname !== "api.github.com" && call.method === "POST"));
  }
});

test("migration inventory hashes are pinned; inspector cannot inherit DB_URL, credentials or arbitrary env", async () => {
  const policy = await verifyMigrationPolicy(fileURLToPath(new URL("../", import.meta.url)));
  assert.equal(policy.migrations.length, 22);
  assert.equal(policy.migrations[18].risk, "incompatible-data-transition");
  assert.throws(() => inspectorEnvironment({ DB_URL: "do-not-leak" }, "/tmp/ca.pem"), /exactly/);
});
