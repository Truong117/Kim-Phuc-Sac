# Work Report schedule rollout

The configured KPS reporting schedule is:

- Business timezone: `Asia/Ho_Chi_Minh`.
- Working days: Monday through Saturday.
- Weekly non-working day: Sunday.
- Cutoff for newly created reports: `23:00` local time (`16:00 UTC`).

## Persisted deadlines

`daily_reports.locked_at` is persisted when a report is created and remains authoritative for that report. Changing `REPORT_CUTOFF_TIME` does not rewrite historical rows. This prevents an already locked report from being silently reopened and preserves the deadline that applied when it was submitted.

Consequently, reports created under the former 18:00 rule keep their stored 18:00 deadline. Their detail and edit state continue to use that persisted value. Reports created after the new configuration takes effect receive a 23:00 deadline. A rollout during a working day can therefore produce mixed deadlines for that one date; the safest operational rollout is before the first report of a new working day.

No database migration or backfill is included. If KPS later requires a one-time deadline conversion, it needs a separately approved data plan that defines the exact report/date population and whether reopening previously locked reports is acceptable.

## Environment configuration

Set both localhost and Render backend environments to:

```dotenv
REPORT_TIMEZONE=Asia/Ho_Chi_Minh
REPORT_CUTOFF_TIME=23:00
```

After changing Render environment variables, redeploy/restart the backend so Laravel reloads configuration. If configuration is cached in an operating environment, clear and rebuild that cache through the normal deployment process. Do not run migrations or seeders for this schedule-only change.
