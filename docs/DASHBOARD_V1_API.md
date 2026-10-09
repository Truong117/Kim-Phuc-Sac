# Dashboard V1 API

Dashboard V1 is presented as **Tổng quan** in the KPS frontend. The backend remains the authority for dashboard mode, business scope, report participation, and cutoff state.

## Endpoint

```http
GET /api/dashboard
```

Required middleware:

- `auth:sanctum`
- active/default KPS membership
- `dashboard.view`

The endpoint accepts no scope, department, location, role, or period parameters. Dashboard V1 represents the server-authoritative KPS business date only.

## Safe modes

The response exposes one frontend-safe mode:

- `organization`
- `department`
- `location`
- `personal`

It never exposes role codes, permission codes, `DataScope`, authorization pivots, or membership internals.

The resolver evaluates the complete set of effective `dashboard.view` scopes rather than using the generic scope rank:

- `ALL` or `ORGANIZATION` produces organization mode.
- `DEPARTMENT` with optional `SELF`/`OWN` produces department mode.
- `LOCATION` with optional `SELF`/`OWN` produces location mode.
- only `SELF`/`OWN` produces personal mode.
- `TEAM`, a missing management context, conflicting department/location scopes, and incompatible states fail closed with `403`.

## Response shape

```json
{
  "data": {
    "mode": "department",
    "business_date": "2026-10-09",
    "cutoff_at": "2026-10-09T18:00:00+07:00",
    "context": { "id": 4, "name": "Marketing" },
    "reporting": {
      "expected": 5,
      "submitted": 4,
      "missing": 1
    },
    "work": {
      "total": 21,
      "completed": 16,
      "in_progress": 4,
      "blocked": 1
    },
    "self_report": {
      "state": "submitted_editable",
      "report_id": 40,
      "report_date": "2026-10-09",
      "cutoff_at": "2026-10-09T18:00:00+07:00",
      "overall_status": "IN_PROGRESS",
      "work": {
        "total": 4,
        "completed": 3,
        "in_progress": 1,
        "blocked": 0
      },
      "items": [],
      "unread_comment_count": 0,
      "actions": {
        "can_create": false,
        "can_edit": true,
        "can_view": true
      }
    },
    "group_breakdown": [],
    "people_progress": [],
    "attention_items": [],
    "recent_reports": []
  }
}
```

`reporting` is `null` in personal mode. `self_report` is `null` when the authenticated membership is exempt from daily reporting, including OWNER memberships with additional roles.

Self-report states are opaque UI states:

- `not_submitted_open`
- `submitted_editable`
- `locked`
- `not_submitted_closed`

## Current membership and report snapshots

Current active/default KPS memberships determine:

- expected reporters;
- submitted reporters;
- missing reporters;
- the people-progress population.

`ReportParticipationService` excludes every membership containing OWNER. ADMIN and the other active KPS business roles remain report participants.

Persisted daily-report snapshots determine:

- work totals;
- department/location work totals;
- report and work-item visibility;
- attention items;
- recent reports.

For a same-day transfer, the new department can count the employee as submitted, but it receives no report ID, work counts, or content when the report snapshot belongs to the previous department.

## Mode-specific sections

- Organization: reporting/work summaries, optional self report, department breakdown, blocked attention items, recent reports.
- Department/location: reporting/work summaries, self report, people progress, blocked attention items, recent reports.
- Personal: self report and today's ordered work items. Recent report-comment feedback reuses the existing notification API on the frontend and is not duplicated in this endpoint.

Attention items and recent reports are capped at five. Recent reports may span multiple business dates. The GET endpoint never mutates notification state.
