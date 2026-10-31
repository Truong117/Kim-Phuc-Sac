# Weekly Planning V3 Phase 7B.2B — TiDB audit and execution plan

## Decision and approval boundary

Audit date: **2026-10-31**, Asia/Ho_Chi_Minh. Base: `origin/staging` at `fa443e9749bc5f785900f6133bc122c297d4e143`, the PR #71 merge. Branch: `feature/weekly-planning-v3-tidb-validation`. The working tree was clean before branching.

This first execution is **audit and plan only**. This change adds documentation, not a runnable database provisioner or concurrency harness. No WSL/Linux/TiUP/TiDB installation, TiDB connection, database provisioning, migration, seeding, database cleanup, or deployed flag change was performed. The existing MySQL service was not connected to or modified. Plan 30001 was not read or changed.

Phase 7B.2A browser evidence is recorded in [the merged browser report](weekly-planning-v3-phase7b2-browser-e2e.md). The Phase 7B/7B.1 readiness documents remain historical baselines; this audit addresses the outstanding TiDB gate.

**TiDB validation: BLOCKED. Rollout: NO-GO.** Installation approval is separate from approval to provision a named disposable database and execute migrations. Existing SQLite, browser, mock, or source tests do not count as TiDB evidence.

## Workstation audit

| Item                          | Observation                                                                                                      | Assessment                                                                                                  |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| Windows                       | Windows 11 Home Single Language, x64, build 26200 / 10.0.26200                                                   | OS generation supports WSL2; installation absent                                                            |
| Physical/usable RAM           | 16 GB class; CIM reports 15.69 GiB                                                                               | Limited headroom for a distributed cluster                                                                  |
| Available RAM                 | 2.15 GiB initially; 2.02 GiB on recheck                                                                          | Neither proposed topology should start now                                                                  |
| CPU                           | Intel Core i7-1355U, 10 cores, 12 logical processors                                                             | Adequate instruction/thread capacity for small correctness tests; mobile CPU is not a performance test host |
| Disk                          | NVMe SSD; C: 71.21 GiB free, D: 631.53 GiB free                                                                  | Space sufficient for a dedicated local distro and bounded test artifacts                                    |
| Hypervisor                    | `HypervisorPresent=True`                                                                                         | Hypervisor is present; this is not proof that WSL2 can launch                                               |
| CPU virtualization properties | Firmware virtualization, SLAT and VM monitor extension fields return False while a hypervisor is present         | Inconclusive; do not infer a BIOS setting from these fields alone                                           |
| Optional Windows features     | Read-only feature inspection requires an elevated administrator token                                            | NOT VERIFIED; no feature was enabled                                                                        |
| WSL                           | `wsl --status` and `wsl --list --verbose`: WSL not installed                                                     | BLOCKED until approval/install/restart verification                                                         |
| Existing database service     | `MySQL84`, running, port 3306, PID 33172 at audit                                                                | Unrelated service; never reuse it as TiDB evidence                                                          |
| Proposed Windows-side ports   | No listener observed on 4000/4001, 2379/2380/2381, 10080/10081, 20160–20162, 20180–20182, 3000, 9090, 8002, 5175 | No observed conflict; recheck all actual allocated ports in Windows and Linux after installation            |
| PHP                           | 8.2.12; PDO `mysql` and `sqlite` present                                                                         | Reuse Windows PHP for independent workers; no Linux PHP installation proposed                               |
| Node/npm                      | v22.23.3 / 10.9.9                                                                                                | Existing frontend regression tools available                                                                |
| Composer / PHPUnit            | 2.10.3 / 11.5.57                                                                                                 | Existing installed dependencies reused                                                                      |
| TiUP/TiDB/Docker              | Commands absent                                                                                                  | Not installed; no Docker route proposed                                                                     |

CIM/volume/network reads initially failed in the filesystem sandbox and succeeded with read-only execution outside it. Optional-feature reads still required actual administrator elevation. Nothing was installed by these audit commands. Free RAM is a point-in-time measurement and must be rechecked before a cluster starts.

The proposed `D:\KPS-V3-TiDB` directory and user `.wslconfig` did not exist at audit time; recheck them immediately before any approved write. Existing Composer/backend vendor and frontend node_modules were reused. Local self-managed v8.5.8 is a chosen test pin, not verified version/configuration parity with a deployed cloud target; no company database was queried to establish parity.

## Topology and resource decision

The original **2 TiDB + 1 PD + 3 TiKV**, without TiFlash or monitoring, is **not recommended on the current available RAM**. Planning budget: roughly 12–16 GiB for the Linux guest/cluster plus Windows and PHP headroom, preferably a 32 GB host. These are conservative engineering budgets, not measured requirements or a PingCAP guarantee. One PD provides no PD quorum-failure coverage even with three TiKV nodes.

Propose an explicitly approved first stage of **1 TiDB + 1 PD + 1 TiKV**, no TiFlash/Prometheus/Grafana. Budget a 6 GiB WSL cap, four logical CPUs, 2 GiB swap and at least 30 GiB free disk for distro/binaries/data/logs; reserve 50 GiB on D: for practical headroom. Start only after the user frees at least **8 GiB Windows available RAM**, and recheck while running. Swap is not a substitute for adequate RAM. If Windows available memory falls below 2 GiB, or guest OOM/abnormal component restart occurs, stop only this owned cluster gracefully and mark the run FAIL/NOT VERIFIED rather than extending timeouts until it passes.

The small topology can execute real concurrent transactions through separate client connections and validate schema, locks, versions and idempotency. It cannot prove cross-TiDB behavior, three-TiKV replication behavior, node failure tolerance, production capacity, or rollout-candidate cloud parity. The original topology remains a separate gate requiring more headroom/hardware and explicit approval. The plan is not silently reduced.

[PingCAP's local quick-start](https://docs.pingcap.com/tidb/stable/quick-start-with-tidb/) describes Playground as a development setup, not comprehensive stability validation, and gives a 10 GiB/four-core recommendation for its multi-instance example. Cluster memory must also be observed at startup; configured SQL memory limits alone do not establish a safe peak.

## Proposed installation steps — NOT EXECUTED

1. Obtain approval for Windows feature changes, WSL2, Ubuntu 24.04 LTS, the dedicated distro directory, TiUP/component downloads and the smaller topology. Confirm the target distro/directory does not already contain unverified data.
2. In an **administrator PowerShell**, inspect the feature states, then install WSL without a default distro:

    ```powershell
    Get-WindowsOptionalFeature -Online -FeatureName VirtualMachinePlatform
    Get-WindowsOptionalFeature -Online -FeatureName Microsoft-Windows-Subsystem-Linux
    wsl --install --no-distribution
    ```

    A Windows restart may be required. The user controls when to restart; no automatic restart or existing-service shutdown is authorized. A restart would interrupt running applications/services, including MySQL84; schedule and approve that interruption separately. Do not change MySQL startup settings or restart it automatically afterward.

3. After restart, record `wsl --version`, check the distribution name with `wsl --list --online`, then create only the new distro:

    ```powershell
    wsl --install -d Ubuntu-24.04 --location D:\KPS-V3-TiDB\Ubuntu2404 --no-launch
    wsl --list --verbose
    wsl -d Ubuntu-24.04
    ```

    Initialize its Linux user interactively. Require WSL version 2; stop if the installed WSL command does not support the reviewed flags or if a distro/target already exists. Do not overwrite or unregister an existing distro.

4. With approval, merge the following limits into the user's `.wslconfig`, preserving any unrelated settings. This file applies globally to WSL2 distributions; do not replace an existing configuration blindly. If a restart of WSL becomes necessary, terminate only the newly created distro after confirming it owns no other work, rather than using a blanket `wsl --shutdown`.

    ```ini
    [wsl2]
    memory=6GB
    processors=4
    swap=2GB
    localhostForwarding=true
    ```

5. Use **TiUP v1.16.5**, **Playground v1.16.5**, and **TiDB/PD/TiKV v8.5.8**. The official TiUP release API reports Linux amd64 asset `tiup-v1.16.5-linux-amd64.tar.gz`, 57,986,557 bytes, SHA-256 `22703d0fb23fa5036294e65b3588ca58a84705ad5a257a8f59db10eef3fbb539`. Recheck publisher metadata before download; refuse a digest change. Do not use an unversioned `curl | sh` bootstrap.

    Proposed Linux commands after approval (curl, tar and sha256sum must exist; request approval before installing missing utilities):

    ```bash
    export KPS_TIDB_ROOT="$HOME/kps-v3-phase7b2"
    # Require this to be a new, owned directory; stop on unverified existing data.
    mkdir -p "$KPS_TIDB_ROOT/downloads" "$KPS_TIDB_ROOT/bin"
    cd "$KPS_TIDB_ROOT/downloads"
    curl --proto '=https' --tlsv1.2 -fL \
      https://github.com/pingcap/tiup/releases/download/v1.16.5/tiup-v1.16.5-linux-amd64.tar.gz \
      -o tiup-v1.16.5-linux-amd64.tar.gz
    printf '%s  %s\n' \
      22703d0fb23fa5036294e65b3588ca58a84705ad5a257a8f59db10eef3fbb539 \
      tiup-v1.16.5-linux-amd64.tar.gz | sha256sum -c -
    tar -tzf tiup-v1.16.5-linux-amd64.tar.gz
    # Review archive members first: no absolute paths, traversal or symlinks.
    tar -xzf tiup-v1.16.5-linux-amd64.tar.gz -C "$KPS_TIDB_ROOT/bin" tiup
    chmod 0755 "$KPS_TIDB_ROOT/bin/tiup"
    export TIUP_HOME="$KPS_TIDB_ROOT/tiup"
    export PATH="$KPS_TIDB_ROOT/bin:$PATH"
    tiup --version
    tiup mirror set https://tiup-mirrors.pingcap.com
    tiup install playground:v1.16.5 tidb:v8.5.8 pd:v8.5.8 tikv:v8.5.8
    tiup playground:v1.16.5 --help
    ```

    Stop on unexpected archive layout, version, mirror, flags or installation path. Keep database files in the Linux ext4 filesystem; the dedicated distro VHD resides on D:. Do not place TiKV data directly under `/mnt/d`.

6. Only after installation/topology approval, memory/port checks and an owned-process manifest, the small-cluster start command would be:

    ```bash
    tiup playground:v1.16.5 v8.5.8 \
      --db 1 --pd 1 --kv 1 --tiflash 0 --without-monitor --host 127.0.0.1
    ```

    The later original-topology command changes only `--db 2 --pd 1 --kv 3`, but requires its own resource and approval gate. Recheck actual SQL/status/PD/TiKV ports and all bound addresses after startup; do not accept automatic port substitution without updating the reviewed run manifest. No schema migration is authorized by cluster installation/start approval.

Primary references: [Microsoft installation](https://learn.microsoft.com/en-us/windows/wsl/install), [WSL command options](https://learn.microsoft.com/en-us/windows/wsl/basic-commands), [global WSL configuration](https://learn.microsoft.com/en-us/windows/wsl/wsl-config), [TiUP release and asset metadata](https://github.com/pingcap/tiup/releases/tag/v1.16.5), [pinned Playground source/flags](https://github.com/pingcap/tiup/blob/v1.16.5/components/playground/main.go), [TiDB 8.5.8 release](https://docs.pingcap.com/tidb/stable/release-8.5.8/). These commands are a proposal, not executed evidence.

## Existing infrastructure findings and required hardening

`WeeklyPlanV3IsolatedTestEnvironment` requires local markers, the dedicated username/database pattern, loopback and port 4000, no DB_URL, and pinned TiDB version. The TiDB branch of `weekly-plan-v3-environment-preflight.php` opens a raw PDO and checks only VERSION after profile validation. It does **not** yet verify effective Laravel connection settings, DATABASE(), CURRENT_USER(), privileges, schema inventory, database ownership, or local process provenance. It must not be treated as write authorization.

The TiDB example also reuses the browser E2E disk and does not define a dedicated log channel/session cookie. Future preparation must give TiDB its own canonical per-run storage/temp/log/config-cache root under `backend/storage/framework/testing/weekly-plan-v3-tidb`, distinct session cookie and cache prefix, and session/cache records in the exact disposable MySQL schema. Keep sync queue/array mail. Do not reuse or erase the SQLite browser fixture root or invoke its identity-specific cleanup script.

The staging inspector has useful schema/ledger/grant/DDL-job concepts, but its database identity is fixed to `kps_internal`, with staging target/TLS rules. The staging launcher deliberately skips dotenv and restricts inherited environment and migration paths. **Do not execute, relax, or repoint either staging launcher/PowerShell orchestration for this exercise.** Future local code may reuse pure manifest/schema-normalization logic, with a separate local target verifier.

Before any approved database write, implement the following contract in the coordinator and every worker:

1. Start PHP with a clean, allowlisted environment and the explicit ignored local TiDB profile. Never load `backend/.env`; reject cached/alternate config caches, inherited DB_URL, read/write replicas, Unix sockets, unreviewed PDO init commands, SSH/tunnel markers and proxy overrides. Verify filesystem/session/cache/log/queue/mail isolation as well as DB configuration.
2. Require the exact database name in the per-run approval manifest, matching `kps_v3_phase7b1_*`; prefix matching alone is insufficient. Use a random run nonce, cluster identity, component PIDs/binary hashes/paths, profile fingerprint and exact approved endpoints. No general-purpose database name/SQL API.
3. Attest the actual Linux TiDB/PD/TiKV processes, addresses and cluster ID using the owned TiUP manifest, `ss -ltnp`, process/binary inspection and PD metadata. Verify the Windows-side listener/WSL forwarding belongs to this new local distro. Loopback and a TiDB version string alone cannot distinguish a local server from a remote SSH tunnel. Reject unknown portproxy/SSH/relay ownership. Do not log unrelated process command lines that might contain secrets.
4. Reuse Windows PHP PDO MySQL through the explicitly approved local WSL forwarding path; there is no SSH connection. If local ownership or loopback forwarding cannot be established, stop and request a revised plan rather than broadening bind addresses/firewall rules or installing a second PHP stack silently.
5. On the effective Laravel connection, read DATABASE(), CURRENT_USER(), USER(), VERSION(), TIDB_VERSION(), CONNECTION_ID(), foreign_key_checks, SQL mode, charset/collation, transaction mode/isolation and cluster metadata. Compare to the approved manifest. Require distinct connection IDs for workers. Verify every new/reconnected PDO, not just the parent's initial connection. The second TiDB endpoint 4001 needs an explicit endpoint allowlist after approval; the current 4000-only guard is not silently widened.
6. Before provisioning, require an empty schema inventory (tables/views/triggers/routines/events and ledger) and no active competing DDL/migrator. The provisioning administrator must itself be attested to the owned local cluster first. Refuse unknown existing databases, even if their names match the prefix. After provisioning, record an owned random fixture marker and verify it on subsequent connections.
7. Provision only synthetic `kps_v3_phase7b1` credentials with a reviewed literal account host. Do not silently fall back to `%` if WSL forwarding changes the apparent client host. Verify SHOW GRANTS, effective roles and the exact matched CURRENT_USER account. Allow USAGE globally and only SELECT/INSERT/UPDATE/DELETE/CREATE/ALTER/DROP/INDEX/REFERENCES on the exact approved schema for migration; remove DDL rights for race workers. Reject root, global/wildcard schema grants, GRANT OPTION and unrelated privileges. A separate attested local auditor may collect cluster diagnostics; workers do not inherit its credentials.
8. Keep passwords in a restricted local profile or protected inherited pipe/environment, never CLI arguments, shell history, logs or committed evidence. Record hashes/counts/identity without credentials or full employee snapshots. Provisioning, migration, race and cleanup phases have separate explicit manifest states; an empty-schema requirement cannot simply be disabled for later phases.

Bootstrap is a separate approved operation: attest the fresh local cluster/admin connection and require the exact target schema to be absent before creating that one schema/account. Then connect as the dedicated account, verify its identity/grants and truly empty schema, and only then write the owned marker. Migration/race workers accept the exact marker and phase-specific inventory, never an arbitrary nonempty schema. Root is rejected for application workers; the narrowly scoped bootstrap cannot claim to verify a synthetic account before that account exists.

## Migration rehearsal design — all TiDB cases BLOCKED

The strict manifest contains exactly these 21 files in this order. Existing files must not be edited; capture their SHA-256 manifest before execution and require the same fingerprint in every phase.

| #   | Migration purpose / objects                                                       | Main rehearsal concern                                                                |
| --- | --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| 1   | users, password_reset_tokens, sessions                                            | Primary/unique keys, nullable session user index                                      |
| 2   | cache, cache_locks                                                                | String primary keys and expiry integers                                               |
| 3   | jobs, job_batches, failed_jobs                                                    | Long text payloads and UUID uniqueness                                                |
| 4   | organizations, departments, locations, roles, permissions, memberships and pivots | FK dependency order, composite unique keys and unsigned types                         |
| 5   | daily_reports                                                                     | Organization/user/date uniqueness and restrictive FKs                                 |
| 6   | daily_report_items                                                                | Parent integrity and item uniqueness                                                  |
| 7   | report_comments                                                                   | Restrictive author FK and cascading report FK                                         |
| 8   | user_notifications                                                                | Task references are application-level; no exactly-once uniqueness constraint          |
| 9   | ai_daily_report_runs                                                              | JSON columns and cache identity uniqueness                                            |
| 10  | tasks                                                                             | Organization/assignee/membership references                                           |
| 11  | weekly plans, entries, legacy employee reviews, comments, suggestions, events     | Organization/week uniqueness and JSON fields                                          |
| 12  | tasks.weekly_plan_entry_id                                                        | Separate column, unique and FK DDL statements; partial-DDL exposure                   |
| 13  | source passages, entry links/applications                                         | JSON, composite keys, source-link FKs                                                 |
| 14  | AI budget periods and usage records                                               | Accounting uniqueness and parent references                                           |
| 15  | organization Google Docs folders/events                                           | Existing/partial schema is explicitly rejected                                        |
| 16  | source_type and durable Google Docs source                                        | Partial schema rejection; existing plans get UPLOADED_FILE                            |
| 17  | Google Docs tab mappings/events                                                   | Cross-table FK order and partial schema rejection                                     |
| 18  | entry items and source-passage links                                              | Child content JSON and parent references                                              |
| 19  | V2 review rounds/decisions and legacy normalization                               | DDL precedes a DML transaction; legacy statuses are intentionally normalized          |
| 20  | workflow_version and V3 workflows/batches/decisions                               | Default 2, plan/assignee and idempotency uniqueness, JSON snapshots, guarded rollback |
| 21  | V3 correction batches                                                             | Plan/request uniqueness, JSON result snapshot, confirmed-data rollback rejection      |

Plan four disposable, explicitly named rehearsal lanes, one approved database per lane/run:

- **Fresh:** start with no user objects; run the allowlisted migrations 1–21, verify ordered ledger after each step, inventory actual columns/defaults/nullability/unsigned types/index ordering/FK actions and SHOW CREATE TABLE. Re-run the migrator and require no new ledger/DDL/schema changes. Do not require auto-increment IDs to be gapless.
- **V2 upgrade:** run through 18, insert synthetic legacy V2 fixtures, then verify the documented normalization at 19. Establish a new baseline after 19; run 20/21 and require the exact V2 plans, entries, comments, Tasks, notifications and timestamps to remain unchanged, workflow_version=2 for old/default rows, and empty V3 tables. No fixture uses ID 30001.
- **Rollback:** with empty V3 data, test only the approved 21/20 down paths and preservation of the V2 baseline. In a separate fresh lane, create genuine confirmed Assistant/OWNER/correction history through services; 21 down must refuse confirmed correction data, and 20 down must refuse non-V2 plans/workflow data before destructive DDL. Compare schema/data/ledger fingerprints after rejection.
- **Partial DDL:** inject a controlled failure after a known successful DDL statement in the local test process, without editing historical migrations. Capture schema, absent/incomplete ledger and DDL jobs. The local inspector must refuse automatic rerun/repair when schema and ledger disagree. Preserve evidence; rebuild only that verified disposable database after explicit cleanup authorization. Do not assume DDL rolls back with a PHP transaction.

Behaviorally probe required unique/FK constraints with synthetic rows in DML transactions and roll them back. Normalize RESTRICT/NO ACTION metadata differences without accepting missing constraints. Test native JSON round trips with Vietnamese/Unicode, nulls, arrays, integer versions and canonical content hashes; compare decoded semantics and the application's canonical hash, not database JSON whitespace/key formatting. Inspect both SHOW CREATE and information_schema; a parsed FK clause alone is not enforcement evidence. [TiDB transactions](https://docs.pingcap.com/tidb/stable/transaction-overview/) document implicit DDL commit; [TiDB foreign keys](https://docs.pingcap.com/tidb/stable/foreign-key/) describe enforcement and metadata verification.

Prefix-version fixtures must insert only columns available at that migration prefix, rather than invoking a current model that assumes later V3 columns. Probe nullable Task-source uniqueness explicitly: multiple manual Tasks with NULL source remain valid, while duplicate non-NULL entry sources are rejected.

## Genuine parallel harness design — not implemented or executed

Use a dedicated CLI coordinator outside the normal PHPUnit config. `backend/phpunit.xml` force-selects SQLite :memory: and must remain unchanged. Do not run these cases through it or through the single-thread PHP development HTTP server.

For each approved iteration, the coordinator creates a synthetic fixture, then launches two separate PHP processes with independent Laravel boots/PDO connections. Each reports verified identity and READY over private per-run IPC/files. Release a bounded start barrier only after both are ready. Capture transaction-begin events and test-only connection callbacks before/after the first plan FOR UPDATE. Hold worker A after it owns the lock until B has entered its transaction and attempted the same lock; then release A. This demonstrates overlapping transactions and contention even when the plan lock intentionally serializes commits. Swap the holder/order on alternating iterations. A start timestamp alone is insufficient evidence.

All hooks live only in test processes; do not add production failure routes or alter service authorization/locking. Suggested bounds: ten iterations per mandatory race, two workers, 30-second startup/barrier deadlines, a 15-second controlled hold ceiling, and a 120-second iteration ceiling. Fail on a missing overlap witness, hung/orphan worker, unexpected exception or final invariant violation. Do not relabel sequential completion as concurrency PASS or automatically rerun a failed scenario to hide it. Use fresh plan/employee/key identities per iteration and stop all owned workers on failure.

| Race                                   | Fixture, expected outcome and assertions                                                                                                                                                                     | TiDB result |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------- |
| 1. Simultaneous V3 submissions         | Same DRAFT with explicit roster; one submit wins, loser conflicts. One V3_SUBMITTED event, complete IN_REVIEW projection, versions 1, no review ledger/Tasks/notifications                                   | BLOCKED     |
| 2. Roster update vs submit             | Same plan lock; submit sees either complete old roster or complete committed new roster. Update after submission conflicts; never partial projection or missing employee                                     | BLOCKED     |
| 3. Concurrent Assistant reviews        | Same employee/version, different decisions/keys: one immutable winner and one conflict. Also test disjoint employee batches both completing with unchanged unselected rows                                   | BLOCKED     |
| 4. Concurrent OWNER publication        | Real Assistant provenance, same employee/version, distinct keys: one publish batch, one conflict, exactly one Task per entry and one assignment notification per eligible Task                               | BLOCKED     |
| 5. OWNER publish vs stale confirmation | Valid current versions vs stale versions; stale writes no batch/decision/event/Task/notification. Published content and aggregate state remain consistent                                                    | BLOCKED     |
| 6. HR correction vs stale versions     | Valid entry/child update vs stale content version; one content increment, stale conflict, state remains HR_CORRECTION. Protected employees stay byte-identical; add edit-vs-resubmit variant                 | BLOCKED     |
| 7. Identical idempotent retries        | Same key and canonical payload, separately for Assistant/OWNER/HR; same logical batch/results, one ledger and one version transition, OWNER Tasks/notifications exactly once                                 | BLOCKED     |
| 8. Same key, different payloads        | Two eligible payloads compete for one stage/key; exactly one owns it, other receives mismatch conflict. No mixed ledger, unselected workflow mutation or duplicate side effects; cover all three batch types | BLOCKED     |

The services currently lock the plan before employee data, use ordered locks and three-attempt Laravel transactions. Review/correction batches have unique plan/stage/key or plan/key constraints plus payload hashes and authorized replay after QueryException. OWNER locks Assistant provenance, existing Tasks and memberships; TaskService nests Task and notification insertion inside the outer transaction. Tasks have unique weekly_plan_entry_id, while notifications rely on transaction/idempotency discipline rather than their own uniqueness key. Confirmed-record immutability is enforced through model behavior; compare raw persisted ledger fingerprints too, not only Eloquent results.

Synthetic publishers must be distinct from assignees so the expected assignment-notification count equals the published Task count; zero-work acceptance creates neither. Keep an explicit self-assignment variant with the service's intentional no-notification behavior rather than treating that suppression as data loss.

Assert Task source, organization, assignee/membership scope, dates, priority, generated child-item description and initial TODO status. Child items do not multiply Task count: publication creates one Task per entry. Check for orphan Tasks/notifications/decisions and compare already-published Task raw fingerprints through subsequent corrections and retries.

Capture worker PID, connection ID, approved endpoint, monotonic start/end and transaction/lock timestamps, commit/conflict/replay result, batch IDs/hashes, state/content versions, source-Task identities, scoped notification/event counts, and before/after immutable fingerprints. Do not archive full snapshot bodies, credentials or raw SQL bindings. A separate bounded synthetic lock-order probe can demonstrate deadlock capture; it is additional evidence, not a replacement for any race. Record SQLSTATE/vendor code and retry count. Existing three-attempt Laravel behavior must be observed; do not assume it handles every TiDB-specific conflict code or add a broad retry loop solely to obtain PASS. See [PingCAP transaction-error handling](https://docs.pingcap.com/developer/dev-guide-transaction-troubleshoot/).

## Cleanup and approval stages

After evidence is captured, stop coordinator/owned workers first. While the owned TiDB cluster is still running, re-verify database/account/cluster nonce/process provenance and require the exact approved database name before dropping only that schema. Revoke/remove only the synthetic account created for that run. Then gracefully stop only the owned Playground process. If a tag was used for diagnostics, retained data must be removed only after canonical ownership checks and a separate explicit cleanup decision. Never use `tiup clean --all`, wildcard database drops, broad recursive deletion, unrelated service stops or distro unregister as routine cleanup.

Approval stages: **A** installation/restart/resource limits and the reduced topology; **B** review hardened verifier and actual local cluster identity, then approve exact disposable database/account, migration/fixture/race operations and scoped cleanup; **C** approve original topology on adequate hardware and rerun required multi-instance evidence. None of these authorizes a release or remote company database access.

## Execution status and regressions

| Check                                                                  | Result                 | Evidence                                                                                                                         |
| ---------------------------------------------------------------------- | ---------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Staging base and PR #71 merge                                          | PASS                   | Git SHA and merge subject match the requested base                                                                               |
| Workstation/process/Windows-port audit                                 | PASS                   | Read-only CIM/service/listener observations above                                                                                |
| Optional-feature administrator state                                   | NOT VERIFIED           | Requires an actual elevated Windows token                                                                                        |
| WSL launch / Linux-side ports                                          | BLOCKED                | WSL not installed and installation not approved                                                                                  |
| Pure existing backend guard/manifest/launcher tests                    | PASS                   | 46 tests, 131 assertions; no migration handler or live database operation                                                        |
| Default frontend `npm test`                                            | FAIL                   | 229 passed, 17 Vite hook startup timeouts after 60 seconds; no application files changed in this audit                           |
| Full frontend with `node --test --test-concurrency=1 tests/*.test.mjs` | PASS                   | 246 tests, 78.8 seconds; same full suite serialized, without changing package scripts                                            |
| Lint                                                                   | PASS                   | Zero errors, four existing Fast Refresh warnings                                                                                 |
| Production build                                                       | PASS                   | Existing large-chunk warning only                                                                                                |
| Full Pint / changed-document Prettier / git diff --check               | PASS                   | Formatting and whitespace checks only, no database operation                                                                     |
| Full backend Feature suite / browser E2E rerun                         | BLOCKED for this audit | These execute migrations/fixtures; first-run approval boundary is audit-only. Prior PR #71 evidence is historical, not a new run |
| Effective TiDB identity/grants/empty schema                            | BLOCKED                | No installed/approved local TiDB and hardened runtime verifier not yet implemented                                               |
| Migration 1–21, ledger, constraints, JSON and re-execution             | BLOCKED                | No TiDB migration executed                                                                                                       |
| V2 preservation, guarded rollback and partial DDL                      | BLOCKED                | Planned lanes only                                                                                                               |
| All eight genuine races and deadlock evidence                          | BLOCKED                | No parallel TiDB workers executed                                                                                                |
| Database cleanup                                                       | NOT APPLICABLE         | No database was created or connected to in this audit                                                                            |

**NEEDS FIX / approval required for execution.** The audit/design is reviewable, but it is not TiDB validation. The current RAM headroom, missing WSL/TiDB tooling, unimplemented target verification/harness and unexecuted migration/races remain blockers. Rollout stays **NO-GO** pending actual TiDB evidence and separate release approval.
