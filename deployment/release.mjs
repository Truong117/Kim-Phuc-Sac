import { appendFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { resolve } from "node:path";
import { setTimeout as delay } from "node:timers/promises";
import { inspectStaging } from "./staging-database.mjs";
import { normalizeBackendOrigin } from "./vercel-config.mjs";

const REPOSITORY = "Truong117/Kim-Phuc-Sac";
const LOCK = "tags/kps-release-lock";
const RENDER_TERMINAL = new Set(["live", "deactivated", "build_failed", "pre_deploy_failed", "update_failed", "canceled"]);
const VERCEL_TERMINAL = new Set(["READY", "ERROR", "CANCELED", "BLOCKED"]);
const root = fileURLToPath(new URL("../", import.meta.url));

export function releaseConfig(env, target) {
  if (target !== "staging") throw new Error("Production deploy/migration is disabled in this phase.");
  const names = ["GITHUB_SHA", "GITHUB_REPOSITORY", "GH_TOKEN", "RENDER_API_KEY", "VERCEL_TOKEN",
    "RENDER_SERVICE_ID", "RENDER_PRODUCTION_SERVICE_ID", "BACKEND_ORIGIN", "PRODUCTION_BACKEND_ORIGIN",
    "FRONTEND_ORIGIN", "VERCEL_PROJECT_ID", "VERCEL_TEAM_ID", "STAGING_DB_INSPECTOR_ENV",
    "STAGING_DB_CA_PEM", "STAGING_SMOKE_EMAIL", "STAGING_SMOKE_PASSWORD", "AUTO_DEPLOY_DISABLED_ACK"];
  const missing = names.filter((name) => !env[name]);
  if (missing.length) throw new Error(`Missing configuration: ${missing.join(", ")}`);
  if (env.GITHUB_REPOSITORY !== REPOSITORY || env.GITHUB_REF !== "refs/heads/staging" ||
      !/^[a-f0-9]{40}$/.test(env.GITHUB_SHA) || env.AUTO_DEPLOY_DISABLED_ACK !== "verified") {
    throw new Error("Release requires trusted staging ref, exact SHA and verified dashboard auto-deploy controls.");
  }
  if (env.RENDER_SERVICE_ID === env.RENDER_PRODUCTION_SERVICE_ID ||
      normalizeBackendOrigin(env.BACKEND_ORIGIN) === normalizeBackendOrigin(env.PRODUCTION_BACKEND_ORIGIN) ||
      normalizeBackendOrigin(env.FRONTEND_ORIGIN) !== "https://kim-phuc-sac-git-staging-tristan-7343.vercel.app") {
    throw new Error("Staging and Production targets must be distinct.");
  }
  return { ...env, BACKEND_ORIGIN: normalizeBackendOrigin(env.BACKEND_ORIGIN), FRONTEND_ORIGIN: normalizeBackendOrigin(env.FRONTEND_ORIGIN) };
}

export function assertRenderService(service, env) {
  const details = service.serviceDetails;
  if (service.id !== env.RENDER_SERVICE_ID || service.ownerId !== "tea-db31pf8m7kps73cnjl30" ||
      service.branch !== "staging" || service.repo?.replace(/\.git$/, "") !== `https://github.com/${REPOSITORY}` ||
      service.autoDeploy !== "no" || (service.autoDeployTrigger && service.autoDeployTrigger !== "off") ||
      service.suspended !== "not_suspended" || details?.plan !== "free" || details.numInstances !== 1 ||
      normalizeBackendOrigin(details.url) !== env.BACKEND_ORIGIN || service.rootDir?.replace(/\/$/, "") !== "backend" ||
      details.env !== "docker" || details.envSpecificDetails?.dockerCommand ||
      !["/up", "/api/health"].includes(details.healthCheckPath)) {
    throw new Error("Render identity/configuration mismatch, auto-deploy enabled, or service is not one Free instance.");
  }
}

export function assertIdle(renderDeploys, vercelDeploys) {
  if (renderDeploys.some((item) => !RENDER_TERMINAL.has((item.deploy ?? item).status)) ||
      vercelDeploys.some((item) => !VERCEL_TERMINAL.has(item.readyState ?? item.state))) {
    throw new Error("An external deployment is active or has unknown state. Do not overlap releases.");
  }
}

export async function runRelease(environment, { fetcher = fetch, sleep = delay, inspect = inspectStaging } = {}) {
  const evidence = { environment: "staging", candidate_sha: environment.GITHUB_SHA ?? null,
    run_id: environment.GITHUB_RUN_ID ?? null, started_at: new Date().toISOString(),
    status: "started", phase: "configuration", lock: "not_acquired", database: null,
    migration: { writes_executed: false, status: "not_checked" }, backend: null, frontend: null, smoke: "not_run" };
  const save = async () => writeFile(resolve(root, "release-evidence.json"), JSON.stringify(evidence, null, 2) + "\n");
  await save();
  const api = async (provider, path, { method = "GET", body } = {}) => {
    const base = provider === "github" ? "https://api.github.com" : provider === "render" ? "https://api.render.com" : "https://api.vercel.com";
    const token = provider === "github" ? environment.GH_TOKEN : provider === "render" ? environment.RENDER_API_KEY : environment.VERCEL_TOKEN;
    const url = new URL(path, base);
    if (provider === "vercel") url.searchParams.set("teamId", environment.VERCEL_TEAM_ID);
    const response = await fetcher(url, { method, redirect: "error", signal: AbortSignal.timeout(30_000),
      headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json", Accept: "application/json" },
      ...(body ? { body: JSON.stringify(body) } : {}) });
    // Do not print API response bodies: those may contain environment values.
    if (!response.ok) throw new Error(`${provider} ${method} request failed (HTTP ${response.status}); inspect provider dashboard.`);
    return response.status === 204 ? null : response.json();
  };
  const gh = (path, options) => api("github", `/repos/${REPOSITORY}/${path}`, options);
  const renderPath = `/v1/services/${environment.RENDER_SERVICE_ID}`;
  const latest = async () => {
    const branch = await gh("branches/staging");
    if (branch.commit.sha !== environment.GITHUB_SHA) throw new Error("Stale candidate: staging advanced. Run the newest commit after reviewing any held lock.");
  };
  const idle = async () => {
    const render = await api("render", `${renderPath}/deploys?limit=100`);
    const vercel = await api("vercel", `/v6/deployments?projectId=${environment.VERCEL_PROJECT_ID}&limit=100`);
    assertIdle(render, vercel.deployments);
    return { render, vercel: vercel.deployments };
  };
  const poll = async (provider, path, success, failure) => {
    for (let attempt = 0; attempt < 120; attempt++) {
      const value = await api(provider, path);
      if (success(value)) return value;
      if (failure(value)) throw new Error(`${provider} deployment failed; database will not be rolled back.`);
      await sleep(10_000);
    }
    throw new Error(`${provider} deployment timed out; the provider may still be running. Keep the release lock.`);
  };
  try {
    const env = releaseConfig(environment, "staging");
    await latest();
    const service = await api("render", renderPath);
    assertRenderService(service, env);
    const project = await api("vercel", `/v9/projects/${env.VERCEL_PROJECT_ID}`);
    if (project.id !== env.VERCEL_PROJECT_ID || project.accountId !== env.VERCEL_TEAM_ID ||
        project.link?.type !== "github" || String(project.link.repoId) !== "1383601023" ||
        project.gitProviderOptions?.createDeployments !== "disabled" || project.autoExposeSystemEnvs !== true) {
      throw new Error("Vercel project/repository identity or dashboard Git auto-deploy policy is not verified disabled.");
    }
    const before = await idle();
    const live = before.render.map((item) => item.deploy ?? item).find((item) => item.status === "live");
    evidence.backend = { before_sha: live?.commit?.id ?? null, before_deploy_id: live?.id ?? null, sha: null, deploy_id: null };
    const aliasHost = new URL(env.FRONTEND_ORIGIN).hostname;
    const previousAlias = await api("vercel", `/v4/aliases/${aliasHost}`);
    const previousFrontend = await api("vercel", `/v13/deployments/${previousAlias.deploymentId}`);
    evidence.frontend = { before_sha: previousFrontend.meta?.githubCommitSha ?? previousFrontend.gitSource?.sha ?? null,
      before_deploy_id: previousAlias.deploymentId, sha: null, deploy_id: null };
    // Atomic durable lock. A failed/canceled workflow never releases it automatically.
    await gh("git/refs", { method: "POST", body: { ref: `refs/${LOCK}`, sha: env.GITHUB_SHA } });
    evidence.lock = "held";
    evidence.phase = "database_verification";
    await save();
    await inspect(root, env, async (database) => { evidence.database = database; await save(); });
    evidence.phase = "migration_gate";
    evidence.migration.status = "no_pending_migrations";
    await save();
    // No automatic DDL in phase 1. Existing reviewed interactive runner remains
    // the only mutation path; pending/schema drift blocks before any deploy.
    await latest();
    await idle();
    assertRenderService(await api("render", renderPath), env);
    evidence.phase = "backend_deploy";
    await save();
    const backend = await api("render", `${renderPath}/deploys`, { method: "POST", body: { commitId: env.GITHUB_SHA, clearCache: "do_not_clear" } });
    evidence.backend.deploy_id = backend.id;
    await save();
    const deployed = await poll("render", `${renderPath}/deploys/${backend.id}`,
      (item) => item.status === "live", (item) => RENDER_TERMINAL.has(item.status));
    evidence.backend.sha = deployed.commit?.id ?? null;
    if (evidence.backend.sha !== env.GITHUB_SHA) throw new Error("Render deployed a different SHA.");
    await save();
    await health(env.BACKEND_ORIGIN, env.GITHUB_SHA, fetcher);
    await latest();
    await idle();
    evidence.phase = "frontend_deploy";
    await save();
    const frontend = await api("vercel", "/v13/deployments", { method: "POST", body: {
      name: project.name, project: project.id,
      gitSource: { type: "github", repoId: "1383601023", ref: "staging", sha: env.GITHUB_SHA },
      meta: { kpsReleaseSha: env.GITHUB_SHA, kpsReleaseRun: env.GITHUB_RUN_ID ?? "manual" },
    } });
    evidence.frontend = { ...evidence.frontend, deploy_id: frontend.id, sha: null };
    await save();
    const ready = await poll("vercel", `/v13/deployments/${frontend.id}`,
      (item) => item.readyState === "READY", (item) => ["ERROR", "CANCELED", "BLOCKED"].includes(item.readyState));
    evidence.frontend.sha = ready.meta?.githubCommitSha ?? ready.gitSource?.sha ?? null;
    if (evidence.frontend.sha !== env.GITHUB_SHA) throw new Error("Vercel deployed a different SHA.");
    // Bind the stable authenticated staging alias only after the build is READY.
    const currentAlias = await api("vercel", `/v4/aliases/${aliasHost}`);
    if (currentAlias.deploymentId !== frontend.id) {
      await api("vercel", `/v2/deployments/${frontend.id}/aliases`, { method: "POST", body: { alias: aliasHost } });
    }
    evidence.phase = "smoke";
    await save();
    await smoke(env, fetcher);
    // Recheck DB/schema and provider revision after smoke; never report a partial
    // frontend/backend release as successful.
    await inspect(root, env, async (database) => { evidence.database = database; await save(); });
    const finalBackend = await api("render", `${renderPath}/deploys/${backend.id}`);
    if (finalBackend.status !== "live") throw new Error("Backend changed during release.");
    await idle();
    const lock = await gh(`git/ref/${LOCK}`);
    if (lock.object.sha !== env.GITHUB_SHA) throw new Error("Release lock ownership changed.");
    await gh(`git/refs/${LOCK}`, { method: "DELETE" });
    evidence.lock = "released";
    evidence.smoke = "passed";
    evidence.status = "success";
  } catch (error) {
    evidence.status = "failed";
    evidence.failure = error.message;
    throw error;
  } finally {
    evidence.finished_at = new Date().toISOString();
    await save();
    if (environment.GITHUB_STEP_SUMMARY) await appendFile(environment.GITHUB_STEP_SUMMARY,
      `Release ${evidence.status}; phase: ${evidence.phase}; candidate: ${evidence.candidate_sha}; lock: ${evidence.lock}. See redacted release artifact. No automatic DB rollback.\n`);
  }
}

async function publicRequest(url, fetcher, options = {}) {
  const response = await fetcher(url, { redirect: "error", signal: AbortSignal.timeout(90_000), ...options });
  return response;
}

async function health(origin, sha, fetcher, headers = {}) {
  const response = await publicRequest(`${origin}/api/health`, fetcher, { headers });
  if (!response.ok) throw new Error("Backend health HTTP check failed.");
  const data = await response.json();
  if (data.status !== "ok" || data.service !== "kps-backend" || data.revision !== sha || data.environment !== "staging") {
    throw new Error("Backend health environment/revision check failed.");
  }
}

export async function smoke(env, fetcher) {
  const origin = env.FRONTEND_ORIGIN;
  const bypass = env.VERCEL_AUTOMATION_BYPASS_SECRET ? { "x-vercel-protection-bypass": env.VERCEL_AUTOMATION_BYPASS_SECRET } : {};
  const page = await publicRequest(`${origin}/login`, fetcher, { headers: bypass });
  if (!page.ok || !(await page.text()).includes('id="root"')) throw new Error("Frontend login page unavailable.");
  const revision = await publicRequest(`${origin}/release.json`, fetcher, { headers: bypass });
  if (!revision.ok) throw new Error("Frontend release metadata unavailable.");
  const metadata = await revision.json();
  if (metadata.sha !== env.GITHUB_SHA || metadata.environment !== "staging") throw new Error("Frontend stable alias has wrong revision/environment.");
  await health(origin, env.GITHUB_SHA, fetcher, bypass);
  const cookies = new Map();
  const request = async (path, { method = "GET", body } = {}) => {
    const xsrf = cookies.get("XSRF-TOKEN");
    const response = await publicRequest(`${origin}${path}`, fetcher, { method,
      headers: { ...bypass, Accept: "application/json", Origin: origin, Referer: `${origin}/`,
        "Content-Type": "application/json", Cookie: [...cookies].map(([key, value]) => `${key}=${value}`).join("; "),
        ...(xsrf ? { "X-XSRF-TOKEN": decodeURIComponent(xsrf) } : {}) },
      ...(body ? { body: JSON.stringify(body) } : {}) });
    for (const cookie of response.headers.getSetCookie()) {
      const pair = cookie.split(";", 1)[0];
      const separator = pair.indexOf("=");
      if (separator > 0) cookies.set(pair.slice(0, separator), pair.slice(separator + 1));
    }
    return response;
  };
  if (!(await request("/sanctum/csrf-cookie")).ok || !cookies.has("XSRF-TOKEN")) throw new Error("CSRF/session proxy failed.");
  const login = await request("/api/auth/login", { method: "POST", body: { email: env.STAGING_SMOKE_EMAIL, password: env.STAGING_SMOKE_PASSWORD } });
  if (!login.ok) throw new Error("Staging smoke login failed.");
  try {
    for (const path of ["/api/auth/me", "/api/navigation", "/api/reports/today"]) {
      if (!(await request(path)).ok) throw new Error("Authenticated staging smoke failed.");
    }
  } finally {
    if (!(await request("/api/auth/logout", { method: "POST" })).ok) throw new Error("Smoke logout failed.");
  }
  if ((await request("/api/auth/me")).status !== 401) throw new Error("Session remained authenticated after logout.");
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  if (process.argv[2] !== "staging") {
    console.error("Only staging is enabled. Production is blocked.");
    process.exitCode = 1;
  } else {
    await runRelease(process.env).catch((error) => { console.error(error.message); process.exitCode = 1; });
  }
}
