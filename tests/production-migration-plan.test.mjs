import assert from "node:assert/strict";
import { fileURLToPath } from "node:url";
import test from "node:test";
import { loadSourceMigrationManifest, planProductionMigrations } from "../deployment/production-migration-plan.mjs";

const root = fileURLToPath(new URL("../", import.meta.url));
const evidence = {
  production_identity_verified: true, tls_certificate_verified: true,
  grants_verified: true, schema_matches_ledger: true, exclusive_migrator_verified: true,
  backup_restore_verified: true, p0_baseline_verified: true, staging_rehearsal_verified: true,
};

test("source manifest is exactly 24 pinned migration files; cannot silently run DDL", async () => {
  const migrations = await loadSourceMigrationManifest(root);
  assert.equal(migrations.length, 24);
  assert.ok(migrations.every((m) => m.automatic === false));
  const applied = migrations.map((m) => m.file.slice(0, -4));
  assert.deepEqual(planProductionMigrations(migrations, applied, evidence),
    { status: "NO_PENDING", pending: [], blocked: [], writes_executed: false });
});

test("unexpected migration, wrong order, missing backup or identity fail closed", async () => {
  const migrations = await loadSourceMigrationManifest(root);
  const ledger = migrations.map((m) => m.file.slice(0, -4));
  assert.throws(() => planProductionMigrations(migrations, [...ledger, "unknown"], evidence), /ledger/);
  assert.throws(() => planProductionMigrations(migrations, ledger.slice(1), evidence), /ledger/);
  assert.throws(() => planProductionMigrations(migrations, [...ledger].reverse(), evidence), /ledger/);
  assert.throws(() => planProductionMigrations(migrations, ledger, { ...evidence, backup_restore_verified: false }), /evidence/);
  assert.throws(() => planProductionMigrations(migrations, ledger, { ...evidence, production_identity_verified: false }), /evidence/);
});

test("new migration is blocked unless independently approved as expand-only, and plan never executes", async () => {
  const migrations = await loadSourceMigrationManifest(root);
  const applied = migrations.map((m) => m.file.slice(0, -4));
  const newMigration = { file: "2026_11_02_000000_create_example_table.php",
    sha256: "a".repeat(64), automatic: false, risk: "manual-review-required" };
  const pending = [...migrations, newMigration];
  const blocked = planProductionMigrations(pending, applied, evidence);
  assert.equal(blocked.status, "MANUAL_REVIEW_REQUIRED");
  assert.deepEqual(blocked.blocked, [newMigration.file]);
  const approved = { ...newMigration, automatic: true, risk: "expand-only",
    production_approval: { reviewed_sha256: newMigration.sha256,
      additive_only: true, no_data_backfill: true } };
  const candidate = planProductionMigrations([...migrations, approved], applied, evidence);
  assert.equal(candidate.status, "APPROVED_SOURCE_PLAN_ONLY");
  assert.equal(candidate.writes_executed, false);
  const mismatch = planProductionMigrations([...migrations, { ...approved,
    production_approval: { ...approved.production_approval, reviewed_sha256: "b".repeat(64) } }], applied, evidence);
  assert.equal(mismatch.status, "MANUAL_REVIEW_REQUIRED");
});
