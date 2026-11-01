import { appendFile, writeFile } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { setTimeout as delay } from "node:timers/promises";
import { verifyMigrationPolicy } from "./staging-database.mjs";

const REPO = "Truong117/Kim-Phuc-Sac";
const MAIN = "refs/heads/main";
const SERVICE = "srv-db3esa59fdbs73dg62lg";
const PROJECT = "prj_sSEBHcwALQHag7fQQxFmbisxocz4";
const TEAM = "team_hJaxsqskjIBmzfrdt8eI9Sky";
const API_ORIGIN = "https://kim-phuc-sac.onrender.com";
const SITE_ORIGIN = "https://kimphucsac.vercel.app";
const LOCK = "tags/kps-release-lock";
const root = fileURLToPath(new URL("../", import.meta.url));
const terminals = new Set(["live", "deactivated", "build_failed", "pre_deploy_failed", "update_failed", "canceled"]);

export function validateReleaseInput(env) {
  const required = ["GITHUB_SHA", "GITHUB_REF", "GITHUB_REPOSITORY", "GH_TOKEN",
    "RENDER_API_KEY", "VERCEL_TOKEN", "VERCEL_ORG_ID", "VERCEL_PROJECT_ID",
    "VERCEL_AUTOMATION_BYPASS_SECRET", "KPS_RELEASE_CONFIRMATION"];
  const missing = required.filter((key) => !env[key]);
  if (missing.length) throw new Error("Missing protected release configuration: " + missing.join(", "));
  if (env.GITHUB_REF !== MAIN || env.GITHUB_REPOSITORY !== REPO ||
      env.VERCEL_ORG_ID !== TEAM || env.VERCEL_PROJECT_ID !== PROJECT ||
      env.KPS_RELEASE_CONFIRMATION !== "DEPLOY_KPS_PRODUCTION" ||
      !/^[0-9a-f]{40}$/.test(env.GITHUB_SHA) ||
      env.KPS_RELEASE_EXPECTED_SHA !== env.GITHUB_SHA) {
    throw new Error("Production release denied: confirmation, target, or exact main SHA mismatch.");
  }
  if (env.GITHUB_EVENT_NAME === "push" && env.PRODUCTION_CD_ENABLED !== "true") {
    throw new Error("Automatic Production release is not armed.");
  }
  return env.GITHUB_SHA;
}

export function assertApprovedMainMerge(pulls, sha) {
  if (!Array.isArray(pulls) || !pulls.some((pr) => pr.merged_at &&
      pr.base?.ref === "main" && pr.head?.ref === "staging" &&
      pr.merge_commit_sha === sha)) {
    throw new Error("Production auto-release requires a merged staging-to-main PR for the exact SHA.");
  }
}

export function assertDeploymentTargets(render, project) {
  const d = render.serviceDetails;
  if (render.id !== SERVICE || render.ownerId !== "tea-db31pf8m7kps73cnjl30" ||
      render.branch !== "main" || (render.repo?.endsWith(".git") ? render.repo.slice(0, -4) : render.repo) !== "https://github.com/" + REPO ||
      render.autoDeploy !== "no" || render.autoDeployTrigger !== "off" ||
      render.suspended !== "not_suspended" || d?.plan !== "free" ||
      d?.numInstances !== 1 || d?.url !== API_ORIGIN ||
      !["backend", "backend/"].includes(render.rootDir) ||
      d.env !== "docker" || d.envSpecificDetails?.dockerCommand ||
      d.healthCheckPath !== "/api/health") {
    throw new Error("Render Production identity, entrypoint, or manual-deploy guard failed.");
  }
  if (project.id !== PROJECT || project.accountId !== TEAM ||
      project.link?.type !== "github" || String(project.link.repoId) !== "1383601023" ||
      project.gitProviderOptions?.createDeployments !== "disabled" ||
      project.autoExposeSystemEnvs !== true) {
    throw new Error("Vercel Git auto-deploy is not verified disabled or project identity differs.");
  }
}

function checkUrl(value) {
  const url = new URL(value.trim());
  if (url.protocol !== "https:" || url.username || url.password ||
      !url.hostname.endsWith(".vercel.app") || url.pathname !== "/" ||
      url.search || url.hash) throw new Error("Unexpected Vercel deployment URL.");
  return url.origin;
}

function command(args, env) {
  return execFileSync("vercel", args, {
    cwd: root, encoding: "utf8", timeout: 20 * 60_000,
    env: { ...process.env, ...env }, stdio: ["ignore", "pipe", "pipe"], maxBuffer: 1024 * 1024,
  }).trim();
}

async function api(token, url, options = {}) {
  const response = await fetch(url, {
    ...options, redirect: "error", signal: AbortSignal.timeout(30_000),
    headers: { Authorization: "Bearer " + token, Accept: "application/json",
      "Content-Type": "application/json" },
  });
  // Do not log response bodies, which might contain credentials or customer data.
  if (!response.ok) throw new Error("Provider request failed (HTTP " + response.status + ").");
  return response.status === 204 ? null : response.json();
}

async function health(url, sha, bypass = null) {
  const headers = bypass ? { "x-vercel-protection-bypass": bypass } : {};
  const response = await fetch(url + "/api/health", {
    headers, redirect: "error", signal: AbortSignal.timeout(90_000),
  });
  if (!response.ok) throw new Error("Backend/proxy health check failed.");
  const data = await response.json();
  if (data.status !== "ok" || data.service !== "kps-backend" ||
      data.environment !== "production" || data.revision !== sha) {
    throw new Error("Backend/proxy health environment or deployed revision mismatch.");
  }
}

async function checkSite(url, sha, bypass = null) {
  const headers = bypass ? { "x-vercel-protection-bypass": bypass } : {};
  const response = await fetch(url + "/release.json", {
    headers, redirect: "error", signal: AbortSignal.timeout(90_000),
  });
  if (!response.ok) throw new Error("Frontend release metadata unavailable.");
  const data = await response.json();
  if (data.sha !== sha || data.environment !== "production") {
    throw new Error("Frontend does not contain the approved Production SHA.");
  }
  await health(url, sha, bypass);
  const login = await fetch(url + "/login", {
    headers, redirect: "error", signal: AbortSignal.timeout(90_000),
  });
  if (!login.ok || !(await login.text()).includes('id="root"')) {
    throw new Error("Frontend login page smoke failed.");
  }
}

export async function productionRelease(env) {
  const sha = validateReleaseInput(env);
  const evidence = { candidate_sha: sha, status: "started", phase: "preflight",
    lock: "not_acquired", writes_executed: false, backend: null, frontend: null,
    started_at: new Date().toISOString() };
  const save = async () => writeFile(resolve(root, "production-release-evidence.json"),
    JSON.stringify(evidence, null, 2) + "\n");
  await save();
  const gh = (path, opts) => api(env.GH_TOKEN, "https://api.github.com/repos/" + REPO + "/" + path, opts);
  const render = (path, opts) => api(env.RENDER_API_KEY, "https://api.render.com/v1/services/" + SERVICE + path, opts);
  const vercel = (path, opts) => api(env.VERCEL_TOKEN, "https://api.vercel.com" + path + "?teamId=" + TEAM, opts);
  try {
    // The source allowlist is independent of DB credentials. A change in any
    // migration blocks deployment until a separate reviewed migration cutover.
    await verifyMigrationPolicy(root);
    const branch = await gh("branches/main");
    if (branch.commit.sha !== sha) throw new Error("main moved; candidate is stale.");
    if (env.GITHUB_EVENT_NAME === "push") {
      assertApprovedMainMerge(await gh("commits/" + sha + "/pulls"), sha);
    }
    const service = await render("");
    const project = await vercel("/v9/projects/" + PROJECT);
    assertDeploymentTargets(service, project);
    const active = await render("/deploys?limit=20");
    if (active.some((v) => !terminals.has((v.deploy ?? v).status))) {
      throw new Error("Another Render deployment is still active.");
    }
    const lock = await gh("git/refs", {
      method: "POST", body: JSON.stringify({ ref: "refs/" + LOCK, sha }),
    });
    if (!lock) throw new Error("Release lock was not acquired.");
    evidence.lock = "held";
    evidence.phase = "backend";
    await save();
    const deploy = await render("/deploys", {
      method: "POST", body: JSON.stringify({ commitId: sha, clearCache: "do_not_clear" }),
    });
    evidence.backend = { deploy_id: deploy.id, sha: null };
    await save();
    let live = false;
    for (let attempt = 0; attempt < 150; attempt++) {
      const current = await render("/deploys/" + deploy.id);
      if (current.status === "live") {
        if (current.commit?.id !== sha) throw new Error("Render deployed an unexpected SHA.");
        live = true;
        break;
      }
      if (terminals.has(current.status)) throw new Error("Render deploy failed; frontend remains unchanged.");
      await delay(10_000);
    }
    if (!live) throw new Error("Render deploy is unresolved; release lock retained.");
    evidence.backend.sha = sha;
    await save();
    await health(API_ORIGIN, sha);
    if ((await gh("branches/main")).commit.sha !== sha) throw new Error("main advanced during backend deployment.");
    evidence.phase = "frontend_staged";
    await save();

    // Git integration is disabled; this CI job alone builds a Production
    // artifact, but --skip-domain keeps all user traffic on the old frontend.
    command(["pull", "--yes", "--environment=production"], env);
    command(["build", "--prod"], { ...env, VERCEL_ENV: "production",
      VERCEL_GIT_COMMIT_REF: "main", VERCEL_GIT_COMMIT_SHA: sha });
    const deploymentUrl = checkUrl(command(["deploy", "--prebuilt", "--prod", "--skip-domain"], env).split("\n").at(-1));
    evidence.frontend = { staged_url: deploymentUrl, promoted: false };
    await save();
    await checkSite(deploymentUrl, sha, env.VERCEL_AUTOMATION_BYPASS_SECRET);
    if ((await gh("branches/main")).commit.sha !== sha) throw new Error("main advanced during release.");
    await health(API_ORIGIN, sha);
    evidence.phase = "frontend_promote";
    await save();
    command(["promote", deploymentUrl], env);
    evidence.frontend.promoted = true;
    await save();
    await checkSite(SITE_ORIGIN, sha);
    const current = await render("/deploys/" + deploy.id);
    if (current.status !== "live" || current.commit?.id !== sha) throw new Error("Backend changed during promotion.");
    const held = await gh("git/ref/" + LOCK);
    if (held.object?.sha !== sha) throw new Error("Release lock ownership changed.");
    await gh("git/refs/" + LOCK, { method: "DELETE" });
    evidence.lock = "released";
    evidence.status = "success";
    evidence.phase = "complete";
  } catch (error) {
    evidence.status = "failed";
    evidence.error = error.message;
    throw error;
  } finally {
    evidence.finished_at = new Date().toISOString();
    await save();
    if (env.GITHUB_STEP_SUMMARY) await appendFile(env.GITHUB_STEP_SUMMARY,
      "Production release " + evidence.status + "; SHA " + sha +
      "; phase " + evidence.phase + "; lock " + evidence.lock +
      ". No database migration or rollback was executed.\n");
  }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await productionRelease(process.env).catch((error) => {
    // Error messages contain only controlled metadata; never echo API bodies.
    console.error(error.message);
    process.exitCode = 1;
  });
}
