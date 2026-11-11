# Spa frontend base

UI foundation in the existing KPS app, using option 1 and the shared theme. All
records are synthetic and read-only. This phase does not implement spa APIs,
database tables, migrations, clinical rules, payment, package redemption or uploads.

## Open the preview

Set `VITE_SPA_UI_PREVIEW_ENABLED=true` in the local/staging frontend environment,
then restart Vite or rebuild. The default in `.env.example` is false. Sign in
through the normal KPS login with a user whose navigation response includes
`settings`; open `/spa` or the **Spa · Bản mẫu** sidebar item.

The existing settings navigation permission is a temporary review gate only.
It is checked for both sidebar visibility and direct routes. Do not equate it
with approved spa RBAC, and do not add real customer data to this preview.
The technician shown is a fixed fictional persona, not the signed-in user.

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
results/images/handover. Before connecting actual data, replace the temporary
review gate with backend spa permissions and branch/assignment scopes, define
photo and approval rules, and implement authoritative state changes server-side.
