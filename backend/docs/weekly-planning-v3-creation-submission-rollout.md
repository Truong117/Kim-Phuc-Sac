# Weekly Planning V3 creation and submission rollout

## Architecture and opt-in contract

V3 is selected only when a new plan is created. Both upload and Google Docs
creation accept `workflow_version` with the allowlisted values `2` or `3`.
Omitting the field creates V2. Selecting V3 is rejected unless
`WEEKLY_PLAN_WORKFLOW_V3_ENABLED=true`; an existing plan is never converted.

Google Docs binds the selected version into its idempotency payload hash. V2
requests created before this release remain replayable through a narrowly
scoped legacy-hash check that is accepted only when the persisted plan is V2.

`weekly_plan_employee_workflows` is also the authoritative draft roster. A row
exists for every explicitly selected employee, including zero-work employees.
The plan remains `DRAFT`, so these rows cannot be reviewed. Roster membership
may change only while the plan is a V3 draft. Submission locks the plan,
workflow rows, entries, and child items, validates completeness, normalizes all
workflow rows to their version-1 initial values, and atomically moves the plan
to `IN_REVIEW`.

No V2 review round, Task, or notification is created during V3 submission.

## Controlled migration order

This phase adds no schema. A target database must already have the reviewed
21-migration manifest. In particular, apply these in their existing order:

1. `2026_10_30_000000_create_weekly_plan_v3_schema_foundation.php`
2. `2026_10_30_010000_create_weekly_plan_v3_correction_batches.php`

Do not reorder, edit, or mark these migrations as run manually. Use the strict
staging incremental migration inspector and reviewed launcher during a future
approved rollout; this branch does not run either against staging.

## Preflight

- Take and verify a restorable database backup before migrations or flag work.
- Confirm the deployed migration manifest and fingerprints match the reviewed
  application manifest.
- Confirm every existing `weekly_plans.workflow_version` is `2` before the
  first V3 rollout and specifically verify plan `30001` remains V2.
- Confirm V3 workflow, batch, and correction tables are empty before the first
  opt-in.
- Confirm active default memberships are unique and belong to the same
  organization as any planned pilot roster.
- Confirm the feature flag is absent or false in every deployed environment.
- Run the complete V2 import, draft, review, approval, publishing, Task, and
  notification regression suites against an isolated database.

## Activation sequence

1. Deploy schema-compatible application code with V3 disabled.
2. Apply migrations 20 then 21 through the reviewed launcher if they are not
   already present.
3. Run postflight schema and constraint checks.
4. Keep V3 disabled while validating V2 creation and publication.
5. Enable V3 only in an explicitly approved pilot environment and create a
   synthetic V3 plan through the visible creation selector.
6. Do not convert or update existing V2 plans.

## Postflight

- Verify migration rows, indexes, unique constraints, and foreign keys with the
  strict inspector.
- Verify V2 creation still defaults to workflow version 2.
- Verify a disabled V3 request returns validation failure and writes nothing.
- Verify a pilot V3 draft has an explicit roster with exactly one workflow per
  selected active organization employee.
- Verify submission produces `IN_REVIEW`, `submitted_by`, `submitted_at`, and
  one `V3_SUBMITTED` event while creating no V2 round, Task, or notification.
- Verify every entry assignee has a workflow and zero-work roster employees
  retain their workflow.
- Verify Assistant, HR correction, and OWNER partial publishing on synthetic
  data, including exactly-once Tasks and notifications.

## Recovery constraints

Before any immutable V3 batch, correction ledger, or published Task exists,
disable the feature flag and use the guarded migration rollback only after its
preconditions are proven. Once immutable V3 decisions or Tasks exist, the
foundation rollback intentionally refuses to run. Recovery must then be a
forward fix or a full database restore coordinated with application rollback;
never delete ledger rows, workflow rows, Tasks, or notifications manually.

Disabling the feature flag stops new V3 creation and mutation but does not
convert or erase existing V3 data. V2 remains available throughout.

## TiDB concurrency test plan

- Race two initial submissions for one V3 draft and verify one transition and
  one submission event.
- Race roster update against submission and verify lock ordering yields either
  the complete old roster or complete new roster, never a partial projection.
- Race Assistant/OWNER batches against stale state and content versions.
- Retry Google Docs creation with the same request/version and then a different
  version using the same request ID.
- Verify duplicate Task constraints and transactional notifications under
  concurrent OWNER publication.

Use synthetic plans only and capture deadlock/retry metrics without logging
employee content.

## Browser E2E plan

- Verify the creation selector is hidden while V3 is disabled and defaults to
  V2 when available.
- Create V3 from upload and Google Docs, then reload each wizard step.
- Select work and zero-work employees, edit parent/child work, and verify
  cross-organization employees cannot be selected.
- Verify Step 4 version, roster, zero-work, readiness, and submit summaries.
- Complete Assistant review, OWNER correction, HR resubmission through
  Assistant, and OWNER publication.
- Verify V2 wizard routing and review behavior are unchanged.
