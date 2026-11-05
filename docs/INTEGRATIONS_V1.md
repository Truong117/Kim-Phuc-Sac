# Integration directory V1

The existing `/integrations` page now stores real organization-owned directory entries. It does not call Google Drive, social providers or Ads APIs. It does not change the existing weekly-planning Google Docs approved-folder registry. Enabling a directory entry is a local business setting, not API consent or approval of a weekly-plan source.

## Access

All routes require Sanctum authentication, active default KPS membership and `integrations.view`. POST/PATCH/DELETE additionally require `integrations.manage` and are throttled. Existing AuthorizationSeeder grants OWNER/ADMIN view and manage, EXECUTIVE_ASSISTANT view only; no new role grants or broad authorization reseeding are required.

The backend derives organization identity from the active KPS membership. The client cannot choose an organization. Foreign IDs return 404. Responsible users must be active default KPS members; references return only IDs and names, without HR/email data.

## API

- GET `/api/integrations`: `{data: [...], actions: {can_manage: boolean}}`.
- GET `/api/integrations/references`: `{data: [{id, name}]}` for owner selection.
- GET `/api/integrations/{id}`: scoped detail.
- POST `/api/integrations`: creates an entry, returns 201.
- PATCH `/api/integrations/{id}`: updates an entry, requires current `version`.
- DELETE `/api/integrations/{id}`: JSON `{version}`, returns 204.

Write fields: `kind` (`drive`/`social`), nullable `platform` (TikTok/Facebook/Zalo OA/Meta Ads codes), `name`, `url`, `responsible_user_id`, `note`, `enabled`. Kind is immutable. Responses use string IDs, an integer version, responsible display name and `connection_status: not_connected`. Every successful edit increments version; stale edit/delete returns 409. Invalid fields/duplicates return 422. Organization, URL hash, audit actor IDs and connection state are backend-owned.

HTTPS and provider-domain validation runs in the backend. Credential-bearing URLs and token query parameters are rejected. Duplicate identity ignores tracking parameters while preserving case-sensitive Drive IDs and account IDs. There is an organization/kind/URL-hash unique constraint; writes lock the organization and entry. Directory capacity is 500 entries per organization, allowing the existing client search/filter over a bounded full list.

## Rollout

1. Merge/review in staging first. Apply `2026_11_05_000000_create_integration_entries_table` through the normal migration tool and approved migration procedure.
2. Deploy backend, then frontend. Test OWNER/ADMIN CRUD and assistant read-only access on `/integrations`.
3. Check create -> reload / another authorized browser -> edit -> stale edit/delete -> delete. Verify responsible options and invalid/duplicate links.
4. Repeat the approved process for production. This branch does not execute live migrations, deploy or change credentials.

Existing browser demo records are not imported or uploaded automatically. The real directory starts empty. Existing weekly-plan approved-folder rows remain unchanged; this feature does not activate a source or revoke one when its directory metadata changes/deletes.

## Verification

`IntegrationManagementTest` covers CRUD/shared visibility, permission enforcement, inactive/foreign membership, organization isolation, assignment validation, URL validation/duplicates, stale writes/deletes and absence of provider HTTP calls.

`e2e/integrations-persistence-ui.spec.ts` covers API-backed reload/CRUD, read-only controls, validation focus, conflict preservation and a 390px viewport using intercepted API responses. These UI fixtures do not replace backend database tests. Included in the existing `playwright.report-validation.config.ts` CI browser checks.
