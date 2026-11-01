[CmdletBinding()]
param(
    [string]$AuditConfigPath = '',
    [string]$SecretsPath = '',
    [Parameter(Mandatory = $true)][string]$ManifestPath,
    [string]$Batch = 'wra76-v1',
    [string]$EndDate = '',
    [string]$OutputPath = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$RepositoryRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$BackendRoot = Join-Path $RepositoryRoot 'backend'
$forbiddenRoots = @($RepositoryRoot)
$gitPointer = Join-Path $RepositoryRoot '.git'
if (Test-Path -LiteralPath $gitPointer -PathType Leaf) {
    $pointer = (Get-Content -LiteralPath $gitPointer -Raw).Trim()
    if ($pointer -match '^gitdir:\s*(.+)$') {
        $metadata = [IO.Path]::GetFullPath($Matches[1]).Replace('/', '\')
        $boundary = $metadata.IndexOf('\.git\', [StringComparison]::OrdinalIgnoreCase)
        if ($boundary -gt 0) { $forbiddenRoots += $metadata.Substring(0, $boundary) }
    }
}
if (-not $AuditConfigPath) { $AuditConfigPath = Join-Path $env:LOCALAPPDATA 'KPS/independent-staging-db-audit.json' }
if (-not $SecretsPath) { $SecretsPath = Join-Path $env:LOCALAPPDATA 'KPS/independent-staging-bootstrap-secrets.json' }
foreach ($fixturePath in @($AuditConfigPath, $SecretsPath, $ManifestPath)) {
    $absolute = [IO.Path]::GetFullPath($fixturePath)
    foreach ($root in $forbiddenRoots) {
        if ($absolute.StartsWith($root.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase)) {
            throw 'Fixture identity, credentials and ownership manifest must remain outside Git.'
        }
    }
}
$profile = Get-Content -LiteralPath $AuditConfigPath -Raw | ConvertFrom-Json
$secrets = Get-Content -LiteralPath $SecretsPath -Raw | ConvertFrom-Json
if ($profile.version -ne 3 -or $profile.expected_target -cne 'KPS-Staging' -or $profile.target_type -cne 'independent_instance' -or
    $profile.production_instance_name -cne 'KPS-Internal' -or $profile.connection_metadata_source -cne 'tidb_cloud_connect_dialog' -or
    $profile.application_database -cne 'kps_internal' -or $profile.staging_username_prefix -ceq $profile.production_username_prefix -or
    $secrets.expected_target -cne 'KPS-Staging' -or $secrets.connection_fingerprint -cne $profile.staging_fingerprint -or
    $secrets.runtime_username -cne ($profile.staging_username_prefix + '.kps_runtime')) {
    throw 'Unconfirmed or ambiguous staging identity. No child was started.'
}
if (Test-Path -LiteralPath $ManifestPath) {
    throw 'This read-only launcher only prepares a new dataset. Inspect existing signed manifests inside staging with the real APP_KEY.'
}

function ConvertTo-NativeArgument([string]$Value) {
    if ($Value -notmatch '[\s"]') { return $Value }
    $quoted = [regex]::Replace($Value, '(\\*)"', '$1$1\"')
    $quoted = [regex]::Replace($quoted, '(\\+)$', '$1$1')
    return '"' + $quoted + '"'
}

$bstr = [IntPtr]::Zero
$process = $null
try {
    $secure = ConvertTo-SecureString ([string]$secrets.encrypted_runtime_password)
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    $keyBytes = New-Object byte[] 32
    $random = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $random.GetBytes($keyBytes) } finally { $random.Dispose() }
    $processInfo = [Diagnostics.ProcessStartInfo]::new()
    $processInfo.FileName = (Get-Command php -CommandType Application | Select-Object -First 1).Source
    $processInfo.WorkingDirectory = $BackendRoot
    $processInfo.UseShellExecute = $false
    $processInfo.CreateNoWindow = $true
    $processInfo.RedirectStandardOutput = $true
    $processInfo.RedirectStandardError = $true
    $processInfo.StandardOutputEncoding = [Text.UTF8Encoding]::new($false)
    $processInfo.StandardErrorEncoding = [Text.UTF8Encoding]::new($false)
    $arguments = @('artisan', 'work-report-ai:fixture', '--mode=dry-run', ('--profile=' + [IO.Path]::GetFullPath($AuditConfigPath)),
        ('--manifest=' + [IO.Path]::GetFullPath($ManifestPath)), ('--batch=' + $Batch))
    if ($EndDate) { $arguments += '--end-date=' + $EndDate }
    $processInfo.Arguments = ($arguments | ForEach-Object { ConvertTo-NativeArgument $_ }) -join ' '
    $processInfo.EnvironmentVariables.Clear()
    foreach ($name in @('SystemRoot', 'WINDIR', 'TEMP', 'TMP')) {
        $value = [Environment]::GetEnvironmentVariable($name, 'Process')
        if ($value) { $processInfo.EnvironmentVariables[$name] = $value }
    }
    $child = @{
        APP_ENV = 'staging'; APP_DEBUG = 'false'; APP_KEY = 'base64:' + [Convert]::ToBase64String($keyBytes)
        APP_CONFIG_CACHE = Join-Path ([IO.Path]::GetTempPath()) ('kps-fixture-config-' + [guid]::NewGuid().ToString('N') + '.php')
        DB_CONNECTION = 'mysql'; DB_HOST = [string]$profile.staging_host; DB_PORT = [string]$profile.staging_port
        DB_DATABASE = 'kps_internal'; DB_USERNAME = [string]$secrets.runtime_username; DB_PASSWORD = $password
        MYSQL_ATTR_SSL_CA = [string]$profile.staging_ssl_ca; KPS_DATABASE_ENVIRONMENT = 'staging'
        KPS_STAGING_EPHEMERAL_FILES = 'true'; FILESYSTEM_DISK = 'local'; WEEKLY_PLAN_STORAGE_DISK = 'local'
        CACHE_STORE = 'array'; SESSION_DRIVER = 'array'; QUEUE_CONNECTION = 'sync'; MAIL_MAILER = 'array'
        WORK_REPORT_AI_ENABLED = 'false'; WORK_REPORT_AI_DRIVER = 'fake'; LOG_CHANNEL = 'stderr'
    }
    foreach ($entry in $child.GetEnumerator()) { $processInfo.EnvironmentVariables[$entry.Key] = [string]$entry.Value }
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $processInfo
    if (-not $process.Start()) { throw 'Read-only fixture child could not start.' }
    $stdoutTask = $process.StandardOutput.ReadToEndAsync()
    $stderrTask = $process.StandardError.ReadToEndAsync()
    if (-not $process.WaitForExit(45000)) { $process.Kill(); throw 'Read-only fixture preflight timed out.' }
    $stdout = $stdoutTask.GetAwaiter().GetResult()
    $null = $stderrTask.GetAwaiter().GetResult() # Never relay raw exceptions/connection details.
    $lastLine = @($stdout -split "`r?`n" | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })[-1]
    $result = $lastLine | ConvertFrom-Json
    if ($process.ExitCode -ne 0 -or $result.status -cne 'PASS' -or $result.mode -cne 'dry-run' -or $result.writes_executed -ne $false) {
        if ($result.PSObject.Properties.Name -contains 'code' -and [string]$result.code -match '^FIXTURE_[A-Z0-9_,]+$') {
            Write-Output ($result | ConvertTo-Json -Compress)
        }
        throw 'Verified staging dry-run did not PASS; no fixture was loaded.'
    }
    if ($OutputPath) {
        $outputAbsolute = [IO.Path]::GetFullPath($OutputPath)
        if (Test-Path -LiteralPath $outputAbsolute) { throw 'Dry-run evidence already exists; choose a new evidence filename.' }
        [IO.File]::WriteAllText($outputAbsolute, $lastLine, [Text.UTF8Encoding]::new($false))
    }
    Write-Output $lastLine
} finally {
    if ($bstr -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
    $password = $null
    if ($null -ne $process) { $process.Dispose() }
}
