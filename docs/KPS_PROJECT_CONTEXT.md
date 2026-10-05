# KPS Internal System — Project Context

Last repository audit: 2026-10-05 (Asia/Saigon)

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
- Current branch: `main`
- Tracking branch: `origin/main`
- Audited commit: `92c190e` (`feat: report history`)
- Existing dirty file at the start of this audit: `package-lock.json`
  - The only observed diff changes `brace-expansion` from `5.0.9` to `5.0.12`.
  - This change predates the cleanup and was preserved while the lockfile was updated for dependency removal.
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

There is no automated test script.

## 4. Current application architecture

### Verified

- The application is a client-side SPA.
- `src/main.tsx` installs the theme, language, and Helmet providers.
- `src/App.tsx` owns all routes.
- `src/layout/AppLayout.tsx` provides the standard sidebar/header shell and installs `SidebarProvider` for dashboard routes.
- KPS domain UI is currently split into focused files under:
  - `src/pages/Dashboard/Management.tsx`
  - `src/pages/Reports/`
  - `src/components/dashboard/`
  - `src/components/reports/`
  - `src/types/`
  - `src/mocks/`
- There is no `src/services/` or `src/features/` layer yet.
- There is no backend, database client, HTTP API client, or persisted application data in this repository.
- KPS pages import mock data directly. This is acceptable for the current prototype but is not yet the intended UI → service → API boundary.

### Planned / Business decision

The long-term boundary is:

```text
React/Vite frontend
  -> service layer
  -> Laravel REST API
  -> MySQL or PostgreSQL
  -> external integrations (AI, Pancake, others)
```

The frontend must never connect directly to the database. AI, Pancake, and other secrets must remain on the backend and must not be exposed through `VITE_*` variables.

## 5. Repository structure relevant to KPS

```text
src/
├── App.tsx                         # all route registration
├── main.tsx                        # root providers and global CSS imports
├── index.css                       # Tailwind v4 theme/utilities/overrides
├── layout/
│   ├── AppLayout.tsx
│   ├── AppSidebar.tsx
│   └── AppHeader.tsx
├── context/
│   ├── ThemeContext.tsx
│   ├── SidebarContext.tsx
│   └── LanguageContext.tsx
├── pages/
│   ├── Dashboard/Management.tsx
│   ├── Reports/NewReport.tsx
│   ├── Reports/ReportHistory.tsx
│   ├── Reports/ReportDetailPlaceholder.tsx
│   ├── OtherPage/ModulePlaceholder.tsx
│   └── OtherPage/NotFound.tsx
├── components/
│   ├── dashboard/
│   ├── reports/
│   ├── common/
│   ├── form/
│   └── ui/
├── mocks/
│   ├── currentUser.ts
│   ├── dashboard.ts
│   ├── reports.ts
│   └── reportHistory.ts
├── types/
│   ├── dashboard.ts
│   ├── reports.ts
│   └── user.ts
├── i18n/
│   ├── index.ts
│   └── languages.ts
└── locales/en/common.json
```

The Phase 1 cleanup removed the unused TailAdmin demo areas for auth, calendar, profile, forms, tables, UI elements, ecommerce, and standalone chart demos. Reusable primitives still consumed by KPS pages were deliberately retained.

## 6. Current routes

### Routes inside `AppLayout`

| Route | Current component | Status |
| --- | --- | --- |
| `/` | redirect to `/dashboard` | Implemented |
| `/dashboard` | `Dashboard/Management` | Phase 1 prototype implemented |
| `/reports/new` | `Reports/NewReport` | Phase 1 prototype implemented |
| `/reports` | `Reports/ReportHistory` | Phase 1 prototype implemented |
| `/reports/:id` | `ReportDetailPlaceholder` | Placeholder only |
| `/tasks` | `ModulePlaceholder` | Placeholder only |
| `/customers` | `ModulePlaceholder` | Placeholder only |
| `/customers/:id` | `ModulePlaceholder` | Placeholder only |
| `/products` | `ModulePlaceholder` | Placeholder only |
| `/orders` | `ModulePlaceholder` | Placeholder only |
| `/ai/insights` | `ModulePlaceholder` | Placeholder only |
| `/ai/assistant` | `ModulePlaceholder` | Placeholder only |
| `/employees` | `ModulePlaceholder` | Placeholder only |
| `/integrations` | `ModulePlaceholder` | Placeholder only |
| `/settings` | `ModulePlaceholder` | Placeholder only |

### Standalone routes

| Route | Status |
| --- | --- |
| `*` | KPS-branded 404 page |

There are no retained public TailAdmin demo routes. Authentication routes will be introduced only with an approved authentication implementation.

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

Only Dashboard and the two report pages have KPS-specific functional implementations. The remaining sidebar destinations are placeholders.

## 8. Phase 1 implementation status

### A. Branding and navigation — completed as a prototype

- Kim Phục Sắc logo assets and `BrandMark` are present.
- The sidebar/header shell is responsive and has dark-mode styles.
- The sidebar uses brown tokens: `sidebar-accent: #5c3b1b` and `sidebar-selected: #7A5A39`.
- Most global `brand-*` tokens remain pink/magenta (`brand-500: #b72c54`) and are still used by dashboard filters, AI cards, loading indicators, and links. Therefore the handoff's “primary brown” direction is only partially applied.

### B. Management dashboard — completed as a mock prototype

Implemented in `src/pages/Dashboard/Management.tsx`, `src/components/dashboard/`, and `src/mocks/dashboard.ts`:

- Title: “Tổng quan công việc”
- Subtitle: “Theo dõi tình hình công việc và hoạt động nội bộ”
- Period controls: today, week, month
- KPI values: total 48, completed 39 (81%), in progress 7 (15%), blocked 2 (4%)
- Work-category values: 17, 12, 10, 7, 2 (total 48)
- Work progress table displayed as “Tiến độ công việc”
- Recent reports table
- AI management summary, explicitly backed by static mock content

Limitations:

- Changing the period only updates local selected state; it does not change the displayed mock dataset.
- The source component/type is still named `EmployeePerformance`, although the visible UI correctly avoids presenting the section as an employee performance judgment.

### C. Daily work report — completed as a mock form prototype

Implemented in `src/pages/Reports/NewReport.tsx`, `src/components/reports/`, `src/types/reports.ts`, `src/mocks/currentUser.ts`, and `src/mocks/reports.ts`:

- Read-only mock employee and department
- Editable report date
- One or more work items
- Required content, result, and status fields
- Statuses: `COMPLETED`, `IN_PROGRESS`, `BLOCKED`
- Optional customer, product, and note fields
- Add/remove work items with automatic numbering
- Required-field validation after blur or submit, not immediately on initial render
- Processing modal stages: saving → analyzing → completed

Limitations:

- Submit only logs the normalized form in development and starts timers.
- No report is persisted, and no history row is created.
- Saving and AI analysis are simulated with `setTimeout` (800 ms and 1250 ms).
- There is no `reportService` or `aiService` yet.

### D. Report history — completed as a mock list prototype

Implemented in `src/pages/Reports/ReportHistory.tsx`, `src/components/reports/`, and `src/mocks/reportHistory.ts`:

- From/to date filters
- Employee, department, and status filters
- Text search
- Client-side pagination (8 records per page)
- Report table and status badges
- Links to `/reports/:id`

Limitations:

- All data is static and filtered in memory.
- Overall report statuses are stored on mock rows. The proposed derivation rule from work-item statuses is not implemented as domain logic.
- Report detail is a placeholder page only.

## 9. Module status

| Module | Status |
| --- | --- |
| Management dashboard | Mock prototype implemented |
| Daily work report | Mock form and processing flow implemented; no persistence |
| Report history | Mock filtering/table/pagination implemented |
| Report detail | Placeholder only |
| Tasks | Placeholder only |
| Customers / CRM | List and detail routes are placeholders only |
| Products | Placeholder only |
| Orders | Placeholder only |
| AI insights / assistant | Placeholders only |
| Employees | Placeholder only |
| Integrations / Pancake | Placeholder only |
| Settings | Placeholder only |
| Authentication | Not implemented; obsolete template auth UI was removed |
| Authorization / RBAC | Not implemented |
| Backend / database | Not present in this repository |

## 10. State and data management

**Verified**

- Global UI state uses React Context only:
  - `ThemeContext` — light/dark theme and `localStorage`
  - `LanguageContext` — selected language metadata, HTML `lang`/`dir`, and `localStorage`
  - `SidebarContext` — desktop/mobile sidebar state
- Feature state uses local React state and memoization.
- There is no Redux, Zustand, server-state library, or API cache.
- Mock data for KPS lives in `src/mocks/`.

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
- Daily report “AI analysis” is a timed UI simulation.
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
- There is no authentication, authorization, permission enforcement, or protected route handling. The unused TailAdmin sign-in/sign-up demos were removed to avoid implying otherwise.

### Planned / Business decision

- Intended internal hostname: a single subdomain such as `noibo.kimphucsac.com.vn`.
- Intended topology: one frontend, one backend, one database, and one authentication system.
- Role/permission checks should control menus, routes, and functions, while the backend must independently enforce every protected API action.
- Hiding a frontend menu is not security.
- Prefer permission-based authorization over hardcoding all behavior by role.
- Reference roles (not final requirements): `ADMIN`, `MANAGER`, `OFFICE_STAFF`, `SALES_STAFF`, `CSKH_STAFF`, `SPA_STAFF`.
- Revisit the catch-all Vercel rewrite if `/api` is later served on the same domain.
- Use mock data only until real customer-data handling has been approved. Production CRM data will require authentication, backend authorization, secure APIs/secrets, access controls, and appropriate auditability.

## 16. Pancake integration direction

**Planned / Business decision — not implemented**

The preferred flow is Pancake → KPS backend/integration → KPS database → CRM/AI/dashboard. The KPS database should remain the internal source of truth and should store external identifiers without making the domain schema depend on Pancake.

Prefer one-way Pancake-to-KPS synchronization first. Do not begin two-way synchronization without a separate design and approval.

## 17. Known technical debt and audit findings

1. `npm run build` passes. The KPS application chunk is about 448 KB (about 135 KB gzip), while the lazily loaded `react-apexcharts` vendor chunk remains large at about 926 KB (about 265 KB gzip).
2. `npm run lint` passes with four Fast Refresh warnings across the three context files. The warnings are intentionally retained because removing them would require reorganizing context exports.
3. There is no automated test suite.
4. Report submit, report history, dashboard filters, and AI analysis are not connected to services or persistent state.
5. Report-detail and CRM routes are placeholders.
6. Authentication and permission enforcement are absent.
7. Internationalization configuration and available resources are inconsistent, as described above.
8. Global brand tokens remain pink/magenta while the intended KPS primary color is brown; only sidebar-specific tokens consistently use brown.
9. The active notification dropdown still contains template-style mock people/content and sample avatars. It remains because it is part of the live header and requires a separate product decision rather than a cleanup deletion.
10. The active user dropdown contains no-op account/support actions and inline SVG icons; it requires functional/design review before modification.

The Phase 1 cleanup removed verified-unused demo routes, pages, components, assets, styles, translations, and dependencies without changing KPS business behavior.

## 18. Recommended next steps

1. Confirm the first CRM slice and answer only the business questions needed for that slice. A sensible first candidate is a read-only/mock customer list plus customer profile information architecture, but the owner model, customer-type model, status semantics, and permission visibility must be agreed first.
2. Before API work, define a narrow customer service interface and mock implementation so components do not import CRM mocks directly.
3. Decide whether the product is Vietnamese-only for now or must restore all four locale dictionaries; then align `i18n/index.ts`, `i18n/languages.ts`, and `LanguageContext.tsx`.
4. Schedule a separate maintenance task for the Fast Refresh warnings and large chart vendor chunk; do not mix that cleanup into the first CRM feature.
5. When backend work begins, define authentication/permission enforcement and data-security boundaries before using real customer data.

Do not implement CRM or change the sidebar until a concrete requirement is approved.
