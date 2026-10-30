# Weekly Planning V3 HR inline corrections

## Mutation boundary

V3 corrections use dedicated routes and never call the V2 review-round or
whole-plan resubmission paths. The plan remains `IN_REVIEW`; employee workflow
rows in `HR_CORRECTION` are the authority for mutation eligibility.

Every entry mutation locks the plan, employee workflow, employee entries, and
child items in that order. It verifies a fresh organization-scoped permission
and the expected employee `content_version`. A successful add, update, or
delete increments only that employee's content version once and does not alter
the state version.

## Deletion retention policy

V3 does not enable global soft deletion. An HR correction entry can be
hard-deleted only when it has no `weekly_plan_comments` and no published Task.
Entries with either protected dependency are rejected with a stable conflict
code and a user-facing instruction to update rather than delete.

Confirmed V3 review snapshots are immutable JSON and do not reference entry
rows by a foreign key, so they survive a supported hard delete. For an
unprotected entry, child items, source-passage pivots, and suggestions are
content-owned dependents and follow their existing cascade policy. The
employee workflow row remains, so deleting the final entry produces an
explicit zero-work group that can still be resubmitted and reviewed.

## Durable correction batches

`weekly_plan_v3_correction_batches` is an immutable HR-specific ledger. It is
separate from Assistant/OWNER review batches and stores the request ID,
canonical payload hash, submitter identity snapshot, confirmation time, and
logical transition result.

The unique `(weekly_plan_id, request_id)` key is the concurrency boundary.
Exact retries return the stored logical result; reusing a key for a different
payload returns a conflict. Selected workflows are locked by assignee ID and
transition atomically through `HR_RESUBMIT` with a null review stage. A failed
employee rolls back the complete selected batch.

`correction_origin_stage` is preserved when transitioning to
`AWAITING_ASSISTANT`, including OWNER-origin corrections. No Task or
notification is created by correction mutations or resubmission.
