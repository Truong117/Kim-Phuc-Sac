# KPS-Staging incremental migration runner

`deployment/Invoke-KpsStagingIncrementalMigration.ps1` applies exactly one reviewed migration to the independent `KPS-Staging` instance. It is separate from the initial database bootstrap and never runs a seeder or the next migration automatically.

## Allowlist

Only these exact filenames are accepted:

1. `2026_10_27_000000_add_google_docs_source_to_weekly_plans.php`
2. `2026_10_27_010000_create_weekly_plan_google_docs_employee_mapping_tables.php`
3. `2026_10_28_000000_create_weekly_plan_entry_items_tables.php`
4. `2026_10_29_000000_create_weekly_plan_staged_review_tables.php`
5. `2026_10_30_000000_create_weekly_plan_v3_schema_foundation.php`
6. `2026_10_30_010000_create_weekly_plan_v3_correction_batches.php`
7. `2026_10_31_000001_create_account_audit_events_table.php`

Directories, arbitrary paths, raw Artisan arguments, multiple migrations, traversal, and unknown names are rejected by both the PowerShell wrapper and the isolated PHP launcher.

## Read-only modes

Omitting all mode switches defaults to `-Status`:

```powershell
.\deployment\Invoke-KpsStagingIncrementalMigration.ps1 `
  -Migration 2026_10_27_000000_add_google_docs_source_to_weekly_plans.php `
  -Status
```

Status verifies saved target metadata, TLS, the authenticated migration operator, grants, migration ledger, exact physical schema, parent keys, foreign-key checks, DDL jobs, and competing migration-operator sessions. It performs no database write and never creates an Execute receipt.

Plan performs the same read-only checks:

```powershell
.\deployment\Invoke-KpsStagingIncrementalMigration.ps1 `
  -Migration 2026_10_27_000000_add_google_docs_source_to_weekly_plans.php `
  -Plan
```

Only a successful Plan creates a receipt. A failed Plan creates none. The receipt is protected with Windows DPAPI CurrentUser, stored outside the repository under `%LOCALAPPDATA%\KPS\incremental-migration-receipts` by default, and is valid for 15 minutes. Its protected payload binds:

- `PLAN` mode and `PASS` status;
- migration filename and SHA-256;
- target host, port, database, and migrator username;
- the ledger baseline;
- identity, exact schema, concurrency, and Weekly Plan baseline digest;
- issue and expiry timestamps;
- a random nonce.

The Plan output prints `plan_receipt_path`. A custom `-PlanReceiptPath` is allowed only outside the repository. A nonce is atomically marked consumed immediately before migration launch, so the receipt cannot be reused even if the migration later fails.

## Execute gate and final ordering

```powershell
.\deployment\Invoke-KpsStagingIncrementalMigration.ps1 `
  -Migration 2026_10_27_000000_add_google_docs_source_to_weekly_plans.php `
  -Execute `
  -PlanReceiptPath '<path-from-successful-plan>' `
  -AcknowledgeBackupAvailable `
  -AcknowledgeRecoveryPoint `
  -AcknowledgeNewInstanceRestore `
  -AcknowledgeRpo
```

Execute requires all four acknowledgements, an unexpired matching Plan receipt, and the exact interactive confirmation phrase. The acknowledgements mean that the operator has confirmed the KPS-Staging backup, accepted its recovery point and RPO, understands that restore creates a new instance, and accepts possible loss after that recovery point.

Execute decrypts and parses the DPAPI receipt immediately before acquiring the local mutex. The pre-launch sequence after the mutex is acquired is:

1. Validate the DPAPI receipt and run a fresh full preflight.
2. Ask for the exact operator confirmation phrase. This is the last long wait.
3. Run a second full identity, TLS, ledger, schema, FK-check, DDL-job, and competing-session preflight.
4. Revalidate receipt freshness and the full preflight digest.
5. Re-hash the migration file.
6. Open the migration file read-only with Windows sharing that denies write/delete, hash that same handle, and keep it open through child completion.
7. Atomically consume the receipt nonce.
8. Launch exactly one isolated migration child.
9. Run mandatory read-only postflight.

There is no interactive wait between the second preflight and launch.

## Isolated Laravel child

The migration child is created with a new `ProcessStartInfo` environment block. The parent environment is cleared; only `SystemRoot`, `WINDIR`, `TEMP`, `TMP`, explicit non-network application settings, and these runner-specific database values are supplied:

- `KPS_RUNNER_DB_HOST`
- `KPS_RUNNER_DB_PORT`
- `KPS_RUNNER_DB_DATABASE`
- `KPS_RUNNER_DB_USERNAME`
- `KPS_RUNNER_DB_PASSWORD`
- `KPS_RUNNER_DB_SSL_CA`

No standard `DB_*`, `DB_URL`, `DB_SOCKET`, caller credential, API token, or `PATH` value is inherited.

`backend/scripts/run-staging-incremental-migration.php` bootstraps Laravel with an explicit bootstrapper list that omits `LoadEnvironmentVariables`. It therefore never parses `backend/.env`. It also refuses a Laravel configuration cache and installs the runner-only MySQL connection in memory with:

- `url = null`;
- `unix_socket = ''`;
- the receipt/preflight-bound host, port, database, and migrator;
- the reviewed CA;
- server-certificate verification hard-enabled with no environment opt-out.

Before calling the one allowlisted `migrate` command, the launcher verifies the actual Laravel connection's database, `CURRENT_USER`, TiDB identity, `foreign_key_checks=1`, and non-empty TLS cipher. The normal MySQL and MariaDB configuration is unchanged by this runner.

## Exact schema verification

Postflight verifies column order, type, nullability, default, `COLUMN_KEY`, `EXTRA`, primary keys, and `AUTO_INCREMENT`. Index checks compare the complete set, uniqueness, full ordered columns, prefix length, type, collation, and visibility when TiDB exposes it. Foreign keys are grouped by constraint and ordinal and verify the referenced schema/table/full columns, referenced key positions, and update/delete rules.

The complete expected index sets include the deterministic TiDB/MySQL child-FK indexes created for otherwise-unindexed foreign-key columns:

- migration 16 source table: `weekly_google_sources_actor_foreign`, `weekly_google_sources_folder_foreign`;
- migration 17 mapping table: `weekly_gdoc_tab_maps_assignee_foreign`, `weekly_gdoc_tab_maps_verifier_foreign`;
- migration 17 event table: `weekly_gdoc_tab_events_actor_foreign`, `weekly_gdoc_tab_events_new_user_foreign`, `weekly_gdoc_tab_events_old_user_foreign`, `weekly_gdoc_tab_events_plan_foreign`;
- migration 18 item table: `weekly_plan_entry_items_weekly_plan_entry_id_foreign`;
- migration 18 item-source-passage table: `weekly_item_passage_passage_foreign`; its first child FK is already covered by the composite primary key.
- migration 19 review rounds: explicit indexes for `started_by` and `completed_by`; the plan FK is covered by the plan-led composite indexes;
- migration 19 review decisions: explicit indexes for `assignee_id` and `reviewed_by`; the round FK is covered by the round/assignee unique index.
- migration 20 employee workflows: an explicit assignee FK index, unique plan/assignee identity, plan/state lookup, and publisher lookup;
- migration 20 confirmed batches: unique plan/stage/request identity, plan/stage/confirmation lookup, and reviewer lookup;
- migration 20 decisions: unique batch/workflow identity, workflow/history lookup, and assignee lookup.
- migration 21 correction batches: unique plan/request identity, plan/confirmation lookup, submitter lookup, and the existing restricting/nulling foreign keys;
- migration 22 account audit: exactly seven columns, unsigned auto-increment bigint primary key, and `account_audit_events_subject_user_id_created_at_index` over the full ordered `subject_user_id, created_at` columns. No foreign keys are allowed: historical actor/subject IDs must survive account deletion.

Unexpected indexes or an unsupported metadata representation fail closed as partial schema. Parent FK prerequisites require a compatible unsigned bigint and a referenceable single-column PRIMARY/UNIQUE key.

## Sequencing and failure policy

Migration 16 must complete postflight before migration 17 can pass Plan or Execute. Migration 18 requires migrations 16–17 complete, and migration 19 requires migrations 16–18 complete. Migration 16 preserves Weekly Plan IDs/count, requires existing rows to use `UPLOADED_FILE`, and leaves the new source table empty. Migration 17 leaves both new mapping/event tables empty. Migration 18 preserves Weekly Plan IDs/count and the Weekly Plan Entry count, and leaves both new item tables empty.

Migration 19 preserves the exact Weekly Plan count, plan-ID fingerprint, Weekly Plan Entry count, and legacy employee-review row count. Its preflight records plans in `SUBMITTED`/`IN_REVIEW`, plans in legacy `CHANGES_REQUESTED`, and the distinct plan/assignee pairs that must receive OWNER decisions. Postflight requires all legacy statuses to be normalized to `OWNER_REVIEW` or `OWNER_CHANGES_REQUESTED`, exactly one OWNER round per affected plan, the expected copied/default decisions, and no loss of legacy review rows. It does not fabricate Assistant history.

Migration 20 requires migrations 16–19 to be complete. It preserves Weekly Plan IDs/count and the Weekly Plan Entry count, requires every existing plan to retain `workflow_version = 2`, and requires the new employee-workflow, V3 batch, and V3 decision tables to remain empty. The schema is additive only; it does not activate V3 behavior or backfill projections.

Migration 21 requires the exact first 20 ledger entries and complete prior schemas. Its postflight requires the exact first 21 entries and the newly created correction-batch table to be empty.

### Migration 22: account audit

The bootstrap manifest already contains all 22 migration names. Incremental execution additionally requires the matching PowerShell parameter/allowlist, PHP launcher allowlist, plan definition, and inspector. These now explicitly admit migration 22; a manifest entry alone never authorizes execution.

Preflight for migration 22 requires the ledger to equal exactly migrations 1–21 in reviewed order, migrations 16–21 to have complete verified schemas, compatible parent identities, and all audit schema artifacts to be absent. Missing, reordered, duplicated, unexpected or already-applied ledger entries fail closed. Partial/existing audit schema fails closed even if the ledger omits migration 22. An audit artifact also blocks older migration paths; the runner never repairs the ledger or an existing table.

Postflight requires the exact 22-entry ledger, complete prior/audit schemas, no audit foreign keys, an empty audit table, and unchanged Weekly Plan IDs/count and entry count. Existing V3 business rows are allowed; migration 22 does not reuse migration 21's new-table-empty condition for historical correction batches. The receipt digest includes the audit schema state and audit row count, in addition to the existing identity, ledger, concurrency and baseline evidence. All original backup acknowledgements, DPAPI TTL/nonce, confirmation, file hash/lock and final preflight gates remain mandatory.

Safe staging release order (operator actions only after separate authorization):

1. Confirm backups/RPO and exclusive migration ownership. Do not release code that writes account audit records yet.
2. Run `-Status` then `-Plan` with `-Migration 2026_10_31_000001_create_account_audit_events_table.php` using verified staging target metadata. No live staging ledger/schema was assumed or queried during repository preparation.
3. If any migration 1–21 is missing or physical schema disagrees with the ledger, stop and report. Do not apply other migrations, edit ledger rows, skip guards or repair schema automatically as part of this release.
4. Only after Plan PASS, use its fresh receipt for `-Execute` with the existing four backup/recovery/RPO acknowledgements and exact interactive confirmation. Execute runs only the audit migration, never the entire folder or a seeder.
5. Require the runner's mandatory read-only postflight PASS before deploying Account Settings code. A completed database step does not authorize application deployment by itself.

Migration 22 creates a separate table, performs no user/business backfill, and does not modify existing data. Its `down()` permits an empty-table rollback but refuses to discard audit history. Never invoke rollback automatically after failure; retain evidence and choose a separately reviewed recovery/forward-fix plan.

Laravel's MySQL grammar emits two statements for this migration: `CREATE TABLE` followed by `ALTER TABLE ... ADD INDEX`. [TiDB's transaction documentation](https://docs.pingcap.com/tidb/stable/transaction-overview/#explicit-and-implicit-transaction) states that DDL commits automatically and cannot be transactionally rolled back. A failure between statements or before ledger insertion can therefore leave partial schema; the next Status/Plan must stop on that mismatch rather than retry or mark it applied. The generated data types and auto-increment primary key use the documented [TiDB/MySQL CREATE TABLE syntax](https://docs.pingcap.com/tidb/stable/sql-statement-create-table/); this syntax review does not replace execution on the target engine.

Local evidence covers real Laravel migrations 1–21 followed by 22 on SQLite `:memory:`, preservation of existing account/RBAC/business tables, ledger order, index/no-FK checks, rollback guards, MySQL grammar compilation with connection I/O disabled, synthetic MySQL/TiDB metadata, and mocked isolated launcher/PowerShell orchestration. This does not establish the actual staging ledger, table metadata, grants or TiDB DDL behavior. Successful live Status/Plan and postflight remain required.

On any migration or postflight failure—including invalid inspector output—stop. Do not retry, roll back, drop objects, edit the migration ledger, seed, start the next migration, or attempt automatic repair. Collect only read-only evidence from the migration ledger, `INFORMATION_SCHEMA`, `SHOW CREATE TABLE`, process state, and DDL jobs.

## Concurrency limitation

The runner's `Local\KPS-Staging-Incremental-Migration` mutex serializes only the current Windows session. The two preflights and process-list/DDL-job checks reduce the race window but do not provide a database advisory lock or cross-machine serialization. The release operator must still confirm that Render hooks, remote shells, other Windows sessions, and other workstations are not running migrations.
