# Weekly Planning V3 OWNER partial publishing

## API and authorization contract

`POST /api/weekly-plans/{plan}/owner-review-batches` accepts an immutable
`Idempotency-Key` and a non-empty, bounded list of employee decisions. Every
decision supplies the assignee, decision, expected state version, and expected
content version. Supported decisions are `ACCEPTED`, `CHANGES_REQUESTED`, and
`APPROVE_NO_WORK`.

All decisions require an active organization-scoped `weekly_plans.review`
grant. `ACCEPTED` and `APPROVE_NO_WORK` are final OWNER decisions and also
require active organization-scoped `weekly_plans.approve` and
`weekly_plans.publish` grants. `CHANGES_REQUESTED` does not inherit publication
authority. The service re-reads these grants and the active KPS membership
inside the transaction. Exact replay performs the same current authorization
checks, including the finalization permissions used by the stored batch.

OWNER publication does not require or grant `tasks.manage`. `TaskService`
exposes a narrowly scoped V3 method that accepts only an already-authorized V3
plan, a source entry belonging to that plan, and a locked active/default target
membership in the same organization. The ordinary Task create/update APIs and
their authorization remain unchanged.

## Atomic lock and validation strategy

The batch uses one retried database transaction and locks in this order:

1. plan;
2. selected employee workflows ordered by assignee;
3. selected entries ordered by assignee, sort order, and ID;
4. child items ordered by entry, sort order, and ID;
5. immutable Assistant decision provenance and existing source-linked Tasks;
6. publication target memberships ordered by user ID.

All selected decisions, versions, lifecycle transitions, content readiness,
zero-work rules, organization integrity, Assistant snapshot hashes, existing
Task absence, and snapshot bounds are checked before the batch ledger or any
Task is inserted. A failure rolls back the complete batch.

An applicable Assistant acceptance is identified by workflow ID, Assistant
stage, accepted decision, the immediately preceding state version, and the
same content version. Its immutable content hash must equal the current locked
snapshot. Timestamp ordering is not used as provenance.

## Task mapping and exactly-once effects

Each active accepted entry creates exactly one Task using the existing V2
mapping: source entry ID, plan organization, OWNER as creator, employee as
assignee, the employee's active department/location, title, combined content
and expected-result description, priority, dates, and `TODO` status. The
existing `tasks_weekly_plan_entry_unique` index remains the database-level
duplicate barrier.

Task assignment notifications are database records created with the winning
Task insert. They participate in the same transaction; rollback removes both,
and exact replay performs no Task or notification insert. There is currently
no irreversible external delivery in this path.

## Idempotency, audit, and plan completion

The existing unique `(weekly_plan_id, stage, request_id)` key is authoritative.
Canonical payload hashes make decision order irrelevant. An exact replay
returns the stored logical result with HTTP 200; key reuse with different
content returns 409. Stored decision snapshots provide replay task counts and
remain immutable.

`ACCEPTED` transitions to `PUBLISHED`, creates Tasks, and stores employee
publication metadata. `CHANGES_REQUESTED` transitions to `HR_CORRECTION`, sets
OWNER as the correction origin, and creates no Task. `APPROVE_NO_WORK` is valid
only for zero active entries, transitions to `NO_WORK_APPROVED`, and records
zero Tasks. Unselected workflows are untouched.

The projection summary remains authoritative. A mixed terminal/non-terminal
plan stays `IN_REVIEW` and reports `PARTIALLY_PUBLISHED` when at least one
employee is published. Only a non-empty collection containing exclusively
`PUBLISHED` and `NO_WORK_APPROVED` employees finalizes the plan as `PUBLISHED`
and records plan approval/publication metadata. V2 status and publishing paths
are unchanged.

No schema migration is required for this phase. The dedicated V3 batch and
decision tables, employee projection, source-linked Task uniqueness, and
transactional notification rows provide the required storage guarantees.
