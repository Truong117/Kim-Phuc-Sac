# KPS Internal System — Project Context

Last repository audit: 2026-10-22 (Asia/Saigon)

This document is the handoff baseline for future work. It distinguishes:

- **Verified**: confirmed in the current repository.
- **Planned / Business decision**: agreed direction that is not necessarily implemented.
- **Known gap**: the repository does not yet match the intended direction.

The repository remains the source of truth for implementation status.

## 1. Project purpose

**Planned / Business decision**

KPS Internal System is the internal operations platform for Kim Phục Sắc. It is broader than a CRM and is expected to cover internal work, work reports, customer management, spa/customer-care operations, sales, products/orders, AI assistance, employees, permissions, and external integrations over time.

The current priority after the Phase 1 reporting prototype is the **Sales / CRM** area. Do not begin deep CRM implementation until its business workflow has been confirmed.

## 2. Repository snapshot

**Verified**

- Repository: `https://github.com/Truong117/Kim-Phuc-Sac.git`
- Current branch at the Task Management V1 implementation baseline: `main`
- AI Daily Report V1 builds on the completed Authentication, Authorization, User Management, Work Report, Dashboard, HR User Management, and Profile/Account baseline.
- No TailAdmin upstream remote is configured; only `origin` exists, and the cleanup did not alter remotes.
- `docs/KPS_PROJECT_CONTEXT.md` was created during the project handoff and remains the main technical/business context document.
- `README.md` is now a concise KPS setup guide and retains the required TailAdmin attribution/license reference.

## 3. Verified frontend stack

The package is named `kps-internal-system`, version `2.4.0`, and is private.

| Area | Current repository |
| --- | --- |
| UI runtime | React `^19.2.7`, React DOM `^19.2.7` |
| Language | TypeScript `^5.8`, strict compiler settings |
| Build | Vite `^8.2.2` with `@vitejs/plugin-react` |
| Styling | Tailwind CSS `^4.3.3`, configured in `src/index.css` |
| Routing | `react-router` `^8.3.1`, `BrowserRouter` |
| Internationalization | i18next `^26.4.0`, react-i18next `^17.0.12` |
| Metadata | `react-helmet-async` |
| Package manager | npm with `package-lock.json` |

Unused template libraries for FullCalendar, Swiper, flatpickr, vector/maps, simplebar, React DnD, and ApexCharts were removed after their remaining demo consumers were retired.

The audit ran successfully on Node `v22.23.3` and npm `10.9.9`.

Available scripts:

- `npm run dev` — Vite development server
- `npm run build` — `tsc -b && vite build`
- `npm run lint` — ESLint
- `npm test` — lightweight Node contract tests
- `npm run preview` — Vite preview

### Verified backend foundation

The Laravel API foundation lives in `backend/` and is intentionally independent from the React/Vite toolchain at the repository root.

| Area | Current repository |
| --- | --- |
| Runtime | PHP `8.2.12` |
| Framework | Laravel Framework `12.69.3` |
| Dependency manager | Composer `2.10.3` with `backend/composer.lock` |
| Authentication | Laravel Sanctum `4.3.3`, first-party SPA session/cookie flow |
| API routing | `backend/routes/api.php`, registered in `backend/bootstrap/app.php` |
| Public endpoint | `GET /api/health` |
| Application/session database | MySQL `8.4.11` locally; SQLite in-memory for tests |
| Automated tests | PHPUnit; SQLite in-memory test configuration |

Authentication V1 reuses the default `users` and `sessions` tables. Profile V1 reuses the existing user identity fields and does not add profile-media schema. Authorization V1 adds organizations, departments, locations, organization memberships, roles, permissions, and the two role-assignment pivot tables. User Management V1 uses that existing schema to manage internal accounts, KPS memberships, one primary role, department/location references, and membership access status. Work Report V1 adds daily reports/items, append-only comments, and normalized user notifications. AI Daily Report V1 adds only `ai_daily_report_runs`; it reads scoped Work Report data without mutating it. Task Management V1 adds independent task records, scoped assignment/management, derived overdue status, and assignment notifications. No CRM persistence has been implemented.

## 4. Current application architecture

### Verified

- The application is a client-side SPA.
- `src/main.tsx` installs the theme, language, Helmet, authentication, and authorization providers.
- `src/App.tsx` owns all routes.
- `src/layout/AppLayout.tsx` provides the standard sidebar/header shell and installs `SidebarProvider` plus the authenticated `NotificationProvider` for dashboard routes.
- KPS domain UI is currently split into focused files under:
  - `src/pages/Dashboard/Overview.tsx`
  - `src/pages/Reports/`
  - `src/pages/Tasks/`
  - `src/pages/AI/AiDailyInsights.tsx`
  - `src/components/dashboard/`
  - `src/components/reports/`
  - `src/components/tasks/`
  - `src/types/`
  - `src/services/`
- `src/services/apiClient.ts` owns the shared credentialed native-fetch behavior. Focused auth, navigation, user-management, report, task, notification, and AI Daily Report services use it.
- The Laravel API under `backend/` implements Authentication V1, Authorization V1, User Management V1, Work Report V1, Dashboard V1, and mock-first AI Daily Report V1. The overview, daily report, report history/detail/comments, recent reports, header notifications, and AI insights page consume real backend APIs.
- Dashboard prototype KPI/category/AI mocks and report-domain mocks have been removed.

### Planned / Business decision

The long-term boundary is:

```text
React/Vite frontend
  -> service layer
  -> Laravel REST API
  -> MySQL
  -> external integrations (AI, Pancake, others)
```

The frontend must never connect directly to the database. AI, Pancake, and other secrets must remain on the backend and must not be exposed through `VITE_*` variables. Authentication and business services use `VITE_API_BASE_URL`.

## 5. Repository structure relevant to KPS

```text
backend/
├── app/                           # Laravel application code
├── bootstrap/app.php              # framework bootstrap and route registration
├── config/cors.php                # credentialed frontend origin from FRONTEND_URL
├── config/sanctum.php             # stateful SPA authentication configuration
├── config/reports.php             # KPS business timezone and daily cutoff
├── config/kps_ai.php              # AI driver and bounded context limits
├── database/migrations/           # framework, Authorization, Work Report, and AI run schema
├── database/seeders/              # idempotent RBAC and KPS structure master data
├── routes/api.php                 # health, auth, navigation, user-management, report, and notification routes
└── tests/                          # PHPUnit tests

src/
├── App.tsx                         # all route registration
├── main.tsx                        # root providers and global CSS imports
├── index.css                       # Tailwind v4 theme/utilities/overrides
├── layout/
│   ├── AppLayout.tsx
│   ├── AppSidebar.tsx
│   └── AppHeader.tsx
├── context/
│   ├── AuthContext.ts
│   ├── AuthProvider.tsx
│   ├── AuthorizationContext.ts
│   ├── AuthorizationProvider.tsx
│   ├── NotificationContext.ts
│   ├── NotificationProvider.tsx
│   ├── ThemeContext.tsx
│   ├── SidebarContext.tsx
│   └── LanguageContext.tsx
├── pages/
│   ├── Auth/Login.tsx
│   ├── Dashboard/Overview.tsx
│   ├── AI/AiDailyInsights.tsx      # scoped mock-first AI Daily Report UI
│   ├── AI/AiExecutiveDashboard.tsx # OWNER/ADMIN 7/30-day operational analytics
│   ├── Employees/                  # user list/create/edit pages
│   ├── Profile/Profile.tsx          # authenticated self-service profile
│   ├── Account/Account.tsx          # authenticated account security settings
│   ├── Reports/NewReport.tsx
│   ├── Reports/ReportHistory.tsx
│   ├── Reports/ReportDetail.tsx
│   ├── OtherPage/ModulePlaceholder.tsx
│   └── OtherPage/NotFound.tsx
├── components/
│   ├── auth/
│   ├── dashboard/
│   ├── ai/                         # AI calendar and Executive Dashboard sections
│   ├── employees/                  # User Management V1 components
│   ├── profile/                    # personal and work information
│   ├── account/                    # read-only account summary and password change
│   ├── reports/
│   ├── common/
│   ├── form/
│   └── ui/
├── services/dashboardService.ts    # scoped Dashboard V1 request
├── services/apiClient.ts           # shared credentialed fetch client
├── services/authService.ts         # Sanctum CSRF/session requests
├── services/navigationService.ts   # allowed navigation-key request
├── services/userService.ts         # User Management API requests
├── services/reportService.ts       # Work Report API requests
├── services/notificationService.ts # notification API requests
├── services/aiDailyReportService.ts # AI summary/run/history requests
├── services/aiExecutiveDashboardService.ts # Executive Dashboard analytics request
├── services/accountService.ts      # self-service profile/password requests
├── hooks/useAuth.ts                # authenticated-user context hook
├── hooks/useAuthorization.ts       # navigation-key UX authorization hook
├── hooks/useNotifications.ts       # authenticated notification context hook
├── types/
│   ├── auth.ts
│   ├── dashboard.ts
│   ├── reports.ts
│   ├── notifications.ts
│   ├── aiDailyReport.ts
│   ├── aiExecutiveDashboard.ts
│   ├── userManagement.ts
│   └── user.ts
├── i18n/
│   ├── index.ts
│   └── languages.ts
└── locales/vi/common.json
```

The Phase 1 cleanup removed the unused TailAdmin demo areas for auth, calendar, profile, forms, tables, UI elements, ecommerce, and standalone chart demos. Reusable primitives still consumed by KPS pages were deliberately retained.

## 6. Current routes

### Public frontend routes

| Route | Current component | Status |
| --- | --- | --- |
| `/login` | `Auth/Login` | Authentication V1 implemented |

### Protected routes inside `AppLayout`

| Route | Current component | Status |
| --- | --- | --- |
| `/` | `Dashboard/Overview` | Dashboard V1 implemented; canonical Tổng quan route |
| `/dashboard` | redirect to `/` | Backward-compatible redirect |
| `/reports/new` | `Reports/NewReport` | Work Report V1 create/edit/locked/exempt states implemented |
| `/reports` | `Reports/ReportHistory` | Work Report V1 scoped history implemented |
| `/reports/:id` | `Reports/ReportDetail` | Work Report V1 detail/comments implemented |
| `/tasks` | `Tasks/TaskList` | Task Management V1 scoped list/filter/summary implemented |
| `/tasks/new` | `Tasks/TaskCreate` | Task Management V1 manager create/assign flow implemented |
| `/tasks/:id` | `Tasks/TaskDetail` | Scoped detail, manager edit, and assignee status update implemented |
| `/customers` | `ModulePlaceholder` | Placeholder only |
| `/customers/:id` | `ModulePlaceholder` | Placeholder only |
| `/products` | `ModulePlaceholder` | Placeholder only |
| `/orders` | `ModulePlaceholder` | Placeholder only |
| `/ai/dashboard` | `AI/AiExecutiveDashboard` | Phân tích điều hành V1 implemented |
| `/ai/insights` | `AI/AiDailyInsights` | AI Daily Report V1 implemented |
| `/ai/assistant` | `ModulePlaceholder` | Placeholder only |
| `/employees` | `Employees/EmployeeList` | User Management V1 list implemented |
| `/employees/new` | `Employees/EmployeeCreate` | User Management V1 create form implemented |
| `/employees/:id` | `Employees/EmployeeEdit` | Profile, role, and access-status management implemented |
| `/profile` | `Profile/Profile` | Profile V1 self-service name and read-only work context |
| `/account` | `Account/Account` | Read-only login summary and self-service password change |
| `/integrations` | `ModulePlaceholder` | Placeholder only |
| `/settings` | `ModulePlaceholder` | Placeholder only |

### Standalone routes

| Route | Status |
| --- | --- |
| `*` | KPS-branded 404 page |

There are no retained public TailAdmin demo routes. Authentication V1 adds only the KPS login route; registration and account-recovery routes remain absent.

All `AppLayout` routes are wrapped by `ProtectedRoute` for authentication. Most module routes also use a navigation-key guard for authorization UX. `/profile` and `/account` are authenticated self-service routes and deliberately require no role/navigation permission. `/` is the canonical Tổng quan route and `/dashboard` redirects to it. `/reports/new` deliberately relies on the safe `GET /api/reports/today` state instead of the navigation guard so an exempt membership following a direct URL sees the non-participant state rather than a false 403 page; its sidebar item is still controlled by backend navigation output. The wildcard KPS 404 route remains outside the protected layout.

### Backend authentication routes

| Route | Protection | Purpose |
| --- | --- | --- |
| `GET /sanctum/csrf-cookie` | Public | Initialize Sanctum CSRF cookie |
| `POST /api/auth/login` | Public, CSRF-protected | Validate credentials and create the server session |
| `GET /api/auth/me` | `auth:sanctum`, active KPS membership | Return user identity plus safe organization/department/location/primary-role display context |
| `POST /api/auth/logout` | `auth:sanctum`, CSRF-protected | Invalidate the current session |
| `GET /api/navigation` | `auth:sanctum`, active KPS membership | Return only the allowed frontend navigation keys |

Login now requires an active, default KPS membership in the active KPS organization in addition to valid credentials. Protected application requests re-check that membership; a session whose membership becomes inactive receives JSON `401`, is logged out, and the frontend returns to `/login`. Logout intentionally remains available with `auth:sanctum` alone.

### Backend Profile V1 routes

All account routes require `auth:sanctum` and an active KPS membership. They always operate on the authenticated user and accept no target user ID.

| Route | Purpose |
| --- | --- |
| `PATCH /api/account/profile` | Update only the authenticated user's display name |
| `PATCH /api/account/password` | Verify the current password and replace it with an 8–72 character password |

The shared authenticated-user payload used by login, `/api/auth/me`, and account mutations exposes only safe identity and organization display fields. Ảnh đại diện được hoãn sang phiên bản sau; no avatar storage provider or profile-media schema is currently required.

### Backend User Management routes

All routes below require `auth:sanctum`, an active KPS membership, and the indicated backend permission. Account-management permissions accept only `ORGANIZATION` or `ALL` scope.

| Route | Permission | Purpose |
| --- | --- | --- |
| `GET /api/users` | `users.view` | Paginated search/filter list of KPS accounts |
| `POST /api/users` | `users.create` and `users.assign_role` | Atomically create user, KPS membership, and primary role |
| `GET /api/users/{id}` | `users.view` | Safe KPS account detail |
| `PATCH /api/users/{id}` | `users.update` | Update name, email, department, and location |
| `PATCH /api/users/{id}/role` | `users.assign_role` | Replace all assignments with one primary role |
| `PATCH /api/users/{id}/status` | `users.disable` | Activate/deactivate KPS membership |
| `GET /api/reference/{roles,departments,locations}` | `users.view` | Safe `{id, name}` form/filter references |

### Backend Dashboard route

`GET /api/dashboard` requires `auth:sanctum`, an active/default KPS membership, and `dashboard.view`. It returns one safe server-resolved mode (`organization`, `department`, `location`, or `personal`) plus today's scoped reporting/work aggregates, opaque self-report state, and the mode-appropriate sections. It accepts no frontend scope or organization-unit authorization parameters. Full semantics and examples are documented in `docs/DASHBOARD_V1_API.md`.

### Backend Work Report routes

All routes require `auth:sanctum` and an active KPS membership. Report list/detail/comment access is additionally constrained by backend report scope. Full request/response examples are documented in `docs/WORK_REPORT_V1_API.md`.

| Route | Purpose |
| --- | --- |
| `GET /api/reports/today` | Server-authoritative business date, working-day/cutoff state, report-participation state, current report, and create action state |
| `GET /api/reports/references` | Scope-safe employee/department/location filter options |
| `GET /api/reports` | Scoped paginated report history with derived status/counts |
| `POST /api/reports` | Create exactly one report for the authenticated author/business date |
| `GET /api/reports/{id}` | Scoped report detail with ordered items/comments/action state |
| `PATCH /api/reports/{id}` | Author-only atomic item replacement before the persisted deadline |
| `POST /api/reports/{id}/comments` | Scoped manager comment after deadline plus author notification |
| `PATCH /api/reports/{id}/notifications/read` | Mark the author's report-comment notifications read |
| `GET /api/notifications` | Safe recipient-only notification list and unread count |
| `PATCH /api/notifications/read-all` | Idempotently mark all recipient notifications read |
| `PATCH /api/notifications/{id}/read` | Idempotently mark one recipient notification read |

Work Report V1 has a business-level participation rule: an active KPS membership containing `OWNER` is exempt from submitting or editing daily reports, even when another assigned role grants `reports.create`. OWNER retains organization-wide report viewing and may comment on other employees' locked reports. `ADMIN` is not exempt and remains a normal report participant. The backend exposes only the opaque `reporting_required` boolean, hides the create-report navigation item for exempt memberships, and stores no `report_required` database field in V1. The reporting calendar is Monday–Saturday in `Asia/Ho_Chi_Minh`; Sunday is a non-working day with no create/edit window and no expected/missing population. The configured cutoff for newly created reports is 23:00 local time and locks exactly at that instant.

### Backend AI Daily Report routes

All routes require `auth:sanctum`, an active KPS membership, `ai.insights.view`, and `reports.view`. Source reports are always selected through `ReportScopeService`; client-supplied scope filters cannot broaden access.

| Route | Purpose |
| --- | --- |
| `GET /api/ai/daily-reports/available-dates` | Distinct locked report dates currently visible through `reports.view` and eligible under the AI V1 window |
| `GET /api/ai/daily-reports/summary?date=YYYY-MM-DD` | Database-derived counts and authorized source report IDs; no AI call |
| `GET /api/ai/daily-reports?date=YYYY-MM-DD` | Current requester's cached run for a date, or `null` |
| `POST /api/ai/daily-reports` | Idempotently create/queue or retry one requester/day run |
| `GET /api/ai/daily-reports/history` | Up to 31 current-requester runs, re-filtered by current access |
| `GET /api/ai/daily-reports/{id}` | Current-requester run detail with current source-scope recheck |

V1 accepts only the most recent 30 business dates and requires today's reporting window to be closed. A new run is rejected before persistence/dispatch when the selected date has no locked report in the requester's current scope; requester history and legacy empty runs remain readable through the history/detail endpoints. Run payloads decorate source IDs with only the scoped report author's display name and department snapshot so the UI can label source links without exposing email, membership, or authorization data. `KPS_AI_DRIVER=fake` is the safe default: the deterministic provider makes no network request. The database queue job checks permissions again when it executes, and completed/cached results remain subject to current report scope. OWNER/ADMIN receive the existing seeded AI permission; a department manager needs an explicit AI grant and then remains constrained by the existing department-scoped `reports.view` grant.

### Backend AI Executive Dashboard route

`GET /api/ai/executive-dashboard?period=7d|30d` is the backend-only Executive Dashboard V1 contract. It requires `auth:sanctum`, an active KPS membership, organization-wide `ai.dashboard.view`, and organization-wide `reports.view`. The seeded permission is granted only to OWNER and ADMIN. Every report query starts from `ReportScopeService`, is constrained to the active KPS organization, and includes only reports whose persisted `locked_at` is not later than the server-generated timestamp.

The response contains locked report/work-item totals, daily trends, department snapshot aggregates, blocked attention items with safe source metadata, and rule-based observations. `COMPLETED` remains an employee-reported status and is never presented as acceptance. The selected 7/30-day windows remain calendar-day ranges, while reporting-rate expected submissions use current active/default non-OWNER memberships multiplied only by Monday–Saturday working days. Sunday historical reports remain in actual report/work totals but do not enter the reporting-rate numerator or denominator. When that denominator is unavailable, the API returns `insufficient_data` and a null rate instead of guessing. Historical membership effective dates, public holidays, and leave are not modeled in V1.

Fresh installations receive `ai.dashboard.view` through `AuthorizationSeeder`. Existing databases should use the targeted `AiExecutiveDashboardPermissionSeeder`, which adds or updates only this permission and the OWNER/ADMIN `ALL` grants through `syncWithoutDetaching`. Running the full sync-based authorization seeder on an established database requires a prior role-permission drift review because it deliberately normalizes every seeded system role.

## 7. Current sidebar

**Verified** in `src/layout/AppSidebar.tsx`:

```text
Tổng quan                    -> /

CÔNG VIỆC
- Báo cáo công việc        -> /reports/new
- Lịch sử báo cáo          -> /reports
- Công việc                -> /tasks

KINH DOANH
- Khách hàng               -> /customers
- Sản phẩm                 -> /products
- Đơn hàng                 -> /orders

AI
- Phân tích điều hành         -> /ai/dashboard
- Phân tích AI             -> /ai/insights
- Trợ lý AI                -> /ai/assistant

HỆ THỐNG
- Nhân viên                -> /employees
- Tích hợp                 -> /integrations
- Cài đặt                  -> /settings
```

Tổng quan, the report pages, Task Management, Phân tích điều hành, AI Insights, and User Management now have KPS-specific functional implementations. The other sidebar destinations remain placeholders. The backend maps `tasks.view` to `tasks`, `ai.dashboard.view` to `ai.dashboard`, `ai.insights.view` to `ai.insights`, and `users.view` to `employees`; permission codes and data scopes remain backend-only. Frontend menu filtering is UX only and is not a security boundary.

## 8. Phase 1 implementation status

### A. Branding and navigation — completed as a prototype

- Kim Phục Sắc logo assets and `BrandMark` are present.
- The sidebar/header shell is responsive and has dark-mode styles.
- The sidebar uses brown tokens: `sidebar-accent: #5c3b1b` and `sidebar-selected: #7A5A39`.
- Most global `brand-*` tokens remain pink/magenta (`brand-500: #b72c54`) for retained shared/template-era components. KPS-specific Dashboard, login, navigation, and report actions use the brown `kps-primary` direction, so any future token normalization should remain a separate reviewed change.

### B. Tổng quan — Dashboard V1 implemented

Implemented by `GET /api/dashboard`, `src/pages/Dashboard/Overview.tsx`, and section-oriented components under `src/components/dashboard/`:

- One backend-owned safe mode: organization, department, location, or personal.
- Organization, current department, current location, and own-data views are resolved without exposing roles, permissions, or data scopes.
- Today-only reporting population and work aggregates use real Work Report data.
- Current active/default memberships determine expected/submitted/missing people; daily-report snapshots determine work and report visibility.
- OWNER is excluded from expected reporters and receives no self-report card; ADMIN and managers remain report participants.
- Organization department breakdown, manager people progress, blocked attention items, and five recent authorized reports are real scoped data.
- Personal mode shows today's self-report/work plus existing safe report-comment notifications.
- The old fake period switcher, AI summary, work-category chart, employee-performance mock, and dashboard mock file were removed.

### C. Daily work report — Work Report V1 implemented

Implemented in `src/pages/Reports/NewReport.tsx`, `src/components/reports/`, `src/types/reports.ts`, and `src/services/reportService.ts`:

- Server-authoritative business date, cutoff, existing report, and opaque action state from `GET /api/reports/today`
- Monday–Saturday working calendar, Sunday non-working state, and a 23:00 `Asia/Ho_Chi_Minh` cutoff for newly created reports
- Opaque `reporting_required` state for the OWNER exemption; the frontend does not inspect role names or permission codes
- Create-before-cutoff and author-edit-before-persisted-deadline flows
- Locked/read-only and closed-without-report states
- One or more work items containing only content, result, `COMPLETED|IN_PROGRESS|BLOCKED` status, and optional note
- Atomic replacement through the backend update contract; conflict responses trigger a fresh today-state request
- No fake AI processing, timers, editable date, customer/product fields, or report-domain mock persistence

### D. Report history, detail, comments, and notifications — Work Report V1 implemented

- History uses backend scope, filters, search, pagination, derived overall status/counts, and viewer-specific unread-comment counts.
- Filter references come only from `GET /api/reports/references`; User Management references are not reused.
- Detail renders ordered items and chronological comments plus opaque `can_edit`/`can_comment` actions.
- Manager comments use the append-only API. Opening a report explicitly marks only that report's comment notifications read after detail is loaded.
- The header dropdown uses safe notification payloads, shows unread state, supports mark-one/mark-all, refreshes on open/focus/visibility, and polls every two minutes only while the document is visible.
- Report detail handles scoped `403`/`404`, network, and general failures without attempting to infer backend permissions.

### E. HR User Management authorization — implemented

- `HR_MANAGER` is the dedicated “Hành chính nhân sự” role. The `HR_ADMIN` department remains organization structure only and never grants authority by itself.
- HR_MANAGER receives organization-scoped User Management permissions plus personal Dashboard, own Work Report, and normal employee capabilities.
- HR may view every KPS employee account, but OWNER/ADMIN memberships are read-only for HR even when those roles are secondary roles.
- OWNER, ADMIN, and HR_MANAGER are privileged assignment roles. Only OWNER/ADMIN may assign them; backend enforcement remains authoritative even for crafted requests.
- KPS permits at most one active/default HR_MANAGER membership. Create, role-change, and reactivation flows enforce the rule while holding the KPS organization lock.
- User payloads expose only `is_protected` and opaque `actions`; they do not expose permission codes, data scopes, role codes, or policy reasons.
- The employee list marks protected accounts, and employee detail becomes read-only when backend actions disallow mutation.

### F. AI Daily Report V1 — implemented mock-first

- OWNER/ADMIN can request deterministic analysis of locked Work Reports through a queued backend job.
- PHP/database aggregation is the source of counts; fake-provider narrative output is labeled clearly and never treated as KPI or approval data.
- Overview, requester-only history, and authorized source-report links are available at `/ai/insights`.
- The date selector is an accessible calendar backed by the scoped available-dates API. It defaults to the latest eligible date, renders non-eligible days as disabled buttons, uses `dd/MM/yyyy` for display, and keeps `YYYY-MM-DD` for API calls.
- Report sources use current backend data scope, and every cached result is rechecked after role/scope changes.
- Work Report rows/items are read-only inputs to this module; no existing report workflow or state is changed.
- The OpenAI adapter is dormant while `KPS_AI_DRIVER=fake`; no key or external data transfer is needed for V1.

### G. AI Executive Dashboard V1 — implemented

- `/ai/dashboard` is a separately guarded route using the opaque `ai.dashboard` navigation key; it does not reuse the AI Daily Report permission.
- Its user-facing name is **Phân tích điều hành**, distinguishing its trend/decision-support purpose from the daily operational Tổng quan page.
- The page loads the real 7/30-day backend contract, shows the backend-resolved date range and generated timestamp, and cancels/ignores stale requests when the period changes.
- The hierarchy is analysis-first: rule-based executive observations, 7/30-day trend and factual data explanation, source-linked priority issues, department comparison, then compact summary KPIs.
- Trend explanations require at least two days with locked reports and state only verifiable coverage, peak work volume, and blocked-day facts. They do not infer causes, performance, persistent work identity, or current-versus-previous-period change.
- Department highlighting compares only the blocked counts returned for the selected period; it does not rank employee performance.
- Null reporting rates and empty datasets are shown explicitly as insufficient data. The UI never labels the metric as compliance or on-time submission and never treats employee-reported `COMPLETED` as acceptance.
- Loading skeleton, network/auth/forbidden errors, retry, dark mode, responsive tables, tooltip/focus behavior, and source links are implemented.

## 9. Module status

| Module | Status |
| --- | --- |
| Tổng quan / Dashboard | V1 implemented: backend-owned modes, today reporting/work aggregates, self-report state, progress, attention, recent reports, and personal feedback |
| Daily work report | Work Report V1 create/edit/locked/read-only frontend and backend implemented |
| Report history | Work Report V1 scoped, filtered, paginated frontend and backend implemented |
| Report detail | Work Report V1 detail, comments, unread state, and notifications implemented |
| Tasks | Task Management V1 implemented with scoped list/create/detail/edit/status APIs and UI |
| Customers / CRM | List and detail routes are placeholders only |
| Products | Placeholder only |
| Orders | Placeholder only |
| AI insights / assistant | AI Daily Report V1 implemented at `/ai/insights`; Phân tích điều hành V1 implemented at `/ai/dashboard`; both remain mock/rule-based and the assistant is still a placeholder |
| Employees / User Management | V1 plus HR authorization: scoped HR CRUD for normal employees, protected OWNER/ADMIN targets, safe role references/actions, and one active HR_MANAGER holder |
| Integrations / Pancake | Placeholder only |
| Settings | Placeholder only |
| Authentication | V1 implemented with Sanctum session/cookie auth, login, current user, logout, route protection, and tests |
| Authorization / RBAC | V1 implemented: organization membership → roles → permissions → optional data scope, backend navigation mapping, permission middleware, route UX guards, and tests |
| Backend / database | Laravel 12, MySQL/TiDB/SQLite-compatible schema, Authentication V1, Authorization V1, User Management V1, Work Report V1, Dashboard V1, and AI Daily Report V1 implemented; no CRM persistence integration |

User Management V1 seeds the following extensible KPS department master data: `HR_ADMIN` (Hành chính nhân sự), `MARKETING`, `MEDIA`, `ACCOUNTING` (Kế toán), `SALES`, and `IT`. It deliberately creates no locations and stores no fixed headcount values. `HR_ADMIN` is not an authorization source; HR authority comes only from the separately assigned `HR_MANAGER` role.

## 10. State and data management

**Verified**

- Global UI state uses React Context only:
  - `AuthProvider` — current authenticated user, startup session restore, login, and logout
  - `AuthorizationProvider` — allowed navigation keys and frontend route/menu UX state
  - `NotificationProvider` — authenticated safe notifications, unread count, refresh lifecycle, and read actions
  - `ThemeContext` — light/dark theme and `localStorage`
  - `LanguageContext` — selected language metadata, HTML `lang`/`dir`, and `localStorage`
  - `SidebarContext` — desktop/mobile sidebar state
- Feature state uses local React state and memoization.
- There is no Redux, Zustand, server-state library, or API cache. Authentication, navigation, User Management, Work Reports, Dashboard, and notifications use the shared native-fetch API client with `credentials: "include"`.
- The API client exposes HTTP status, safe backend messages, and Laravel validation errors. A global 401/419 signal clears authenticated state; 403 and 409 responses do not end the session.
- Dashboard and report-domain mock data have been removed; placeholder modules still contain no fabricated business records.

### Internationalization

- The application currently supports Vietnamese only, using canonical locale `vi` and `src/locales/vi/common.json`.
- `src/i18n/languages.ts` is the authoritative supported-language configuration and exposes `Tiếng Việt`, `VIE`, LTR direction, and the local Vietnam flag icon.
- i18next defaults and falls back to `vi`; `LanguageContext` consumes the same language configuration and synchronizes HTML `lang`/`dir` plus both persisted language keys.
- Legacy or unsupported stored locale values, including `en`, are normalized to `vi` during initialization.

Do not add another locale or translation resource without a localization decision.

## 11. UI and coding conventions

### Current verified patterns

- Tailwind CSS v4 tokens and custom utilities live in `src/index.css`; there is no Tailwind config file.
- Dark mode uses the `.dark` class and `dark:` variants.
- KPS-specific code generally uses logical direction utilities (`ms`, `me`, `ps`, `pe`, `start`, `end`) for RTL compatibility.
- Route pages render `PageMeta` and generally use `PageBreadCrumb`.
- Reusable form and UI primitives come from `src/components/form/` and `src/components/ui/`.
- KPS report and dashboard code uses explicit domain types instead of `any`.
- Dashboard V1 uses server aggregates and compact tables/cards; the obsolete ApexCharts prototype was removed.

### Working rules for future changes

- Read relevant code and this document before implementing a feature.
- Keep UI labels in Vietnamese and source identifiers in English.
- Add user-facing text through i18next; resolve the locale gap before claiming four-language support.
- Reuse existing components and theme tokens.
- Do not add dependencies, providers, frameworks, or large refactors without explicit approval.
- Do not mix assigned `Task` entities with self-reported `WorkItem` entries.
- Keep mock data outside presentational components and introduce a service boundary when an API-backed feature begins.
- Keep secrets out of the frontend.
- Run build and lint after changes; report existing unrelated failures rather than mass-refactoring them.
- Do not push to GitHub unless explicitly requested.

## 12. CRM next direction

**Planned / Business decision — not implemented**

The next product area is Sales / CRM. The initial CRM should center on:

```text
Customer
  -> Owner / assigned employee
  -> Customer status
  -> Interaction history
  -> Spa visit
  -> Appointment
  -> Next follow-up
  -> Follow-up reminder
```

Retail and wholesale journeys must not be forced into an identical workflow:

- Retail: lead → consultation → appointment → spa visit/experience → 24-hour follow-up → 3–7-day follow-up → purchase/return → periodic care.
- Wholesale: lead → verification/classification → policy consultation → order/receipt → sales support → revenue/debt tracking → reorder.

Potential incremental CRM capabilities include customer create/update, retail/wholesale classification, phone duplicate checks, ownership assignment, consultation/care history, spa visits, appointments, follow-up reminders, inactive-customer views, products, orders, revenue, and wholesale debt. This list is direction, not an approved schema or a single implementation phase.

## 13. Unresolved business questions

These require stakeholder decisions before a deep CRM schema/workflow is implemented:

1. Does a customer have one owner or multiple carers?
2. Must owner assignment history be retained?
3. Are “potential”, “visited spa”, and “purchased” sequential statuses or independent attributes?
4. Can one customer be both retail and wholesale, or transition between them?
5. What is the source of truth for wholesale debt?
6. Are CRM orders authoritative or references to another system?
7. Which permissions control phone numbers, revenue, and debt visibility?
8. What event starts the 24-hour and 3–7-day follow-up clocks?
9. How should overdue follow-ups warn, notify, or escalate?
10. Which identifiers drive deduplication: phone, Pancake/Facebook ID, Zalo, or a combination?
11. Which metrics, if any, validly represent employee care effectiveness?

Do not encode assumptions for these questions without confirmed requirements.

## 14. AI direction

**Current verified state**

- The fake Dashboard AI summary remains removed; AI analysis lives in its own authorized `/ai/insights` module.
- `KPS_AI_DRIVER=fake` uses a deterministic backend provider and makes no external AI API call.
- AI runs persist separately in `ai_daily_report_runs`; original Work Reports are never updated by analysis.
- Counts and source IDs are derived in PHP from currently authorized reports. Narrative findings retain source report references for verification.
- The OpenAI adapter is present only for a separately approved future activation and has not been enabled or live-tested.

**Planned / Business decision**

- AI is an assistance layer, never the source of truth.
- Preserve raw user-entered data before AI-derived output.
- Start with one useful use case rather than a broad autonomous agent.
- Candidate use cases: customer-history summary, suggested next action, customers to contact today, suggested message content, daily care summary, and detection of neglected customers.
- Use human-in-the-loop review: suggestion → accept/edit/reject → save feedback.
- Feedback is initially for evaluation, prompt/rule improvement, test datasets, and RAG improvement; saving feedback does not mean automatic model training.

## 15. Deployment, authentication, and security direction

### Verified

- The application is configured as a Vercel SPA.
- `vercel.mjs` builds environment-specific rewrites: `/api` and `/sanctum` are proxied to the validated `KPS_BACKEND_ORIGIN` before the SPA fallback. Production/Preview browser requests use `VITE_API_BASE_URL=/`; missing or unsafe upstream configuration fails closed.
- Demo URL from the project handoff: `https://kimphucsac.vercel.app/`.
- Authentication V1 is implemented with Sanctum first-party SPA authentication, Laravel's `web` session guard, CSRF protection, credentialed CORS, and `auth:sanctum` on protected endpoints.
- Authorization V1 remains backend-owned. It now resolves the active/default membership specifically for organization code `KPS`, aggregates all assigned roles, and exposes a `permission:<code>` middleware for protected backend actions.
- Data scopes are represented by the backend-only order `SELF < OWN < TEAM < DEPARTMENT < LOCATION < ORGANIZATION < ALL`; the broadest grant wins for existing generic authorization consumers. Dashboard V1 deliberately resolves the full scope set instead, because department and location are independent business dimensions; TEAM and conflicting management dimensions fail closed.
- `/api/auth/me` returns display context only, and `/api/navigation` returns UI navigation keys only. Permission codes, role codes, role-permission assignments, and data scopes are not sent to the frontend.
- Frontend menu hiding and route guards are UX controls only. Backend permission enforcement remains authoritative.
- HR User Management uses organization-scoped `users.*` grants, centralized protected-target/privileged-role rules, and transaction-level organization locking for the single active HR_MANAGER constraint.
- The recommended local convention is frontend `http://localhost:5173` and backend `http://localhost:8000` so stateful cookies remain consistent.
- There is no public registration, bearer-token/JWT flow, password recovery, or authorization-matrix UI. Profile V1 provides authenticated self-service name/password management while organization fields and email remain administrator/HR controlled.
- `GET /api/health` remains public.

### Planned / Business decision

- Intended internal hostname: a single subdomain such as `noibo.kimphucsac.com.vn`.
- Intended topology: one frontend, one backend, one database, and one authentication system.
- Every future protected business endpoint must apply backend permission enforcement; frontend checks must never be treated as sufficient.
- Data-scope-aware CRM query filtering is future work and must be implemented with the business repositories/queries rather than trusted frontend filters.
- External dealer and external spa tenancy remain future work; Authorization V1 models only the internal `KPS` organization.
- Keep Vercel and Render authentication first-party through the same-origin proxy unless approved sibling custom domains are configured and browser-tested. Direct `vercel.app` → `onrender.com` Sanctum cookie authentication is not supported.
- Use mock data only until real customer-data handling has been approved. Production CRM data will require authentication, backend authorization, secure APIs/secrets, access controls, and appropriate auditability.

## 16. Pancake integration direction

**Planned / Business decision — not implemented**

The preferred flow is Pancake → KPS backend/integration → KPS database → CRM/AI/dashboard. The KPS database should remain the internal source of truth and should store external identifiers without making the domain schema depend on Pancake.

Prefer one-way Pancake-to-KPS synchronization first. Do not begin two-way synchronization without a separate design and approval.

## 17. Weekly Work Planning V1

- Weekly Planning uses `/weekly-plans`, private OOXML import, structured preview/editing, per-employee OWNER review, explicit final approval, and a separate publication action.
- Weekly Planning is presented as the permission-controlled `Kế hoạch tuần` tab inside the Công việc module. Its `weekly-plans` navigation key and direct routes remain independently guarded, but it is not a standalone sidebar item.
- Plans cover the next Monday–Saturday period in `Asia/Ho_Chi_Minh`. API dates remain date-only `YYYY-MM-DD`; the frontend uses `KpsDatePicker` and displays `DD/MM/YYYY`.
- Imported rows, validation results, review decisions, comments, rule-based suggestions, and audit events are persisted separately from Task Management. No external AI provider is called.
- Tasks are created only from an `APPROVED` plan during the transactional publish step. `tasks.weekly_plan_entry_id` provides a unique source link and repeated publication is idempotent.
- Weekly permissions are isolated from the main authorization seeder. HR_MANAGER receives organization-wide draft/submit grants; OWNER receives view/review/approve/publish grants; ADMIN receives only an `ALL`-scoped read grant and all write actions remain unavailable.
- The original file uses a private Laravel Storage disk. Render local storage is not durable; production activation requires an approved persistent private object-storage adapter and configuration. See `docs/WEEKLY_PLANNING_V1_API.md`.

## 18. Known technical debt and audit findings

1. `npm run build` passes. The main KPS application chunk is roughly 581 KB (roughly 161 KB gzip). ApexCharts remains removed; the Executive Dashboard trend uses lightweight HTML/CSS without adding a chart dependency. Route-level code splitting remains a future performance improvement.
2. `npm run lint` passes with three Fast Refresh warnings across the three context files. The warnings are intentionally retained because removing them would require reorganizing context exports.
3. The frontend has lightweight Node contract tests for navigation, Profile/Account route separation, and locale migration. The backend PHPUnit suite covers health, Authentication V1, active-membership enforcement, authorization resolution/scopes, Profile V1, User Management CRUD/filtering/safeguards and HR authorization, Work Report cutoff/scope/transaction/notification behavior, Dashboard mode/aggregate/snapshot behavior, AI owner/admin access, department scope, queue dispatch, revocation rechecks, history/source isolation, navigation output, safe payloads, and idempotent RBAC/department seeding.
4. The primary Dashboard V1 remains today-only. Phân tích điều hành provides current-period 7/30-day Work Report trends and excludes Sunday from the reporting-rate denominator, but previous-period comparisons, historical membership effective dates, public holidays, attendance/leave history, Task Management-derived analytics, CRM metrics, and work categories remain deferred.
5. CRM routes remain placeholders. The `/employees` routes manage internal accounts rather than a separate HR employee-profile domain.
6. Existing users are deliberately not assigned memberships or roles automatically. Department/location CRUD, HR profiles, multiple-role UI, password reset, email verification, 2FA, audit history, and lower-scope User Management semantics remain absent by design.
7. Vietnamese is the only supported locale; adding other languages remains a separate product/localization decision.
8. Global legacy `brand-*` tokens remain pink/magenta in retained shared components, while KPS-specific Dashboard, login, navigation, and report actions use the brown `kps-primary` direction.
9. Header notifications currently cover Work Report comment notifications only; broader notification types require a separate product/API decision.
10. The active user dropdown uses the authenticated user's name/email with an initials indicator, links to Profile and Account Settings, and supports logout. The support action remains a no-op; existing inline SVG icons still require later design review.
11. The AI migration file is integrated and exercised by SQLite tests. Applying it to the local MySQL development database requires the local `MySQL84` service to be running; the current shell could not start the Windows service because Service Control Manager access was denied, and direct startup was also denied access to `D:\MySQL\Data`. No TiDB migration was attempted.

The Phase 1 cleanup removed verified-unused demo routes, pages, components, assets, styles, translations, and dependencies without changing KPS business behavior.

## 19. Recommended next steps

1. Confirm the first CRM slice and answer only the business questions needed for that slice. A sensible first candidate is a read-only/mock customer list plus customer profile information architecture, but the owner model, customer-type model, status semantics, and permission visibility must be agreed first.
2. Before API work, define a narrow customer service interface and mock implementation so components do not import CRM mocks directly.
3. Keep Vietnamese as the sole locale until a separate product decision approves additional translation resources.
4. Schedule a separate maintenance task for the four Fast Refresh warnings; do not mix that context-export refactor into the first CRM feature.
5. Use User Management V1 for new internal accounts. Existing orphan users still require an explicit active/default KPS membership and role before they can log in; do not auto-assign roles to arbitrary users.
6. Apply the backend permission middleware and data-scope query constraints as each real business API is introduced; do not rely on the frontend navigation guard.

Do not implement CRM or change the sidebar until a concrete requirement is approved.
