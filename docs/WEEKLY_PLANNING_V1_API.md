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
| POST | `/api/weekly-plans/{id}/source/extract` | Read ordered paragraphs, headings, lists and table rows from the current private DOCX |
| POST | `/api/weekly-plans/{id}/source/replace` | Replace a missing DOCX source on the same draft plan, then extract its text |
| PATCH | `/api/weekly-plans/{id}/source/group` | Assign one manual document-group label to a contiguous passage range |
| POST | `/api/weekly-plans/{id}/source/apply` | Atomically append HR-confirmed draft entries linked to selected source passages |
| POST | `/api/weekly-plans/{id}/source/ai-extract` | Scoped, throttled Structured Output preview from persisted passages; Google Docs accepts optional `source_passage_ids`; returns `409` while no approved provider is configured |
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

### DOCX document extraction

The existing DOCX source can also be read as an ordered, reviewable set of passages. V1 preserves headings, ordinary paragraphs, numbered/bulleted list items and table rows with stable source keys and safe structural metadata. OOXML section boundaries are exposed only as neutral `source_context` values such as `docx-section:1`; a section, heading or person-like text is never inferred to be an employee group. Employee auto-matching remains exact email only.

Only a draft manager can extract, replace a missing source, assign a manual group, select passages and apply entries. OWNER and ADMIN can inspect extracted passages and entry-to-source evidence through the normal scoped detail endpoint, but every extraction/write endpoint remains denied to them. The resource never exposes storage disk/path or provider credentials.

Manual grouping uses `PATCH /api/weekly-plans/{id}/source/group`:

```json
{
  "passage_ids": [31, 32, 33],
  "group_title": "Nhân viên A"
}
```

The IDs must form one contiguous range in the current source version. The persisted `source_group` contains a deterministic key, document order and passage-range evidence. Its `identity_verified` field is always `false`: a source label is not a KPS user identity and never assigns an employee automatically. Duplicate titles on separate ranges remain separate groups. Retrying an unchanged assignment is idempotent and does not duplicate the `SOURCE_GROUP_ASSIGNED` audit event.

The metadata contract is intentionally provider-neutral. A future approved Google Docs connector can request `includeTabsContent=true`, walk `tabs`/`childTabs`, and map `tabId`, `title` and `documentTab.body` into ordered source contexts with an explicit `GOOGLE_DOCS_TAB` origin. V1.2 does not implement Google OAuth, store provider credentials, or treat a tab title as a verified KPS account.

Applying passages requires a UUID `request_id`. Repeating the same request and payload is idempotent; reusing the UUID with different content returns `409`. Once passages have manual group metadata, all passages selected for one entry must belong to the same group; entirely ungrouped legacy passages retain their previous behavior. HR must still provide an independently validated `assignee_id`. The complete batch is validated before any entry is appended, and the batch, passage links, reviews, suggestion invalidation and audit event share one transaction. Existing entries are never overwritten or deleted.

```json
{
  "request_id": "22fcd23e-4fdc-46b7-a8c6-a71b9f4e22b1",
  "entries": [
    {
      "source_passage_ids": [31, 32],
      "assignee_id": 24,
      "title": "Chuẩn bị lịch nội dung tuần",
      "content": "Nội dung HR đã kiểm tra từ tài liệu nguồn",
      "expected_result": "Lịch nội dung hoàn chỉnh",
      "start_date": "2026-10-26",
      "due_date": "2026-10-31",
      "priority": "HIGH",
      "note": null
    }
  ]
}
```

Document extraction is bounded by `WEEKLY_PLAN_MAX_SOURCE_PASSAGES`, `WEEKLY_PLAN_MAX_SOURCE_CHARACTERS`, `WEEKLY_PLAN_MAX_SOURCE_PASSAGE_CHARACTERS`, `WEEKLY_PLAN_MAX_SOURCE_PASSAGES_PER_ENTRY` and `WEEKLY_PLAN_MAX_SOURCE_ENTRIES_PER_REQUEST`. AI output is also bounded by `WEEKLY_PLAN_AI_MAX_TASKS_PER_RESPONSE`; the effective task limit is the smaller of that value and the one-request apply limit. Oversized output is rejected without truncation. The structured task extractor remains behind `WeeklyPlanTaskExtractor`: `none` is the safe default and `openai` uses the shared budgeted AI Gateway only after its key, model, prices and positive budget are configured. It reads persisted passages, returns proposals with source keys/evidence/provenance, and never creates entries or Tasks. DOCX retains manual assignee review. Google Docs may optionally send `{ "source_passage_ids": [31, 32] }` to analyze one explicit current-source selection; omitting the field keeps the all-eligible-verified-tabs behavior. The server rejects unknown, stale, unresolved and `NOT_APPLICABLE` passages before the provider call. One bounded provider request receives only the selected `VERIFIED` passages and no employee identity, while trusted assignees are injected server-side. Each returned task must still reference exactly one tab, and the assignment is revalidated in the existing idempotent atomic `source/apply` flow. See `docs/AI_GATEWAY_V1.md`.

Google Docs AI review uses a compact employee-grouped UI and does not expose provenance, raw source keys or source excerpts to normal users; those values remain in the server response and continue to drive source/mapping validation and audit-safe Apply All. Optional `detail_suggestions` use seven bounded fields (`channel`, `account`, `frequency`, `format`, `content_direction`, `objective`, `notes`). Every non-null field has a `value` plus a `basis` of `SOURCE_DERIVED` or `AI_SUGGESTION`; the latter must never be represented as a company fact.

For this review only, the two UI priority choices map to existing enums: `Không` → `MEDIUM`, `Cao` → `HIGH`. An AI `HIGH` or `URGENT` hint initializes `Cao`; every other or missing hint initializes `Không`. HR may change this before applying. Date inputs are intentionally absent: for Google Docs the server normalizes provider dates to `weekly_plans.week_start`/`weekly_plans.week_end` before returning the preview, and each included suggestion is submitted with the same deterministic dates. The existing request and domain validators still require and validate both dates inside the Monday–Saturday plan period. DOCX, manual-entry and other Task workflows retain their existing date and priority behavior.

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

Normalized rows and DOCX source passages are durable database records. Import parses the uploaded file immediately; the original file is stored through a configurable private Laravel Storage disk and its disk/path are never returned by the public resource.

- Local development/tests: `WEEKLY_PLAN_STORAGE_DISK=local`.
- Staging may temporarily use private local ephemeral storage only with `APP_ENV=staging` and `KPS_STAGING_EPHEMERAL_FILES=true`. This exception is rejected in production.
- Production: configure a private persistent object-storage disk (for example `s3`) plus provider credentials, bucket, endpoint, and path-style setting as applicable.
- Render's local filesystem is ephemeral. If a staging restart removes the source, the API exposes only `source_file.availability`, keeps saved plan data intact and asks an authorized HR user to upload the same file type again instead of returning a 500.
- The Composer lock includes `league/flysystem-aws-s3-v3`. Before selecting the `s3` disk, configure a private non-production bucket first and validate provider-specific endpoint, region, credentials/IAM and prefix settings.

Dependency after import:

- Works without the original file: view/edit saved entries, rule-based analysis, review/approval/publication, view/group/apply persisted passages, and AI extraction that consumes those persisted passages.
- Still needs the original file: reparse with a new column mapping and first-time extraction for legacy DOCX plans imported before immediate passage persistence. Re-upload restores only the source object/metadata; it does not silently rewrite existing entries.

Do not log provider secrets, expose a public source URL, or run the general authorization seeder merely to enable this module.

## Safe rollout

1. Back up and inspect the target TiDB schema.
2. Build a staging artifact with PHP `zip` enabled (the Dockerfile now installs it).
3. Install/configure the approved private object-storage adapter and verify private read/write/delete with a non-production bucket.
4. Review the Weekly Planning migrations, Task source-link migration and `2026_10_22_020000_create_weekly_plan_document_extraction_tables.php` with `php artisan migrate --pretend` against a schema-compatible non-production database.
5. Apply migrations during a controlled window. Do not use `migrate:fresh`.
6. Run only `WeeklyPlanningPermissionSeeder` after verifying OWNER, ADMIN and HR_MANAGER role records. Do not run the general authorization seeder.
7. Smoke-test import, review, approval, publication, Task linkage and assignment notification with isolated test users.
8. Roll out the frontend only after backend routes, storage and permissions are ready.

The Docker entry point does not run migrations. Keep schema changes in a separately approved Render pre-deploy/one-off operation and follow [`ENVIRONMENT_DEPLOYMENT_SAFETY.md`](ENVIRONMENT_DEPLOYMENT_SAFETY.md); never add migrations back to container startup.
# Durable Google Docs source (V1)

`POST /api/weekly-plans/google-docs/import` xác nhận snapshot đã xem trước và tạo Weekly Plan draft mới. Payload chỉ gồm `document_url`, `expected_snapshot_sha256`, `request_id`; organization, Google document ID, passages và assignee không được nhận từ client. Chi tiết authorization, persistence, idempotency và migration xem tại [GOOGLE_DOCS_DURABLE_SOURCE_V1.md](./GOOGLE_DOCS_DURABLE_SOURCE_V1.md).

Replay cùng organization/request/payload chỉ trả plan durable đã có khi active membership và `weekly_plans.manage_draft` vẫn hợp lệ; folder bị disable/replace không làm replay gọi Google hoặc ghi thêm dữ liệu. Với Google Docs, `can_ai_extract_source=true` chỉ khi mọi tab đã là `VERIFIED` hoặc `NOT_APPLICABLE`, có ít nhất một tab `VERIFIED`, và mọi nhân viên mapping còn hợp lệ. Direct AI extraction fail-closed trước gateway nếu mapping chưa hoàn tất. AI extraction của DOCX giữ nguyên.
