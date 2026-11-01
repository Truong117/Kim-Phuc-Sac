import { execFileSync } from "node:child_process";
import { mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { probeProductionNetwork, validateTrust } from "./production-network.mjs";

const root = fileURLToPath(new URL("../", import.meta.url));
const keys = ["PRODUCTION_DB_HOST", "PRODUCTION_DB_NAME", "PRODUCTION_DB_PORT", "PRODUCTION_RO_USERNAME",
  "PRODUCTION_RO_PASSWORD", "PRODUCTION_MIGRATOR_USERNAME", "PRODUCTION_MIGRATOR_PASSWORD",
  "PRODUCTION_DB_CA_SHA256", "PRODUCTION_P0_HMAC_KEY", "PRODUCTION_RECOVERY_EVIDENCE",
  "PRODUCTION_CD_ENABLED", "PRODUCTION_MIGRATIONS_ENABLED"];

export function databaseEnvironment(env, ca) {
  const isolated = Object.fromEntries(keys.filter((key) => typeof env[key] === "string").map((key) => [key, env[key]]));
  for (const key of ["SystemRoot", "WINDIR", "TEMP", "TMP"]) if (env[key]) isolated[key] = env[key];
  return { ...isolated, KPS_RELEASE_SHA: env.GITHUB_SHA, PRODUCTION_DB_CA_FILE: ca };
}

export function redactedDatabaseRecord(record) {
  const allowed = ["kind", "status", "mode", "phase", "migration", "writes_executed", "writes_may_have_started",
    "identity_binding", "console_labels_independently_queryable", "tls_verified", "grants_verified", "applied",
    "applied_count", "pending", "ledger_sha256", "schema_sha256", "migration_status"];
  const result = Object.fromEntries(allowed.filter((key) => Object.hasOwn(record, key)).map((key) => [key, record[key]]));
  if (typeof record.reason === "string" && /^[a-z_]{3,100}$/.test(record.reason)) result.reason = record.reason;
  return result;
}

export async function productionDatabase(env, mode, save = async () => {}, { run = execFileSync, probe = probeProductionNetwork } = {}) {
  if (!["preflight", "migrate", "postflight"].includes(mode)) throw new Error("Production database operation denied.");
  const required = [...keys.slice(0, 8), "PRODUCTION_DB_CA_PEM", "GITHUB_SHA"];
  if (required.some((key) => !env[key])) throw new Error("Production DB credentials, reviewed CA or candidate SHA configuration is missing.");
  const policy = JSON.parse(await readFile(join(root, "deployment/production-db-policy.json"), "utf8"));
  validateTrust(env, policy, env.PRODUCTION_DB_CA_PEM);
  const network = await probe(env, env.PRODUCTION_DB_CA_PEM);
  await save({ phase: "network", network, writes_executed: false });
  const directory = await mkdtemp(join(tmpdir(), "kps-production-ci-"));
  try {
    const ca = join(directory, "ca.pem");
    await writeFile(ca, env.PRODUCTION_DB_CA_PEM, { mode: 0o600 });
    let output;
    let interrupted = false;
    try {
      output = run("php", [join(root, "backend/scripts/production-ci.php"), mode], {
        env: databaseEnvironment(env, ca), cwd: join(root, "backend"), encoding: "utf8",
        stdio: ["ignore", "pipe", "pipe"], timeout: 12 * 60_000, maxBuffer: 1024 * 1024,
      });
    } catch (error) { output = error.stdout; interrupted = true; }
    const records = [];
    let malformed = false;
    for (const line of String(output ?? "").trim().split(/\r?\n/)) {
      try { records.push(redactedDatabaseRecord(JSON.parse(line))); } catch { malformed = true; break; }
    }
    for (const record of records) await save(record);
    const result = records.at(-1);
    if (malformed || !result || result.kind !== "result" || interrupted && result.status === "PASS") {
      await save({ status: "FAIL", reason: "database_process_interrupted", writes_may_have_started: mode === "migrate" });
      throw new Error("Production DB process failed or timed out; details withheld. Keep the release lock.");
    }
    if (result.kind !== "result" || result.status !== "PASS" || result.mode !== mode) {
      throw new Error("Production DB preflight/migration/postflight denied; see redacted database evidence. No automatic retry or rollback.");
    }
    if (mode === "postflight" && result.pending?.length !== 0) throw new Error("Production postflight still has pending migrations.");
    return result;
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  // Standalone workflow is permanently read-only. Mutations are reachable only
  // through the merge/CI/Environment/lock-gated coordinator, never CLI arguments.
  if (process.argv[2] !== "preflight") {
    console.error("Standalone Production database command supports preflight only.");
    process.exitCode = 1;
  } else {
    const records = [];
    try { await productionDatabase(process.env, "preflight", async (record) => { records.push(record); }); }
    catch (error) { records.push({ status: "FAIL", error: error.message }); process.exitCode = 1; }
    await writeFile(join(root, "production-preflight-evidence.json"), JSON.stringify(records, null, 2) + "\n");
  }
}
