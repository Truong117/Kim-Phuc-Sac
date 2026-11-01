import { execFileSync } from "node:child_process";
import { mkdtemp, readFile, readdir, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createHash } from "node:crypto";

const requiredNames = [
  "KPS_STAGING_TARGET_TYPE", "KPS_STAGING_EXPECTED_TARGET", "KPS_STAGING_METADATA_SOURCE",
  "KPS_STAGING_DB_HOST", "KPS_STAGING_DB_PORT", "KPS_STAGING_DB_DATABASE",
  "KPS_STAGING_DB_USERNAME", "KPS_STAGING_DB_PASSWORD", "KPS_STAGING_AUDIT_DATABASE",
  "KPS_STAGING_AUDIT_USERNAME", "KPS_STAGING_AUDIT_PASSWORD", "KPS_STAGING_CONFIRMED_USERNAME_PREFIX",
  "KPS_STAGING_EXPECTED_FINGERPRINT", "KPS_STAGING_EXPECTED_AUDIT_FINGERPRINT",
  "KPS_STAGING_EXPECTED_ENDPOINT_FINGERPRINT", "KPS_PRODUCTION_ENDPOINT_FINGERPRINT",
  "KPS_PRODUCTION_CONFIRMED_USERNAME", "KPS_PRODUCTION_CONFIRMED_USERNAME_PREFIX",
  "KPS_PRODUCTION_USERNAME_PREFIX_FINGERPRINT",
];

export async function verifyMigrationPolicy(root) {
  const policy = JSON.parse(await readFile(join(root, "deployment/migration-policy.json")));
  const folder = join(root, "backend/database/migrations");
  const files = (await readdir(folder)).filter((file) => file.endsWith(".php")).sort();
  if (JSON.stringify(files) !== JSON.stringify(policy.migrations.map((item) => item.file))) {
    throw new Error("Migration inventory changed: review risk, inspector support and policy in the same PR.");
  }
  for (const item of policy.migrations) {
    const content = (await readFile(join(folder, item.file), "utf8")).replaceAll("\r\n", "\n");
    if (createHash("sha256").update(content).digest("hex") !== item.sha256 || item.automatic !== false) {
      throw new Error("Migration policy/hash changed: automatic DDL is not enabled in phase 1.");
    }
  }
  return policy;
}

export function inspectorEnvironment(profile, caPath) {
  if (!profile || typeof profile !== "object" || Array.isArray(profile) ||
      Object.keys(profile).some((key) => !requiredNames.includes(key)) ||
      requiredNames.some((key) => typeof profile[key] !== "string" || profile[key].trim() === "")) {
    throw new Error("STAGING_DB_INSPECTOR_ENV must contain exactly the documented inspector keys.");
  }
  const environment = { ...profile,
    KPS_STAGING_INCREMENTAL_MODE: "release",
    KPS_STAGING_INCREMENTAL_MIGRATION: "2026_10_31_000001_create_account_audit_events_table.php",
    KPS_STAGING_DB_SSL_CA: caPath,
  };
  for (const key of ["SystemRoot", "WINDIR", "TEMP", "TMP"]) {
    if (process.env[key]) environment[key] = process.env[key];
  }
  return environment;
}

export async function inspectStaging(root, env, save) {
  await verifyMigrationPolicy(root);
  const directory = await mkdtemp(join(tmpdir(), "kps-inspector-"));
  try {
    const caPath = join(directory, "ca.pem");
    await writeFile(caPath, env.STAGING_DB_CA_PEM, { mode: 0o600 });
    let profile;
    try { profile = JSON.parse(env.STAGING_DB_INSPECTOR_ENV); } catch {
      throw new Error("STAGING_DB_INSPECTOR_ENV is not valid JSON; content withheld.");
    }
    const environment = inspectorEnvironment(profile, caPath);
    let output;
    try {
      output = execFileSync(env.PHP_BINARY || "php", [join(root, "backend/scripts/inspect-staging-incremental-migration.php")], {
        env: environment, cwd: join(root, "backend"), encoding: "utf8", timeout: 180_000,
        stdio: ["ignore", "pipe", "pipe"], maxBuffer: 1024 * 1024,
      });
    } catch (error) {
      // Never forward raw PHP stderr, PDO errors, hostnames or credentials.
      output = error.stdout;
    }
    let result;
    try { result = JSON.parse(output); } catch {
      throw new Error("Staging inspector failed without a valid redacted result; no deployment allowed.");
    }
    const summary = {
      status: result.status, identity: result.identity ?? null,
      ledger: result.ledger ?? null, concurrency: result.concurrency ?? null,
      schema: Object.fromEntries(Object.entries(result.schema ?? {}).filter(([key]) => /^migration_\d+_state$/.test(key))),
      violations: result.violations ?? [], reason: result.reason ?? null,
      writes_executed: false,
    };
    await save(summary);
    if (result.status !== "PASS" || result.mode !== "release" ||
        result.ledger?.pending_migrations?.length !== 0 || result.ledger?.unexpected_migrations?.length !== 0) {
      throw new Error("Database/migration gate blocked. Review redacted evidence; use the existing interactive runner for pending migrations, then retry deliberately.");
    }
    return summary;
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
}
