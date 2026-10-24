[CmdletBinding()]
param(
    [ValidateSet('Plan', 'Execute', 'Status')]
    [string]$Mode = 'Plan',
    [switch]$ConfigureSecrets,
    [string]$ApprovalFingerprint = '',
    [string]$AuditConfigPath = '',
    [string]$SecretsPath = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepositoryRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$BackendRoot = Join-Path $RepositoryRoot 'backend'
$AuditWrapper = Join-Path $PSScriptRoot 'Invoke-KpsStagingDatabaseAudit.ps1'
$BootstrapScript = Join-Path $BackendRoot 'scripts\bootstrap-staging-database.php'
$ApplicationDatabase = 'kps_internal'
$ExpectedTarget = 'KPS-Staging'
$ExpectedProductionTarget = 'KPS-Internal'

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
        throw 'Staging credentials and bootstrap secrets must remain outside the Git repository.'
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

function Read-ConfirmedSecret {
    param(
        [Parameter(Mandatory = $true)][string]$Prompt,
        [Parameter(Mandatory = $true)][int]$MinimumLength,
        [Parameter(Mandatory = $true)][int]$MaximumLength
    )

    $first = Read-Host $Prompt -AsSecureString
    $second = Read-Host ($Prompt + ' (confirm)') -AsSecureString
    $firstPlain = Convert-SecureStringToPlainText $first
    $secondPlain = Convert-SecureStringToPlainText $second

    try {
        if ($firstPlain.Length -lt $MinimumLength -or $firstPlain.Length -gt $MaximumLength) {
            throw "$Prompt must contain $MinimumLength to $MaximumLength characters."
        }

        if ($firstPlain -cne $secondPlain) {
            throw "$Prompt confirmation does not match."
        }

        return ConvertFrom-SecureString $first
    } finally {
        $firstPlain = $null
        $secondPlain = $null
    }
}

function Get-ValidatedAuditConfig {
    if (-not (Test-Path -LiteralPath $ResolvedAuditConfigPath)) {
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

function Invoke-JsonPhpScript {
    param(
        [Parameter(Mandatory = $true)][hashtable]$EnvironmentValues
    )

    $previousValues = @{}

    try {
        foreach ($entry in $EnvironmentValues.GetEnumerator()) {
            $previousValues[$entry.Key] = [Environment]::GetEnvironmentVariable($entry.Key, 'Process')
            [Environment]::SetEnvironmentVariable($entry.Key, [string]$entry.Value, 'Process')
        }

        $output = (& php $BootstrapScript 2>&1 | Out-String).Trim()
        $exitCode = $LASTEXITCODE
    } finally {
        foreach ($entry in $previousValues.GetEnumerator()) {
            [Environment]::SetEnvironmentVariable($entry.Key, $entry.Value, 'Process')
        }

        foreach ($sensitiveName in @(
            'KPS_STAGING_DB_PASSWORD',
            'KPS_STAGING_MIGRATION_PASSWORD',
            'KPS_STAGING_RUNTIME_PASSWORD'
        )) {
            if ($EnvironmentValues.ContainsKey($sensitiveName)) {
                $EnvironmentValues[$sensitiveName] = ''
            }
        }
    }

    try {
        $result = $output | ConvertFrom-Json
    } catch {
        throw "The staging bootstrap helper did not return valid JSON (exit code $exitCode). Execution stopped before any later bootstrap stage."
    }

    return [pscustomobject]@{
        ExitCode = $exitCode
        Result = $result
        Json = $output
    }
}

function Get-VerifiedEmptyAudit {
    $output = (& $AuditWrapper -Target IndependentInstance -ConfigPath $ResolvedAuditConfigPath 2>&1 | Out-String).Trim()
    $exitCode = $LASTEXITCODE

    try {
        $audit = $output | ConvertFrom-Json
    } catch {
        throw 'The KPS-Staging audit did not return valid JSON.'
    }

    if ($exitCode -ne 0 -or $audit.status -ne 'PASS') {
        throw 'The live read-only KPS-Staging audit did not PASS.'
    }

    if (-not [bool]$audit.identity.tidb_verified -or
        -not [bool]$audit.identity.tls_verified -or
        -not [bool]$audit.identity.user_verified -or
        -not [bool]$audit.identity.username_prefix_distinct_from_production -or
        [bool]$audit.schema.application_database_exists -or
        [int]$audit.schema.user_schema_object_count -ne 0 -or
        $audit.schema.evidence -notin @('no_user_schema_detected', 'empty_default_test_schema_only')) {
        throw 'The live audit no longer proves the reviewed empty KPS-Staging state.'
    }

    return $audit
}

function Get-Plan {
    param(
        [Parameter(Mandatory = $true)]$Config,
        [Parameter(Mandatory = $true)][string]$Password
    )

    $environment = Get-CommonDatabaseEnvironment $Config $Password 'plan'
    $result = Invoke-JsonPhpScript $environment

    if ($result.ExitCode -ne 0 -or $result.Result.status -ne 'PLAN_READY') {
        throw 'The reviewed staging bootstrap plan could not be generated.'
    }

    return $result.Result
}

function Get-CommonDatabaseEnvironment {
    param(
        [Parameter(Mandatory = $true)]$Config,
        [Parameter(Mandatory = $true)][string]$Password,
        [Parameter(Mandatory = $true)][string]$Mode
    )

    return @{
        KPS_STAGING_BOOTSTRAP_MODE = $Mode
        KPS_STAGING_TARGET_TYPE = [string]$Config.target_type
        KPS_STAGING_EXPECTED_TARGET = [string]$Config.expected_target
        KPS_STAGING_METADATA_SOURCE = [string]$Config.connection_metadata_source
        KPS_STAGING_DB_HOST = [string]$Config.staging_host
        KPS_STAGING_DB_PORT = [string]$Config.staging_port
        KPS_STAGING_DB_DATABASE = [string]$Config.staging_database
        KPS_STAGING_DB_USERNAME = [string]$Config.staging_username
        KPS_STAGING_DB_PASSWORD = $Password
        KPS_STAGING_DB_SSL_CA = [string]$Config.staging_ssl_ca
        KPS_STAGING_CONFIRMED_USERNAME_PREFIX = [string]$Config.staging_username_prefix
        KPS_STAGING_EXPECTED_FINGERPRINT = [string]$Config.staging_fingerprint
        KPS_STAGING_EXPECTED_ENDPOINT_FINGERPRINT = [string]$Config.staging_endpoint_fingerprint
        KPS_PRODUCTION_ENDPOINT_FINGERPRINT = [string]$Config.production_endpoint_fingerprint
        KPS_PRODUCTION_CONFIRMED_USERNAME = [string]$Config.production_username
        KPS_PRODUCTION_CONFIRMED_USERNAME_PREFIX = [string]$Config.production_username_prefix
        KPS_PRODUCTION_USERNAME_PREFIX_FINGERPRINT = [string]$Config.production_username_prefix_fingerprint
    }
}

function Get-ValidatedBootstrapSecrets {
    param([Parameter(Mandatory = $true)]$Config)

    if (-not (Test-Path -LiteralPath $ResolvedSecretsPath)) {
        throw 'Bootstrap secrets are missing. Run with -ConfigureSecrets after reviewing a fresh Plan.'
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
        throw 'Bootstrap secrets are not bound to the current verified KPS-Staging target.'
    }

    return $secrets
}

function Invoke-ArtisanWithEnvironment {
    param(
        [Parameter(Mandatory = $true)][hashtable]$EnvironmentValues,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )

    $previousValues = @{}

    try {
        foreach ($entry in $EnvironmentValues.GetEnumerator()) {
            $previousValues[$entry.Key] = [Environment]::GetEnvironmentVariable($entry.Key, 'Process')
            [Environment]::SetEnvironmentVariable($entry.Key, [string]$entry.Value, 'Process')
        }

        Push-Location $BackendRoot

        try {
            & php artisan @Arguments

            if ($LASTEXITCODE -ne 0) {
                throw ('Artisan failed: ' + ($Arguments -join ' '))
            }
        } finally {
            Pop-Location
        }
    } finally {
        foreach ($entry in $previousValues.GetEnumerator()) {
            [Environment]::SetEnvironmentVariable($entry.Key, $entry.Value, 'Process')
        }
    }
}

$config = Get-ValidatedAuditConfig

if ($Mode -eq 'Status') {
    $adminPassword = Convert-SecureStringToPlainText (
        ConvertTo-SecureString ([string]$config.encrypted_password)
    )

    try {
        $environment = Get-CommonDatabaseEnvironment $config $adminPassword 'status'
        $result = Invoke-JsonPhpScript $environment
        Write-Output $result.Json
        exit $result.ExitCode
    } finally {
        $adminPassword = $null
    }
}

$null = Get-VerifiedEmptyAudit
$planPassword = Convert-SecureStringToPlainText (
    ConvertTo-SecureString ([string]$config.encrypted_password)
)

try {
    $plan = Get-Plan $config $planPassword
} finally {
    $planPassword = $null
}

if ($ConfigureSecrets) {
    if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT) {
        throw 'Bootstrap secret configuration requires Windows DPAPI.'
    }

    if (Test-Path -LiteralPath $ResolvedSecretsPath) {
        throw "Bootstrap secrets already exist and were not overwritten: $ResolvedSecretsPath"
    }

    $migrationPassword = Read-ConfirmedSecret 'Migration operator password' 20 128
    $runtimePassword = Read-ConfirmedSecret 'Laravel runtime password' 20 128
    $testAccountPassword = Read-ConfirmedSecret 'Fake staging account password' 16 72
    $secretsDirectory = Split-Path -Parent $ResolvedSecretsPath
    New-Item -ItemType Directory -Path $secretsDirectory -Force | Out-Null

    [ordered]@{
        version = 1
        expected_target = $ExpectedTarget
        connection_fingerprint = [string]$config.staging_fingerprint
        staging_username_prefix = [string]$config.staging_username_prefix
        migration_username = [string]$plan.database_accounts.migration_operator.username
        runtime_username = [string]$plan.database_accounts.runtime.username
        encrypted_migration_password = $migrationPassword
        encrypted_runtime_password = $runtimePassword
        encrypted_test_account_password = $testAccountPassword
    } | ConvertTo-Json | Set-Content -LiteralPath $ResolvedSecretsPath -Encoding UTF8

    Write-Host "Bootstrap secrets saved with Windows DPAPI outside Git: $ResolvedSecretsPath"
    Write-Host 'No database write was performed.'
    exit 0
}

if ($Mode -eq 'Plan') {
    Write-Output ($plan | ConvertTo-Json -Depth 10)
    exit 0
}

if ([string]::IsNullOrWhiteSpace($ApprovalFingerprint) -or
    $ApprovalFingerprint -cne [string]$plan.approval.plan_fingerprint) {
    throw 'Execution requires the exact approval fingerprint from a fresh dry-run Plan.'
}

if (Test-Path -LiteralPath (Join-Path $BackendRoot 'bootstrap\cache\config.php')) {
    throw 'A Laravel config cache exists. Remove it through a separately reviewed local-only step before bootstrap.'
}

$confirmationText = 'BOOTSTRAP KPS-STAGING ' + $ApprovalFingerprint.Substring(0, 12)
$confirmation = Read-Host "Type exactly '$confirmationText' to create the staging database and continue"

if ($confirmation -cne $confirmationText) {
    throw 'Manual confirmation did not match. No write was performed.'
}

$secrets = Get-ValidatedBootstrapSecrets $config
$adminPassword = Convert-SecureStringToPlainText (
    ConvertTo-SecureString ([string]$config.encrypted_password)
)
$migrationPassword = Convert-SecureStringToPlainText (
    ConvertTo-SecureString ([string]$secrets.encrypted_migration_password)
)
$runtimePassword = Convert-SecureStringToPlainText (
    ConvertTo-SecureString ([string]$secrets.encrypted_runtime_password)
)
$testAccountPassword = Convert-SecureStringToPlainText (
    ConvertTo-SecureString ([string]$secrets.encrypted_test_account_password)
)

try {
    $provisionEnvironment = Get-CommonDatabaseEnvironment $config $adminPassword 'provision'
    $provisionEnvironment['KPS_STAGING_BOOTSTRAP_CONFIRMATION'] = 'EXECUTE_KPS_STAGING_BOOTSTRAP'
    $provisionEnvironment['KPS_STAGING_BOOTSTRAP_APPROVAL_FINGERPRINT'] = $ApprovalFingerprint
    $provisionEnvironment['KPS_STAGING_MIGRATION_PASSWORD'] = $migrationPassword
    $provisionEnvironment['KPS_STAGING_RUNTIME_PASSWORD'] = $runtimePassword
    $provision = Invoke-JsonPhpScript $provisionEnvironment

    if ($provision.ExitCode -ne 0 -or $provision.Result.status -ne 'PROVISIONED') {
        Write-Output $provision.Json
        throw 'Database/account provisioning failed. Run Status and review partial DDL before any retry.'
    }

    $artisanEnvironment = @{
        APP_ENV = 'staging'
        APP_DEBUG = 'false'
        APP_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
        APP_CONFIG_CACHE = (Join-Path $env:TEMP ('kps-staging-bootstrap-config-' + [Guid]::NewGuid().ToString('N') + '.php'))
        KPS_DATABASE_ENVIRONMENT = 'staging'
        DB_CONNECTION = 'mysql'
        DB_URL = '(null)'
        DB_HOST = [string]$config.staging_host
        DB_PORT = [string]$config.staging_port
        DB_DATABASE = $ApplicationDatabase
        DB_USERNAME = [string]$secrets.migration_username
        DB_PASSWORD = $migrationPassword
        MYSQL_ATTR_SSL_CA = [string]$config.staging_ssl_ca
        CACHE_STORE = 'array'
        SESSION_DRIVER = 'array'
        QUEUE_CONNECTION = 'sync'
        FILESYSTEM_DISK = 'local'
        WEEKLY_PLAN_STORAGE_DISK = 's3'
        AWS_BUCKET = 'kps-staging-bootstrap-not-used'
        AWS_ACCESS_KEY_ID = '(null)'
        AWS_SECRET_ACCESS_KEY = '(null)'
        AWS_SESSION_TOKEN = '(null)'
        KPS_AI_DRIVER = 'fake'
        KPS_STAGING_BOOTSTRAP_SEED = 'true'
        KPS_STAGING_TEST_ACCOUNT_PASSWORD = $testAccountPassword
    }

    Invoke-ArtisanWithEnvironment $artisanEnvironment @('migrate', '--force', '--no-interaction')
    Invoke-ArtisanWithEnvironment $artisanEnvironment @(
        'db:seed',
        '--class=Database\Seeders\StagingSeeder',
        '--force',
        '--no-interaction'
    )

    $statusEnvironment = Get-CommonDatabaseEnvironment $config $adminPassword 'status'
    $status = Invoke-JsonPhpScript $statusEnvironment
    Write-Output $status.Json
    exit $status.ExitCode
} finally {
    $adminPassword = $null
    $migrationPassword = $null
    $runtimePassword = $null
    $testAccountPassword = $null
}
