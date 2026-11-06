# Frontend CI

The `CI` workflow runs two independent frontend jobs on Ubuntu 24.04:

| Job | Checks |
| --- | --- |
| Frontend checks | Node tests, ESLint, TypeScript and Vite build |
| Frontend browser tests | All specs selected by `playwright.report-validation.config.ts` |
| Frontend | Required aggregate check; succeeds only when both jobs succeed |

Keep `Frontend` as the required check in existing branch protection rules. The
aggregate runs even when a prerequisite fails, is cancelled or is skipped, and
rejects every result other than two successes. Backend jobs and deployment
workflows are independent of this change.

Each worker installs dependencies with `npm ci` and uses the existing npm download
cache. Running both jobs in parallel reduces elapsed time but can use slightly
more total runner time because dependency installation occurs twice.

The browser job installs system libraries separately from Chromium's headless
shell. The suite uses Playwright's default headless Chromium with no custom
browser channel. Full Chromium is unnecessary for these tests. Browser binaries
are not cached; the npm lockfile determines the matching Playwright version.

Dependency installation has a three-minute step limit. System library and browser
downloads each have two minutes, so a stalled installer is identified separately.
Playwright has a five-minute global CI limit and its step allows six minutes to
finish diagnostics. Browser failure screenshots and traces are retained for seven
days when available. Tests intercept APIs and do not use live accounts.

Before this split, three completed frontend jobs spent roughly 37–39 seconds on
`npm ci`, 10–11 seconds on Node tests, 17–18 seconds on lint, 12 seconds on the
build, 17–19 seconds installing the browser and 1m49s–1m56s on browser tests.
These are historical observations, not a guaranteed runtime. Runner queues and
package mirrors can still affect elapsed time.

The split keeps all selected specs (50 browser cases at introduction), one browser
worker, existing per-test timeouts and no automatic retries. No path-based test
skipping is enabled. Re-run only the failed job when investigating infrastructure
failures; do not remove checks to obtain a green aggregate.
