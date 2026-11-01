# Weekly Planning V3 schema foundation

## Scope

This foundation is additive. It does not enable V3 behavior, convert an
existing plan, publish a Task, or alter a V2 review round. Existing and newly
created plans remain `workflow_version = 2` until a later rollout explicitly
opts a plan into V3.

## Confirmed batch storage decision

V3 uses dedicated `weekly_plan_v3_review_batches` and
`weekly_plan_v3_review_decisions` tables instead of extending the V2 round
tables.

V2 rounds are mutable while open, carry accepted decisions into later rounds,
and use `CHANGES_REQUESTED` and `SUPERSEDED` as lifecycle states. A V3 batch is
created only when the reviewer confirms it and is immutable from creation.
Reusing the V2 tables would couple incompatible lifecycle rules and make a V2
rollback or compatibility path unsafe.

Confirmed batches cannot be deleted through their model, and their plan
foreign key uses `RESTRICT`. Decisions likewise restrict deletion of their
batch and employee projection. These database constraints ensure a parent
delete cannot silently cascade confirmed audit history away.

The V3 batch idempotency boundary is the unique combination of plan, stage,
and request ID. `payload_sha256` detects reuse of a request ID with a different
payload. Each decision stores the expected state/content versions and an
immutable content hash and snapshot.

## Employee workflow projection

`weekly_plan_employee_workflows` is the current-state projection. Its allowed
states are:

- `AWAITING_ASSISTANT`
- `STAGED_FOR_OWNER`
- `HR_CORRECTION`
- `PUBLISHED`
- `NO_WORK_APPROVED`

`state_version` protects state transitions. `content_version` protects the
reviewed employee content. Publication metadata is stored on the employee
projection; no Task is created merely by inserting a projection row or moving
it to `STAGED_FOR_OWNER`.

## Existing Task idempotency and notifications

`tasks.weekly_plan_entry_id` already has a unique index and a `RESTRICT`
foreign key to the source entry. `TaskService::createFromWeeklyPlanEntry()`
first returns an existing Task and otherwise creates the Task and assignment
notification in the surrounding database transaction. The unique index is the
authoritative last line of defense against duplicate Tasks, and notification
creation rolls back with a failed Task transaction.

The current check-then-create sequence can still surface a duplicate-key error
under concurrent publishers, and `user_notifications` has no source-entry
idempotency key of its own. A later V3 publisher must lock the employee
projection and selected entries, treat the Task unique constraint as a
recoverable winner lookup, and never emit notification work separately from
the winning Task insert. This foundation does not alter Task or notification
behavior.

## Entry deletion and comment foreign-key audit

V2 currently hard-deletes entries. The dependent behavior is not safe to
change by only adding Laravel's `SoftDeletes` trait:

- `weekly_plan_comments.weekly_plan_entry_id` cascades on hard delete.
- entry items and both source-passage pivot tables cascade on hard delete.
- suggestions cascade with the entry.
- Tasks reference entries with `RESTRICT` and a unique source constraint.
- readiness, grouping, review initialization, correction authorization,
  source processing, reset/delete safety, and publisher queries all use the
  default entry relation or direct entry queries.
- API resources and frontend counts assume every loaded entry is active.

No soft-delete column or model trait is introduced by this foundation. A later
entry-retention change must define whether deleted work remains reviewable,
how comments retain their target context, how zero-work employee groups are
represented, and which queries include archived entries. It must ship with
focused migration, authorization, readiness, publishing, and rollback tests.

## Version and rollout guard

V2 mutation, review, approval, and publication services reject any plan whose
workflow version is not V2. V3 is disabled by default in configuration and no
code path creates or activates a V3 plan in this change.

The migration creates empty V3 tables and adds `workflow_version` with a
database default of `2`. It does not update existing rows, including any plan
whose external staging identifier is `30001`.

## Rollback

Rollback first refuses to continue if any non-V2 plan or any V3 projection,
batch, or decision exists. When the foundation is still unused, rollback drops
V3 decisions, batches, and employee projections in dependency order, then
removes the workflow-version index and column. Because the foundation never
populates V3 tables or changes V2 data, this guarded rollback preserves V2
plans, review history, entries, Tasks, and notifications.
