# Weekly Work Planning V1 API

Weekly Work Planning prepares next week's Monday–Saturday work in a private draft, separates per-employee review from final approval, and creates Task Management records only during publication.

## Authorization

All endpoints require `auth:sanctum` and an active default KPS membership. Weekly Planning permissions require an `ORGANIZATION` or `ALL` data scope; narrower and unsupported scopes fail closed.

| Permission | Default grant prepared by the isolated seeder | Purpose |
| --- | --- | --- |
| `weekly_plans.view` | HR_MANAGER / OWNER / ADMIN | List and read plans in the active organization; ADMIN is read-only |
| `weekly_plans.manage_draft` | HR_MANAGER | Import, reparse, add, update, delete and decide suggestions |
| `weekly_plans.submit` | HR_MANAGER | Submit or resubmit a valid plan |
| `weekly_plans.review` | OWNER | Review an employee and edit allowed entry fields during review |
| `weekly_plans.approve` | OWNER | Final approval after every employee is accepted |
| `weekly_plans.publish` | OWNER | Atomically publish approved entries as Tasks |

`WeeklyPlanningPermissionSeeder` is additive and idempotent. ADMIN receives only `weekly_plans.view` with `ALL` scope; it does not receive import, edit, analyze, submit, review, approve or publish permissions. The seeder is intentionally not called automatically.

The frontend keeps `weekly-plans` as an opaque navigation/route-authorization key, but does not render it as a separate sidebar item. Authorized users open Weekly Planning from the `Kế hoạch tuần` tab inside the Công việc module. Import and all detail actions are rendered only from backend action flags.

## State machine

```text
DRAFT -> SUBMITTED -> IN_REVIEW -> APPROVED -> PUBLISHED
                         |
                         -> CHANGES_REQUESTED -> SUBMITTED
```

Employee review status is `PENDING`, `CHANGES_REQUESTED`, or `ACCEPTED`. Editing an employee's entries resets that employee's review to `PENDING`. The OWNER UI uses one final `approve-and-publish` action; the legacy `approve` and `publish` endpoints remain available for compatibility.

## Endpoints

| Method | Path | Result |
| --- | --- | --- |
| GET | `/api/weekly-plans` | Paginated organization list |
| GET | `/api/weekly-plans/references` | Next business week, active organization users, opaque import action |
| GET | `/api/weekly-plans/template` | Generated `.xlsx` template |
| POST | `/api/weekly-plans/import` | Multipart import of one `.xlsx` or structured-table `.docx` |
| GET | `/api/weekly-plans/{id}` | Entries, reviews, suggestions, comments, audit events and opaque actions |
| POST | `/api/weekly-plans/{id}/reparse` | Reparse the private source with `column_mapping` |
| POST | `/api/weekly-plans/{id}/entries` | Add a draft entry |
| PATCH | `/api/weekly-plans/{id}/entries/{entryId}` | Edit a draft or an OWNER-reviewable entry |
| DELETE | `/api/weekly-plans/{id}/entries/{entryId}` | Remove a draft entry |
| POST | `/api/weekly-plans/{id}/submit` | Submit/resubmit a valid draft |
| POST | `/api/weekly-plans/{id}/analyze` | Run the rule-based V1 analyzer explicitly on normalized draft entries |
| POST | `/api/weekly-plans/{id}/employee-reviews` | Accept or request changes for one employee |
| POST | `/api/weekly-plans/{id}/comments` | Append a plan, employee or entry comment for a draft manager or reviewer; view-only ADMIN is rejected |
| PATCH | `/api/weekly-plans/{id}/suggestions/{suggestionId}` | Accept or reject a rule-based suggestion record |
| POST | `/api/weekly-plans/{id}/approve` | Final approval; does not create Tasks |
| POST | `/api/weekly-plans/{id}/publish` | Atomically create Tasks and mark the plan published |
| POST | `/api/weekly-plans/{id}/approve-and-publish` | Atomically approve, create Tasks and publish after every employee is accepted |

Dates are `YYYY-MM-DD` in the API. The business timezone is `Asia/Ho_Chi_Minh`; the generated next-week period always covers Monday through Saturday.

### Import

`POST /api/weekly-plans/import` uses `multipart/form-data` with `file`. Optional mappings use `column_mapping[canonical_name]=Source header`. Supported canonical names are:

`employee_identifier`, `employee_name`, `department_name`, `title`, `expected_result`, `start_date`, `due_date`, `priority`, and `note`.

KPS currently has no persisted employee-code field. Automatic identity matching is therefore exact, case-insensitive email only. A code, name-only match, inactive user, or cross-organization user remains an invalid row for manual HR resolution; no fuzzy-name assignment occurs and no row is silently discarded.

The parser does not execute formulas. It rejects macro-enabled extensions, embedded VBA, external links, formulas, unsafe XML/DOCTYPE, path traversal, oversized archives, excessive compression ratios, excessive sheet counts, and excessive row counts.

### Explicit rule-based analysis

Import, reparse and entry edits do not run analysis automatically. HR explicitly calls `POST /api/weekly-plans/{id}/analyze` while the plan is editable. The analyzer reads normalized database entries only, never calls an external AI provider, and never changes entry content, assignee, dates, priority or workflow status.

Each analysis stores an `ANALYZED` audit event with a normalized-content fingerprint. Repeating analysis for unchanged content reuses the current suggestions and does not duplicate or reset them. A subsequent reparse/add/update/delete clears current suggestions and records `SUGGESTIONS_INVALIDATED`; HR must analyze the changed draft again.

### Entry payload

```json
{
  "assignee_id": 24,
  "title": "Chuẩn bị lịch nội dung tuần",
  "content": "Hoàn thiện nội dung theo kế hoạch đã duyệt",
  "expected_result": "Lịch nội dung hoàn chỉnh",
  "start_date": "2026-10-26",
  "due_date": "2026-10-31",
  "priority": "HIGH",
  "note": null
}
```

### Employee review payload

```json
{
  "assignee_id": 24,
  "status": "CHANGES_REQUESTED",
  "comment": "Cần làm rõ kết quả bàn giao."
}
```

### Comment payload

`entry_id` and `assignee_id` are optional. When present, both are validated against the scoped plan.

```json
{
  "content": "Ưu tiên hoàn tất trước thứ Sáu.",
  "assignee_id": 24,
  "entry_id": 83
}
```

## Publication consistency

The combined endpoint locks the plan row, requires every employee review to be `ACCEPTED`, checks every entry, then records approval and calls the existing `TaskService` in the same database transaction. It revalidates each assignee's current active organization membership and entry dates at execution time. Approval, Task creation, assignment notification, source linkage, publication and audit events all roll back together on failure. `tasks.weekly_plan_entry_id` is unique, while repeating the combined request for an already-published plan is idempotent.

## Private storage and deployment

Normalized rows are durable database records. The original file is stored through a configurable private Laravel Storage disk and its disk/path are never returned by the public resource.

- Local development/tests: `WEEKLY_PLAN_STORAGE_DISK=local`.
- Production: configure a private persistent object-storage disk (for example `s3`) plus provider credentials, bucket, endpoint, and path-style setting as applicable.
- Render's local filesystem is ephemeral and must not be used as the production source-file guarantee.
- The current Composer lock does not install the optional S3 Flysystem adapter. Add and validate `league/flysystem-aws-s3-v3` in a separately approved dependency/deployment step before selecting the `s3` disk.

Do not log provider secrets, expose a public source URL, or run the general authorization seeder merely to enable this module.

## Safe rollout

1. Back up and inspect the target TiDB schema.
2. Build a staging artifact with PHP `zip` enabled (the Dockerfile now installs it).
3. Install/configure the approved private object-storage adapter and verify private read/write/delete with a non-production bucket.
4. Review the two new Weekly Planning migrations and the Task source-link migration with `php artisan migrate --pretend` against a schema-compatible non-production database.
5. Apply migrations during a controlled window. Do not use `migrate:fresh`.
6. Run only `WeeklyPlanningPermissionSeeder` after verifying OWNER, ADMIN and HR_MANAGER role records. Do not run the general authorization seeder.
7. Smoke-test import, review, approval, publication, Task linkage and assignment notification with isolated test users.
8. Roll out the frontend only after backend routes, storage and permissions are ready.

The current Docker entry point runs `php artisan migrate --force` on startup. Do not deploy the image until this automatic migration behavior is explicitly accepted for the target environment or separated into a controlled release command.
