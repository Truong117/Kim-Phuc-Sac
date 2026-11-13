# Facebook analytics access

An active member of the KPS organization assigned as `IntegrationEntry.responsible_user_id` can open Facebook analytics for that entry. Assignment does not require `reports.view` or `integrations.view`. Only enabled entries of kind `social` and platform `facebook` qualify. A member assigned multiple Pages sees all of those Pages.

The existing organization-wide access for integration managers and organization-scoped report viewers with integration access remains unchanged. Ordinary assignees cannot see other employees' Pages. The Page list, overview, individual posts, milestones, period review, monthly reports and analytics mutations all use the same server-side entry scope. Changing a URL or sending a foreign entry ID returns 404.

Navigation comes from `/api/navigation`. An assignee without other office permissions receives an internal workspace whose landing page is `/social-analytics/facebook`; this does not grant dashboard, integration/token management or work-report access. Report links inside analytics retain the actor's existing report permissions and data scope. Assignment grants the same channel analytics capabilities as existing assignees, not a separate read-only role.

Changing/removing the assignment, disabling the channel or deactivating membership revokes access on subsequent API requests. The analytics Page list refreshes on focus/visibility; navigation and workspace capabilities reload with the application. Reload after a new assignment to show its menu immediately.

No migration, permission seeder, new environment variable or Facebook token change is needed. Existing `SOCIAL_METRICS_ENABLED` and `FACEBOOK_ANALYTICS_ENABLED` gates still apply. This change covers Facebook only.
