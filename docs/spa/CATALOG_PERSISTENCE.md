# Spa catalog persistence V1

This slice persists **only service and retail-product definitions**. It does not persist appointments, customers, package use, photos, stock, sales, receipts or payments. The saved catalog is deliberately separate from the synthetic operations dataset until those operations have real APIs. No sample records are copied into the database.

## Access and rollout

- Reuses Sanctum, active default KPS membership and existing `spa.services.manage` with `ORGANIZATION`/`ALL` scope. Owner/Admin/Executive Assistant already have those grants. No roles, assignments or grants are changed. A location-only Spa manager cannot access this organization-wide catalog yet.
- `SPA_CATALOG_ENABLED=false` is the default in backend configuration. The frontend reads `/api/spa/catalog/status`; an explicitly disabled catalog retains the interactive preview. Network/auth/migration errors never silently save to preview instead.
- Apply `2026_11_14_000000_create_spa_catalog_items.php` on the verified staging database using the existing migration operator. Do not run migrations on startup or use a production database from local/test. Review table/index/FK creation and ledger before enabling.
- Set **backend** `SPA_CATALOG_ENABLED=true`, rebuild/restart backend configuration as appropriate and deploy the frontend. Keep the existing `VITE_SPA_UI_PREVIEW_ENABLED=true`; there is no additional frontend catalog flag.
- Test staging with Owner/Admin/Assistant: create a service/product, reload, edit, reload again. Check empty catalog, duplicate names, invalid fields, denied accounts, stale versions, API failure and a response lost after save. Resetting the preview must leave saved catalog records intact.
- Production requires the same reviewed migration and activation on its own environment after staging acceptance. No remote migration or rollout is performed by this code change.
- Disabling the backend flag hides the saved-catalog UI without deleting records. Migration rollback refuses to drop a populated table.

## Contract

`GET /api/spa/catalog/status` returns `{enabled, can_manage}`. The remaining endpoints require organization-wide management scope and an enabled/migrated catalog:

- `GET /api/spa/catalog?kind=service|product&search=&page=1`: 20 rows per page, `data` plus `meta.current_page/last_page/total`; organization filter is always derived from membership.
- `POST /api/spa/catalog`: stable client UUID `id`, `kind`, `name`, integer VND `price`; products require `unit`; services require `duration_minutes` (15–180 in 15-minute steps), boolean `requires_skin_photo`, optional `group/description`.
- `PATCH /api/spa/catalog/{uuid}`: same definition fields plus expected `version`, without `id`. A stale version/type change returns 409. A foreign organization/unknown ID returns 404.
- `organization_id`, actor IDs and `stock` cannot be supplied by clients. Writes have throttles, database uniqueness on organization/kind/normalized-name, transactions, creator/updater identities and a version counter. No delete endpoint in V1.
- Retrying the same completed create UUID with the same actor/unchanged fields returns the existing row. A conflicting UUID is rejected; the browser never automatically retries writes.
- Responses are `private, no-store`. Errors retain form values; concurrent edits require reloading rather than overwriting another user's version.

The table is `spa_catalog_items`. Names and service/product definitions are shared within KPS for this first rollout; branch-specific pricing/catalog and customer-level scope are future work.

Catalog unit prices use a text input with a numeric mobile keyboard and Vietnamese thousand separators (e.g. `1000000` displays as `1.000.000`). Forms retain unformatted digits and submit integer VND; existing backend limits still apply. The saved-catalog dialog identifies server storage instead of displaying the preview-only subtitle. This input change needs no migration or environment-variable change.
