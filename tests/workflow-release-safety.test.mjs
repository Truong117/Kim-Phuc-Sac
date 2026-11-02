import assert from "node:assert/strict";
import { test } from "node:test";
import { readFile, readdir } from "node:fs/promises";
import { checkProductionCandidate } from "../deployment/production-candidate.mjs";
import yaml from "js-yaml"; // Already pinned by the repository's ESLint dependency.

const workflow = async (name) => readFile(new URL(`../.github/workflows/${name}.yml`, import.meta.url), "utf8");

test("CI backend uses forced SQLite and an empty local env, without deployment secrets", async () => {
  const content = await workflow("ci");
  const backend = yaml.load(content).jobs.backend;
  assert.equal(backend.env.APP_ENV, "testing");
  assert.equal(backend.env.DB_CONNECTION, "sqlite");
  assert.equal(backend.env.DB_DATABASE, ":memory:");
  assert.equal(backend.env.DB_URL, "");
  assert.equal(backend.environment, undefined);
  const prepare = backend.steps.findIndex((step) => step.run === "touch .env");
  assert.ok(prepare >= 0 && prepare < backend.steps.findIndex((step) => step.run === "php artisan test"));
  assert.doesNotMatch(content, /secrets\.PRODUCTION_/);
  const config = yaml.load(content);
  assert.ok(config.jobs.frontend.steps.some((step) => step.run === "npm audit"));
  assert.ok(backend.steps.some((step) => step.run === "composer audit --no-interaction"));
});

test("exactly one workflow coordinates each environment; diagnostic/PR jobs cannot deploy", async () => {
  const files = (await readdir(new URL("../.github/workflows/", import.meta.url))).filter((file) => /\.ya?ml$/.test(file));
  assert.deepEqual(files.sort(), ["ci.yml", "production-network.yml", "production-preflight.yml", "production-release.yml", "release-prs.yml", "release.yml"]);
  const production = [];
  const staging = [];
  for (const file of files) {
    const config = yaml.load(await readFile(new URL(`../.github/workflows/${file}`, import.meta.url), "utf8"));
    for (const job of Object.values(config.jobs)) {
      for (const step of job.steps ?? []) {
        if (/node deployment\/production-release\.mjs/.test(step.run ?? "")) production.push(file);
        if (/node deployment\/release\.mjs staging/.test(step.run ?? "")) staging.push(file);
        if (!["production-release.yml", "release.yml"].includes(file)) {
          assert.doesNotMatch(step.run ?? "", /vercel (deploy|promote)|api\.render\.com.*deploys|production-ci\.php migrate/);
        }
      }
    }
  }
  assert.deepEqual(production, ["production-release.yml"]);
  assert.deepEqual(staging, ["release.yml"]);
});

test("release workflows isolate Staging/main, require reusable CI, serialize and never cancel", async () => {
  const staging = await workflow("release");
  const production = await workflow("production-release");
  const stageConfig = yaml.load(staging);
  const prodConfig = yaml.load(production);
  assert.deepEqual(stageConfig.on.push.branches, ["staging"]);
  assert.equal(stageConfig.jobs.staging.needs, "checks");
  assert.equal(stageConfig.jobs.staging.if, "github.ref == 'refs/heads/staging'");
  assert.equal(stageConfig.jobs.staging.environment, "staging");
  assert.deepEqual(prodConfig.on.push.branches, ["main"]);
  assert.equal(prodConfig.jobs.checks.needs, "candidate");
  assert.equal(prodConfig.jobs.production.needs, "checks");
  assert.equal(prodConfig.jobs.production.if, "github.ref == 'refs/heads/main' && vars.PRODUCTION_CD_ENABLED == 'true'");
  assert.equal(prodConfig.jobs.production.environment, "production");
  assert.match(staging, /push:\s+branches: \[staging\]/);
  assert.match(staging, /if: github.ref == 'refs\/heads\/staging'/);
  assert.match(staging, /environment: staging/);
  assert.doesNotMatch(staging, /secrets\.PRODUCTION_/);
  assert.match(production, /push:\s+branches: \[main\]/);
  assert.match(production, /candidate:[\s\S]+node deployment\/production-candidate.mjs/);
  assert.match(production, /checks:\s+needs: candidate\s+uses: \.\/.github\/workflows\/ci.yml/);
  assert.match(production, /needs: checks\s+if: github.ref == 'refs\/heads\/main' && vars.PRODUCTION_CD_ENABLED == 'true'/);
  assert.match(production, /environment: production/);
  for (const content of [staging, production, await workflow("production-preflight"), await workflow("production-network")]) {
    assert.match(content, /group: kps-release\s+cancel-in-progress: false/);
    assert.match(content, /uses: \.\/.github\/workflows\/ci.yml/);
    assert.doesNotMatch(content, /pull_request_target:|continue-on-error: true/);
  }
});

test("network diagnosis is manual, main-only, Environment/CI protected and gets no SQL/deploy credential", async () => {
  const content = await workflow("production-network");
  const config = yaml.load(content);
  assert.deepEqual(Object.keys(config.on), ["workflow_dispatch"]);
  assert.equal(config.jobs.network.needs, "checks");
  assert.equal(config.jobs.network.if, "github.ref == 'refs/heads/main'");
  assert.equal(config.jobs.network.environment, "production");
  assert.deepEqual(config.permissions, { contents: "read" });
  assert.match(content, /on:\s+workflow_dispatch:/);
  assert.doesNotMatch(content, /\bpush:|\bpull_request:|PRODUCTION_\w+_ENABLED|production-release.mjs|production-database.mjs/);
  assert.match(content, /network:\s+needs: checks\s+if: github.ref == 'refs\/heads\/main'\s+environment: production/);
  const secrets = [...content.matchAll(/secrets\.([A-Z_]+)/g)].map((match) => match[1]);
  assert.deepEqual(secrets.sort(), ["PRODUCTION_DB_CA_PEM", "PRODUCTION_DB_HOST", "PRODUCTION_DB_PORT"].sort());
  assert.match(content, /PRODUCTION_DB_CA_SHA256: \$\{\{ vars.PRODUCTION_DB_CA_SHA256 \}\}/);
  assert.match(content, /permissions:\s+contents: read/);
  const preflight = await workflow("production-preflight");
  assert.match(preflight, /on:\s+workflow_dispatch:/);
  assert.match(preflight, /vars.PRODUCTION_PREFLIGHT_ENABLED == 'true'/);
  assert.doesNotMatch(preflight, /production-ci.php migrate|RENDER_API_KEY|VERCEL_TOKEN/);
});

test("candidate API check denies direct/hotfix/feature/fork/unmerged/stale SHAs for push and manual", async () => {
  const sha = "a".repeat(40);
  const repo = { full_name: "Truong117/Kim-Phuc-Sac" };
  const merge = { merged_at: "2026-11-02T00:00:00Z", merge_commit_sha: sha, base: { ref: "main", repo }, head: { ref: "staging", repo } };
  const env = { GITHUB_REF: "refs/heads/main", GITHUB_REPOSITORY: repo.full_name, GITHUB_SHA: sha, GH_TOKEN: "fixture" };
  const fetcher = (pulls, current = sha) => async (url) => new Response(JSON.stringify(url.endsWith("branches/main") ? { commit: { sha: current } } : pulls));
  for (const GITHUB_EVENT_NAME of ["push", "workflow_dispatch"]) {
    await checkProductionCandidate({ ...env, GITHUB_EVENT_NAME }, fetcher([merge]));
    for (const pulls of [[], [{ ...merge, head: { ref: "hotfix/test", repo } }], [{ ...merge, head: { ref: "feature/test", repo } }],
      [{ ...merge, base: { ref: "staging", repo } }], [{ ...merge, head: { ref: "staging", repo: { full_name: "fork/repo" } } }],
      [{ ...merge, merged_at: null }], [{ ...merge, merge_commit_sha: "b".repeat(40) }]]) {
      await assert.rejects(checkProductionCandidate({ ...env, GITHUB_EVENT_NAME }, fetcher(pulls)));
    }
    await assert.rejects(checkProductionCandidate({ ...env, GITHUB_EVENT_NAME }, fetcher([merge], "b".repeat(40))));
    await assert.rejects(checkProductionCandidate({ ...env, GITHUB_EVENT_NAME, GITHUB_REF: "refs/heads/staging" }, fetcher([merge])));
  }
});
