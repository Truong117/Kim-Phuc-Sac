#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][ValidateSet('staging', 'production')][string]$Environment,
    [switch]$Setup,
    [switch]$StatusOnly,
    [string]$RepositoryRoot = (Get-Location).Path,
    [string]$Php = 'php'
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$releaseBranch = if ($Environment -eq 'production') { 'main' } else { 'staging' }
$store = Join-Path $env:LOCALAPPDATA 'KPS\MigrationTool'
$profilePath = Join-Path $store ($Environment + '.json')
$utf8 = New-Object System.Text.UTF8Encoding($false)
New-Item -ItemType Directory -Path $store -Force | Out-Null

function Read-Default([string]$Label, [string]$Default) {
    $value = Read-Host "$Label [$Default]"
    if ([string]::IsNullOrWhiteSpace($value)) { return $Default }
    return $value.Trim()
}
if ($Setup) {
    $caDefault = ''
    $existingStaging = Join-Path $store 'staging.json'
    if ($Environment -eq 'production' -and (Test-Path -LiteralPath $existingStaging)) {
        $caDefault = (Get-Content -LiteralPath $existingStaging -Raw | ConvertFrom-Json).ca
    }
    $profile = [ordered]@{
        version = 1; environment = $Environment
        host = Read-Default 'TiDB host' ''
        port = 4000; database = 'kps_internal'
        username = Read-Default 'Migrator username (copy from TiDB)' ''
        ca = (Read-Default 'CA file path' $caDefault).Trim('"')
    }
    if ($profile.host -notmatch '^[a-zA-Z0-9.-]+$' -or $profile.username -notmatch '^[a-zA-Z0-9]+\.kps_(ci_)?migrator$') {
        throw 'Invalid host or migrator username; do not include quotes, @%, or a URL.'
    }
    $profile.ca = (Resolve-Path -LiteralPath $profile.ca).Path
    $profile['ca_sha256'] = (Get-FileHash -LiteralPath $profile.ca -Algorithm SHA256).Hash.ToLowerInvariant()
    $other = Join-Path $store ($(if ($Environment -eq 'staging') { 'production' } else { 'staging' }) + '.json')
    if (Test-Path -LiteralPath $other) {
        $otherProfile = Get-Content -LiteralPath $other -Raw | ConvertFrom-Json
        if ($otherProfile.username.Split('.')[0] -ceq $profile.username.Split('.')[0]) { throw 'Staging and production must use different TiDB prefixes.' }
    }
    [IO.File]::WriteAllText($profilePath, ($profile | ConvertTo-Json), $utf8)
    Write-Host "Profile saved: $profilePath (no password)."
    return
}
if (!(Test-Path -LiteralPath $profilePath)) { throw "Run with -Environment $Environment -Setup once first." }
$profile = Get-Content -LiteralPath $profilePath -Raw | ConvertFrom-Json
if ($profile.version -ne 1 -or $profile.environment -cne $Environment) { throw 'Profile environment/version mismatch.' }
if ((Get-FileHash -LiteralPath $profile.ca -Algorithm SHA256).Hash.ToLowerInvariant() -cne $profile.ca_sha256) { throw 'CA changed. Check it and run -Setup again.' }
$RepositoryRoot = (Resolve-Path -LiteralPath $RepositoryRoot).Path
if (!(Test-Path -LiteralPath (Join-Path $RepositoryRoot 'backend\vendor\autoload.php'))) { throw 'RepositoryRoot must contain backend/vendor/autoload.php (install backend dependencies first).' }
$runner = Join-Path $PSScriptRoot 'kps-migrate.php'
if (!(Test-Path -LiteralPath $runner)) { throw 'Missing sibling kps-migrate.php.' }
$lock = $null; $password = $null; $pointer = [IntPtr]::Zero; $inputData = $null; $payload = $null
$temp = Join-Path ([IO.Path]::GetTempPath()) ('kps-migrate-' + [guid]::NewGuid())
$receipt = [ordered]@{ environment = $Environment; started_at = [DateTime]::UtcNow.ToString('o'); outcome = 'stopped'; commit = $null }
try {
    $lock = [IO.File]::Open((Join-Path $store ($Environment + '.lock')), 'OpenOrCreate', 'ReadWrite', 'None')
    $origin = & git -C $RepositoryRoot remote get-url origin
    if ($LASTEXITCODE -ne 0 -or $origin -notmatch '^(https://github\.com/|git@github\.com:)Truong117/Kim-Phuc-Sac(?:\.git)?/?$') { throw 'Origin must be Truong117/Kim-Phuc-Sac.' }
    & git -C $RepositoryRoot fetch origin "+refs/heads/${releaseBranch}:refs/remotes/origin/${releaseBranch}"
    if ($LASTEXITCODE -ne 0) { throw 'Git fetch failed; no DB connection attempted.' }
    $source = (& git -C $RepositoryRoot rev-parse "refs/remotes/origin/$releaseBranch").Trim()
    if ($LASTEXITCODE -ne 0 -or $source -notmatch '^[a-f0-9]{40}$') { throw 'Cannot resolve release SHA.' }
    & git -C $RepositoryRoot diff --quiet $source -- backend
    if ($LASTEXITCODE -ne 0) { throw 'Local tracked backend differs from target release. Update/stash local work before running; no DB connection attempted.' }
    $receipt.commit = $source
    New-Item -ItemType Directory -Path $temp | Out-Null
    $paths = @(& git -C $RepositoryRoot ls-tree -r --name-only $source -- backend/database/migrations)
    if ($LASTEXITCODE -ne 0) { throw 'Cannot list release migrations.' }
    $files = @()
    foreach ($path in $paths) {
        if ($path -notmatch '^backend/database/migrations/[0-9_]+_[a-z0-9_]+\.php$') { throw "Unexpected migration path: $path" }
        $content = & git -C $RepositoryRoot show "${source}:$path"
        if ($LASTEXITCODE -ne 0) { throw "Cannot read $path" }
        $file = Join-Path $temp ([IO.Path]::GetFileName($path))
        [IO.File]::WriteAllText($file, (($content -join "`n") + "`n"), $utf8)
        $files += [ordered]@{ path = $file; sha256 = (Get-FileHash $file -Algorithm SHA256).Hash.ToLowerInvariant() }
    }
    if ($files.Count -eq 0) { throw 'No migration files found.' }
    Write-Host "TARGET: $Environment | $($profile.host) | $($profile.database) | $($profile.username)"
    Write-Host "SOURCE: origin/$releaseBranch @ $source"
    $password = Read-Host 'Migrator password' -AsSecureString
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($password)
    $inputData = @{ profile = $profile; password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer); files = $files; mode = 'plan'; expected_ledger = $null }
    function Invoke-Runner {
        $json = $inputData | ConvertTo-Json -Depth 8 -Compress
        $payload = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($json))
        try {
            $reply = $payload | & $Php -d display_errors=stderr $runner (Join-Path $RepositoryRoot 'backend')
            if ($LASTEXITCODE -ne 0) { throw 'Migration runner stopped; inspect the error above. Do not blindly rerun partial DDL.' }
            return (($reply -join "`n") | ConvertFrom-Json)
        } finally { $json = $null; $payload = $null }
    }
    $plan = Invoke-Runner
    $receipt['pending'] = @($plan.pending)
    Write-Host "Identity verified: $($plan.account); TLS enabled."
    Write-Host "Applied: $($plan.applied_count); pending: $(@($plan.pending).Count)"
    foreach ($name in $plan.pending) { Write-Host "  + $name" }
    if ($StatusOnly -or @($plan.pending).Count -eq 0) { $receipt.outcome = 'status_ok'; return }
    # Same password remains in process memory; no second prompt and no credential file.
    $inputData.mode = 'execute'
    $inputData.expected_ledger = $plan.ledger_sha256
    $result = Invoke-Runner
    $receipt['applied'] = @($result.executed)
    $receipt.outcome = 'success'
    Write-Host "MIGRATION_OK: $Environment; $($result.applied_count) applied; no pending migrations."
} finally {
    if ($pointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    if ($password) { $password.Dispose() }
    if ($inputData) { $inputData.Clear() }
    $payload = $null
    Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue
    if ($lock) {
        $receipt['finished_at'] = [DateTime]::UtcNow.ToString('o')
        $logDir = Join-Path $store 'logs'
        New-Item -ItemType Directory -Path $logDir -Force | Out-Null
        $logPath = Join-Path $logDir ($Environment + '-' + [guid]::NewGuid() + '.json')
        try { [IO.File]::WriteAllText($logPath, ($receipt | ConvertTo-Json -Depth 5), $utf8); Write-Host "Receipt: $logPath" }
        finally { $lock.Dispose() }
    }
}
