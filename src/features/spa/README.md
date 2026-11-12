# Spa frontend base

UI foundation in the existing KPS app, using option 1 and the shared theme. All
records are synthetic. Customer, booking and dispatch edits live only in the current
Spa route session; no business data is sent to an API or stored in browser storage. This phase does not implement spa APIs,
database tables, migrations, clinical rules, payment, package redemption or uploads.

## Open the preview

Set `VITE_SPA_UI_PREVIEW_ENABLED=true` in the local/staging frontend environment,
then restart Vite or rebuild. The default in `.env.example` is false. Deploy
both frontend and backend to staging before checking workspace switching.

The same KPS login/session is used throughout. `GET /api/workspaces` (Sanctum +
active KPS membership) returns workspace IDs, validated home paths and Spa screen
keys. No display role names are read by the frontend. Existing office roles keep
Internal; existing Spa roles use Spa. Owner/Admin/Executive Assistant receive both
through their existing Spa grants. The temporary `settings` preview gate and
Internal sidebar Spa item have been removed.

| Existing role / capability                                   | Workspace | Spa landing       |
| ------------------------------------------------------------ | --------- | ----------------- |
| Office roles without Spa grants                              | Internal  | —                 |
| Owner / Admin / Executive Assistant with existing Spa grants | Both      | `/spa`            |
| KPS Spa manager (`spa.services.manage`)                      | Spa       | `/spa`            |
| Existing KPS Spa staff / appointment management              | Spa       | `/spa/reception`  |
| Dedicated Spa technician (`spa.technician.view`)             | Spa       | `/spa/technician` |

For dedicated job roles, run this **additive, idempotent** provisioning command
against staging through the existing approved DB tooling (no schema migration):

```sh
php artisan db:seed --class=WorkspacePermissionSeeder --force
```

It creates `KPS_SPA_TECHNICIAN`, `KPS_SPA_RECEPTION`, `KPS_SPA_DISPATCH` and their
minimal UI grants; it never assigns users or rewrites existing role grants.
Assign the appropriate role via existing employee management afterward. Existing
`KPS_SPA_STAFF` remains a generic reception role; it is not silently relabeled KTV.
For dual-duty roles, explicitly grant `workspace.internal.access` in addition to
Spa rights, or assign both existing roles through the membership model. There is
no new multi-role editing UI in this change.

The workspace preference is device-local and keyed by user ID. It grants no
rights. Authorized deep links override the preference; first login with both
workspaces defaults to Internal. Invalid/revoked preferences fall back to an
allowed workspace. `/workspace` re-runs the resolver. Links and browser history
continue through the existing navigation safety mechanism. Profile/account have
Spa aliases so visiting them keeps the Spa shell.

A missing workspace endpoint (404 during a rolling deploy) permits only the
legacy Internal shell; other errors or invalid capability payloads fail closed.
The frontend flag must be enabled for Spa to appear. Turning it off removes Spa
from available workspaces even if the backend returns Spa access.

These are **UI workspace capabilities**, not finalized business RBAC. Existing
backend business endpoint permissions/scopes remain authoritative. Spa records
are fictional. Managers can choose a demo technician to inspect dispatch results;
this never changes login identity or permissions. No real customer data may be added.

## Structure

- `config.ts`: feature flag and module navigation.
- `types/`: appointments and service tasks with separate status types.
- `mocks/`: isolated demonstration records, never actual customer information.
- `services/`: typed read-only repository; replace with an API adapter later.
- `hooks/`: snapshot access; no global provider or browser storage.
- `selectors.ts`: filtering, overview counts and assigned technician tasks.
- `components/`: spa layout and small domain components; reuse KPS primitives.
- `src/pages/Spa/`: route entry pages, metadata and page composition.
- `src/App.tsx`: the only route registration point.
- `src/locales/vi/common.json`: current Vietnamese `spa` dictionary.

## Customer → booking → reception → technician preview

- Customers: search by unaccented name/phone, add/edit mock contact records,
  duplicate normalized-phone check, and customer-specific booking/service history.
- Bookings: choose an existing customer, date/time and up to five ordered services;
  create/edit a booked visit, inspect details or cancel with a required reason.
  Editing resets service assignments so they can be dispatched again.
- Reception: filter one day by queue stage/name/code, confirm arrival, then assign
  or reassign each service to a demo technician and room/bed. An unassigned service
  is distinct from an assigned one; appointment status remains separate.
- Assignment rejects overlapping technician, bed or customer slots within the same
  date; back-to-back intervals and other dates are independent. Cancelled visits
  release resource slots. Running/completed services cannot be cancelled/reassigned.
- Details show the receiving person, dispatcher and temporary action history using
  the signed-in reviewer's name. This is a UI demonstration, not a trusted audit log.
- KTV Today shows arrived visits on the selected day; Schedule also includes booked
  visits. Managers may select a demo persona and open its tasks directly from dispatch.
  Phone stays one pane at a time, tablet supports list/detail.

`SpaLayout` owns a single feature-local in-memory session, provided through its
existing router Outlet context to all Spa pages. It resets on reload, logout,
leaving Spa, or the explicit reset action. No localStorage/sessionStorage/IndexedDB
business records, global module state, mock API writes or extra global providers.
Workspace preference storage remains unchanged and separate. Form validation retains
values and focuses the invalid field; dirty forms use the existing navigation guard.

All other modules remain foundations. Payment, KTV start/finish, result/photos and
handover actions are still pending; the image requirement is only displayed.

## Checks and next steps

Run `npm test`, `npm run build`, and the isolated browser suite:

```sh
npx playwright test --config playwright.report-validation.config.ts spa-ui.spec.ts
SPA_UI_TEST_ENABLED=false npx playwright test --config playwright.report-validation.config.ts spa-ui.spec.ts
```

Restart the preview server between flag modes; do not reuse a running server
with a different flag. Browser tests intercept all API requests, including KPS
authentication; production source contains no mock login or auth bypass.

Next UI slices are technician start/finish, results/images/handover, then cashier. Before connecting actual data, complete business spa permissions and branch/assignment scopes, define
photo and approval rules, and implement authoritative state changes server-side.

## Staging review (Owner / Admin / Assistant)

This UI slice adds no backend changes, schema migration, seed, role or package.
Keep the existing workspace capabilities and frontend preview flag. Test with an
existing reviewer account; do not provision KTV roles for this review.

1. Open Customers, add a clearly fictional contact with a test phone number.
2. Choose Book, add services and save; the new booking appears in Appointments.
3. Open Reception, confirm arrival, assign KTV and bed. Test an occupied 09:00 slot
   against the seeded Lan Anh / room 01 assignment to inspect conflict feedback.
4. Open the assigned technician view and verify the new task, receiver/dispatcher
   in booking details; try reassigning before service start.
5. Cancel a not-yet-started visit with a reason; check its history and released slot.
6. Reload or leave Spa to confirm the temporary data disappears.
