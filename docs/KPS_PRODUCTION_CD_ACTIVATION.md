# KPS Production CD activation

The current implementation and operator checklist are in
[Production preflight and migration runbook](PRODUCTION_CI_PREFLIGHT.md).
This document replaces the earlier claim that a Production SQL inspector/executor
was absent. The code now includes both; neither has been run against Production
by this implementation task, and activation is not authorized here.

## Current repository state checked on 02/11/2026

- PR #74 is merged. `main`: `c0aa57e4352b981c2c522edb6065e90af6686e4a`.
- `staging`: `7ed0a12b0d8d9f63cbfbaa420be50bcdf109be97` (PR #76 includes M23/M24).
- PR #75 remains unmerged; its source was `e4ac06de974a726107c79b6a4962059712aa04a1`.
- This feature carries that unmerged CI/CD code and the hotfix source already in
  staging. Do not assume any CI/CD file is installed on main from these PRs.
- The seven SQL secrets and protected `production` Environment are user-reported
  configuration. Their values, grants and network reachability were not accessed.
- No live backend/frontend SHA, Production ledger/schema, backup or restore result
  was newly certified here. A Git merge is not evidence of the deployed version.

## Gates

Both automatic pushes and manual retries must use the exact current main SHA
associated with a merged **same-repository staging → main PR**. Direct main
pushes, hotfix-to-main PRs, forks named staging and feature-to-staging merges
cannot deploy Production. CI must pass before the production Environment job,
which must be approved by the configured reviewer.

`PRODUCTION_CD_ENABLED` is required for **both** push and manual release.
`PRODUCTION_MIGRATIONS_ENABLED` is a separate additional gate for pending DDL.
The standalone read-only workflow needs `PRODUCTION_PREFLIGHT_ENABLED` and
Environment approval, and cannot select the migration mode from CLI arguments.
None of these variables was enabled by this change.

The release then verifies provider targets/auto-deploy-off/Render Free, acquires
the shared durable Git lock, checks Production DB identity/TLS/grants/schema,
validates recovery, runs the dedicated migration gate and postflight, deploys
backend before frontend, verifies the staged Production frontend, promotes it,
and runs authenticated smoke and final DB checks. Only then is success reported
and the lock removed. SQL secrets are excluded from frontend process environments.

The dedicated executor supports only reviewed CREATE TABLE statements for new,
independent tables, pinned to both Laravel source and SQL hashes. All 24 existing
migrations remain `automatic: false`. No historical migration or unmerged future
migration is implicitly approved. ALTER, destructive DDL, backfill, references to
existing tables and arbitrary PHP migration execution are not supported by this
first automatic executor and must stop for separate review.

## Activation remains a separate action

Follow the full runbook to prepare reviewed identity/schema contracts, verified
CA, a permitted runner egress path, backup/restore and P0 evidence, deployment
credentials and a dedicated smoke account. Leave `production-baseline.json`
blocked until actual evidence is reviewed. Run the read-only workflow only when
separately authorized; then rehearse migration/failure handling against an
isolated non-Production test setup before any migration activation.

No database rollback, ledger repair, seeding, Production configuration change,
merge, deploy or activation is performed by preparing/reviewing this PR.
