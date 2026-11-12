# Spa frontend base

UI foundation in the existing KPS app, using option 1 and the shared theme. All
records are synthetic and read-only. This phase does not implement spa APIs,
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

| Existing role / capability | Workspace | Spa landing |
| --- | --- | --- |
| Office roles without Spa grants | Internal | — |
| Owner / Admin / Executive Assistant with existing Spa grants | Both | `/spa` |
| KPS Spa manager (`spa.services.manage`) | Spa | `/spa` |
| Existing KPS Spa staff / appointment management | Spa | `/spa/reception` |
| Dedicated Spa technician (`spa.technician.view`) | Spa | `/spa/technician` |

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
are fictional and read-only; the technician shown is a fixed fictional persona,
not the signed-in user's real assignments. No real customer data may be added.

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

`/spa` has a mock overview; `/spa/appointments` supports search/status filtering;
`/spa/technician` supports task selection, mobile back navigation, and four views.
The ten remaining sections are explicitly marked as screen foundations, not
completed features. Technician detail uses a list/detail layout on tablet and
one pane at a time on phone. It shows the service's image requirement but cannot
capture or persist images. The one-photo versus before/after rule is not decided.

## Checks and next steps

Run `npm test`, `npm run build`, and the isolated browser suite:

```sh
npx playwright test --config playwright.report-validation.config.ts spa-ui.spec.ts
SPA_UI_TEST_ENABLED=false npx playwright test --config playwright.report-validation.config.ts spa-ui.spec.ts
```

Restart the preview server between flag modes; do not reuse a running server
with a different flag. Browser tests intercept all API requests, including KPS
authentication; production source contains no mock login or auth bypass.

Next UI slices are customer/booking forms, reception/dispatch, then technician
results/images/handover. Before connecting actual data, complete business spa permissions and branch/assignment scopes, define
photo and approval rules, and implement authoritative state changes server-side.
