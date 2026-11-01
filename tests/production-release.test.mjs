import assert from "node:assert/strict";
import test from "node:test";
import { validateReleaseInput, assertDeploymentTargets, assertApprovedMainMerge } from "../deployment/production-release.mjs";

const sha = "a".repeat(40);
const env = {
  GITHUB_SHA: sha,
  GITHUB_REF: "refs/heads/main",
  GITHUB_REPOSITORY: "Truong117/Kim-Phuc-Sac",
  GH_TOKEN: "fake", RENDER_API_KEY: "fake", VERCEL_TOKEN: "fake",
  VERCEL_ORG_ID: "team_hJaxsqskjIBmzfrdt8eI9Sky",
  VERCEL_PROJECT_ID: "prj_sSEBHcwALQHag7fQQxFmbisxocz4",
  VERCEL_AUTOMATION_BYPASS_SECRET: "fake",
  KPS_RELEASE_EXPECTED_SHA: sha,
  KPS_RELEASE_CONFIRMATION: "DEPLOY_KPS_PRODUCTION",
  GITHUB_EVENT_NAME: "workflow_dispatch", PRODUCTION_CD_ENABLED: "true",
  PRODUCTION_DB_HOST: "fixture", PRODUCTION_DB_NAME: "fixture", PRODUCTION_DB_PORT: "4000",
  PRODUCTION_RO_USERNAME: "fixture", PRODUCTION_RO_PASSWORD: "fixture",
  PRODUCTION_MIGRATOR_USERNAME: "fixture", PRODUCTION_MIGRATOR_PASSWORD: "fixture",
  PRODUCTION_DB_CA_PEM: "fixture", PRODUCTION_DB_CA_SHA256: "fixture", PRODUCTION_RECOVERY_EVIDENCE: "fixture",
  PRODUCTION_SMOKE_EMAIL: "fixture", PRODUCTION_SMOKE_PASSWORD: "fixture",
};
const render = {
  id: "srv-db3esa59fdbs73dg62lg",
  ownerId: "tea-db31pf8m7kps73cnjl30",
  branch: "main",
  repo: "https://github.com/Truong117/Kim-Phuc-Sac",
  autoDeploy: "no", autoDeployTrigger: "off",
  suspended: "not_suspended", rootDir: "backend/",
  serviceDetails: {
    env: "docker", plan: "free", numInstances: 1,
    url: "https://kim-phuc-sac.onrender.com",
    healthCheckPath: "/api/health",
    envSpecificDetails: { dockerCommand: "" },
  },
};
const project = {
  id: "prj_sSEBHcwALQHag7fQQxFmbisxocz4",
  accountId: "team_hJaxsqskjIBmzfrdt8eI9Sky",
  link: { type: "github", repoId: 1383601023 },
  gitProviderOptions: { createDeployments: "disabled" },
  autoExposeSystemEnvs: true,
};

test("production release requires exact reviewed main SHA and protected credentials", () => {
  assert.equal(validateReleaseInput(env), sha);
  for (const changed of [
    { GITHUB_REF: "refs/heads/staging" },
    { GITHUB_REPOSITORY: "other/repo" },
    { GITHUB_SHA: "b".repeat(40) },
    { KPS_RELEASE_CONFIRMATION: "DEPLOY" },
    { VERCEL_ORG_ID: "wrong-team" },
    { RENDER_API_KEY: "" },
  ]) assert.throws(() => validateReleaseInput({ ...env, ...changed }));
});

test("fail closed on Render entrypoint/auto-deploy or Vercel Git auto-deploy", () => {
  assert.doesNotThrow(() => assertDeploymentTargets(render, project));
  for (const changed of [
    { autoDeploy: "yes" },
    { autoDeployTrigger: "commit" },
    { suspended: "suspended" },
    { branch: "staging" },
    { serviceDetails: { ...render.serviceDetails, envSpecificDetails: { dockerCommand: "php artisan migrate --force" } } },
  ]) assert.throws(() => assertDeploymentTargets({ ...render, ...changed }, project));
  assert.throws(() => assertDeploymentTargets(render, {
    ...project, gitProviderOptions: { createDeployments: "enabled" },
  }));
  assert.throws(() => assertDeploymentTargets(render, {
    ...project, accountId: "wrong-team",
  }));
});


test("automatic Production is armed only for exact staging-to-main merge PR", () => {
  assert.throws(() => validateReleaseInput({ ...env, GITHUB_EVENT_NAME: "push", PRODUCTION_CD_ENABLED: "false" }), /not armed/);
  assert.equal(validateReleaseInput({ ...env, GITHUB_EVENT_NAME: "push", PRODUCTION_CD_ENABLED: "true" }), sha);
  const merge = { merged_at: "2026-11-01T19:00:00Z", base: { ref: "main", repo: { full_name: "Truong117/Kim-Phuc-Sac" } },
    head: { ref: "staging", repo: { full_name: "Truong117/Kim-Phuc-Sac" } }, merge_commit_sha: sha };
  assert.doesNotThrow(() => assertApprovedMainMerge([merge], sha));
  assert.throws(() => assertApprovedMainMerge([], sha));
  assert.throws(() => assertApprovedMainMerge([{ ...merge, head: { ref: "hotfix/x" } }], sha));
  assert.throws(() => assertApprovedMainMerge([{ ...merge, merge_commit_sha: "b".repeat(40) }], sha));
  assert.throws(() => assertApprovedMainMerge([{ ...merge, merged_at: null }], sha));
});
