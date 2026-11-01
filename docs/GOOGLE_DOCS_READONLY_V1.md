# Google Docs Read-only Integration V1

## Scope and current integration status

The provider-neutral reader and Service Account transports are now connected to an organization-scoped, read-only preview endpoint. The endpoint returns only safe metadata and counts; it does not persist a snapshot, create a Weekly Plan or entry, assign a KPS user, call OpenAI, or create a Task.

Access is controlled by the server-side organization-folder registry described in [`GOOGLE_DOCS_ORGANIZATION_AUTHORIZATION.md`](GOOGLE_DOCS_ORGANIZATION_AUTHORIZATION.md). A caller-supplied document ID or Service Account Viewer access is never sufficient authorization.

## Reader architecture

`GoogleDocsDocumentReader` depends on the small `GoogleDocsApiClient` contract. A future transport adapter must:

1. authenticate only in the backend with a Google Cloud service account;
2. use only `https://www.googleapis.com/auth/documents.readonly`;
3. call the fixed Google Docs `documents.get` endpoint by validated document ID;
4. set `includeTabsContent=true`;
5. verify TLS and enforce the supplied timeout and response-byte limit before fully buffering a response;
6. return a decoded array or throw `GoogleDocsApiException` with only the HTTP status.

The reader independently validates the decoded response and applies limits for response bytes, tab count, nesting depth, passage count, total characters and characters per passage. Overflow is rejected; content is never silently truncated.

`GoogleDocsDocumentReference` accepts only canonical HTTPS URLs on `docs.google.com` in `/document/d/{documentId}` form, or a document ID matching the allowed identifier format. It never sends a request to a user-supplied host.

## Snapshot and passage mapping

Tabs are traversed parent-first in document order. Each preview contains:

- document ID, title and optional revision ID;
- a snapshot SHA-256 derived from document ID and revision ID (or the complete decoded response when no revision is supplied);
- tab ID, title, parent ID, index, nesting level, document order and passage count;
- ordered paragraph, list-item and table-row passages.

Every passage has:

- `source_key`: `gdoc:{snapshot}:{tab}:{sequence}`, unique and stable within the same snapshot;
- global `sequence`, `kind`, normalized `text` and `content_sha256`;
- `metadata.tab_id` and `metadata.tab_title`;
- provider-neutral `source_context` and `source_group` metadata;
- `source_group.origin=GOOGLE_DOCS_TAB` and `identity_verified=false`.

A tab title is untrusted document metadata. Duplicate tab titles remain distinct because group identity is based on document ID plus tab ID. The reader never emits `assignee_id` and never matches a title to a KPS account.

## Read-only preview contract

The current output is an in-memory preview suitable for a future scoped service to show tab names, tab hierarchy and passage counts. It performs no database or storage writes. Document text, provider payloads and credentials are not logged.

Provider failures are normalized to safe internal codes:

| Provider result | Reader code | Retryable |
| --- | --- | --- |
| 401 / 403 | `GOOGLE_DOCS_ACCESS_DENIED` | no |
| 404 | `GOOGLE_DOCS_NOT_FOUND` | no |
| 429 | `GOOGLE_DOCS_RATE_LIMITED` | yes |
| 5xx | `GOOGLE_DOCS_UNAVAILABLE` | yes |
| malformed or unsupported structure | `GOOGLE_DOCS_MALFORMED_RESPONSE` | no |
| configured limit exceeded | `GOOGLE_DOCS_LIMIT_EXCEEDED` | no |

Frontend-facing messages should remain generic. Provider response bodies, document content, document IDs, service-account data and tokens must not be included in logs or public errors.

## Organization boundary

Concrete Google transports and the database-backed approved-folder registry are bound in the backend container. Before an ADMIN can approve or replace a registry value, Drive metadata must verify the requested ID as an accessible `application/vnd.google-apps.folder`; verification failure never writes registry or audit data. The preview endpoint rejects arbitrary document IDs, cross-organization registry access, inactive membership, unsupported permission scope and documents whose exact direct parent is not the organization's active approved folder.

Migration 15 and the new permission are implemented but have not been applied outside automated SQLite tests. Therefore the feature remains operationally disabled until the controlled migration, permission seed and backend-only credential setup are approved.

## Configuration limits

The reader and transport provide safe defaults in `backend/config/google_docs.php`:

- `GOOGLE_DOCS_TIMEOUT_SECONDS=15`
- `GOOGLE_DOCS_MAX_RESPONSE_BYTES=5242880`
- `GOOGLE_DOCS_MAX_TABS=100`
- `GOOGLE_DOCS_MAX_NESTING_DEPTH=10`
- `GOOGLE_DOCS_MAX_PASSAGES=500`
- `GOOGLE_DOCS_MAX_CHARACTERS=200000`
- `GOOGLE_DOCS_MAX_PASSAGE_CHARACTERS=10000`
- `GOOGLE_SERVICE_ACCOUNT_CREDENTIALS_MAX_BYTES=65536`
- `GOOGLE_DRIVE_METADATA_MAX_RESPONSE_BYTES=65536`
- `GOOGLE_DOCS_PREVIEW_PER_MINUTE=4`

The transport is disabled while `GOOGLE_SERVICE_ACCOUNT_CREDENTIALS_PATH` is empty. The configured file must exist outside the repository and must contain a service-account credential using Google's fixed OAuth token endpoint. Do not place service-account JSON in an environment variable exposed to Vite or in a tracked file.

## Dependency

The official `google/apiclient` package is locked at the Composer-compatible `^2.20` series. It supplies service-account authentication and official read-only scope constants. Composer also adds `google/auth`, `google/apiclient-services`, `firebase/php-jwt` and `psr/cache`; existing Guzzle packages remain in their supported current series.

Automated tests inject mock HTTP/authentication clients and never call Google.

## Next approved integration step

1. Review and apply only migration 15 on isolated Staging using the migration operator.
2. Run the additive Weekly Planning permission seeder and verify the ADMIN-only grant.
3. Create a least-privilege Service Account and share only a synthetic Staging folder as Viewer.
4. Mount credentials as a backend-only secret in an isolated non-production environment.
5. Configure the approved folder through the protected internal endpoint.
6. Smoke-test one synthetic direct-parent Google Doc and the fail-closed cases; do not use production personnel data.
