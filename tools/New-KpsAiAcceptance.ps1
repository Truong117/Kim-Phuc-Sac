#requires -Version 5.1
[CmdletBinding()]
param(
    [switch]$Apply,
    [string]$WeekEnd,
    [string]$Username,
    [string]$RepositoryRoot = (Get-Location).Path,
    [string]$Php = 'php'
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$profileStore = Join-Path $env:LOCALAPPDATA 'KPS\MigrationTool'
$fixtureStore = Join-Path $env:LOCALAPPDATA 'KPS\AiAcceptance'
$utf8 = New-Object System.Text.UTF8Encoding($false)
$stagingFile = Join-Path $profileStore 'staging.json'
$productionFile = Join-Path $profileStore 'production.json'
if (!(Test-Path -LiteralPath $stagingFile) -or !(Test-Path -LiteralPath $productionFile)) {
    throw 'Both existing MigrationTool profiles are required to exclude the production TiDB prefix.'
}
$staging = Get-Content -LiteralPath $stagingFile -Raw | ConvertFrom-Json
$production = Get-Content -LiteralPath $productionFile -Raw | ConvertFrom-Json
if ($staging.environment -cne 'staging' -or $production.environment -cne 'production' -or
    $staging.username -notmatch '^([a-zA-Z0-9]+)\.kps_(ci_)?migrator$') { throw 'Invalid staging profile.' }
$stagingPrefix = $staging.username.Split('.')[0]
if ($production.username -notmatch '^[a-zA-Z0-9]+\.kps_(ci_)?migrator$' -or
    $stagingPrefix -ieq $production.username.Split('.')[0]) { throw 'Staging/production prefixes must differ.' }
if ([string]::IsNullOrWhiteSpace($Username)) { $Username = $stagingPrefix + '.root' }
if ($Username -cne ($stagingPrefix + '.root') -and $Username -cne ($stagingPrefix + '.kps_runtime')) {
    throw 'Use the exact staging root or runtime username; a migrator does not necessarily have fixture INSERT grants.'
}
if ((Get-FileHash -LiteralPath $staging.ca -Algorithm SHA256).Hash.ToLowerInvariant() -cne $staging.ca_sha256) {
    throw 'Staging CA missing or changed. Review the existing profile first.'
}
$gitRoot = & git -C $RepositoryRoot rev-parse --show-toplevel
if ($LASTEXITCODE -ne 0) { throw 'Run from within the KPS repository or pass -RepositoryRoot.' }
$RepositoryRoot = (Resolve-Path -LiteralPath $gitRoot.Trim()).Path
$origin = & git -C $RepositoryRoot remote get-url origin
if ($LASTEXITCODE -ne 0 -or $origin -notmatch '^(https://github\.com/|git@github\.com:)Truong117/Kim-Phuc-Sac(?:\.git)?/?$') { throw 'Unexpected repository origin.' }
& git -C $RepositoryRoot fetch origin '+refs/heads/staging:refs/remotes/origin/staging'
if ($LASTEXITCODE -ne 0) { throw 'Cannot fetch staging; no DB connection attempted.' }
$release = (& git -C $RepositoryRoot rev-parse origin/staging).Trim()
if ($LASTEXITCODE -ne 0) { throw 'Cannot resolve staging SHA.' }
& git -C $RepositoryRoot diff --quiet $release -- backend
if ($LASTEXITCODE -ne 0) { throw 'Local tracked backend differs from origin/staging. Update local staging first; no DB connection attempted.' }
$runner = Join-Path $PSScriptRoot 'kps-ai-acceptance.php'
if (!(Test-Path -LiteralPath $runner)) { throw 'Missing sibling kps-ai-acceptance.php.' }
New-Item -ItemType Directory -Path $fixtureStore -Force | Out-Null
$lock = $null; $password = $null; $pointer = [IntPtr]::Zero; $inputData = $null; $payload = $null
try {
    $lock = [IO.File]::Open((Join-Path $fixtureStore 'staging.lock'), 'OpenOrCreate', 'ReadWrite', 'None')
    Write-Host 'TARGET: STAGING only. This inserts synthetic staff/reports, never migrates or calls AI.'
    Write-Host "SOURCE: origin/staging @ $release"
    Write-Host "LOGIN: $Username (use this account's password, NOT the migrator password)"
    $password = Read-Host 'TiDB STAGING account password' -AsSecureString
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($password)
    $selectedEnd = if ([string]::IsNullOrWhiteSpace($WeekEnd)) { $null } else { $WeekEnd }
    $inputData = @{ staging = $staging; production = $production; username = $Username;
        password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer); week_end = $selectedEnd; mode = 'plan' }
    function Invoke-Fixture {
        $json = $inputData | ConvertTo-Json -Depth 8 -Compress
        try {
            $payload = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($json))
            $reply = $payload | & $Php -d display_errors=stderr $runner (Join-Path $RepositoryRoot 'backend')
            if ($LASTEXITCODE -ne 0) { throw 'Fixture STOP. Inspect the safe error code; no automatic overwrite or repair.' }
            return (($reply -join "`n") | ConvertFrom-Json)
        } finally { $json = $null; $payload = $null }
    }
    $plan = Invoke-Fixture
    Write-Host "PLAN_OK: week ending $($plan.week_end); dates $(@($plan.dates) -join ', ')"
    Write-Host "Department: $($plan.department)"
    Write-Host "Fixture: $($plan.expected.users) staff / $($plan.expected.reports) reports / $($plan.expected.items) work items"
    Write-Host "Existing namespace: $($plan.existing.state)"
    if (!$Apply) {
        Write-Host 'DRY RUN ONLY: no rows inserted. Run again with -Apply to load this fixture into STAGING.'
        return
    }
    $inputData.mode = 'apply'
    $inputData.week_end = $plan.week_end
    $inputData['approval'] = $plan.approval
    $result = Invoke-Fixture
    $logPath = Join-Path $fixtureStore ('staging-' + [guid]::NewGuid() + '.json')
    [IO.File]::WriteAllText($logPath, ($result | ConvertTo-Json -Depth 8), $utf8)
    Write-Host "FIXTURE_OK: reused=$($result.reused); week ending $($result.week_end)"
    Write-Host "Department ID: $($result.owned.department_id)"
    Write-Host 'Expected weekly totals: Sale 40 products; Editor 5 images + 3 videos; cumulative sale stays unconfirmed.'
    Write-Host "Receipt: $logPath"
} finally {
    if ($pointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    if ($password) { $password.Dispose() }
    if ($inputData) { $inputData.Clear() }
    $payload = $null
    if ($lock) { $lock.Dispose() }
}
