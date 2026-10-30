# Weekly Planning V3 employee lifecycle

## Phase 2 boundary

This phase adds a read-only employee workflow domain on top of the V3 schema
foundation. V3 remains disabled by default. No route creates V3 plans, no V3
transition is persisted, and no Task or notification is created by these
services.

## Lifecycle calculation

`WeeklyPlanEmployeeLifecycleService` validates and calculates a transition
result without saving it. The caller supplies the expected state and content
versions. Conflicts use stable reason codes so a later batch service can map
stale requests and invalid transitions consistently.

The allowed graph is:

- Assistant accept: `AWAITING_ASSISTANT` to `STAGED_FOR_OWNER`.
- Assistant request changes: `AWAITING_ASSISTANT` to `HR_CORRECTION`.
- OWNER accept: `STAGED_FOR_OWNER` to `PUBLISHED`.
- OWNER approve zero work: `STAGED_FOR_OWNER` to `NO_WORK_APPROVED`.
- OWNER request changes: `STAGED_FOR_OWNER` to `HR_CORRECTION`.
- HR resubmit: `HR_CORRECTION` to `AWAITING_ASSISTANT`.

HR resubmission is a distinct action and always returns through Assistant.
`correction_origin_stage` records the reviewer stage that requested a
correction and remains available after HR resubmission. Terminal states cannot
transition.

## Read contract

Only a full-detail read of a `workflow_version = 3` plan receives these
additive fields:

- `workflow_version`
- `employee_workflows`
- `publication_summary`

Each employee workflow includes assignee identity, state and content versions,
correction origin, active entry/item counts, publication metadata, viewer
context, and fail-closed action flags. Workflow rows are the membership source,
so zero-work employees remain visible. Entries and items are grouped from
eager-loaded relations rather than queried per employee.

The aggregate summary is derived on every read. It is never persisted, and an
empty workflow collection is `NOT_STARTED` rather than fully published.

## Authorization and integrity

Organization visibility and permissions come from the existing Weekly
Planning scope service. Viewer context is permission-based, never role-name
based. Every workflow assignee must have an active default membership in the
plan organization. Invalid projections fail with a generic conflict response
without exposing the unrelated employee identity.

The contextual modes are `ASSISTANT_REVIEW`, `OWNER_REVIEW`, `HR_CORRECTION`,
or `OBSERVER`. They describe where a viewer would participate in a future V3
workflow; they do not authorize execution. Until mutation phases ship,
`can_accept`, `can_request_changes`, `can_correct`, `can_resubmit`, and
`can_publish` are always false.

V2 resources and list resources do not receive the V3 detail fields. Existing
V2 mutation guards remain authoritative, and the existing import endpoints
continue to create version 2 plans.
