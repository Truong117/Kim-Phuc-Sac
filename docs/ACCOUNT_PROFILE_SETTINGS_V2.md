# Account Profile and Settings V2

## Initial audit — 2026-10-31

Audited before implementation on `feature/kps-chat2` in `D:\DQT\KPS\KPS-Chat2`; the working tree was clean.

- `/profile` edits `users.name` and repeats a read-only email. `/account` repeats name/email and changes passwords. Both are authenticated self-service routes, without administrative permission requirements.
- Authentication uses Sanctum stateful cookies, the `web` guard, CSRF, and an active default KPS organization membership. Login accepts **email only**, normalized to lowercase. There is no username column or separate employee model.
- `users.email` has a global unique index. Administrative validation also checks case-insensitive uniqueness. Identity is global across organization memberships; changing email must never create another identity or change user IDs/memberships.
- Existing data includes name/email/password and nullable `email_verified_at`. Login does not require email verification. HR/admin can change name/email through guarded User Management endpoints. Organization, department, location, and role are membership data and remain company managed.
- No phone, avatar storage, employee position, password recovery, MFA, or self-service email verification workflow exists. These are separate product/schema decisions, not fields to invent in this redesign.
- Existing profile validation rejects company-managed fields and explicitly selects the name for persistence. Password changes require the current password. Both currently overwrite without a concurrency precondition; password changes have no dedicated sensitive-operation throttle.
- Existing PHPUnit coverage includes self-service name/password, restricted-field rejection, active membership, login, and RBAC. Frontend tests are Node contract tests; there is no DOM testing library.
- The checked-out application uses `src/locales/vi/common.json` and single-brace interpolation. Some older AGENTS.md stack/locale notes describe the earlier baseline; V2 follows the current source and does not introduce another language.
- Risks: changing a login email prematurely can lock out users; global uniqueness must be enforced again at confirmation; old links must not override later HR edits/password resets; emails and verification tokens must not appear in normal identity payloads or browser query strings.

## Login identifier decision

| Option                        | Benefits                                                                          | Costs / compatibility                                                                                   |
| ----------------------------- | --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| A. Stable username            | Independent of mailbox changes                                                    | No username exists; requires provisioning, unique constraints, recovery policy, and login/API migration |
| B. Email                      | Existing account/admin/login contract; mailbox ownership supports future recovery | Requires secure email changes; legacy addresses are not necessarily verified                            |
| C. Username or verified email | Flexible sign-in                                                                  | Requires username migration, collision rules, two identifiers, and additional support/UI complexity     |

**Recommendation:** retain the existing email-only contract. Verify each newly requested address before replacing the current login email. Do not require verification of legacy emails at login or migrate identifiers in this change. A future username/dual-login or mandatory verification policy needs explicit approval, including employee provisioning, recovery, global versus tenant uniqueness, and future spa/agency identity rules. No such migration is authorized here.

## Implementation design

- Profile owns the editable display name and read-only work context. Account owns login email, verified email changes, and passwords. Cross-page navigation describes these responsibilities.
- Email requests and confirmations require the authenticated employee's current password. No target user ID is accepted. Requests replace earlier requests; cancellation invalidates the pending token.
- An additive table stores one pending request per user, a SHA-256 digest of a random 256-bit token, original email, password fingerprint, target email, and expiry. No existing user row is backfilled or changed by the migration.
- A verification link goes to the configured frontend `/account` with the token in the fragment. Visiting the link never mutates data. The authenticated UI removes the fragment and requires an explicit confirmation POST with password and token.
- Requests accept well-formed addresses without an availability oracle. Global case-insensitive uniqueness is checked at confirmation after mailbox ownership is proven; the existing unique index remains the final concurrent-write guard. Unavailable, expired, stale, and replayed confirmations use the same safe error.
- Confirmation locks the actor's user row and pending request, checks expiry, token, original email and password fingerprint, then changes email and verification time atomically and consumes the request. Sessions, passwords, user ID, and RBAC remain intact. An intervening HR email change/password change invalidates the request.
- A narrow User model invariant clears the previous mailbox's verification timestamp whenever a model-based email edit replaces its address, including existing HR edits. The self-service flow sets the timestamp only after assigning the proven address. Login still accepts legacy unverified accounts.
- Laravel notifications deliver verification and old-address security notices. Tests fake notifications and use SQLite `:memory:`. Staging/production data and `backend/.env` are untouched.

## Migration safety review

The new migration creates only an independent pending-request table with a unique user foreign key and cascade cleanup. It does not alter `users`, rewrite email, add mandatory verification, or backfill employee data. `down()` removes pending requests only; it never restores or deletes users. Apply only through the existing reviewed release process. During this task migrations run exclusively in isolated PHPUnit databases.

The exact empty-database bootstrap manifest is extended from 21 to 22 files and its exact-count test is updated. This follows the established additive-migration convention and changes the computed approval fingerprint, so prior approvals cannot silently cover the new migration. Incremental staging execution allowlists, deployment commands, and all environment targets are unchanged. No bootstrap or staging execution was performed.

## API and operations

All routes below use the existing Sanctum authentication, active KPS membership, and CSRF middleware. They operate only on the authenticated user; no administrative permission is needed.

| Method / path                            | Behavior                                                                                                                                                   |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /api/account/email-change`          | Own verification flag and pending address/expiry; `no-store, private`; never returns a token                                                               |
| `POST /api/account/email-change`         | `email`, `current_password`; always returns generic `202` for valid, password-confirmed requests regardless of availability; 3/minute and 12/hour per user |
| `POST /api/account/email-change/confirm` | `token`, `current_password`; consumes only a matching live request; 6/minute per user                                                                      |
| `DELETE /api/account/email-change`       | Cancels the current user's pending request; 6/minute per user                                                                                              |
| `PATCH /api/account/profile`             | Existing name contract plus optional `expected_name`; V2 sends it and stale edits receive `409`; old clients remain supported                              |
| `PATCH /api/account/password`            | Existing contract, with a password recheck under the user lock and pending-request invalidation; 6/minute per user                                         |

Both new notifications use Laravel's queued mail channel, encrypted job payloads, after-commit dispatch, and three delivery attempts. Configure the existing `FRONTEND_URL` to the canonical HTTPS frontend and a real mail transport/sender. Run the existing queue worker and monitor failed jobs; the default log transport does not deliver mail. Mail/queue delivery is an operational dependency, not evidence provided by unit tests. A queue dispatch or transport failure can require a new request or operator retry; confirmation itself is committed before after-commit delivery. No credentials or runtime environment files were changed for this task.

New emails become the login address only after ownership proof. They are globally unique, matching the existing user model; pending requests do not reserve addresses. Duplicates are deliberately rejected with the same confirmation error as stale/invalid tokens, after proof, rather than through a mailbox availability lookup. Existing unverified accounts can still log in. Password recovery and MFA remain separate work.

## Validation and delivery

| Check                        | Executed result                                                                                          |
| ---------------------------- | -------------------------------------------------------------------------------------------------------- |
| `npm test`                   | PASS — 250 tests, including email service/parser/locale, form rendering, and navigation guard tests      |
| `php vendor/bin/phpunit`     | PASS — 658 tests, 6,039 assertions; SQLite `:memory:`, synthetic users, fake notifications; no real mail |
| `npm run lint`               | PASS — zero errors; four existing Fast Refresh warnings in unchanged context files                       |
| `npm run build`              | PASS — TypeScript and Vite; existing large-bundle advisory remains                                       |
| `php vendor/bin/pint --test` | PASS — full backend formatting check                                                                     |
| Changed-file Prettier        | PASS — changed frontend, tests, and report                                                               |
| `git diff --check`           | PASS                                                                                                     |

Coverage includes own-profile view/name edits, company-managed field rejection, stale-name conflicts, all existing self-service roles, valid/invalid/duplicate addresses, unverified pending addresses, expiry/replay/replacement/cancellation, wrong password, cross-user attempts, HR email edits, password reset invalidation, CSRF, throttling, successful verification and old-address notification, session continuity, login/RBAC regression, and additive migration rollback safety.

The first full backend run correctly rejected the new migration against the old exact bootstrap manifest. After extending that manifest, the migration-21 test fixture was pinned to its original 21-file release and explicitly proves the 22nd migration does not broaden that release's approval. The final full run above is green; staging execution code was not widened.

## Backward compatibility and remaining issues

- Login stays email-only; no legacy user is forced to verify, renamed, re-provisioned, logged out by an email change, or assigned new permissions. Auth response shape and existing profile/password endpoint contracts remain intact. New rate limits intentionally constrain sensitive operations.
- Deploy the additive migration before the application version that references its table. Rolling it back discards pending requests but keeps users and already-confirmed emails. No migration was run on local employee data, staging, or production.
- Configure and validate mail transport, canonical frontend URL, queue worker, and failed-job monitoring during a separately authorized release. Real email delivery and MySQL/TiDB concurrent stress behavior were not exercised; isolated transaction/uniqueness behavior was tested on SQLite.
- Live visual/responsive/browser interaction QA could not run: Browser discovery returned no available browser. Server-rendered form tests verify labels, request/confirmation modes, required inputs, and disabled saving controls; these do not prove pixel layout or full browser interaction.
- Unsaved-change prompts cover ordinary link navigation and browser unload/reload. Browser history traversal and programmatic redirects within the existing BrowserRouter are not blocked. Passwords and verification tokens are never persisted as drafts; reopening the mailed link recovers the confirmation flow.
- Avatar upload, phone, employee position, MFA, password recovery, and username/dual-identifier migration remain separate decisions. No unsupported profile fields or new UI/auth dependencies were added.

**Status: READY TO REVIEW.** Changes are committed on `feature/kps-chat2`; no push, merge, or deployment is authorized or performed.
