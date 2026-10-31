# Weekly Planning V3 Phase 7B readiness

## Assessment

**Rollout assessment: NO-GO.** The SQLite integration, rollback, migration-safety,
V2 regression, and frontend suites pass. The required genuinely parallel TiDB
transactions and real browser E2E scenarios are still **BLOCKED** by the local
environment. This document does not treat sequential requests, mocked frontend
tests, source inspection, MySQL, or SQLite as substitutes for those two gates.

The Phase 7B test and documentation changes are suitable for review, but they do
not authorize deployment, migration, feature activation, or staging access.

## Tested source

- Base commit: `48fe4f41e7b3f46da0bd78cab7baae2ef09bedc1`
  (`origin/staging`, merged PR #68).
- Tested implementation commit: `IMPLEMENTATION_COMMIT_PENDING`.
- Branch: `feature/weekly-planning-v3-integration-readiness`.
- V3 remained disabled by default. Tests enabled it only inside isolated test
  processes using synthetic fixtures.
- No staging or production connection, migration, seeder, Task, or notification
  was used. Plan `30001` was not read or modified.

The tested implementation SHA is intentionally the first commit containing the
Phase 7B test and this evidence template. A later documentation-only commit may
record that immutable SHA without claiming the documentation commit was the
test target.

## Test environment

| Component             | Version / configuration                                                      | Result        |
| --------------------- | ---------------------------------------------------------------------------- | ------------- |
| Host                  | Windows NT `10.0.26200.0`                                                    | Informational |
| PHP                   | `8.2.12`                                                                     | PASS          |
| Laravel test database | PDO SQLite in-memory, SQLite `3.39.2`                                        | PASS          |
| PDO drivers           | `mysql`, `sqlite`                                                            | Informational |
| Node.js               | `v22.23.3`                                                                   | PASS          |
| npm                   | `10.9.9`                                                                     | PASS          |
| PowerShell/Pester     | Windows PowerShell, Pester `3.4.0`                                           | PASS          |
| Disposable TiDB       | Docker/TiDB unavailable; `docker` is not installed                           | BLOCKED       |
| Browser runner        | No Playwright, Cypress, WebDriver, or browser-test package in the repository | BLOCKED       |

An unrelated local MySQL 8.4 service was detected but was not connected to or
modified. Its database identity and disposability were not established, and
MySQL is not evidence of TiDB behavior.

## Coverage matrix

| Area                                              | Evidence                                                                                                                                 | Result  |
| ------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | ------- |
| Multi-employee HR → Assistant → OWNER lifecycle   | `WeeklyPlanningV3Phase7BIntegrationTest` with four work employees and one zero-work employee                                             | PASS    |
| Mixed Assistant decisions                         | ACCEPTED and CHANGES_REQUESTED in one immutable batch                                                                                    | PASS    |
| Assistant-origin correction                       | HR changes only the requested employee, resubmits, then Assistant accepts                                                                | PASS    |
| Mixed OWNER decisions                             | ACCEPTED, CHANGES_REQUESTED, and APPROVE_NO_WORK in one partial batch                                                                    | PASS    |
| OWNER-origin correction                           | HR correction returns through Assistant before OWNER publication                                                                         | PASS    |
| Partial then full publication                     | Three Tasks first, corrected employee last, plan remains IN_REVIEW until terminal                                                        | PASS    |
| Exact replay                                      | Assistant, HR, and OWNER retries preserve batch identity and produce no duplicate rows                                                   | PASS    |
| Task contract                                     | One Task per published entry; source ID, assignee, organization, department, location, dates, priority, status, and description asserted | PASS    |
| Notification contract                             | One assignment notification per Task; none for correction or zero-work; exact replay adds none                                           | PASS    |
| Previously published work                         | Task IDs, source IDs, titles, and raw update timestamps remain unchanged across another employee's correction                            | PASS    |
| Employee versions                                 | Final state/content versions asserted for all five employee workflows                                                                    | PASS    |
| Immutable audit                                   | Review/correction ledger counts, snapshots, hashes, and V3 event types asserted                                                          | PASS    |
| Roster failure rollback                           | Late `V3_ROSTER_UPDATED` event failure rolls back projection creation                                                                    | PASS    |
| Submission failure rollback                       | Late `V3_SUBMITTED` event failure rolls back plan and workflow mutation                                                                  | PASS    |
| Assistant failure rollback                        | Late batch event failure rolls back batch, decisions, and selected workflow changes                                                      | PASS    |
| HR confirmation failure rollback                  | Late batch event failure rolls back ledger and workflow transitions                                                                      | PASS    |
| Immutable decision failure rollback               | Decision insert failure rolls back OWNER batch                                                                                           | PASS    |
| Task failure rollback                             | Second Task insert failure rolls back both Tasks, notifications, batch, decisions, and workflow changes                                  | PASS    |
| Notification failure rollback                     | Notification insert failure rolls back its Task and complete OWNER batch                                                                 | PASS    |
| Publication finalization rollback                 | Late OWNER batch event failure rolls plan status and all side effects back; same logical request retries successfully                    | PASS    |
| Roster/workflow integrity                         | Existing V3 creation, lifecycle, projection, Assistant, correction, OWNER, and new integration suites                                    | PASS    |
| V2 behavior                                       | Import, Google Docs import, draft, staged review, correction, publication, reset/delete, Task mapping, notifications                     | PASS    |
| SQLite migration 20/21 schema and rollback guards | `WeeklyPlanningMigrationTest` and strict manifest/inspector/launcher unit tests                                                          | PASS    |
| Migration orchestration mocks                     | Pester tests use fake config/secrets/inspector and never invoke a live migration child                                                   | PASS    |
| Actual TiDB migration rehearsal                   | No disposable TiDB available                                                                                                             | BLOCKED |
| Actual parallel TiDB transactions                 | No disposable TiDB available                                                                                                             | BLOCKED |
| Browser E2E                                       | No existing browser test runner/dependency; package installation was not authorized                                                      | BLOCKED |
| Frontend source-level/component-contract tests    | Node test suite, including V3 recovery/navigation contracts                                                                              | PASS    |

The controlled failures use test-process Eloquent listeners. They throw inside
the real production transaction boundaries but add no runtime route, feature
flag, service branch, or production failure hook.

## Commands executed

Focused Phase 7B:

```text
cd backend
php artisan test --filter=WeeklyPlanningV3Phase7BIntegrationTest
```

Result: **PASS**, 4 tests, 97 assertions.

V3 lifecycle regressions:

```text
cd backend
php artisan test \
  tests/Feature/WeeklyPlanningV3SchemaFoundationTest.php \
  tests/Feature/WeeklyPlanEmployeeLifecycleTest.php \
  tests/Feature/WeeklyPlanningV3ReadProjectionTest.php \
  tests/Feature/WeeklyPlanningV3AssistantBatchTest.php \
  tests/Feature/WeeklyPlanningV3CorrectionTest.php \
  tests/Feature/WeeklyPlanningV3OwnerBatchTest.php \
  tests/Feature/WeeklyPlanningV3CreationSubmissionTest.php \
  tests/Feature/WeeklyPlanningV3Phase7BIntegrationTest.php
```

Result: **PASS**, 64 tests, 959 assertions.

Migration and environment safety:

```text
cd backend
php artisan test \
  tests/Feature/WeeklyPlanningMigrationTest.php \
  tests/Unit/StagingBootstrapPlanTest.php \
  tests/Unit/StagingIncrementalMigrationPlanTest.php \
  tests/Unit/StagingIncrementalMigrationInspectorTest.php \
  tests/Unit/StagingIncrementalMigrationLauncherTest.php \
  tests/Feature/EnvironmentSafetyTest.php \
  tests/Feature/FilesystemSafetyTest.php
```

Result: **PASS**, 64 tests, 411 assertions.

V2 and Task regressions:

```text
cd backend
php artisan test \
  tests/Feature/WeeklyPlanningTest.php \
  tests/Feature/WeeklyPlanningStagedReviewTest.php \
  tests/Feature/WeeklyPlanningDeleteTest.php \
  tests/Feature/WeeklyPlanningResetTest.php \
  tests/Feature/GoogleDocsSourceImportTest.php \
  tests/Feature/TaskManagementTest.php
```

Result: **PASS**, 103 tests, 1,483 assertions.

Mock-only migration orchestration safety:

```text
Invoke-Pester \
  -Script deployment/tests/Invoke-KpsStagingIncrementalMigration.Tests.ps1 \
  -PassThru
```

Result: **PASS**, 25 tests. The test file supplies synthetic connection
metadata and mocks the inspector, secrets, and migration child. This is not a
staging migration rehearsal and not TiDB evidence.

Frontend:

```text
npm test
npm run lint
npm run build
npx prettier --check .
```

Backend/full repository checks:

```text
cd backend
php artisan test
vendor/bin/pint --test

cd ..
git diff --check
```

Final validation results:

| Command                                    | Result                                                       |
| ------------------------------------------ | ------------------------------------------------------------ |
| `php artisan test --compact`               | PASS — 608 tests, 5,788 assertions                           |
| `npm test`                                 | PASS — 243 tests                                             |
| `npm run lint`                             | PASS — 0 errors, 4 existing Fast Refresh warnings            |
| `npm run build`                            | PASS — production bundle built; existing large-chunk warning |
| `vendor/bin/pint --test`                   | PASS                                                         |
| Prettier on the changed readiness document | PASS                                                         |
| `git diff --check`                         | PASS                                                         |

The repository-wide Prettier audit was also attempted, but it scans
`backend/vendor` and reports 194 pre-existing source/config files outside this
change. It was stopped while traversing vendored dependencies and then rerun on
the changed document, which passes. No unrelated formatting rewrite was made.

## Concurrent transaction gate

No scenario in this section was executed. SQLite in-memory gives each process
an isolated database and cannot verify TiDB row locks, deadlocks, DDL behavior,
or duplicate-key races. Sequential conflict tests are useful regressions but
are not concurrency evidence.

Before running any future harness, fail closed unless all of these are true:

1. the target host is loopback;
2. the database name is a newly created `kps_v3_phase7b_*` throwaway database;
3. credentials are synthetic and have no access outside that database;
4. `SELECT VERSION()` identifies a pinned TiDB version;
5. staging/production URLs and secrets are absent from the process;
6. the test runner confirms migration 1–21 on an empty target;
7. cleanup is limited to the exact validated throwaway database.

| Race                                 | Permitted committed outcomes                                                                          | Status  |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------- | ------- |
| Two submissions                      | One submission wins; one V3_SUBMITTED event; loser returns stale/conflict or observes committed state | BLOCKED |
| Roster update vs submission          | Complete old or complete new roster; never a partial projection or entry without workflow             | BLOCKED |
| Two Assistant batches, same employee | Exactly one state transition and one immutable winning decision                                       | BLOCKED |
| Two OWNER publishes, same employee   | Exactly one Task and notification per entry; exactly one winning decision                             | BLOCKED |
| OWNER publish vs stale confirmation  | Valid locked version wins; stale request writes nothing                                               | BLOCKED |
| HR correction vs stale versions      | One valid content/state version advance; stale request writes nothing                                 | BLOCKED |
| Two exact retries, same key/payload  | One ledger row; both callers receive the same logical result                                          | BLOCKED |
| Same key, different payload          | One payload wins; other receives idempotency conflict; no mixed decisions                             | BLOCKED |

Required execution uses independent processes/connections, an explicit start
barrier, bounded repetition, and post-race database assertions. These remain a
hard Phase 7C prerequisite. No optional TiDB job was added to normal CI because
no safe disposable service is currently available and pull requests must not
depend on a shared database.

## TiDB migration compatibility gate

SQLite and pure metadata validation cover ordering, exact table/index/foreign
key contracts, migration 20/21 guards, and V2 preservation. They do not verify
TiDB JSON behavior, row locking, online DDL, foreign-key enforcement, or the
non-transactional failure modes of partial DDL.

Actual migration 1–21, re-execution, guarded down paths, and concurrent DML on
a disposable TiDB instance are **BLOCKED**. Consequently TiDB version is **NOT
RUN**, and migration compatibility is **NOT VERIFIED** for rollout.

Do not execute `Invoke-KpsStagingIncrementalMigration.ps1`, its Plan/Status
modes, or the live PHP inspector to close this gate. The approved future test
must target a separately provisioned disposable instance.

## Browser E2E gate and manual test script

The repository has no browser E2E framework. The Node tests exercise reducers,
request construction, component contracts, recovery state, and source wiring;
they do not launch a browser, authenticate a session, or prove BrowserRouter,
CSRF, history, unload, and reload behavior. Browser E2E is therefore
**BLOCKED**, not PASS.

Run this script only against a local frontend, local backend, and isolated
synthetic database after recording the exact origins and database identity.
Capture a timestamped screen recording, request log with secrets redacted,
database assertion output, commit SHA, browser/version, and final cleanup.

### Creation

1. With V3 disabled, sign in as synthetic HR; verify V2 is the default and V3
   cannot be selected or submitted.
2. Enable V3 only in the local process; create an uploaded plan and a mocked
   Google Docs plan. Verify the selected workflow version is explicit.
3. Select several active same-organization employees, including one zero-work
   employee. Reject duplicates, inactive/cross-organization employees, and
   removal of an employee who still owns work.
4. Add/edit parent entries and child items. Verify incomplete upload/Google
   processing prevents submission and a complete source permits it.
5. Confirm submission once and verify the UI moves to IN_REVIEW with no Task or
   notification.

### Review and correction

1. Sign in as Assistant. Mix accept/change decisions, add independent notes,
   confirm one batch, then verify partial queues and immutable versions.
2. Sign in as HR. Verify only HR_CORRECTION employees are editable and staged
   or published employees cannot be changed. Correct and resubmit a subset.
3. Verify the corrected employee returns to Assistant before OWNER, including
   an OWNER-origin correction.
4. Sign in as OWNER. Mix publish, request changes, and zero-work approval.
   Verify the Task preview matches the exact immutable pending payload.
5. Verify partial publication does not hide other queues; finish the remaining
   correction and reach full publication.
6. Sign in with a multi-permission account and confirm Assistant/OWNER pending
   requests remain stage-separated. Verify observer/read-only controls never
   mutate.

### Recovery and navigation

1. Intercept a confirmation response after the server commits and make the
   client observe an unknown/5xx outcome. Verify the same key and payload are
   retained, and exact retry reconciles without duplicates.
2. Repeat for a 409 stale response and a 422 validation response; verify their
   distinct UI states and that only ambiguous outcomes retain retry identity.
3. With unresolved Assistant and OWNER requests, switch reviewer tabs and
   verify identities remain independent.
4. Attempt an internal Link, browser Back, and Forward. Choose “stay” and verify
   the exact pending request survives. Choose “leave” only after capturing the
   documented minimal recovery metadata; verify no automatic retry.
5. Reload, close, and reopen where supported. Verify recovery does not persist
   full plan/employee content and never describes a different payload from the
   one retried.
6. Reconcile successfully and verify the navigation warning clears only for
   the confirmed stage.

### Security

1. Exercise unauthenticated, wrong-organization, revoked-membership, revoked-
   permission, disabled-V3, and V2-plan requests against every V3 route.
2. Verify session and CSRF failures are rejected and do not create ledgers,
   Tasks, or notifications.
3. Verify read-only workspace access does not imply mutation permission.
4. Confirm no V2 plan can be mutated through V3 controls.

Evidence is acceptable only if UI actions are paired with server/database
assertions for batch IDs, versions, Tasks, notifications, and events. Screenshots
alone are insufficient.

## Recovery decision matrix

| Situation                                  | Disable V3?                                            | Schema rollback?                                                                                      | Required recovery                                                                                                                              | Data that must be preserved                                             | Verification                                                                |
| ------------------------------------------ | ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| Code failure before any V3 usage           | Stops new V3 creation/mutation                         | Allowed only if guards prove all plans V2 and V3 tables empty                                         | Roll back code or forward fix                                                                                                                  | All V2 plans/Tasks/notifications                                        | Full V2 suite; feature disabled; V3 tables empty                            |
| Migration failure / partial DDL            | Stops application V3 mutations but does not repair DDL | Do not assume TiDB DDL rollback                                                                       | Stop, inspect exact ledger/schema, restore disposable rehearsal or use reviewed forward repair; production requires separate incident approval | Migration ledger and all V2 data                                        | Strict inspector, backups, schema fingerprint, V2 smoke tests               |
| V3 enabled, no confirmed decisions         | Stops new mutations; existing drafts remain            | Only if no V3 plan/projection/ledger violates guarded down preconditions; normally prefer forward fix | Disable flag, retain drafts, fix forward or restore approved backup                                                                            | Draft source, roster, entries, events                                   | No batches/Tasks; V2 functional; plan identities unchanged                  |
| Confirmed Assistant/OWNER/HR batches exist | Stops new mutations                                    | Not allowed by immutable-ledger guards                                                                | Forward fix; do not delete ledger rows                                                                                                         | Batches, decisions, snapshots, versions, events                         | Ledger hashes and projection reconciliation                                 |
| Published Tasks exist                      | Stops new mutations, not employee work                 | Not allowed                                                                                           | Forward fix or coordinated full restore using an approved recovery point                                                                       | Tasks, source links, notifications, publication metadata, audit history | Source uniqueness, Task/notification counts, employee state, V2 smoke tests |

Disabling V3 never converts plans, reverses Tasks, or deletes immutable audit.
Manual deletion of decisions, Tasks, notifications, or plan `30001` is forbidden.

## CI decision

No CI workflow was changed. The new Phase 7B feature test is automatically part
of the existing deterministic SQLite backend job and adds no shared service,
secret, package, or deployment dependency. The focused local command is:

```text
cd backend
php artisan test --filter=WeeklyPlanningV3Phase7BIntegrationTest
```

A future TiDB or browser job must be isolated, synthetic, bounded, fail closed,
and explicitly approved before dependencies or hosted services are added.

## Known issues and unverified scenarios

- **BLOCKED:** all eight genuinely concurrent TiDB races.
- **BLOCKED:** migration 1–21 and guarded rollback behavior on actual TiDB.
- **BLOCKED:** real browser authentication, CSRF, navigation, history, unload,
  reload, and post-commit network-recovery flows.
- **NOT VERIFIED:** TiDB deadlock/retry behavior and duplicate-key winner lookup
  under simultaneous OWNER publication.
- **NOT VERIFIED:** browser-specific persistence behavior across a closed and
  reopened tab.
- No production-code defect was found by the executed SQLite integration and
  rollback tests.

## Rollout prerequisites

1. Provision a pinned, disposable TiDB instance with synthetic credentials and
   prove loopback/isolation before any migration.
2. Execute the eight synchronized races repeatedly and archive redacted final-
   state evidence.
3. Rehearse migrations 1–21, re-execution, schema constraints, and safe guarded
   down behavior on that disposable TiDB instance.
4. Approve or provide an existing browser E2E runner, then execute the browser
   script against a fully isolated local stack.
5. Resolve every FAIL and convert all rollout-critical BLOCKED/NOT VERIFIED
   cells to PASS.
6. Re-run full backend/frontend, V2, migration safety, formatting, lint, and
   production build on the exact candidate commit.
7. Obtain separate Phase 7C approval before staging migration, feature flag
   activation, synthetic pilot creation, or deployment.

Until these prerequisites are met, the evidence supports review of Phase 7B
tests but not a controlled staging rollout.
