# Reusable Windows migration tool

Requires Windows PowerShell 5.1+ (or PowerShell 7 on Windows), Git, PHP CLI with pdo_mysql and the backend's installed Composer dependencies. No extra packages. The tool does not change .env, grants, runtime credentials, application code or Render settings. It does not bootstrap or seed a database and never runs down/rollback. Only released, reviewed migrations should be merged into staging/main.

## Install without switching local branches

From D:\DQT\KPS\Kim-Phuc-Sac, fetch the tool feature branch and copy the two tool files out of Git into `%LOCALAPPDATA%\KPS\MigrationTool`. This allows use before the tool PR is merged and does not overwrite local files. Pin the installation to the fetched commit SHA; record it when updating.

```powershell
git fetch origin feature/migration-tool
if ($LASTEXITCODE -ne 0) { throw 'Fetch failed' }
$kpsToolCommit = (git rev-parse FETCH_HEAD).Trim()
$kpsToolDir = Join-Path $env:LOCALAPPDATA 'KPS\MigrationTool'
New-Item -ItemType Directory -Path $kpsToolDir -Force | Out-Null
$kpsEncoding = New-Object System.Text.UTF8Encoding($false)
foreach ($kpsFile in @('Migrate-Kps.ps1', 'kps-migrate.php')) {
    $kpsText = git show "${kpsToolCommit}:tools/$kpsFile"
    if ($LASTEXITCODE -ne 0) { throw "Cannot read $kpsFile" }
    [IO.File]::WriteAllText((Join-Path $kpsToolDir $kpsFile), ($kpsText -join "`n"), $kpsEncoding)
}
Write-Host "Installed tool commit: $kpsToolCommit"
```

## One-time profiles

```powershell
& "$env:LOCALAPPDATA\KPS\MigrationTool\Migrate-Kps.ps1" -Environment staging -Setup
& "$env:LOCALAPPDATA\KPS\MigrationTool\Migrate-Kps.ps1" -Environment production -Setup
```

Setup asks for the host, full migrator username and CA path once for each environment. Port 4000 and database kps_internal are fixed. Production Setup offers the existing staging CA path as a default. Copy the exact username case from TiDB; do not append @% or SQL quotes. Internal endpoint/account details are deliberately absent from repository source. Profiles are workstation-local JSON with CA hash; no passwords. Updating a profile or moving the CA requires -Setup again. Do not commit profiles.

## Daily usage

Run from repository root (or pass -RepositoryRoot). The tool fetches staging for staging and main for production. The tracked local backend must match that release; if it differs, update through the normal Git workflow while preserving local edits. Installed vendor packages must be compatible with that release's composer.lock. StatusOnly connects through PDO without bootstrapping Laravel.

```powershell
& "$env:LOCALAPPDATA\KPS\MigrationTool\Migrate-Kps.ps1" -Environment staging -StatusOnly
& "$env:LOCALAPPDATA\KPS\MigrationTool\Migrate-Kps.ps1" -Environment staging
& "$env:LOCALAPPDATA\KPS\MigrationTool\Migrate-Kps.ps1" -Environment production
```

Only the password is prompted on routine runs. The non-StatusOnly command authorizes **all pending migrations from the selected release**. It prints target, release SHA and pending names before executing. Review StatusOnly first for unfamiliar/destructive migrations. Password goes only through child stdin, never arguments, .env, disk profiles or receipts. Managed strings may remain in process memory until GC; complete memory erasure is not guaranteed.

Safeguards: verified TLS and CA hash; exact authenticated account; existing baseline of at least 24 unique migration rows; reject unknown names, gaps or duplicates; pinned remote SHA and file checksums; plan/execute ledger comparison; stop on first failure; postflight no pending ledger entries. Schema-specific/manual migrations still need their own reviewed preflight/postflight and any backup/writer freeze required by their runbook. This tool does not replace those requirements. Ledger success alone does not prove arbitrary schema/data correctness.

A local file lock per environment prevents overlap on this workstation. **No cross-machine lock**: use one migration operator at a time. TiDB DDL is not rollbackable as a group. If execution fails midway, earlier successful DDL remains. Inspect schema and ledger before retrying; never insert ledger rows or drop columns merely to clear an error. Generic runner errors expose only SQLSTATE/code; use restricted application/DB logs for detailed diagnostics.

Receipts: `%LOCALAPPDATA%\KPS\MigrationTool\logs`. Contain environment, pinned SHA, pending/applied names and outcome, no credentials or raw SQL. After failure, pending records the attempted scope; inspect the live ledger for the subset applied. Installation and Setup never execute migrations.

## Verification

`php tools/test-kps-migrate.php`: offline guard tests, no DB access. PHP syntax/offline tests checked in development. Windows PowerShell and real TiDB StatusOnly still need verification on the operator workstation; development has no live DB credentials.

## Safe runner diagnostics

Runner failures include the exception type, phase (`input_decode`, `plan`, or `execute`), source basename and line number. Unexpected exception messages, SQL and stack traces remain hidden. A failure in `input_decode` or `plan` happens before this invocation starts migrations. An `execute` failure requires schema/ledger inspection before any write retry. Run `-StatusOnly` for diagnosis; do not use a write rerun to uncover an error. The installed `%LOCALAPPDATA%\KPS\MigrationTool\kps-migrate.php` must be refreshed separately from the repository copy.
