import { createHash } from "node:crypto";
import { readFile, readdir } from "node:fs/promises";
import { join } from "node:path";

// Source-side policy only: this module has no SQL connection, database writes,
// deploy hooks, or credentials. Its output is NOT a Production postflight.
export async function loadSourceMigrationManifest(root) {
  const policy = JSON.parse(await readFile(join(root, "deployment/migration-policy.json"), "utf8"));
  const folder = join(root, "backend/database/migrations");
  const files = (await readdir(folder)).filter((name) => name.endsWith(".php")).sort();
  if (!Array.isArray(policy.migrations) ||
      JSON.stringify(files) !== JSON.stringify(policy.migrations.map((entry) => entry.file))) {
    throw new Error("Migration manifest is missing, reordered, duplicated, or contains unexpected files.");
  }
  const names = new Set();
  for (const entry of policy.migrations) {
    if (typeof entry.file !== "string" || !/^[a-zA-Z0-9_]+\.php$/.test(entry.file) ||
        names.has(entry.file) || !/^[0-9a-f]{64}$/.test(entry.sha256)) {
      throw new Error("Invalid migration policy entry.");
    }
    names.add(entry.file);
    const source = (await readFile(join(folder, entry.file), "utf8")).replaceAll("\r\n", "\n");
    if (createHash("sha256").update(source).digest("hex") !== entry.sha256) {
      throw new Error("Migration source changed without an exact policy update.");
    }
  }
  return policy.migrations;
}

export function planProductionMigrations(migrations, applied, evidence) {
  if (!Array.isArray(migrations) || !Array.isArray(applied) ||
      applied.some((x) => typeof x !== "string") ||
      !evidence || typeof evidence !== "object") {
    throw new Error("Production migration planning requires validated manifest, ledger, and inspector evidence.");
  }
  if (applied.length > migrations.length ||
      applied.some((name, i) => name !== migrations[i]?.file.replace(/\.php$/, ""))) {
    throw new Error("Production ledger has gaps, reordering, duplicates, or unexpected migrations.");
  }
  const required = [
    "production_identity_verified", "tls_certificate_verified", "grants_verified",
    "schema_matches_ledger", "exclusive_migrator_verified",
    "backup_restore_verified", "p0_baseline_verified", "staging_rehearsal_verified",
  ];
  if (required.some((key) => evidence[key] !== true)) {
    throw new Error("Production inspector, exclusive migration, P0 or recoverable-backup evidence is missing.");
  }
  const pending = migrations.slice(applied.length);
  const blocked = pending.filter((item) => item.automatic !== true ||
    item.risk !== "expand-only" ||
    item.production_approval?.reviewed_sha256 !== item.sha256 ||
    item.production_approval?.additive_only !== true ||
    item.production_approval?.no_data_backfill !== true);
  if (blocked.length) {
    return { status: "MANUAL_REVIEW_REQUIRED", pending: pending.map((x) => x.file),
      blocked: blocked.map((x) => x.file), writes_executed: false };
  }
  return { status: pending.length ? "APPROVED_SOURCE_PLAN_ONLY" : "NO_PENDING",
    pending: pending.map((x) => x.file), blocked: [], writes_executed: false };
}
