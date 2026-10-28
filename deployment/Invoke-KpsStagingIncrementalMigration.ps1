[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet(
        '2026_10_27_000000_add_google_docs_source_to_weekly_plans.php',
        '2026_10_27_010000_create_weekly_plan_google_docs_employee_mapping_tables.php',
        '2026_10_28_000000_create_weekly_plan_entry_items_tables.php'
    )]
    [string]$Migration,
    [switch]$Status,
    [switch]$Plan,
    [switch]$Execute,
    [string]$PlanReceiptPath = '',
    [switch]$AcknowledgeBackupAvailable,
    [switch]$AcknowledgeRecoveryPoint,
    [switch]$AcknowledgeNewInstanceRestore,
    [switch]$AcknowledgeRpo,
    [string]$AuditConfigPath = '',
    [string]$SecretsPath = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$selectedModeCount = @($Status, $Plan, $Execute).Where({ $_.IsPresent }).Count

if ($selectedModeCount -gt 1) {
    throw 'Choose only one mode: -Status, -Plan, or -Execute.'
}

$Mode = if ($Execute.IsPresent) {
    'Execute'
} elseif ($Plan.IsPresent) {
    'Plan'
} else {
    'Status'
}

$RepositoryRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$BackendRoot = Join-Path $RepositoryRoot 'backend'
$InspectorScript = Join-Path $BackendRoot 'scripts\inspect-staging-incremental-migration.php'
$MigrationLauncher = Join-Path $BackendRoot 'scripts\run-staging-incremental-migration.php'
$ApplicationDatabase = 'kps_internal'
$ExpectedTarget = 'KPS-Staging'
$ExpectedProductionTarget = 'KPS-Internal'
$PlanReceiptTtlMinutes = 15
$ReceiptStoreRoot = Join-Path $env:LOCALAPPDATA 'KPS\incremental-migration-receipts'
$AllowedMigrations = @(
    '2026_10_27_000000_add_google_docs_source_to_weekly_plans.php',
    '2026_10_27_010000_create_weekly_plan_google_docs_employee_mapping_tables.php',
    '2026_10_28_000000_create_weekly_plan_entry_items_tables.php'
)

if ($Migration -notin $AllowedMigrations -or [IO.Path]::GetFileName($Migration) -cne $Migration) {
    throw 'Migration is not in the reviewed KPS-Staging incremental allowlist.'
}

if ([string]::IsNullOrWhiteSpace($AuditConfigPath)) {
    $AuditConfigPath = Join-Path $env:LOCALAPPDATA 'KPS\independent-staging-db-audit.json'
}

if ([string]::IsNullOrWhiteSpace($SecretsPath)) {
    $SecretsPath = Join-Path $env:LOCALAPPDATA 'KPS\independent-staging-bootstrap-secrets.json'
}

$ResolvedAuditConfigPath = [IO.Path]::GetFullPath($AuditConfigPath)
$ResolvedSecretsPath = [IO.Path]::GetFullPath($SecretsPath)
$RepositoryPrefix = $RepositoryRoot.TrimEnd([IO.Path]::DirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar

foreach ($path in @($ResolvedAuditConfigPath, $ResolvedSecretsPath)) {
    if ($path.StartsWith($RepositoryPrefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Staging credentials and migration secrets must remain outside the Git repository.'
    }
}

function Get-Sha256 {
    param([Parameter(Mandatory = $true)][string]$Value)

    $sha = [Security.Cryptography.SHA256]::Create()

    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes($Value)
        return ([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha.Dispose()
    }
}

function ConvertTo-NativeArgument {
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string]$Value)

    if ($Value -notmatch '[\s"]') {
        return $Value
    }

    $escaped = [regex]::Replace($Value, '(\\*)"', '$1$1\"')
    $escaped = [regex]::Replace($escaped, '(\\+)$', '$1$1')
    return '"' + $escaped + '"'
}

function New-IsolatedProcessEnvironment {
    param(
        [Parameter(Mandatory = $true)][hashtable]$ExplicitValues,
        [Parameter(Mandatory = $true)][string[]]$AllowedNames
    )

    $allowed = @{}
    foreach ($name in $AllowedNames) {
        $allowed[$name.ToUpperInvariant()] = $true
    }

    $environment = @{}
    foreach ($name in @('SystemRoot', 'WINDIR', 'TEMP', 'TMP')) {
        $value = [Environment]::GetEnvironmentVariable($name, 'Process')
        if (-not [string]::IsNullOrEmpty($value) -and $allowed.ContainsKey($name.ToUpperInvariant())) {
            $environment[$name] = $value
        }
    }

    foreach ($entry in $ExplicitValues.GetEnumerator()) {
        if (-not $allowed.ContainsKey(([string]$entry.Key).ToUpperInvariant())) {
            throw "Environment variable '$($entry.Key)' is not allowed for this isolated child."
        }

        $environment[[string]$entry.Key] = [string]$entry.Value
    }

    return $environment
}

function Invoke-IsolatedPhpProcess {
    param(
        [Parameter(Mandatory = $true)][string]$ScriptPath,
        [Parameter(Mandatory = $true)][AllowEmptyCollection()][string[]]$Arguments,
        [Parameter(Mandatory = $true)][hashtable]$EnvironmentValues,
        [Parameter(Mandatory = $true)][string[]]$AllowedEnvironmentNames,
        [Parameter(Mandatory = $true)][string]$WorkingDirectory
    )

    $phpCommand = Get-Command php -CommandType Application -ErrorAction Stop | Select-Object -First 1
    $isolatedEnvironment = New-IsolatedProcessEnvironment $EnvironmentValues $AllowedEnvironmentNames
    $processInfo = [Diagnostics.ProcessStartInfo]::new()
    $processInfo.FileName = $phpCommand.Source
    $processInfo.WorkingDirectory = $WorkingDirectory
    $processInfo.UseShellExecute = $false
    $processInfo.CreateNoWindow = $true
    $processInfo.RedirectStandardOutput = $true
    $processInfo.RedirectStandardError = $true
    $processInfo.Arguments = (@($ScriptPath) + $Arguments | ForEach-Object {
        ConvertTo-NativeArgument ([string]$_)
    }) -join ' '
    $processInfo.EnvironmentVariables.Clear()

    foreach ($entry in $isolatedEnvironment.GetEnumerator()) {
        $processInfo.EnvironmentVariables[[string]$entry.Key] = [string]$entry.Value
    }

    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $processInfo

    try {
        if (-not $process.Start()) {
            throw 'Unable to start the isolated PHP child process.'
        }

        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()
        $process.WaitForExit()
        $stdout = $stdoutTask.GetAwaiter().GetResult()
        $stderr = $stderrTask.GetAwaiter().GetResult()

        return [pscustomobject]@{
            ExitCode = $process.ExitCode
            StdOut = $stdout
            StdErr = $stderr
            EnvironmentNames = @($isolatedEnvironment.Keys | Sort-Object)
        }
    } finally {
        $process.Dispose()

        foreach ($secretName in @(
            'KPS_STAGING_DB_PASSWORD',
            'KPS_STAGING_AUDIT_PASSWORD',
            'KPS_RUNNER_DB_PASSWORD'
        )) {
            if ($EnvironmentValues.ContainsKey($secretName)) {
                $EnvironmentValues[$secretName] = ''
            }
        }
    }
}

function Get-ConnectionFingerprint {
    param(
        [Parameter(Mandatory = $true)][string]$HostName,
        [Parameter(Mandatory = $true)][int]$Port,
        [Parameter(Mandatory = $true)][string]$Username,
        [Parameter(Mandatory = $true)][string]$Database
    )

    return Get-Sha256 ('{0}|{1}|{2}|{3}' -f (
        $HostName.Trim().ToLowerInvariant()
    ), $Port, (
        $Username.Trim().ToLowerInvariant()
    ), (
        $Database.Trim().ToLowerInvariant()
    ))
}

function Get-EndpointFingerprint {
    param(
        [Parameter(Mandatory = $true)][string]$HostName,
        [Parameter(Mandatory = $true)][int]$Port
    )

    return Get-Sha256 ('{0}|{1}' -f $HostName.Trim().ToLowerInvariant(), $Port)
}

function Get-StarterUsernamePrefix {
    param([Parameter(Mandatory = $true)][string]$Username)

    if ($Username -notmatch '^(?<prefix>[A-Za-z0-9]{1,64})\.(?<account>[A-Za-z0-9_][A-Za-z0-9_-]{0,63})$') {
        throw 'TiDB Cloud Starter username must use the format <instance-prefix>.<account>.'
    }

    return [string]$Matches.prefix
}

function Convert-SecureStringToPlainText {
    param([Parameter(Mandatory = $true)][Security.SecureString]$SecureValue)

    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($SecureValue)

    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
}

function Unprotect-RunnerSecret {
    param([Parameter(Mandatory = $true)][string]$ProtectedValue)

    return Convert-SecureStringToPlainText (ConvertTo-SecureString $ProtectedValue)
}

function Get-FileSha256 {
    param([Parameter(Mandatory = $true)][string]$Path)

    $stream = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::ReadWrite)
    $sha = [Security.Cryptography.SHA256]::Create()

    try {
        return ([BitConverter]::ToString($sha.ComputeHash($stream))).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha.Dispose()
        $stream.Dispose()
    }
}

function Open-VerifiedMigrationFile {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$ExpectedHash
    )

    $stream = [IO.File]::Open(
        $Path,
        [IO.FileMode]::Open,
        [IO.FileAccess]::Read,
        [IO.FileShare]::Read
    )
    $sha = [Security.Cryptography.SHA256]::Create()

    try {
        $actualHash = ([BitConverter]::ToString($sha.ComputeHash($stream))).Replace('-', '').ToLowerInvariant()
        $stream.Position = 0

        if ($actualHash -cne $ExpectedHash) {
            throw 'The migration file changed after Plan approval. No migration was run.'
        }

        return $stream
    } catch {
        $stream.Dispose()
        throw
    } finally {
        $sha.Dispose()
    }
}

function Assert-OutsideRepositoryPath {
    param([Parameter(Mandatory = $true)][string]$Path)

    $resolvedPath = [IO.Path]::GetFullPath($Path)
    $repositoryPrefix = $RepositoryRoot.TrimEnd([IO.Path]::DirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar

    if ($resolvedPath.StartsWith($repositoryPrefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Plan receipts and staging credentials must remain outside the Git repository.'
    }

    return $resolvedPath
}

function Get-InspectionStateDigest {
    param([Parameter(Mandatory = $true)]$InspectionResult)

    $payload = [ordered]@{
        migration = [string]$InspectionResult.migration
        identity = $InspectionResult.identity
        ledger = $InspectionResult.ledger
        schema = $InspectionResult.schema
        concurrency = $InspectionResult.concurrency
        baseline = $InspectionResult.baseline
    }

    return Get-Sha256 ($payload | ConvertTo-Json -Depth 20 -Compress)
}

function New-PlanReceiptObject {
    param(
        [Parameter(Mandatory = $true)]$Config,
        [Parameter(Mandatory = $true)]$Secrets,
        [Parameter(Mandatory = $true)]$InspectionResult,
        [Parameter(Mandatory = $true)][string]$MigrationHash,
        [Parameter(Mandatory = $true)][datetimeoffset]$IssuedAt
    )

    if ($InspectionResult.mode -cne 'plan' -or
        $InspectionResult.status -cne 'PASS' -or
        [string]$InspectionResult.migration -cne $Migration) {
        throw 'Only a successful Plan may create an Execute receipt.'
    }

    return [ordered]@{
        version = 1
        mode = 'PLAN'
        status = 'PASS'
        migration = $Migration
        migration_sha256 = $MigrationHash
        target = [ordered]@{
            host = [string]$Config.staging_host
            port = [int]$Config.staging_port
            database = $ApplicationDatabase
            migrator_username = [string]$Secrets.migration_username
        }
        ledger = $InspectionResult.ledger
        preflight_digest = Get-InspectionStateDigest $InspectionResult
        issued_at = $IssuedAt.UtcDateTime.ToString('o')
        expires_at = $IssuedAt.AddMinutes($PlanReceiptTtlMinutes).UtcDateTime.ToString('o')
        nonce = [Guid]::NewGuid().ToString('N')
    }
}

function Write-ProtectedPlanReceipt {
    param(
        [Parameter(Mandatory = $true)]$Receipt,
        [string]$RequestedPath = ''
    )

    if ($env:OS -cne 'Windows_NT') {
        throw 'Plan receipts require Windows DPAPI CurrentUser protection.'
    }

    $path = if ([string]::IsNullOrWhiteSpace($RequestedPath)) {
        Join-Path $ReceiptStoreRoot ('plan-' + $Receipt.nonce + '.dpapi')
    } else {
        $RequestedPath
    }
    $path = Assert-OutsideRepositoryPath $path

    if (Test-Path -LiteralPath $path) {
        throw 'The Plan receipt path already exists and was not overwritten.'
    }

    $directory = Split-Path -Parent $path
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }

    $json = $Receipt | ConvertTo-Json -Depth 20 -Compress
    $protected = ConvertFrom-SecureString (ConvertTo-SecureString $json -AsPlainText -Force)
    Set-Content -LiteralPath $path -Value $protected -Encoding UTF8 -NoNewline

    return $path
}

function Read-ProtectedPlanReceipt {
    param([Parameter(Mandatory = $true)][string]$Path)

    if ($env:OS -cne 'Windows_NT') {
        throw 'Plan receipts require Windows DPAPI CurrentUser protection.'
    }

    $resolvedPath = Assert-OutsideRepositoryPath $Path
    if (-not (Test-Path -LiteralPath $resolvedPath -PathType Leaf)) {
        throw 'The successful Plan receipt is missing or has already been removed.'
    }

    try {
        $protected = (Get-Content -LiteralPath $resolvedPath -Raw).Trim()
        $json = Convert-SecureStringToPlainText (ConvertTo-SecureString $protected)
        return $json | ConvertFrom-Json
    } catch {
        throw 'The successful Plan receipt is invalid for the current Windows user.'
    }
}

function Assert-PlanReceiptMatches {
    param(
        [Parameter(Mandatory = $true)]$Receipt,
        [Parameter(Mandatory = $true)]$Config,
        [Parameter(Mandatory = $true)]$Secrets,
        [Parameter(Mandatory = $true)]$InspectionResult,
        [Parameter(Mandatory = $true)][string]$MigrationHash,
        [Parameter(Mandatory = $true)][datetimeoffset]$Now
    )

    $issuedAt = [datetimeoffset]::MinValue
    $expiresAt = [datetimeoffset]::MinValue
    $issuedValid = [datetimeoffset]::TryParse([string]$Receipt.issued_at, [ref]$issuedAt)
    $expiresValid = [datetimeoffset]::TryParse([string]$Receipt.expires_at, [ref]$expiresAt)

    if ($Receipt.version -ne 1 -or
        $Receipt.mode -cne 'PLAN' -or
        $Receipt.status -cne 'PASS' -or
        -not $issuedValid -or
        -not $expiresValid -or
        $expiresAt -le $issuedAt -or
        $expiresAt -gt $issuedAt.AddMinutes($PlanReceiptTtlMinutes) -or
        $Now -lt $issuedAt -or
        $Now -ge $expiresAt -or
        [string]$Receipt.migration -cne $Migration -or
        [string]$Receipt.migration_sha256 -cne $MigrationHash -or
        [string]$Receipt.target.host -cne [string]$Config.staging_host -or
        [int]$Receipt.target.port -ne [int]$Config.staging_port -or
        [string]$Receipt.target.database -cne $ApplicationDatabase -or
        [string]$Receipt.target.migrator_username -cne [string]$Secrets.migration_username -or
        [string]$Receipt.preflight_digest -cne (Get-InspectionStateDigest $InspectionResult)) {
        throw 'The successful Plan receipt is expired, stale, mismatched, or not valid for this preflight.'
    }

    if ([string]$Receipt.nonce -notmatch '^[a-f0-9]{32}$') {
        throw 'The successful Plan receipt nonce is invalid.'
    }
}

function Use-PlanReceiptOnce {
    param([Parameter(Mandatory = $true)]$Receipt)

    $consumedDirectory = Join-Path $ReceiptStoreRoot 'consumed'
    $consumedDirectory = Assert-OutsideRepositoryPath $consumedDirectory

    if (-not (Test-Path -LiteralPath $consumedDirectory -PathType Container)) {
        New-Item -ItemType Directory -Path $consumedDirectory -Force | Out-Null
    }

    $marker = Join-Path $consumedDirectory ([string]$Receipt.nonce + '.used')

    try {
        $stream = [IO.File]::Open(
            $marker,
            [IO.FileMode]::CreateNew,
            [IO.FileAccess]::Write,
            [IO.FileShare]::None
        )
        $writer = [IO.StreamWriter]::new($stream, [Text.Encoding]::UTF8)
        try {
            $writer.Write([DateTimeOffset]::UtcNow.UtcDateTime.ToString('o'))
        } finally {
            $writer.Dispose()
        }
    } catch [IO.IOException] {
        throw 'The successful Plan receipt was already consumed.'
    }
}

function Get-ValidatedAuditConfig {
    if (-not (Test-Path -LiteralPath $ResolvedAuditConfigPath -PathType Leaf)) {
        throw 'The independent KPS-Staging DPAPI audit configuration is missing.'
    }

    $config = Get-Content -LiteralPath $ResolvedAuditConfigPath -Raw | ConvertFrom-Json

    if ($config.version -ne 3 -or
        $config.target_type -ne 'independent_instance' -or
        $config.expected_target -cne $ExpectedTarget -or
        $config.production_instance_name -cne $ExpectedProductionTarget -or
        $config.application_database -ne $ApplicationDatabase -or
        $config.staging_database -ne 'sys' -or
        $config.connection_metadata_source -ne 'tidb_cloud_connect_dialog') {
        throw 'The saved DPAPI audit configuration is not the reviewed independent KPS-Staging profile.'
    }

    $stagingPrefix = Get-StarterUsernamePrefix ([string]$config.staging_username)
    $productionPrefix = Get-StarterUsernamePrefix ([string]$config.production_username)

    if ($stagingPrefix -cne [string]$config.staging_username_prefix -or
        $productionPrefix -cne [string]$config.production_username_prefix -or
        $stagingPrefix -ceq $productionPrefix -or
        (Get-Sha256 $productionPrefix) -ne [string]$config.production_username_prefix_fingerprint) {
        throw 'The saved TiDB Starter username-prefix evidence is invalid or ambiguous.'
    }

    $connectionFingerprint = Get-ConnectionFingerprint (
        [string]$config.staging_host
    ) ([int]$config.staging_port) (
        [string]$config.staging_username
    ) (
        [string]$config.staging_database
    )
    $stagingEndpointFingerprint = Get-EndpointFingerprint (
        [string]$config.staging_host
    ) ([int]$config.staging_port)
    $productionEndpointFingerprint = Get-EndpointFingerprint (
        [string]$config.production_host
    ) ([int]$config.production_port)

    if ($connectionFingerprint -ne [string]$config.staging_fingerprint -or
        $stagingEndpointFingerprint -ne [string]$config.staging_endpoint_fingerprint -or
        $productionEndpointFingerprint -ne [string]$config.production_endpoint_fingerprint -or
        (($stagingEndpointFingerprint -eq $productionEndpointFingerprint) -ne [bool]$config.shared_gateway)) {
        throw 'The saved KPS-Staging connection fingerprints no longer match their confirmed metadata.'
    }

    if (-not (Test-Path -LiteralPath ([string]$config.staging_ssl_ca) -PathType Leaf)) {
        throw 'The configured TLS CA file is missing.'
    }

    return $config
}

function Get-ValidatedMigrationSecrets {
    param([Parameter(Mandatory = $true)]$Config)

    if (-not (Test-Path -LiteralPath $ResolvedSecretsPath -PathType Leaf)) {
        throw 'The KPS-Staging DPAPI bootstrap secrets are missing.'
    }

    $secrets = Get-Content -LiteralPath $ResolvedSecretsPath -Raw | ConvertFrom-Json
    $expectedMigrationUsername = ([string]$Config.staging_username_prefix) + '.kps_migrator'
    $expectedRuntimeUsername = ([string]$Config.staging_username_prefix) + '.kps_runtime'

    if ($secrets.version -ne 1 -or
        $secrets.expected_target -cne $ExpectedTarget -or
        $secrets.connection_fingerprint -ne [string]$Config.staging_fingerprint -or
        $secrets.staging_username_prefix -cne [string]$Config.staging_username_prefix -or
        $secrets.migration_username -cne $expectedMigrationUsername -or
        $secrets.runtime_username -cne $expectedRuntimeUsername) {
        throw 'Migration secrets are not bound to the current verified KPS-Staging target.'
    }

    return $secrets
}

function Get-InspectorEnvironment {
    param(
        [Parameter(Mandatory = $true)]$Config,
        [Parameter(Mandatory = $true)]$Secrets,
        [Parameter(Mandatory = $true)][string]$Password,
        [Parameter(Mandatory = $true)][string]$AuditPassword,
        [Parameter(Mandatory = $true)][string]$InspectorMode
    )

    $migrationUsername = [string]$Secrets.migration_username

    return @{
        KPS_STAGING_INCREMENTAL_MODE = $InspectorMode
        KPS_STAGING_INCREMENTAL_MIGRATION = $Migration
        KPS_STAGING_TARGET_TYPE = [string]$Config.target_type
        KPS_STAGING_EXPECTED_TARGET = [string]$Config.expected_target
        KPS_STAGING_METADATA_SOURCE = [string]$Config.connection_metadata_source
        KPS_STAGING_DB_HOST = [string]$Config.staging_host
        KPS_STAGING_DB_PORT = [string]$Config.staging_port
        KPS_STAGING_DB_DATABASE = $ApplicationDatabase
        KPS_STAGING_DB_USERNAME = $migrationUsername
        KPS_STAGING_DB_PASSWORD = $Password
        KPS_STAGING_AUDIT_DATABASE = [string]$Config.staging_database
        KPS_STAGING_AUDIT_USERNAME = [string]$Config.staging_username
        KPS_STAGING_AUDIT_PASSWORD = $AuditPassword
        KPS_STAGING_DB_SSL_CA = [string]$Config.staging_ssl_ca
        KPS_STAGING_CONFIRMED_USERNAME_PREFIX = [string]$Config.staging_username_prefix
        KPS_STAGING_EXPECTED_FINGERPRINT = Get-ConnectionFingerprint (
            [string]$Config.staging_host
        ) ([int]$Config.staging_port) $migrationUsername $ApplicationDatabase
        KPS_STAGING_EXPECTED_AUDIT_FINGERPRINT = [string]$Config.staging_fingerprint
        KPS_STAGING_EXPECTED_ENDPOINT_FINGERPRINT = [string]$Config.staging_endpoint_fingerprint
        KPS_PRODUCTION_ENDPOINT_FINGERPRINT = [string]$Config.production_endpoint_fingerprint
        KPS_PRODUCTION_CONFIRMED_USERNAME = [string]$Config.production_username
        KPS_PRODUCTION_CONFIRMED_USERNAME_PREFIX = [string]$Config.production_username_prefix
        KPS_PRODUCTION_USERNAME_PREFIX_FINGERPRINT = [string]$Config.production_username_prefix_fingerprint
    }
}

function Invoke-Inspector {
    param([Parameter(Mandatory = $true)][hashtable]$EnvironmentValues)

    $allowedNames = @(
        'SystemRoot', 'WINDIR', 'TEMP', 'TMP',
        'KPS_STAGING_INCREMENTAL_MODE', 'KPS_STAGING_INCREMENTAL_MIGRATION',
        'KPS_STAGING_TARGET_TYPE', 'KPS_STAGING_EXPECTED_TARGET',
        'KPS_STAGING_METADATA_SOURCE', 'KPS_STAGING_DB_HOST',
        'KPS_STAGING_DB_PORT', 'KPS_STAGING_DB_DATABASE',
        'KPS_STAGING_DB_USERNAME', 'KPS_STAGING_DB_PASSWORD',
        'KPS_STAGING_AUDIT_DATABASE', 'KPS_STAGING_AUDIT_USERNAME',
        'KPS_STAGING_AUDIT_PASSWORD', 'KPS_STAGING_DB_SSL_CA',
        'KPS_STAGING_CONFIRMED_USERNAME_PREFIX', 'KPS_STAGING_EXPECTED_FINGERPRINT',
        'KPS_STAGING_EXPECTED_AUDIT_FINGERPRINT',
        'KPS_STAGING_EXPECTED_ENDPOINT_FINGERPRINT',
        'KPS_PRODUCTION_ENDPOINT_FINGERPRINT', 'KPS_PRODUCTION_CONFIRMED_USERNAME',
        'KPS_PRODUCTION_CONFIRMED_USERNAME_PREFIX',
        'KPS_PRODUCTION_USERNAME_PREFIX_FINGERPRINT',
        'KPS_STAGING_EXPECTED_WEEKLY_PLAN_COUNT',
        'KPS_STAGING_EXPECTED_WEEKLY_PLAN_ID_FINGERPRINT',
        'KPS_STAGING_EXPECTED_WEEKLY_PLAN_ENTRY_COUNT'
    )
    $processResult = Invoke-IsolatedPhpProcess `
        -ScriptPath $InspectorScript `
        -Arguments @() `
        -EnvironmentValues $EnvironmentValues `
        -AllowedEnvironmentNames $allowedNames `
        -WorkingDirectory $BackendRoot
    $output = $processResult.StdOut.Trim()
    $exitCode = $processResult.ExitCode

    try {
        $result = $output | ConvertFrom-Json
    } catch {
        throw "The incremental migration inspector did not return valid JSON (exit code $exitCode)."
    }

    return [pscustomobject]@{
        ExitCode = $exitCode
        Result = $result
        Json = $output
    }
}

function Invoke-ArtisanMigration {
    param(
        [Parameter(Mandatory = $true)][hashtable]$EnvironmentValues,
        [Parameter(Mandatory = $true)][string]$MigrationFile
    )

    $allowedNames = @(
        'SystemRoot', 'WINDIR', 'TEMP', 'TMP',
        'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'KPS_DATABASE_ENVIRONMENT',
        'KPS_RUNNER_DB_HOST', 'KPS_RUNNER_DB_PORT', 'KPS_RUNNER_DB_DATABASE',
        'KPS_RUNNER_DB_USERNAME', 'KPS_RUNNER_DB_PASSWORD', 'KPS_RUNNER_DB_SSL_CA',
        'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION', 'FILESYSTEM_DISK',
        'WEEKLY_PLAN_STORAGE_DISK', 'KPS_STAGING_EPHEMERAL_FILES',
        'KPS_AI_DRIVER', 'WEEKLY_PLAN_DOCUMENT_AI_DRIVER', 'LOG_CHANNEL', 'LOG_LEVEL'
    )
    $processResult = Invoke-IsolatedPhpProcess `
        -ScriptPath $MigrationLauncher `
        -Arguments @($MigrationFile) `
        -EnvironmentValues $EnvironmentValues `
        -AllowedEnvironmentNames $allowedNames `
        -WorkingDirectory $BackendRoot

    if (-not [string]::IsNullOrWhiteSpace($processResult.StdErr)) {
        Write-Warning $processResult.StdErr.TrimEnd()
    }

    if ($processResult.ExitCode -ne 0) {
        throw "The isolated allowlisted migration failed with exit code $($processResult.ExitCode)."
    }

    return [pscustomobject]@{
        Output = $processResult.StdOut.TrimEnd()
        EnvironmentNames = $processResult.EnvironmentNames
    }
}

function Write-FailurePolicy {
    Write-Warning 'STOP: do not retry, rollback, drop objects, edit the migration ledger, run a seeder, or run the next migration.'
    Write-Warning 'Collect read-only evidence from migrations, INFORMATION_SCHEMA.TABLES/COLUMNS/STATISTICS/KEY_COLUMN_USAGE/REFERENTIAL_CONSTRAINTS, SHOW CREATE TABLE, and DDL jobs.'
}

function New-RunnerMutex {
    return [Threading.Mutex]::new($false, 'Local\KPS-Staging-Incremental-Migration')
}

function Enter-RunnerMutex {
    param([Parameter(Mandatory = $true)]$Mutex)

    try {
        if (-not $Mutex.WaitOne(0)) {
            throw 'Another local KPS-Staging incremental migration runner is active.'
        }
    } catch [Threading.AbandonedMutexException] {
        # WaitOne owns an abandoned mutex when it throws. Release it before
        # failing closed so a long-lived PowerShell host is not left locked.
        $Mutex.ReleaseMutex()
        throw 'A previous local migration runner abandoned its mutex. Stop and review before retrying.'
    }
}

function Exit-RunnerMutex {
    param(
        $Mutex,
        [bool]$Acquired
    )

    if ($Acquired -and $null -ne $Mutex) {
        $Mutex.ReleaseMutex()
    }

    if ($null -ne $Mutex) {
        $Mutex.Dispose()
    }
}

function Get-RunnerUtcNow {
    return [DateTimeOffset]::UtcNow
}

function Read-RunnerConfirmation {
    param([Parameter(Mandatory = $true)][string]$Prompt)

    return Read-Host $Prompt
}

function Invoke-KpsStagingIncrementalRunner {
    $outputLines = [Collections.Generic.List[string]]::new()

    if ($Mode -eq 'Execute') {
        if (-not $AcknowledgeBackupAvailable.IsPresent -or
            -not $AcknowledgeRecoveryPoint.IsPresent -or
            -not $AcknowledgeNewInstanceRestore.IsPresent -or
            -not $AcknowledgeRpo.IsPresent) {
            throw 'Execute requires all four backup, recovery-point, new-instance restore, and RPO acknowledgements.'
        }

        if ([string]::IsNullOrWhiteSpace($PlanReceiptPath)) {
            throw 'Execute requires the Windows-DPAPI receipt from a successful fresh -Plan.'
        }
    }

    $config = Get-ValidatedAuditConfig
    $secrets = Get-ValidatedMigrationSecrets $config
    $migrationPassword = Unprotect-RunnerSecret ([string]$secrets.encrypted_migration_password)
    $auditPassword = Unprotect-RunnerSecret ([string]$config.encrypted_password)
    $mutex = $null
    $mutexAcquired = $false
    $migrationFileLock = $null

    try {
        if ($Mode -ne 'Execute') {
            $inspectorMode = if ($Mode -eq 'Plan') { 'plan' } else { 'status' }
            $environment = Get-InspectorEnvironment $config $secrets $migrationPassword $auditPassword $inspectorMode
            $inspection = Invoke-Inspector $environment
            $outputLines.Add($inspection.Json)

            if ($Mode -eq 'Plan' -and
                $inspection.ExitCode -eq 0 -and
                $inspection.Result.status -ceq 'PASS' -and
                $inspection.Result.mode -ceq 'plan') {
                $migrationPath = Join-Path $BackendRoot ('database\migrations\' + $Migration)
                $migrationHash = Get-FileSha256 $migrationPath
                $receipt = New-PlanReceiptObject `
                    -Config $config `
                    -Secrets $secrets `
                    -InspectionResult $inspection.Result `
                    -MigrationHash $migrationHash `
                    -IssuedAt (Get-RunnerUtcNow)
                $writtenPath = Write-ProtectedPlanReceipt $receipt $PlanReceiptPath
                $outputLines.Add(([ordered]@{
                    plan_receipt_path = $writtenPath
                    expires_at = $receipt.expires_at
                    receipt_nonce = $receipt.nonce
                } | ConvertTo-Json -Compress))
            }

            return [pscustomobject]@{
                ExitCode = $inspection.ExitCode
                Output = $outputLines.ToArray()
            }
        }

        $receipt = Read-ProtectedPlanReceipt $PlanReceiptPath
        $mutex = New-RunnerMutex
        Enter-RunnerMutex $mutex
        $mutexAcquired = $true

        $migrationPath = Join-Path $BackendRoot ('database\migrations\' + $Migration)
        $migrationHash = Get-FileSha256 $migrationPath
        $preflightEnvironment = Get-InspectorEnvironment $config $secrets $migrationPassword $auditPassword 'preflight'
        $preflight = Invoke-Inspector $preflightEnvironment

        if ($preflight.ExitCode -ne 0 -or $preflight.Result.status -cne 'PASS') {
            $outputLines.Add($preflight.Json)
            throw 'Execute preflight did not PASS.'
        }

        Assert-PlanReceiptMatches `
            -Receipt $receipt `
            -Config $config `
            -Secrets $secrets `
            -InspectionResult $preflight.Result `
            -MigrationHash $migrationHash `
            -Now (Get-RunnerUtcNow)

        $confirmationText = 'MIGRATE KPS-STAGING ' + $Migration + ' ' + ([string]$receipt.nonce).Substring(0, 12)
        $confirmation = Read-RunnerConfirmation "Type exactly '$confirmationText' after confirming the approved backup and RPO"

        if ($confirmation -cne $confirmationText) {
            throw 'Operator confirmation did not match. No migration was run.'
        }

        # The confirmation prompt is the final long wait. Re-run every identity,
        # ledger, schema and concurrency guard immediately after it.
        $finalEnvironment = Get-InspectorEnvironment $config $secrets $migrationPassword $auditPassword 'preflight'
        $finalPreflight = Invoke-Inspector $finalEnvironment

        if ($finalPreflight.ExitCode -ne 0 -or $finalPreflight.Result.status -cne 'PASS') {
            $outputLines.Add($finalPreflight.Json)
            throw 'The final pre-launch preflight did not PASS.'
        }

        $finalMigrationHash = Get-FileSha256 $migrationPath
        Assert-PlanReceiptMatches `
            -Receipt $receipt `
            -Config $config `
            -Secrets $secrets `
            -InspectionResult $finalPreflight.Result `
            -MigrationHash $finalMigrationHash `
            -Now (Get-RunnerUtcNow)

        # Open first, hash the same handle, and deny write/delete sharing while
        # the isolated Laravel child opens the file for reading.
        $migrationFileLock = Open-VerifiedMigrationFile $migrationPath ([string]$receipt.migration_sha256)
        Use-PlanReceiptOnce $receipt

        $artisanEnvironment = @{
            APP_ENV = 'staging'
            APP_DEBUG = 'false'
            APP_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
            KPS_DATABASE_ENVIRONMENT = 'staging'
            KPS_RUNNER_DB_HOST = [string]$config.staging_host
            KPS_RUNNER_DB_PORT = [string]$config.staging_port
            KPS_RUNNER_DB_DATABASE = $ApplicationDatabase
            KPS_RUNNER_DB_USERNAME = [string]$secrets.migration_username
            KPS_RUNNER_DB_PASSWORD = $migrationPassword
            KPS_RUNNER_DB_SSL_CA = [string]$config.staging_ssl_ca
            CACHE_STORE = 'array'
            SESSION_DRIVER = 'array'
            QUEUE_CONNECTION = 'sync'
            FILESYSTEM_DISK = 'local'
            WEEKLY_PLAN_STORAGE_DISK = 'local'
            KPS_STAGING_EPHEMERAL_FILES = 'true'
            KPS_AI_DRIVER = 'fake'
            WEEKLY_PLAN_DOCUMENT_AI_DRIVER = 'none'
            LOG_CHANNEL = 'stderr'
            LOG_LEVEL = 'warning'
        }

        try {
            $migrationResult = Invoke-ArtisanMigration $artisanEnvironment $Migration
            if (-not [string]::IsNullOrWhiteSpace($migrationResult.Output)) {
                $outputLines.Add($migrationResult.Output)
            }
        } catch {
            Write-FailurePolicy
            throw
        } finally {
            if ($null -ne $migrationFileLock) {
                $migrationFileLock.Dispose()
                $migrationFileLock = $null
            }
        }

        try {
            $postflightEnvironment = Get-InspectorEnvironment $config $secrets $migrationPassword $auditPassword 'postflight'
            $postflightEnvironment['KPS_STAGING_EXPECTED_WEEKLY_PLAN_COUNT'] = [string]$finalPreflight.Result.baseline.weekly_plan_count
            $postflightEnvironment['KPS_STAGING_EXPECTED_WEEKLY_PLAN_ID_FINGERPRINT'] = [string]$finalPreflight.Result.baseline.weekly_plan_id_fingerprint
            $postflightEnvironment['KPS_STAGING_EXPECTED_WEEKLY_PLAN_ENTRY_COUNT'] = [string]$finalPreflight.Result.baseline.weekly_plan_entry_count
            $postflight = Invoke-Inspector $postflightEnvironment
            $outputLines.Add($postflight.Json)

            if ($postflight.ExitCode -ne 0 -or $postflight.Result.status -cne 'PASS') {
                throw 'Migration command completed, but the mandatory read-only postflight failed.'
            }
        } catch {
            Write-FailurePolicy
            throw
        }

        return [pscustomobject]@{
            ExitCode = 0
            Output = $outputLines.ToArray()
        }
    } finally {
        $migrationPassword = $null
        $auditPassword = $null

        if ($null -ne $migrationFileLock) {
            $migrationFileLock.Dispose()
        }

        Exit-RunnerMutex $mutex $mutexAcquired
    }
}

if ($MyInvocation.InvocationName -ne '.') {
    try {
        $runnerResult = Invoke-KpsStagingIncrementalRunner
        foreach ($line in $runnerResult.Output) {
            Write-Output $line
        }
        exit $runnerResult.ExitCode
    } catch {
        Write-Error $_.Exception.Message
        exit 1
    }
}
