# Weekly Planning V3 Phase 7B.2A browser E2E evidence

## Scope and navigation decision

Final fixes continue on `feature/weekly-planning-v3-browser-e2e` from `34bea57a1a77ae34481de298ddbaa3ae9ae370b5`. Its base is the PR #70 staging merge `ad6cb35398c5b88515889ce7824f69dd2d4e427b`. PR-head CI run `41163894141` succeeded; merge-commit CI was not separately verified.

Installed React Router is 8.3.1 in Declarative BrowserRouter mode. The installed `useBlocker` implementation requires Data Router context. The fix keeps BrowserRouter, the complete route table, ProtectedRoute, AuthorizationRoute, and existing navigation APIs. No Data Router migration or dependency installation was needed.

The former route-component popstate listener attempted to cancel a browser traversal using preventDefault and restore history afterward. popstate is not cancellable, and that listener did not control BrowserRouter's subscription. The router could consume the traversal and unmount the workspace before the route guard protected it. The dedicated pre-fix Chromium test recorded zero prompts, one mutation, and the list URL instead of the review URL.

`createWeeklyPlanV3NavigationWindow()` uses BrowserRouter's supported window prop to wrap only its popstate subscription. With an unresolved request, the wrapper asks for confirmation before delegating a POP to React Router. Stay withholds the router callback, reverses the native traversal by the existing history idx delta, and suppresses the restoration callback. Leave delegates once. It creates no sentinel or duplicate history entries. Without a guard, the callback is delegated unchanged.

The existing parent owns separate Assistant and OWNER pending requests. Its existing pending-state hook registers/removes the guard; tab switches do not navigate or retry. Internal Link clicks retain their capture guard, and native beforeunload protects reload/document departure. External document links use native unload protection without an extra custom click prompt. SessionStorage recovery and immutable payloads remain intact. Unknown, non-router history entries fall back to document replacement at the protected URL and sessionStorage recovery; managed indexed app history is the browser-verified path.

## Isolation and commands

Playwright Test remains pinned at 1.64.1; actual Chromium version is `156.0.8078.4`. Vite binds only `127.0.0.1:5174`, Laravel only `127.0.0.1:8001`. Copy the reviewed example to `backend/.env.weekly-planning-v3-e2e`; never replace `backend/.env`.

Run `npm run test:e2e`, `npm run test:e2e -- navigation.spec.ts`, and `npm run test:e2e:disabled`. Each invocation creates a fresh verified SQLite fixture, starts hidden local processes, stops its own PIDs, and cleans only the canonical disposable root. Every completed final invocation reported cleanup PASS.

Setup, preflight, and the HTTP router explicitly select the E2E environment file before Laravel bootstrap, preventing fallback reads from backend/.env. Before writes, the guards check effective Laravel database/storage/session/cache/log configuration, canonical paths, configuration cache absence, loopback origins, inherited overrides, and the database application ID plus fixture marker. The HTTP router performs the same verification in its own request process rather than booting a second PHP process per request. Static-file handling stays inside public and never bypasses the guard for PHP files.

The disabled run uses a closed local scenario that changes only the V3 feature flag to false after effective database configuration passes the guard. It does not change the approved profile's database configuration. The security fixture CLI accepts only fixed operations against one fixed synthetic account: restore, wrong organization, membership revocation, role/permission revocation, and session invalidation. It repeats preflight and runtime checks before writes, accepts no arbitrary IDs or SQL, and is not an HTTP database API.

Security HTTP probes run in Chromium on a static local test page without application background timers. This makes membership invalidation deterministic while retaining real Laravel authorization, CSRF, sessions, and HTTP responses. Login helpers respect actual 429 Retry-After responses; no middleware or limiter is disabled.

## Before and after evidence

| Evidence                                    | Result | Observed state                                                                                                                                                              |
| ------------------------------------------- | ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Dedicated pre-fix native Back test          | FAIL   | Chromium 156.0.8078.4, prompts 0, URL `/weekly-plans`, mutations 1.                                                                                                         |
| Final managed Back/Forward/Link/reload test | PASS   | Ten prompts for ten departure attempts; native beforeunload occurs twice; exact replay gives two HTTP mutations but one committed batch, zero Tasks and zero notifications. |
| Fresh-fixture navigation repetitions        | PASS   | Dedicated two-test runs and the full-suite run each use fresh Chromium contexts and fresh SQLite fixtures. No retry-on-failure is configured.                               |

Redacted records are committed in `e2e/evidence/navigation-before-fix.json` and `navigation-after-fix.json`. The after record includes actual batch IDs, browser version, prompt types, and scoped Task/notification counts. Local synthetic screenshots are `test-results/evidence/before-fix/browser-back-left-workspace.png`, `navigation-stay-after-fix.png`, and `v3-published-synthetic.png`. The original failing trace is preserved locally at `test-results/evidence/before-fix/trace.zip`; raw traces can contain synthetic cookies/login requests and must be reviewed before sharing. Artifacts and reports are ignored by Git.

## Chromium coverage matrix

| Scenario                                                | Result | Browser and database evidence                                                                                                                                                 |
| ------------------------------------------------------- | ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Back and Forward: Stay, Leave, sequential attempts      | PASS   | Real native history traversal, explicit dialogs, URL/workspace/index restoration, unchanged history length and immutable pending payload.                                     |
| Internal Link: Stay and Leave                           | PASS   | Stay retains workspace; Leave reaches list; return restores pending state without mutation.                                                                                   |
| Native unload and reload recovery                       | PASS   | Chromium beforeunload Stay cancels reload; Leave reloads; sessionStorage restores original request and confirmation snapshot.                                                 |
| No pending / successful reconciliation                  | PASS   | Initial navigation works normally; after exact HTTP 200 reconciliation, navigation produces no warning.                                                                       |
| Independent Assistant and OWNER pending requests        | PASS   | Multi-role viewer has two distinct request IDs; tabs and Back preserve both; reconciling OWNER clears only OWNER, then Assistant reconciles separately.                       |
| No automatic or duplicate mutation                      | PASS   | Navigation does not increment request counts. Exact retries preserve key and byte-identical payload; database batch and Task counts remain stable.                            |
| V3 disabled                                             | PASS   | Separate disabled-process run creates default V2; V3 selector is absent and V3 mutation returns 409 V3_WORKFLOW_DISABLED.                                                     |
| V2 default and complete browser regression              | PASS   | Default selector is 2; HR submits, Assistant accepts/finalizes, OWNER accepts/approves/publishes through real UI; one Task and notification, zero V3 batches.                 |
| V2 rejected by V3 endpoints                             | PASS   | Assistant, OWNER, and HR correction batch endpoints reject V2 with HTTP 409; V2 lifecycle continues unchanged.                                                                |
| Unauthorized authenticated employee                     | PASS   | Actual browser POST returns 403; no actionable reviewer workspace, batch, or Task.                                                                                            |
| Wrong organization / revoked membership                 | PASS   | Closed synthetic membership changes make real requests return 401 and invalidate the session; no batch is committed.                                                          |
| Revoked permission                                      | PASS   | Removing the synthetic reviewer role produces 403; restoration re-enables only the fixture's original role.                                                                   |
| Invalidated session                                     | PASS   | Deleting only that synthetic user's local sessions produces 401; protected UI returns to login.                                                                               |
| CSRF failure                                            | PASS   | Missing CSRF header returns 419 and commits no batch.                                                                                                                         |
| Read-only reviewer                                      | PASS   | Assistant can open the HR-correction review workspace; mutation flags/buttons are absent or false; direct decision POST returns 409 and commits no batch.                     |
| 503 / unknown JSON response                             | PASS   | Browser observes 503, then malformed JSON after a real HTTP 201 commit, then HTTP 200 exact reconciliation; all three requests retain one key/payload and one database batch. |
| Explicit V3 upload, roster and zero-work                | PASS   | Real template upload and HR roster create three employee workflows with no Tasks at submission.                                                                               |
| Work, child items, initial submission                   | PASS   | UI edits entries/child items; submit enters IN_REVIEW without publication.                                                                                                    |
| Assistant mixed decisions / OWNER partial publication   | PASS   | Accepted, changes-requested and zero-work decisions advance independently; partial publication creates one Task/notification while plan remains IN_REVIEW.                    |
| HR correction / Assistant re-review / final publication | PASS   | Only correction employee is editable; resubmission returns through Assistant; final state PUBLISHED with exactly two Tasks/notifications and one NO_WORK_APPROVED employee.   |
| Login, logout, Sanctum and CSRF cookie persistence      | PASS   | Real UI logins, authenticated reload, dedicated cookies, CSRF logout HTTP 204, then anonymous HTTP 401.                                                                       |

Database assertions use a restricted read-only plan-state script after fixture identity verification. Counts and notification references are scoped to the selected plan, so other synthetic scenarios cannot hide duplicates or corrupt expectations. Staged OWNER fixture work is created through the real Assistant service to preserve immutable snapshot provenance.

## Validation and remaining limits

An initial frontend run concurrent with backend/lint/build hit Vite's 60-second module transport startup timeout (36 hook failures, not application assertion failures). The unchanged standard frontend command passed all 246 tests when run alone. Final Chromium runs use one worker and no automatic test retries; the observed membership-probe race was removed using the static local probe page rather than accepting variable authorization outcomes.

Full enabled Chromium suite: **9 passed, 1 skipped** in 3.3 minutes. The skipped case belongs to the mutually exclusive disabled-process profile and passes separately (**1 passed**). The two final standalone fresh-fixture navigation runs each passed **2/2**, in 1.1 minutes and 49 seconds; the full-suite navigation cases also passed. Frontend: **246 passed** with the normal `npm test` command in an isolated run (35.2 seconds). Backend: **628 passed, 5,830 assertions**, including V2 regressions. ESLint passed with four existing Fast Refresh warnings; production build passed with the existing large-chunk warning. Full Pint, changed-file Prettier, and `git diff --check` passed.

NOT VERIFIED: natural clock-based session expiry (server-side invalidation is verified; the OS clock was not changed); non-Chromium browsers (outside the approved installation scope) and OS-level window closure (the harness remains alive to capture evidence; native reload beforeunload is verified); arbitrary history entries written outside React Router (not produced by app navigation); additional negative upload/parser paths (covered by existing frontend/backend suites, not added browser cases here). These are not represented as browser PASS results.

BLOCKED / rollout NO-GO: TiDB migration and genuine parallel concurrency evidence remains outstanding. Authorization seeders and migrations ran only in the disposable local SQLite fixture. No TiDB tooling, staging/production connection, staging/production migration or seed, deployment, or plan 30001 operation occurred. V3 remains disabled by default outside the guarded local runner.

**READY TO REVIEW** applies to this Chromium navigation/security fix. It does not authorize V3 rollout.
