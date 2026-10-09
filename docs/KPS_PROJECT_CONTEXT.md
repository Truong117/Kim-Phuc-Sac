# KPS Internal System — Project Context

Last repository audit: 2026-10-09 (Asia/Saigon)

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
- Current branch: `feature/work-report-v1`
- Work Report V1 builds on the completed Authentication, Authorization, and User Management V1 baseline.
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
| Charts | ApexCharts / react-apexcharts |
| Package manager | npm with `package-lock.json` |

Unused template libraries for FullCalendar, Swiper, flatpickr, vector/maps, simplebar, and React DnD were removed during the Phase 1 repository cleanup.

The audit ran successfully on Node `v22.23.3` and npm `10.9.9`.

Available scripts:

- `npm run dev` — Vite development server
- `npm run build` — `tsc -b && vite build`
- `npm run lint` — ESLint
- `npm run preview` — Vite preview

There is no frontend automated test script.

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

Authentication V1 reuses the default `users` and `sessions` tables. Authorization V1 adds organizations, departments, locations, organization memberships, roles, permissions, and the two role-assignment pivot tables. User Management V1 uses that existing schema to manage internal accounts, KPS memberships, one primary role, department/location references, and membership access status. Work Report V1 adds daily reports/items, append-only comments, and normalized user notifications. No CRM persistence has been implemented.

## 4. Current application architecture

### Verified

- The application is a client-side SPA.
- `src/main.tsx` installs the theme, language, Helmet, authentication, and authorization providers.
- `src/App.tsx` owns all routes.
- `src/layout/AppLayout.tsx` provides the standard sidebar/header shell and installs `SidebarProvider` plus the authenticated `NotificationProvider` for dashboard routes.
- KPS domain UI is currently split into focused files under:
  - `src/pages/Dashboard/Management.tsx`
  - `src/pages/Reports/`
  - `src/components/dashboard/`
  - `src/components/reports/`
  - `src/types/`
  - `src/services/`
  - `src/mocks/` (dashboard-only prototype data)
- `src/services/apiClient.ts` owns the shared credentialed native-fetch behavior. Focused auth, navigation, user-management, report, and notification services use it.
- The Laravel API under `backend/` implements Authentication V1, Authorization V1, User Management V1, and Work Report V1. The daily report, report history/detail/comments, recent reports, and header notifications now consume the Work Report API.
- Remaining mock data is limited to the management dashboard's prototype KPI/category/AI content; report-domain mocks were removed.

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
├── database/migrations/           # framework, Authorization V1, and Work Report V1 schema
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
│   ├── Dashboard/Management.tsx
│   ├── Employees/                  # user list/create/edit pages
│   ├── Reports/NewReport.tsx
│   ├── Reports/ReportHistory.tsx
│   ├── Reports/ReportDetail.tsx
│   ├── OtherPage/ModulePlaceholder.tsx
│   └── OtherPage/NotFound.tsx
├── components/
│   ├── auth/
│   ├── dashboard/
│   ├── employees/                  # User Management V1 components
│   ├── reports/
│   ├── common/
│   ├── form/
│   └── ui/
├── mocks/
│   └── dashboard.ts                 # remaining dashboard-only prototype data
├── services/apiClient.ts           # shared credentialed fetch client
├── services/authService.ts         # Sanctum CSRF/session requests
├── services/navigationService.ts   # allowed navigation-key request
├── services/userService.ts         # User Management API requests
├── services/reportService.ts       # Work Report API requests
├── services/notificationService.ts # notification API requests
├── hooks/useAuth.ts                # authenticated-user context hook
├── hooks/useAuthorization.ts       # navigation-key UX authorization hook
├── hooks/useNotifications.ts       # authenticated notification context hook
├── types/
│   ├── auth.ts
│   ├── dashboard.ts
│   ├── reports.ts
│   ├── notifications.ts
│   ├── userManagement.ts
│   └── user.ts
├── i18n/
│   ├── index.ts
│   └── languages.ts
└── locales/en/common.json
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
| `/` | redirect to `/dashboard` | Implemented |
| `/dashboard` | `Dashboard/Management` | Phase 1 prototype; recent reports use Work Report API |
| `/reports/new` | `Reports/NewReport` | Work Report V1 create/edit/locked/exempt states implemented |
| `/reports` | `Reports/ReportHistory` | Work Report V1 scoped history implemented |
| `/reports/:id` | `Reports/ReportDetail` | Work Report V1 detail/comments implemented |
| `/tasks` | `ModulePlaceholder` | Placeholder only |
| `/customers` | `ModulePlaceholder` | Placeholder only |
| `/customers/:id` | `ModulePlaceholder` | Placeholder only |
| `/products` | `ModulePlaceholder` | Placeholder only |
| `/orders` | `ModulePlaceholder` | Placeholder only |
| `/ai/insights` | `ModulePlaceholder` | Placeholder only |
| `/ai/assistant` | `ModulePlaceholder` | Placeholder only |
| `/employees` | `Employees/EmployeeList` | User Management V1 list implemented |
| `/employees/new` | `Employees/EmployeeCreate` | User Management V1 create form implemented |
| `/employees/:id` | `Employees/EmployeeEdit` | Profile, role, and access-status management implemented |
| `/integrations` | `ModulePlaceholder` | Placeholder only |
| `/settings` | `ModulePlaceholder` | Placeholder only |

### Standalone routes

| Route | Status |
| --- | --- |
| `*` | KPS-branded 404 page |

There are no retained public TailAdmin demo routes. Authentication V1 adds only the KPS login route; registration and account-recovery routes remain absent.

All `AppLayout` routes are wrapped by `ProtectedRoute` for authentication. Most module routes also use a navigation-key guard for authorization UX. `/reports/new` deliberately relies on the safe `GET /api/reports/today` state instead of the navigation guard so an exempt membership following a direct URL sees the non-participant state rather than a false 403 page; its sidebar item is still controlled by backend navigation output. The wildcard KPS 404 route remains outside the protected layout.

### Backend authentication routes

| Route | Protection | Purpose |
| --- | --- | --- |
| `GET /sanctum/csrf-cookie` | Public | Initialize Sanctum CSRF cookie |
| `POST /api/auth/login` | Public, CSRF-protected | Validate credentials and create the server session |
| `GET /api/auth/me` | `auth:sanctum`, active KPS membership | Return user identity plus safe organization/department/location/primary-role display context |
| `POST /api/auth/logout` | `auth:sanctum`, CSRF-protected | Invalidate the current session |
| `GET /api/navigation` | `auth:sanctum`, active KPS membership | Return only the allowed frontend navigation keys |

Login now requires an active, default KPS membership in the active KPS organization in addition to valid credentials. Protected application requests re-check that membership; a session whose membership becomes inactive receives JSON `401`, is logged out, and the frontend returns to `/login`. Logout intentionally remains available with `auth:sanctum` alone.

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

### Backend Work Report routes

All routes require `auth:sanctum` and an active KPS membership. Report list/detail/comment access is additionally constrained by backend report scope. Full request/response examples are documented in `docs/WORK_REPORT_V1_API.md`.

| Route | Purpose |
| --- | --- |
| `GET /api/reports/today` | Server-authoritative business date, cutoff, report-participation state, current report, and create action state |
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

Work Report V1 has a business-level participation rule: an active KPS membership containing `OWNER` is exempt from submitting or editing daily reports, even when another assigned role grants `reports.create`. OWNER retains organization-wide report viewing and may comment on other employees' locked reports. `ADMIN` is not exempt and remains a normal report participant. The backend exposes only the opaque `reporting_required` boolean, hides the create-report navigation item for exempt memberships, and stores no `report_required` database field in V1. Future missing-report metrics must exclude OWNER from the expected-report population.

## 7. Current sidebar

**Verified** in `src/layout/AppSidebar.tsx`:

```text
Dashboard

CÔNG VIỆC
- Báo cáo công việc        -> /reports/new
- Lịch sử báo cáo          -> /reports
- Công việc                -> /tasks

KINH DOANH
- Khách hàng               -> /customers
- Sản phẩm                 -> /products
- Đơn hàng                 -> /orders

AI
- Phân tích AI             -> /ai/insights
- Trợ lý AI                -> /ai/assistant

HỆ THỐNG
- Nhân viên                -> /employees
- Tích hợp                 -> /integrations
- Cài đặt                  -> /settings
```

Dashboard, the two report pages, and User Management now have KPS-specific functional implementations. The other sidebar destinations remain placeholders. The backend maps `users.view` to the opaque frontend navigation key `employees`; permission codes and data scopes remain backend-only. Frontend menu filtering is UX only and is not a security boundary.

## 8. Phase 1 implementation status

### A. Branding and navigation — completed as a prototype

- Kim Phục Sắc logo assets and `BrandMark` are present.
- The sidebar/header shell is responsive and has dark-mode styles.
- The sidebar uses brown tokens: `sidebar-accent: #5c3b1b` and `sidebar-selected: #7A5A39`.
- Most global `brand-*` tokens remain pink/magenta (`brand-500: #b72c54`) and are still used by dashboard filters, AI cards, loading indicators, and links. Therefore the handoff's “primary brown” direction is only partially applied.

### B. Management dashboard — hybrid prototype

Implemented in `src/pages/Dashboard/Management.tsx`, `src/components/dashboard/`, and `src/mocks/dashboard.ts`:

- Title: “Tổng quan công việc”
- Subtitle: “Theo dõi tình hình công việc và hoạt động nội bộ”
- Period controls: today, week, month
- KPI values: total 48, completed 39 (81%), in progress 7 (15%), blocked 2 (4%)
- Work-category values: 17, 12, 10, 7, 2 (total 48)
- Work progress table displayed as “Tiến độ công việc”
- Recent reports table backed by the scoped Work Report API
- AI management summary, explicitly backed by static mock content

Limitations:

- Changing the period only updates local selected state; it does not change the displayed mock KPI/category dataset or the recent-reports request.
- The source component/type is still named `EmployeePerformance`, although the visible UI correctly avoids presenting the section as an employee performance judgment.

### C. Daily work report — Work Report V1 implemented

Implemented in `src/pages/Reports/NewReport.tsx`, `src/components/reports/`, `src/types/reports.ts`, and `src/services/reportService.ts`:

- Server-authoritative business date, cutoff, existing report, and opaque action state from `GET /api/reports/today`
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

## 9. Module status

| Module | Status |
| --- | --- |
| Management dashboard | Hybrid prototype: scoped recent reports are live; KPI/category/AI content remains mock |
| Daily work report | Work Report V1 create/edit/locked/read-only frontend and backend implemented |
| Report history | Work Report V1 scoped, filtered, paginated frontend and backend implemented |
| Report detail | Work Report V1 detail, comments, unread state, and notifications implemented |
| Tasks | Placeholder only |
| Customers / CRM | List and detail routes are placeholders only |
| Products | Placeholder only |
| Orders | Placeholder only |
| AI insights / assistant | Placeholders only |
| Employees / User Management | V1 implemented: list/search/filter/pagination, create, edit, primary-role replacement, and membership activation/deactivation |
| Integrations / Pancake | Placeholder only |
| Settings | Placeholder only |
| Authentication | V1 implemented with Sanctum session/cookie auth, login, current user, logout, route protection, and tests |
| Authorization / RBAC | V1 implemented: organization membership → roles → permissions → optional data scope, backend navigation mapping, permission middleware, route UX guards, and tests |
| Backend / database | Laravel 12, MySQL/TiDB/SQLite-compatible schema, Authentication V1, Authorization V1, User Management V1, and Work Report V1 implemented; no CRM persistence integration |

User Management V1 seeds the following extensible KPS department master data: `HR_ADMIN` (Hành chính nhân sự), `MARKETING`, `MEDIA`, `ACCOUNTING` (Kế toán), `SALES`, and `IT`. It deliberately creates no locations and stores no fixed headcount values.

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
- There is no Redux, Zustand, server-state library, or API cache. Authentication, navigation, User Management, Work Reports, and notifications use the shared native-fetch API client with `credentials: "include"`.
- The API client exposes HTTP status, safe backend messages, and Laravel validation errors. A global 401/419 signal clears authenticated state; 403 and 409 responses do not end the session.
- Remaining KPS mock data in `src/mocks/` serves only the non-report management-dashboard prototype.

### Internationalization gap

- The only loaded i18next resource is `src/locales/en/common.json`.
- That `en` file now contains only translation keys used by the KPS UI; most visible values are Vietnamese pending a localization decision.
- `src/i18n/languages.ts` exposes only `en` in the visible language selector.
- `LanguageContext.tsx` separately declares `en`, `ar`, `es`, and `de`, but translation dictionaries for `ar`, `es`, and `de` do not exist.
- The current repository instructions expect four locale dictionaries and RTL support, but the implementation does not yet satisfy that contract.
- Language definitions are duplicated between `src/i18n/languages.ts` and `src/context/LanguageContext.tsx` and can drift.

Do not silently invent the missing translations during an unrelated feature. Confirm the localization strategy first.

## 11. UI and coding conventions

### Current verified patterns

- Tailwind CSS v4 tokens and custom utilities live in `src/index.css`; there is no Tailwind config file.
- Dark mode uses the `.dark` class and `dark:` variants.
- KPS-specific code generally uses logical direction utilities (`ms`, `me`, `ps`, `pe`, `start`, `end`) for RTL compatibility.
- Route pages render `PageMeta` and generally use `PageBreadCrumb`.
- Reusable form and UI primitives come from `src/components/form/` and `src/components/ui/`.
- KPS report and dashboard code uses explicit domain types instead of `any`.
- The work-category chart uses `React.lazy` and `Suspense` for `react-apexcharts`.

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

- Dashboard AI summary is static mock content.
- No external AI API is called.
- No AI output is persisted.

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
- `vercel.json` currently rewrites `/(.*)` to `/index.html`.
- Demo URL from the project handoff: `https://kimphucsac.vercel.app/`.
- Authentication V1 is implemented with Sanctum first-party SPA authentication, Laravel's `web` session guard, CSRF protection, credentialed CORS, and `auth:sanctum` on protected endpoints.
- Authorization V1 remains backend-owned. It now resolves the active/default membership specifically for organization code `KPS`, aggregates all assigned roles, and exposes a `permission:<code>` middleware for protected backend actions.
- Data scopes are represented by the backend-only order `SELF < OWN < TEAM < DEPARTMENT < LOCATION < ORGANIZATION < ALL`; the broadest grant wins when multiple roles provide the same permission.
- `/api/auth/me` returns display context only, and `/api/navigation` returns UI navigation keys only. Permission codes, role codes, role-permission assignments, and data scopes are not sent to the frontend.
- Frontend menu hiding and route guards are UX controls only. Backend permission enforcement remains authoritative.
- The recommended local convention is frontend `http://localhost:5173` and backend `http://localhost:8000` so stateful cookies remain consistent.
- There is no public registration, bearer-token/JWT flow, password recovery, self-service profile page, or authorization-matrix UI. Admin-managed account profile fields are available through User Management V1.
- `GET /api/health` remains public.

### Planned / Business decision

- Intended internal hostname: a single subdomain such as `noibo.kimphucsac.com.vn`.
- Intended topology: one frontend, one backend, one database, and one authentication system.
- Every future protected business endpoint must apply backend permission enforcement; frontend checks must never be treated as sufficient.
- Data-scope-aware CRM query filtering is future work and must be implemented with the business repositories/queries rather than trusted frontend filters.
- External dealer and external spa tenancy remain future work; Authorization V1 models only the internal `KPS` organization.
- Revisit the catch-all Vercel rewrite if `/api` is later served on the same domain.
- Use mock data only until real customer-data handling has been approved. Production CRM data will require authentication, backend authorization, secure APIs/secrets, access controls, and appropriate auditability.

## 16. Pancake integration direction

**Planned / Business decision — not implemented**

The preferred flow is Pancake → KPS backend/integration → KPS database → CRM/AI/dashboard. The KPS database should remain the internal source of truth and should store external identifiers without making the domain schema depend on Pancake.

Prefer one-way Pancake-to-KPS synchronization first. Do not begin two-way synchronization without a separate design and approval.

## 17. Known technical debt and audit findings

1. `npm run build` passes. The main KPS application chunk is roughly 500 KB (roughly 150 KB gzip), while the lazily loaded `react-apexcharts` vendor chunk remains large at about 926 KB (about 265 KB gzip).
2. `npm run lint` passes with four Fast Refresh warnings across the three context files. The warnings are intentionally retained because removing them would require reorganizing context exports.
3. The frontend has no automated test suite. The backend PHPUnit suite covers health, Authentication V1, active-membership enforcement, authorization resolution/scopes, User Management CRUD/filtering/safeguards, Work Report cutoff/scope/transaction/notification behavior, navigation output, safe payloads, and idempotent RBAC/department seeding.
4. Dashboard KPI/category/period data and the dashboard AI summary remain mock; the recent-report table is connected to the Work Report API.
5. CRM routes remain placeholders. The `/employees` routes manage internal accounts rather than a separate HR employee-profile domain.
6. Existing users are deliberately not assigned memberships or roles automatically. Department/location CRUD, HR profiles, multiple-role UI, password reset/change-password, email verification, 2FA, audit history, and lower-scope User Management semantics remain absent by design.
7. Internationalization configuration and available resources are inconsistent, as described above.
8. Global brand tokens remain pink/magenta while the intended KPS primary color is brown; only sidebar-specific tokens consistently use brown.
9. Header notifications currently cover Work Report comment notifications only; broader notification types require a separate product/API decision.
10. The active user dropdown uses the authenticated user's name/email and supports logout. Profile, account settings, and support actions remain no-op pending separate features; existing inline SVG icons still require later design review.

The Phase 1 cleanup removed verified-unused demo routes, pages, components, assets, styles, translations, and dependencies without changing KPS business behavior.

## 18. Recommended next steps

1. Confirm the first CRM slice and answer only the business questions needed for that slice. A sensible first candidate is a read-only/mock customer list plus customer profile information architecture, but the owner model, customer-type model, status semantics, and permission visibility must be agreed first.
2. Before API work, define a narrow customer service interface and mock implementation so components do not import CRM mocks directly.
3. Decide whether the product is Vietnamese-only for now or must restore all four locale dictionaries; then align `i18n/index.ts`, `i18n/languages.ts`, and `LanguageContext.tsx`.
4. Schedule a separate maintenance task for the Fast Refresh warnings and large chart vendor chunk; do not mix that cleanup into the first CRM feature.
5. Use User Management V1 for new internal accounts. Existing orphan users still require an explicit active/default KPS membership and role before they can log in; do not auto-assign roles to arbitrary users.
6. Apply the backend permission middleware and data-scope query constraints as each real business API is introduced; do not rely on the frontend navigation guard.

Do not implement CRM or change the sidebar until a concrete requirement is approved.
