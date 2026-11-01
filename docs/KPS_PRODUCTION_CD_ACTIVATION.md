# KPS Production CI/CD activation (Render Free + Vercel + TiDB)

## Current safe state

This is an **opt-in merge-triggered Production release pipeline**, not an unattended
database migrator. It is deliberately unavailable until merged to the protected
main branch, the **production** GitHub Environment is approved/configured, and
the reviewed baseline and recovery gate is complete.

- Existing users, accounts, RBAC, employee reports, comments, tasks and files are
  never seeded, truncated, migrated or rewritten by this pipeline.
- Migrations M1–M22 are SHA-256 pinned in
  `deployment/migration-policy.json`. Any new/modified/deleted migration fails
  preflight. Do not insert an unreviewed M23 or bypass this gate.
- `deployment/check-production-baseline.mjs` currently fails closed until
  independent Production identity/ledger/schema, current backend/frontend SHA,
  business smoke and recovery evidence have been recorded and reviewed.
  `RISK_ACCEPTED_NOT_VERIFIED` is **not** a successful restore drill.
- Push to `main` automatically starts CI. **Production deploy is skipped** until
  repository variable `PRODUCTION_CD_ENABLED=true` is explicitly armed. After
  activation, only an exact merged `staging → main` PR SHA can enter the guarded
  deployment job; direct pushes and hotfix merges do not auto-deploy.
- The protected GitHub Environment still requires operator approval before
  credentials are made available. Manual `workflow_dispatch` additionally
  requires exact `main` SHA and typed confirmation.
- Render Free is preserved. Neither Pre-Deploy Command nor background worker
  nor maintenance mode is required; automatic migration/rollback never occurs.

## Setup before activating (administrator)

1. Confirm the currently live Production services are healthy and Render's
   Docker Command is empty. Keep Render Production Auto-Deploy **Off**.
2. Disable Vercel Git automatic deployments for **both** `main` and
   `staging`, including dashboard/project-level settings. The CI/CD PR also
   configures `git.deploymentEnabled=false` in generated Vercel config; a
   code-only declaration is **not evidence** of the effective dashboard policy.
   Verify its API returns `gitProviderOptions.createDeployments=disabled`.
   If unavailable, this coordinator fails safely before deploying.
3. Create GitHub Environment **production**, restricted to branch **main** and
   required human reviewers. Do not store Production secrets as repo-wide
   environment variables. Check branch/ruleset protections against direct push,
   force push and bypasses; require PR checks: Branch policy, Frontend, Backend,
   Backend container build.
4. In Environment **production**, set:
   - Secrets: `RENDER_API_KEY`, `VERCEL_TOKEN`,
     `VERCEL_AUTOMATION_BYPASS_SECRET` (for staging the protected Vercel
     Production build URL prior to promotion).
   - Variables: `VERCEL_ORG_ID=team_hJaxsqskjIBmzfrdt8eI9Sky`,
     `VERCEL_PROJECT_ID=prj_sSEBHcwALQHag7fQQxFmbisxocz4`,
     `VERCEL_CLI_VERSION` to an **exact tested semver** (e.g. 48.0.0
     only after validation of that version). No credentials in chat or source.
   - Render API key must be scoped to the existing Production workspace/service.
     Do not pass DB root, APP_KEY or any AI keys to Actions.
5. Leave repository Actions variable `PRODUCTION_CD_ENABLED` unset until
   the required-reviewer Environment, secrets and rehearsals actually PASS.
   Set it to `true` only in a separate, explicit activation step.
6. Refresh `deployment/production-baseline.json` in a **reviewed PR** only
   with actual evidence. Confirm current migration ledger 22/22, schema and
   P0 business data; document backup and independent restore result or leave
   the gate blocked. No unilateral `verified=true` based solely on source
   files or row counts.
7. In staging first, verify the same build/release metadata, health and
   same-origin proxy routes using the existing Phase 1 coordinator. Test a
   failure before backend deploy and a failure between backend and frontend.
   Explicitly rehearse removing the retained Git lock only after both
   platforms/DB states are inspected.

## Release procedure

1. Merge reviewed changes through the branch rules. **A staging-to-main PR
   merge automatically triggers the Production CI workflow.** Pushes to main
   through any other route cannot auto-deploy.
2. After CI passes and `PRODUCTION_CD_ENABLED=true`, GitHub requests
   approval for the **production** Environment. Approve only after checking
   database migrations, verified baseline, recovery, P0 and expected service
   identities. A manual alternative remains available in Actions with exact
   SHA and `DEPLOY_KPS_PRODUCTION` confirmation.
3. Workflow runs all CI; checks baseline and pinned migration policy; verifies
   `main` has not advanced, Render is the approved single-instance Free
   service with Auto-Deploy Off, Vercel points to the approved project with
   Git automatic deploy disabled, and no other Render deploy is in flight.
4. It atomically acquires the shared Git ref lock. It deploys **Render at the
   exact candidate SHA**, waits for `live`, and checks `/api/health` for
   correct revision and production environment.
5. It builds the **Production frontend** using pinned Vercel CLI and deploys
   with `--skip-domain`; checks the staged URL's `release.json`,
   login page and proxy health against the exact SHA. **Only then** runs
   `vercel promote` and repeats checks on the public Production domain.
6. Only after final backend verification does it release the Git ref lock and
   publish the redacted evidence artifact (30-day retention).

**Pending migrations are still a hard stop.** A strict source/ledger planner
now exists in `deployment/production-migration-plan.mjs`. It requires exact
migration file SHA-256, contiguous ledger prefix, independent identity/TLS/grant/
schema checks, no competing migrator, recoverable backup, protected P0 baseline,
and a Staging rehearsal. A proposed `expand-only` migration also needs exact
reviewed hash and an explicit no-backfill approval; the planner returns only
`APPROVED_SOURCE_PLAN_ONLY` and **never writes SQL**.

**Automated Production DDL is not yet implemented**. The repository still lacks
a Production SQL inspector and hardened migration executor equivalent to the
Windows cutover tools; the required dedicated Production RO/migrator credentials
are not available to GitHub Actions. Until those are built, tested against a
TiDB rehearsal branch, and activated by a separate reviewed change, migration
pending means NO-GO: neither CI nor the planner invokes `artisan migrate`.
Never set `PRODUCTION_CD_ENABLED=true` based only on offline planning.
M23/M24 in the separate draft feature work are **not** implicitly approved.

The workflow does not run `artisan migrate`, seeder, user updates, SQL,
backup restore, Docker Command override, or rollback. It does not guarantee
zero downtime or consistency for schema-incompatible changes. Those require
their own freeze, backup/restore, migration, and data-preservation runbook.

## Failure handling

On any failure or timeout **do not run again immediately**. The lock
`refs/tags/kps-release-lock` remains held, by design, and prevents a
second coordinator. Inspect both provider deploy IDs, the actual live SHA,
`/api/health`, Vercel aliases and DB ledger/schema before unlocking by a
reviewed action. Failure after Render starts may leave the **new backend and
old frontend** serving simultaneously; treat that as a blocked cutover and
evaluate an explicitly compatible forward-fix. Neither code rollback nor
database restore is automatic. The failed run's artifact reports the last
phase; raw API responses, tokens, cookies and customer payloads are not logged.

**Important:** GH Environment reviewer rules, Vercel auto-deploy settings,
production recovery and actual secrets are configuration outside the
repository; files alone do not establish that CI/CD is active or safe to use.
