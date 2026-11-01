import assert from "node:assert/strict";
import test from "node:test";
import { EventEmitter } from "node:events";
import { readFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import tls from "node:tls";
import { frontendEnvironment, productionRelease, validateReleaseInput, assertApprovedMainMerge } from "../deployment/production-release.mjs";
import { databaseEnvironment, redactedDatabaseRecord } from "../deployment/production-database.mjs";
import { schemaFingerprint } from "../deployment/production-schema-fingerprint.mjs";
import { sslRequest, validateTrust, probeProductionNetwork } from "../deployment/production-network.mjs";

const sha = "a".repeat(40);
const repo = { full_name: "Truong117/Kim-Phuc-Sac" };
const merged = { merged_at: "2026-11-02T00:00:00Z", base: { ref: "main", repo }, head: { ref: "staging", repo }, merge_commit_sha: sha };
const env = {
  GITHUB_SHA: sha, GITHUB_REF: "refs/heads/main", GITHUB_REPOSITORY: repo.full_name,
  GITHUB_EVENT_NAME: "workflow_dispatch", GH_TOKEN: "fake-gh", RENDER_API_KEY: "fake-render", VERCEL_TOKEN: "fake-vercel",
  VERCEL_ORG_ID: "team_hJaxsqskjIBmzfrdt8eI9Sky", VERCEL_PROJECT_ID: "prj_sSEBHcwALQHag7fQQxFmbisxocz4",
  VERCEL_AUTOMATION_BYPASS_SECRET: "fake-bypass", KPS_RELEASE_CONFIRMATION: "DEPLOY_KPS_PRODUCTION", KPS_RELEASE_EXPECTED_SHA: sha,
  PRODUCTION_CD_ENABLED: "true", PRODUCTION_SMOKE_EMAIL: "fake@example.test", PRODUCTION_SMOKE_PASSWORD: "SMOKE-SECRET",
  PRODUCTION_DB_HOST: "fixture.aws.tidbcloud.com", PRODUCTION_DB_NAME: "kps_internal", PRODUCTION_DB_PORT: "4000",
  PRODUCTION_RO_USERNAME: "TestPrefix.kps_ci_reader", PRODUCTION_RO_PASSWORD: "READER-SECRET",
  PRODUCTION_MIGRATOR_USERNAME: "TestPrefix.kps_ci_migrator", PRODUCTION_MIGRATOR_PASSWORD: "MIGRATOR-SECRET",
  PRODUCTION_DB_CA_PEM: "fake", PRODUCTION_DB_CA_SHA256: "fake", PRODUCTION_RECOVERY_EVIDENCE: "fake",
};
const result = (body, status = 200, headers = {}) => new Response(status === 204 ? null : typeof body === "string" ? body : JSON.stringify(body), { status, headers });

function fixture(failure) {
  const calls = [];
  let loggedIn = false;
  const fetcher = async (url, options = {}) => {
    const u = new URL(url);
    const path = u.pathname;
    const method = options.method ?? "GET";
    calls.push(method + " " + path);
    if (u.hostname === "api.github.com") {
      if (path.endsWith("/branches/main")) return result({ commit: { sha } });
      if (path.endsWith("/pulls")) return result(failure === "provenance" ? [] : [merged]);
      if (path.endsWith("/git/refs")) return result({}, failure === "locked" ? 422 : 201);
      if (path.includes("/git/ref/")) return result({ object: { sha } });
      if (method === "DELETE") return result(null, 204);
    }
    if (u.hostname === "api.render.com") {
      if (path.endsWith("/srv-db3esa59fdbs73dg62lg")) return result({
        id: "srv-db3esa59fdbs73dg62lg", ownerId: "tea-db31pf8m7kps73cnjl30", branch: "main", repo: "https://github.com/" + repo.full_name,
        autoDeploy: "no", autoDeployTrigger: "off", suspended: "not_suspended", rootDir: "backend/",
        serviceDetails: { plan: "free", numInstances: 1, url: "https://kim-phuc-sac.onrender.com", env: "docker", healthCheckPath: "/api/health", envSpecificDetails: { dockerCommand: "" } },
      });
      if (path.endsWith("/deploys")) return method === "POST" ? result({ id: "dep-new" }, failure === "backend" ? 500 : 201) : result([]);
      if (path.endsWith("/dep-new")) return result({ status: "live", commit: { id: sha } });
    }
    if (u.hostname === "api.vercel.com") {
      if (path.includes("/projects/")) return result({ id: env.VERCEL_PROJECT_ID, accountId: env.VERCEL_ORG_ID,
        link: { type: "github", repoId: 1383601023 }, gitProviderOptions: { createDeployments: "disabled" }, autoExposeSystemEnvs: true });
      if (path === "/v6/deployments") return result({ deployments: failure === "external" ? [{ state: "BUILDING" }] : [] });
    }
    if (path === "/api/health") return result({ status: "ok", service: "kps-backend", revision: sha, environment: "production" });
    if (path === "/release.json") return result({ sha, environment: "production" });
    if (path === "/login") return result('<div id="root"></div>');
    if (path === "/sanctum/csrf-cookie") return result(null, 204, { "set-cookie": "XSRF-TOKEN=fixture; Secure" });
    if (path === "/api/auth/login") { loggedIn = true; return result({}, failure === "smoke" ? 500 : 200); }
    if (path === "/api/auth/logout") { loggedIn = false; return result(null, 204); }
    if (["/api/auth/me", "/api/navigation", "/api/reports/today"].includes(path)) return result({}, loggedIn ? 200 : 401);
    throw new Error("Unexpected test request: " + method + path);
  };
  const database = async (_env, mode, save) => {
    calls.push("DB " + mode);
    if (failure === "db" || failure === "migration" && mode === "migrate") throw new Error("SECRET must not leak: SQL error");
    await save({ status: "PASS", mode, pending: [], writes_executed: false });
    return { status: "PASS", pending: [] };
  };
  const runCommand = (args, child) => {
    calls.push("CLI " + args[0]);
    assert.equal(child.PRODUCTION_RO_PASSWORD, undefined);
    assert.equal(child.PRODUCTION_MIGRATOR_PASSWORD, undefined);
    assert.equal(child.GH_TOKEN, undefined);
    if (failure === "frontend") throw new Error("stderr contains SECRET");
    return args[0] === "deploy" ? "https://fixture-staged.vercel.app" : "";
  };
  return { calls, fetcher, database, runCommand, wait: async () => {} };
}

test("Production order: exact merge → lock → DB/migration/postflight → backend → staged frontend → smoke → final DB → unlock", async () => {
  const mock = fixture();
  await productionRelease(env, mock);
  const index = (name) => mock.calls.findIndex((call) => call.includes(name));
  assert.ok(index("DB preflight") < index("DB migrate"));
  assert.ok(index("DB migrate") < index("POST /v1/services/"));
  assert.ok(index("POST /v1/services/") < index("CLI deploy"));
  assert.ok(index("CLI deploy") < index("CLI promote"));
  assert.ok(index("CLI promote") < index("POST /api/auth/login"));
  assert.equal(mock.calls.at(-1), "DELETE /repos/Truong117/Kim-Phuc-Sac/git/refs/tags/kps-release-lock");
});

test("every DB/deploy/smoke failure retains the durable lock and redacts exception payloads", async () => {
  for (const failure of ["db", "migration", "backend", "frontend", "smoke", "provenance", "external", "locked"]) {
    const mock = fixture(failure);
    await assert.rejects(productionRelease(env, mock), (error) => !error.message.includes("SECRET"));
    assert.ok(!mock.calls.some((call) => call.startsWith("DELETE ")));
    const evidence = JSON.parse(await readFile(new URL("../production-release-evidence.json", import.meta.url)));
    assert.ok(!JSON.stringify(evidence).includes("SECRET"));
    if (["db", "migration", "provenance", "external", "locked"].includes(failure)) {
      assert.ok(!mock.calls.some((call) => call.startsWith("POST /v1/services/")));
    }
  }
});

test("manual retry cannot bypass arming, same-repository staging provenance, or secrets; children cannot inherit DB_URL or tokens", () => {
  assert.throws(() => validateReleaseInput({ ...env, PRODUCTION_CD_ENABLED: "false" }));
  assert.throws(() => assertApprovedMainMerge([{ ...merged, head: { ref: "staging", repo: { full_name: "fork/repo" } } }], sha));
  assert.throws(() => validateReleaseInput({ ...env, PRODUCTION_RO_PASSWORD: "" }));
  const isolated = databaseEnvironment({ ...env, DB_URL: "SECRET", APP_KEY: "SECRET", PATH: "/fake" }, "/tmp/ca.pem");
  assert.equal(isolated.DB_URL, undefined);
  assert.equal(isolated.GH_TOKEN, undefined);
  assert.equal(isolated.APP_KEY, undefined);
  assert.equal(frontendEnvironment(env).PRODUCTION_DB_HOST, undefined);
});

test("DB evidence excludes unexpected employee/credential fields and raw SQL messages; schema fingerprints are offline metadata only", () => {
  const record = redactedDatabaseRecord({ kind: "checkpoint", phase: "ddl_may_start", writes_may_have_started: true,
    employee: "SECRET", password: "SECRET", reason: "PDO error includes SECRET" });
  assert.deepEqual(record, { kind: "checkpoint", phase: "ddl_may_start", writes_may_have_started: true });
  const metadata = { tables: [{ TABLE_NAME: "fixture", ENGINE: "InnoDB" }], columns: [], indexes: [], foreign_keys: [] };
  assert.equal(schemaFingerprint(metadata), schemaFingerprint({ ...metadata, tables: [{ ENGINE: "InnoDB", TABLE_NAME: "fixture" }] }));
  assert.throws(() => schemaFingerprint({ ...metadata, employee: "SECRET" }));
});

test("reviewed instance/main endpoint and CA are required; bad binding is rejected before networking", async () => {
  const pem = await readFile(new URL("../backend/tests/Fixtures/production-ci/ca.pem", import.meta.url), "utf8");
  const ca = createHash("sha256").update(pem).digest("hex");
  const identity = { instance_name: "KPS-Internal", branch: "main", instance_id: "123456789", username_prefix: "TestPrefix",
    mapping_reviewed: true, console_evidence: "test", verified_at: new Date().toISOString(), server_hostname_sha256: "b".repeat(64),
    endpoint_sha256: createHash("sha256").update("fixture.aws.tidbcloud.com|4000|kps_internal|TestPrefix|main").digest("hex") };
  assert.equal(validateTrust({ ...env, PRODUCTION_DB_CA_SHA256: ca }, { identity }, pem), ca);
  assert.throws(() => validateTrust({ ...env, PRODUCTION_DB_CA_SHA256: "wrong" }, { identity }, pem));
  assert.throws(() => validateTrust({ ...env, PRODUCTION_DB_CA_SHA256: ca }, { identity: { ...identity, branch: "staging" } }, pem));
  assert.throws(() => validateTrust({ ...env, PRODUCTION_DB_CA_SHA256: ca }, { identity }, pem, Date.now() + 40 * 86400_000));
  assert.ok(tls.checkServerIdentity("wrong.example", { subjectaltname: "DNS:fixture.aws.tidbcloud.com" }));
});

function greeting(tlsEnabled = true) {
  const body = Buffer.alloc(64);
  body[0] = 10;
  body.write("8.0\0", 1);
  const position = 1 + 4 + 4 + 8 + 1;
  body.writeUInt16LE(tlsEnabled ? 0x8a00 : 0x8200, position);
  const header = Buffer.alloc(4);
  header.writeUIntLE(body.length, 0, 3);
  return Buffer.concat([header, body]);
}

test("MySQL probe requires TLS capabilities and uses CA+SNI+hostname verification with no credentials", async () => {
  assert.equal(sslRequest(greeting()).length, 36);
  assert.throws(() => sslRequest(greeting(false)));
  let settings;
  const socket = new EventEmitter(); socket.write = () => {}; socket.destroy = () => {};
  const secure = new EventEmitter(); secure.authorized = true; secure.destroy = () => {};
  const report = await probeProductionNetwork(env, "PUBLIC-CA", {
    resolveDns: async () => ({ address: "192.0.2.1" }),
    connect: () => { queueMicrotask(() => socket.emit("data", greeting())); return socket; },
    secureConnect: (options) => { settings = options; queueMicrotask(() => secure.emit("secureConnect")); return secure; },
  });
  assert.equal(report.hostname_verified, true);
  assert.equal(settings.rejectUnauthorized, true);
  assert.equal(settings.servername, env.PRODUCTION_DB_HOST);
  assert.equal(settings.minVersion, "TLSv1.2");
  assert.equal(settings.checkServerIdentity, tls.checkServerIdentity);
  assert.equal(settings.password, undefined);
  await assert.rejects(probeProductionNetwork(env, "PUBLIC-CA", { resolveDns: async () => { throw new Error("HOST-SECRET"); } }), (error) => !error.message.includes("HOST-SECRET"));
});
