# Spa concept 1 — UI review scope

This iteration extends the Spa workspace in the existing KPS repository. It uses synthetic data in React memory; reload/reset discards all changes. There are no Spa API writes, database migrations, role/permission changes, seeds, outbound messages or real payments.

## Screen coverage

| Module             | Working preview interactions                                                                                                    |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------- |
| Overview           | Visit/service counts, receipts today, outstanding, stock/care alerts, quick links                                               |
| Appointments       | Customer/service/date search, ordered services, optional package, list and technician agenda, create/edit/cancel, visit history |
| Reception          | Check in, record receiver, assign/reassign each service to KTV/bed, conflict validation                                         |
| Customers          | Search/create/edit, notes, visit history, package balance, cashier link when authorized                                         |
| Services / retail  | Search, create/edit service, book service, sell product to customer                                                             |
| Packages           | Sell package, balance/expiry/history, book next session                                                                         |
| KTV                | Phone task/detail/back, four bottom tabs; tablet split view; start, draft result, complete, request/accept/withdraw handover    |
| Cashier            | Invoice detail, partial receipts, outstanding, receipt history, payment method, CSV                                             |
| Rooms / beds       | Day filter, occupancy and per-bed agenda, visit detail                                                                          |
| Inventory          | Search/low-stock filter, batch/expiry, stock in/out with reason, movement log, CSV                                              |
| Staff / commission | Synthetic roster, day attendance, assigned/completed counts, commission illustration                                            |
| Care               | Create follow-up job, due/channel/assignee, record result, open/done filter                                                     |
| Cashbook           | Actual receipt/expense ledger, in/out/net totals, expense form, CSV                                                             |
| Reports            | Day / 7 / 30 days, receipts vs invoices vs debt, cash-flow bars, KTV performance, CSV                                           |
| Settings           | Facility/hours/commission preview, read-only current KPS access, operating audit                                                |

Staff UI reuses the existing `settings` capability; cashbook reuses `cashier`. Both sidebar and route guard share this mapping. The backend workspace permission contract is unchanged. Only existing Owner/Admin/Assistant accounts are required to review this iteration; no new KTV account is created.

## Invariants demonstrated

- Booking does not consume package sessions. Completion consumes one session once; insufficient/expired/wrong-customer packages fail atomically.
- Once all services finish, one invoice contains only services outside packages. Retail/package sales create invoices, without inventing receipts.
- Receipts are positive integer VND, cannot predate their invoice or exceed balance. Reports use receipt date for actual cash and invoice date for billing.
- Retail sale posts stock-out. Stock-out cannot exceed available quantity and manual movements require a reason.
- Used service prices/durations remain fixed; historic task names/prices are snapshots.
- Reception, dispatch, KTV participants/final owner, completion actor and cashier are recorded separately.
- During handover the original KTV remains responsible; the recipient must accept. Incoming slots are reserved and rechecked on acceptance. Completed/history views retain participants.
- Cancelled appointments release resources; running/completed tasks cannot be cancelled or reassigned.
- Draft results stay only in memory, with a 600 ms autosave and flush on task/route switch. KTV UI contains no phone numbers, billing, payment or debt information.

## Review sequence

1. Open `/spa` with Spa preview enabled and an existing authorized account.
2. Create customer → book → receive → assign individual services and inspect the technician agenda/room agenda.
3. Open KTV; authorized reviewers can select a synthetic KTV. Start a service, enter result, complete or request/accept handover.
4. Check package history and invoice. Record a partial receipt, then compare cashier, cashbook and report totals.
5. Sell a retail item, compare inventory movement and unpaid invoice; try invalid overpayment/stock-out to see error focus.
6. Try care result, attendance and settings. Reload/reset and confirm the original fixture returns.

## Deliberate next-stage integrations

Persistent data and business APIs, facility/tenant isolation, server enforcement of final permissions, real KTV identity binding, skin-photo storage/consent, notifications/SMS/Zalo, financial reconciliation/refunds, tax invoices and payroll calculations remain backend/integration work. The UI labels these as a preview; the settings table does not edit access rights. Attendance and commission are operational illustrations, not HR/payroll records.

## Inline customer booking and skin-photo UI

Booking offers existing/new customer modes. New-customer fields stay inside the booking form; the profile and appointment are committed together only after all fields pass. Phones use the same normalization and uniqueness rules as customer management. A duplicate offers the existing profile in place, preserving date, services and notes. Cancelling or a failed booking creates no orphan customer.

Skin photos live in each service detail and are accessible from the customer profile for authorized preview viewers. The UI supports device-file selection and a mobile camera input, before/after groups, captions, enlargement and side-by-side comparison of photos from permitted sessions for the same customer. It records the operator, capture path and time. Uploading is not a new completion prerequisite in this iteration.

This remains a local preview, without a media API or persistent storage. The browser decodes JPEG/PNG/WebP, resizes the longest edge to at most 1600 pixels and re-encodes JPEG to discard source metadata. Limits: source 10 MB, preview 2 MB, 6 images per phase, 24 MB active images per session. HEIC/HEIF needs prior export to JPEG. No photos enter local/session storage or IndexedDB. Blob URLs are revoked on removal, reset and leaving the Spa workspace; in-flight work cannot repopulate a reset session.

Existing managers (`settings` capability) can manage photo records; a KTV can add to their assigned, checked-in service while not awaiting handover, and can remove their own images before completion with a reason. After completion KTV additions are restricted to the after group, and removal requires a manager. Previous participants retain access to their service history. Removal preserves operator, time and reason in the preview audit. These client checks demonstrate the intended UI; real storage and enforcement belong to the later backend integration. No role or database changes are introduced.

## Concept-one visual alignment (2026-11-13)

- Keep the existing KPS sidebar, logo block, workspace switching and backend capability gates. No design-studio variant picker or fake role selector in the application header.
- Spa-only header and heading composition; shared internal shell defaults remain unchanged. Global customer search routes to the customer list and query is preserved in the URL.
- Overview: four capability-filtered KPIs, hourly appointment chart, current in-progress bed occupancy, visit table and actionable care/stock/handover links. Selected day drives operational metrics; cumulative unpaid invoices are calculated as of that day.
- Calendar defaults to a time-by-technician grid with scroll contained inside the grid, variable service durations, overlap lanes and incoming handover reservations. Cancelled visits remain available in list/filter mode; create/edit returns to the list so the saved visit and edit/cancel actions are clear.
- Reception uses four columns; waiting combines unassigned and assigned tasks without losing the original six stage filters or per-service assignment buttons. State transitions retain the same pure command handlers.
- Customer cards use real preview session values for visits, receipts and active packages. Do not fabricate VIP tiers, acquisition sources or historical spend that the current Spa model does not contain. Expanded profile/history/photos remain available below the grid.
- KTV: summary counts, split list/detail on tablet, touch cards/back navigation on phone and a fixed four-item bottom bar that respects the existing sidebar on desktop. Photo controls, result drafts and handover confirmations are retained.
- No dependencies, backend changes, real KTV role, migration or environment changes. Synthetic customer data and images remain memory-only.
