# Weekly Planning V3 Phase 7B.1 test environment

## Decision and scope

This phase prepares isolated local test infrastructure. It does not install a
browser runner, WSL, Docker, TiUP, or TiDB; create a database; seed data; run a
migration; access staging/production; deploy; or enable V3 outside a guarded
local process.

**Rollout remains NO-GO.** Real browser E2E and genuinely parallel TiDB
concurrency/migration evidence remain BLOCKED until the approval gates below
are completed and the tests actually run.

Tested base: `40d319e74e20b213664ff26270b2639dc93fe7d5`
(`origin/staging`). Preparation branch:
`feature/weekly-planning-v3-e2e-infrastructure`.

## Local environment audit

| Capability                 | Observed local state                                                               | Assessment                                                  |
| -------------------------- | ---------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| Host                       | Windows NT `10.0.26200.0`                                                          | Supported by the proposed Playwright route                  |
| Node.js                    | `v22.23.3`                                                                         | Compatible with Playwright 1.64.1                           |
| npm                        | `10.9.9`                                                                           | Available                                                   |
| PHP                        | `8.2.12`                                                                           | Meets the backend requirement                               |
| Composer                   | `2.10.3`                                                                           | Available                                                   |
| PDO                        | Existing backend suite uses `sqlite`; `mysql` driver is installed                  | Available                                                   |
| Chrome                     | `156.0.8078.48`, installed under Program Files (x86)                               | Available for an optional installed-channel smoke test      |
| Edge                       | `156.0.4314.50`, installed under Program Files (x86)                               | Available for an optional installed-channel smoke test      |
| Authorized browser control | Browser inventory is empty; no connected in-app, Chrome, or Edge surface           | BLOCKED                                                     |
| Repository browser runner  | No Playwright, Cypress, Selenium, WebDriver, or Puppeteer dependency/configuration | BLOCKED                                                     |
| WSL                        | `wsl --status` and `wsl -l -v` report that WSL is not installed                    | BLOCKED                                                     |
| Docker                     | `docker` command is unavailable                                                    | BLOCKED                                                     |
| TiUP / TiDB                | Not installed                                                                      | BLOCKED                                                     |
| Backend test database      | PHPUnit forces SQLite `:memory:`                                                   | Available, but not browser-persistent and not TiDB evidence |

The audit did not connect to any database. Existing Chrome and Edge binaries
do not provide a test runner or an authorized automation connection by
themselves.

## Safe repository preparation

The following files are inert unless a developer explicitly opts in:

- `.env.weekly-planning-v3-e2e.example` defines a loopback-only persistent
  SQLite profile. It must never replace `backend/.env`.
- `.env.weekly-planning-v3-tidb.example` defines a loopback-only TiDB profile
  with a throwaway database name and synthetic account.
- `tests/Support/WeeklyPlanV3IsolatedTestEnvironment.php` rejects missing
  opt-in gates, non-local environment markers, `DB_URL`, hosted-runner markers,
  remote hosts/origins, wrong database names, wrong storage, and a non-pinned
  TiDB identity.
- `tests/Infrastructure/weekly-plan-v3-environment-preflight.php` validates
  only one of two exact local profile filenames. TiDB mode performs a
  read-only `SELECT VERSION()` after configuration passes. It has no DDL,
  migration, seed, cleanup, or delete operation.
- `tests/Fixtures/WeeklyPlanningV3E2eFixture.php` records deterministic fake
  identities under the reserved `.test` TLD. It does not seed by itself.
- `weekly_plan_e2e` is a private, non-served filesystem disk rooted under
  `storage/framework/testing/weekly-plan-v3-e2e/files`. Existing environment
  safety continues to reject a local disk in production and rejects it in
  staging without the existing explicit staging exception.
- Session and cache records use the same isolated test database rather than
  shared local filesystem directories; queue and mail remain synchronous and
  in-memory respectively.

The preparation intentionally does not add an Artisan command that calls
`migrate:fresh`, `db:wipe`, a seeder, or recursive cleanup. Those operations
must be added or executed only after an exact profile passes preflight and the
corresponding installation/provisioning approval is recorded.

## Browser E2E recommendation

Use Playwright Test, pinned to `@playwright/test@1.64.1`, with its managed
Chromium binary as the required project. Playwright 1.64.1 supports current
Node 22 and Windows, and its black-box browser model is independent of React
Router and Vite internals. The repository's Node 22.23.3 therefore satisfies
the runner requirement. A second optional project may use installed Chrome;
it is not the reproducible gate because the system browser updates outside the
lockfile.

Use only Chromium initially. Installing Firefox and WebKit would increase
download, disk, and maintenance cost without closing a distinct Phase 7B
requirement. Add them later only with a separate cross-browser decision.

### Commands proposed for explicit approval

Do not run these commands until package installation is approved:

```powershell
Set-Location D:\DQT\KPS\Kim-Phuc-Sac
npm install --save-dev --save-exact @playwright/test@1.64.1
npx playwright install chromium
```

After approval, the implementation should add `playwright.config.ts`, an
`e2e/` test tree, and package scripts rather than using the interactive
`npm init playwright` scaffolder. That makes every generated diff reviewable
and avoids an unreviewed CI workflow. The managed Chromium download and test
artifacts should be budgeted at approximately 0.5-1 GB of local disk. No admin
rights are normally required for the npm package or user-cache browser binary.

Official references:

- <https://playwright.dev/docs/intro>
- <https://playwright.dev/docs/browsers>
- <https://www.npmjs.com/package/@playwright/test>

### Local application topology

The approved runner should launch exactly these loopback services:

1. Laravel at `http://127.0.0.1:8001`, using the dedicated E2E profile.
2. Vite at `http://127.0.0.1:5174`, with API origin set to the Laravel origin.
3. Playwright-managed Chromium, with trace, screenshot, and video retained on
   first retry/failure.

The E2E database path is exactly:

```text
backend/storage/framework/testing/weekly-plan-v3-e2e/database/weekly-plan-v3-e2e.sqlite
```

The source-file disk is exactly:

```text
backend/storage/framework/testing/weekly-plan-v3-e2e/files
```

Before a future setup writes anything, copy the reviewed example to the
separate ignored filename and run preflight:

```powershell
Set-Location D:\DQT\KPS\Kim-Phuc-Sac\backend
Copy-Item .env.weekly-planning-v3-e2e.example .env.weekly-planning-v3-e2e
php tests/Infrastructure/weekly-plan-v3-environment-preflight.php e2e
```

This command does not modify `backend/.env` and does not create or clean the
database. Any future fixture command must invoke the same guard before the
first write and must refuse an existing SQLite file unless its identity marker
matches the current synthetic fixture version.

### Synthetic fixture contract

The fixture manifest defines one fake organization, HR Manager, Executive
Assistant, OWNER, multi-permission reviewer, three work employees, and one
zero-work employee. All emails use `weekly-plan-v3-e2e.example.test`; the
deterministic password is test-only and must never be reused elsewhere.

Future fixture provisioning must:

1. create only the exact isolated SQLite file after preflight;
2. run migrations 1-21 into the empty file;
3. run only the authorization/organization/Weekly Planning seed prerequisites;
4. create the manifest identities and a fixture-version marker;
5. assign the multi-reviewer both OWNER and Executive Assistant roles;
6. enable V3 only in this process;
7. create no real email, queue, object-storage, AI, Google, staging, or
   production integration;
8. print the database path, storage path, fixture version, and synthetic plan
   IDs without printing password or session material.

Cleanup must first rerun preflight, verify the exact path and fixture marker,
stop both local servers, and then remove only the exact E2E SQLite file and
`weekly-plan-v3-e2e` storage directory. A wildcard, repository-root cleanup,
`db:wipe` against an unverified connection, or automatic cleanup of
`backend/.env` is forbidden.

### Browser scenario and data assertions

Each UI scenario must pair visible assertions with backend/database assertions:

| Scenario                 | Browser behavior                                         | Required data assertion                                                                  |
| ------------------------ | -------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| HR creation/submission   | Create V3, upload/finish source, select roster, submit   | One V3 plan, complete roster/projections, one submission event, zero Tasks/notifications |
| Assistant review         | Mix accept/change and confirm once                       | One immutable Assistant batch; selected employee versions/states only                    |
| OWNER partial publish    | Publish work, request changes, approve zero-work         | One Task/notification per published entry, correct no-work state, no duplicates          |
| HR correction            | Only correction employee editable; resubmit              | Protected employees/hashes unchanged; corrected employee returns to Assistant            |
| OWNER-origin correction  | Correct and pass Assistant again                         | Employee reaches OWNER only through a new Assistant acceptance                           |
| Authentication/CSRF      | Anonymous, wrong-org, revoked permission, missing CSRF   | 401/403/419-equivalent response and no ledger/Task/notification change                   |
| Multi-permission routing | Switch Assistant/OWNER/overview                          | Stage queues and unresolved request identities remain independent                        |
| Link/Back/Forward        | Attempt navigation with an ambiguous request             | Stay keeps exact pending request; leave never retries automatically                      |
| SessionStorage recovery  | Reload/reopen with minimal recovery metadata             | No full employee content persisted; stage/key/fingerprint preserved                      |
| Ambiguous retry          | Drop/replace response after server commit, retry exactly | Original key and payload reused; one logical batch and exactly-once side effects         |

The test runner should query a test-only read model or use a local CLI assertion
step after UI actions. Screenshots alone do not satisfy the gate. Never expose a
general-purpose database endpoint in the application.

## Disposable TiDB recommendation

### Evaluated options

| Option                                        | Advantages                                                                                                   | Local blockers / costs                                                                                                                                                                                                                        |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Docker Desktop with a pinned Compose topology | Reproducible containers and straightforward volume/network teardown                                          | Docker and WSL2 are absent; Docker Desktop adds installation, daemon, images, licensing review, and several GB of disk/RAM. PingCAP's old `tidb-docker-compose` repository is archived, so it should not be adopted as a new source of truth. |
| WSL2 Ubuntu with TiUP Playground              | Current official PingCAP local-cluster path; explicit version pin; fewer platform layers than Docker Desktop | WSL2 is absent; installation needs administrator approval and normally a reboot. TiUP downloads Linux binaries and the cluster still needs substantial disk/RAM.                                                                              |

**Recommendation:** use WSL2 Ubuntu plus TiUP Playground, pinned to TiDB
`v8.5.8`, for this workstation. Both alternatives first require WSL2, while the
TiUP route avoids Docker Desktop and an archived Compose template. If company
policy already licenses and standardizes Docker Desktop, a separately reviewed
Compose topology is acceptable, but it is not the preferred default here.

The local self-managed pin validates the current v8.5 LTS line; it is not a
claim of exact TiDB Cloud/serverless parity. Exact rollout-candidate parity
remains a separate gate.

Official references:

- <https://docs.pingcap.com/tidb/dev/tiup-playground/>
- <https://docs.pingcap.com/tidb/stable/upgrade-tidb-using-tiup/>
- <https://learn.microsoft.com/windows/wsl/install>
- <https://docs.docker.com/desktop/setup/install/windows-install/>

### Commands proposed for explicit approval

The following commands are a reviewed proposal, not authorization to run them.
The WSL installation command requires an elevated PowerShell window and may
require a restart:

```powershell
wsl --install -d Ubuntu
```

After restart and Ubuntu initialization, the following Linux commands would
install TiUP and start a pinned local cluster. They download third-party
software and therefore also require explicit approval:

```bash
curl --proto '=https' --tlsv1.2 -sSf https://tiup-mirrors.pingcap.com/install.sh | sh
source ~/.bashrc
tiup --version
tiup playground v8.5.8 --db 2 --pd 1 --kv 3 --tiflash 0 --without-monitor --host 127.0.0.1
```

The topology uses two TiDB servers and three TiKV nodes so independent clients
can exercise distributed locking/races. If `playground` does not support the
listed flags in the approved TiUP version, stop and revise the reviewed command;
do not silently fall back to an unpinned default.

In a separate shell, create only one synthetic account and one database. The
exact SQL must be reviewed immediately before execution and must grant rights
only on `kps_v3_phase7b1_local.*`. No KPS-Staging or KPS-Internal name,
credential, URL, or secret may be present.

Proposed SQL after substituting a newly generated local-only password (never
commit the substituted command or place it in shell history):

```sql
CREATE DATABASE `kps_v3_phase7b1_local`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kps_v3_phase7b1'@'%'
    IDENTIFIED BY '<random-local-only-password>';
GRANT ALL PRIVILEGES ON `kps_v3_phase7b1_local`.*
    TO 'kps_v3_phase7b1'@'%';
```

The `%` account host is acceptable only because TiDB must remain bound to the
loopback interface and the account is limited to the throwaway schema. If
preflight observes a non-loopback endpoint, stop before executing this SQL.
The proposed local Playground connection is intentionally non-TLS over
loopback. It must not be forwarded or exposed to a LAN. Any remote/container
gateway topology requires a separately reviewed TLS CA, hostname verification,
firewall rule, and Laravel PDO configuration before use.

After the local profile is populated, run the read-only preflight before any
migration or fixture write:

```powershell
Set-Location D:\DQT\KPS\Kim-Phuc-Sac\backend
Copy-Item .env.weekly-planning-v3-tidb.example .env.weekly-planning-v3-tidb
php tests/Infrastructure/weekly-plan-v3-environment-preflight.php tidb
```

Preflight requires loopback port 4000, a `kps_v3_phase7b1_*` name, the exact
synthetic username, no `DB_URL`, no hosted environment markers, explicit local
gates, and `SELECT VERSION()` containing both `TiDB` and `v8.5.8`.

### Migration rehearsal

After a separate approval for the now-verified throwaway target:

1. capture `SELECT VERSION()`, `TIDB_VERSION()`, current database/user, and an
   empty schema inventory;
2. run migrations 1-21 using normal Laravel migration code, not a staging
   launcher or staging inspector;
3. verify the migration ledger order and schema fingerprints;
4. assert V3 unique constraints, foreign keys, JSON reads/writes, and expected
   indexes;
5. run `migrate` again and prove it is a no-op;
6. exercise guarded `down` behavior first with non-empty V3 ledgers (must
   refuse), then only on a newly recreated empty throwaway database;
7. archive redacted logs and exact commit/tool versions.

TiDB DDL is not assumed transactional. Any unexpected partial DDL stops the
rehearsal; the disposable database is discarded after identity revalidation
instead of attempting an improvised repair.

## Parallel concurrency harness design

The harness is intentionally not represented by sequential PHPUnit requests.
Once TiDB is approved and available, implement a coordinator plus two worker
processes using existing PHP/Laravel dependencies:

1. Coordinator preflights the exact TiDB profile and creates a unique run
   directory under `storage/framework/testing/weekly-plan-v3-races/<run-id>`.
2. It provisions one synthetic fixture per race in the verified throwaway
   database and writes no credentials to the run directory.
3. Each worker is a separate PHP process that boots Laravel and owns a separate
   PDO/Laravel connection. It loads one immutable request payload.
4. Workers create `ready-a` / `ready-b` files with exclusive creation and wait
   on a `release` file. The coordinator releases both only after both are ready.
5. Workers execute their service/API mutation once, record redacted status,
   batch/request ID, and timing, and exit. No worker retries automatically.
6. Coordinator waits with a bounded timeout, queries final state through a
   third connection, and asserts allowed outcomes.
7. Each race repeats at least 20 times with a fresh fixture. Deadlock/retry
   behavior is recorded explicitly, never hidden as an infrastructure retry.

Required races and invariants:

| Race                            | Final-state invariants                                                                     |
| ------------------------------- | ------------------------------------------------------------------------------------------ |
| Simultaneous submissions        | One transition and V3_SUBMITTED event; complete roster/projection; loser is stale/conflict |
| Roster update vs submission     | Entire old or new roster wins; never a partial roster or missing employee workflow         |
| Concurrent Assistant decisions  | One immutable winning decision/state transition; unselected employees unchanged            |
| Concurrent OWNER publishing     | Exactly one Task and notification per entry and one publication identity                   |
| OWNER vs stale confirmation     | Valid locked version wins; stale request writes no decision, Task, notification, or event  |
| HR correction vs stale versions | One content/state version advance; protected employees/content remain unchanged            |
| Concurrent exact retries        | One ledger row and one logical response/result for the same key and payload                |
| Same key, different payloads    | One payload owns the key; other conflicts; no mixed decisions or side effects              |

The coordinator must additionally assert employee state/content versions,
immutable batch hashes/snapshots, Task source uniqueness, notification counts,
event counts, no open/incomplete workflow state, and no mutation of unselected
employees. The eight scenarios remain **BLOCKED** until this harness runs on
the verified TiDB instance.

### Controlled teardown

Stop application workers and TiUP first. Before dropping anything, rerun the
configuration and server-identity preflight, query the current database/user,
and require an operator to type the exact throwaway database name. Drop only
that database with the limited synthetic account. Then stop Playground with
its normal graceful command. Do not use wildcard paths, `DROP DATABASE` built
from unvalidated input, `tiup clean --all`, or repository-wide recursive
deletion.

## Evidence to capture

For browser E2E:

- exact git SHA, Node/npm/Playwright/browser versions;
- redacted profile fingerprint and exact loopback origins;
- Playwright HTML report, trace, failure screenshots/video;
- redacted request/response log for ambiguous retry scenarios;
- backend assertion output for batches, versions, Tasks, notifications, and
  events;
- cleanup verification.

For TiDB:

- exact git SHA, PHP/Composer/TiUP/TiDB versions;
- redacted preflight output and database/schema identity;
- migration ledger/schema fingerprint before, after, and on re-execution;
- per-race worker timing/outcomes and final-state assertions for every repeat;
- deadlock/duplicate-key diagnostics;
- guarded rollback and teardown evidence.

Do not archive cookies, passwords, authorization headers, source documents,
full employee snapshots, staging/production secrets, or real company data.

## Operational cost and approval gates

| Change                                    | Approval needed                                 | Estimated cost/complexity                                                               |
| ----------------------------------------- | ----------------------------------------------- | --------------------------------------------------------------------------------------- |
| Add Playwright package + Chromium         | Package/network approval                        | Low-medium; lockfile diff, roughly 0.5-1 GB local disk, ongoing version/browser updates |
| Add Playwright specs/config               | Code review after dependency approval           | Medium; local stack orchestration, stable fixtures, trace retention                     |
| Install WSL2 Ubuntu                       | Administrator/platform approval; likely restart | Medium; Windows feature/distro lifecycle and several GB disk                            |
| Install TiUP/TiDB components              | Network/tooling approval                        | Medium-high; multiple cluster processes, several GB disk and meaningful RAM/CPU         |
| Optional Docker Desktop route             | Platform/licensing/admin approval               | High relative to TiUP route on this machine; WSL2 plus daemon/images/licensing review   |
| Run destructive fixture/migration cleanup | Per-run verified-target approval                | Medium; must remain limited to exact synthetic database/path                            |

No paid cloud resource is proposed. Local execution still consumes developer
time, disk, memory, CPU, downloads, and maintenance. A hosted CI TiDB/browser
job would require a separate cost, secret, retention, and isolation review.

## Tests executed in this phase

The new guard unit test exercises accepted profiles and rejects missing
opt-ins, wrong environments, existing/general/traversal database paths, remote
origins/hosts, hosted markers, wrong users/names, MySQL masquerading as TiDB,
and wrong TiDB versions.

| Command                                       | Result                                                |
| --------------------------------------------- | ----------------------------------------------------- |
| Full backend `php artisan test --compact`     | PASS — 626 tests, 5,823 assertions                    |
| Focused V3 + infrastructure tests             | PASS — 83 tests, 995 assertions                       |
| Migration/environment safety tests            | PASS — 64 tests, 411 assertions                       |
| Mock-only Pester migration orchestration      | PASS — 25 tests                                       |
| Full frontend `npm test`                      | PASS — 243 tests                                      |
| `npm run lint`                                | PASS — 0 errors, 4 pre-existing Fast Refresh warnings |
| `npm run build`                               | PASS — existing large-chunk warning only              |
| `vendor/bin/pint --test`                      | PASS                                                  |
| Prettier on changed Markdown                  | PASS                                                  |
| PHP syntax checks on new infrastructure files | PASS                                                  |
| `git diff --check`                            | PASS                                                  |

The Pester suite uses synthetic mocks and does not contact or mutate staging.
No browser E2E, TiDB connection, migration, race, fixture write, seeder, or
cleanup was executed.

## Remaining blockers

- Playwright and its managed browser are not installed or approved.
- No authorized connected browser-control surface is available.
- Synthetic fixture provisioning and real browser scenarios have not run.
- WSL2, TiUP, and TiDB are not installed or approved.
- Migration 1-21 has not run on disposable TiDB.
- All eight genuinely parallel TiDB races have not run.
- Exact TiDB Cloud/serverless rollout-candidate parity remains unverified.

These are hard blockers. Preparation code and passing existing suites do not
change the rollout decision from **NO-GO**.
