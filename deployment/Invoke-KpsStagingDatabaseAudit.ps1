[CmdletBinding()]
param(
    [switch]$Configure,
    [ValidateSet('LegacyBranch', 'IndependentInstance')]
    [string]$Target = 'LegacyBranch',
    [string]$ConfigPath = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Profiles = @{
    LegacyBranch = @{
        TargetType = 'legacy_branch'
        ExpectedTarget = 'kps-weekly-planning-staging'
        DefaultDatabase = 'kps_internal'
        ApplicationDatabase = 'kps_internal'
        ConfigFileName = 'staging-db-audit.json'
        SelectionInstruction = 'Select production instance KPS-Internal and branch kps-weekly-planning-staging.'
    }
    IndependentInstance = @{
        TargetType = 'independent_instance'
        ExpectedTarget = 'KPS-Staging'
        ExpectedProductionTarget = 'KPS-Internal'
        DefaultDatabase = 'sys'
        ApplicationDatabase = 'kps_internal'
        ConfigFileName = 'independent-staging-db-audit.json'
        SelectionInstruction = 'Select independent TiDB Cloud instance KPS-Staging, not a branch of KPS-Internal.'
    }
}

$Profile = $Profiles[$Target]
$RepositoryRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$AuditScript = Join-Path $RepositoryRoot 'backend\scripts\audit-staging-database.php'

if ([string]::IsNullOrWhiteSpace($ConfigPath)) {
    $ConfigPath = Join-Path $env:LOCALAPPDATA ('KPS\' + $Profile.ConfigFileName)
}

$ResolvedConfigPath = [IO.Path]::GetFullPath($ConfigPath)
$RepositoryPrefix = $RepositoryRoot.TrimEnd([IO.Path]::DirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar

if ($ResolvedConfigPath.StartsWith($RepositoryPrefix, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Staging credentials must be stored outside the Git repository.'
}

function Read-RequiredText {
    param(
        [Parameter(Mandatory = $true)][string]$Prompt,
        [string]$DefaultValue = ''
    )

    $displayPrompt = if ([string]::IsNullOrWhiteSpace($DefaultValue)) {
        $Prompt
    } else {
        "$Prompt [$DefaultValue]"
    }
    $value = Read-Host $displayPrompt

    if ([string]::IsNullOrWhiteSpace($value)) {
        $value = $DefaultValue
    }

    if ([string]::IsNullOrWhiteSpace($value)) {
        throw "$Prompt is required."
    }

    return $value.Trim().Trim([char]34).Trim([char]39)
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

function Get-StarterUsernamePrefix {
    param([Parameter(Mandatory = $true)][string]$Username)

    $normalizedUsername = $Username.Trim().Trim([char]34).Trim([char]39)

    if ($normalizedUsername -notmatch '^(?<prefix>[A-Za-z0-9]{1,64})\.(?<account>[A-Za-z0-9_][A-Za-z0-9_-]{0,63})$') {
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

if ($Configure) {
    if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT) {
        throw 'The one-time credential store requires Windows DPAPI.'
    }

    if (Test-Path -LiteralPath $ResolvedConfigPath) {
        throw "Configuration already exists and was not overwritten: $ResolvedConfigPath"
    }

    Write-Host 'Enter connection metadata copied from the TiDB Cloud Connect dialog.'
    Write-Host $Profile.SelectionInstruction
    Write-Host ('Expected target: ' + $Profile.ExpectedTarget)
    $confirmedStagingInstance = ''
    $confirmedProductionInstance = ''

    if ($Target -eq 'IndependentInstance') {
        $confirmedStagingInstance = Read-RequiredText 'Instance name shown in TiDB Cloud'

        if ($confirmedStagingInstance -cne $Profile.ExpectedTarget) {
            throw ('The selected staging instance must be exactly ' + $Profile.ExpectedTarget + '.')
        }
    }

    $stagingHost = Read-RequiredText 'Staging host'
    $stagingPortText = Read-RequiredText 'Staging port' '4000'
    $stagingPort = 0

    if (-not [int]::TryParse($stagingPortText, [ref]$stagingPort) -or $stagingPort -lt 1 -or $stagingPort -gt 65535) {
        throw 'Staging port must be between 1 and 65535.'
    }

    $stagingDatabase = Read-RequiredText 'Audit database' $Profile.DefaultDatabase

    if ($stagingDatabase -ne $Profile.DefaultDatabase) {
        throw ('This profile must connect to database ' + $Profile.DefaultDatabase + '.')
    }

    $stagingUsername = Read-RequiredText 'Staging username'
    $stagingUsernamePrefix = ''

    if ($Target -eq 'IndependentInstance') {
        $stagingUsernamePrefix = Read-RequiredText 'Staging username prefix shown in Connect'
        $parsedStagingPrefix = Get-StarterUsernamePrefix $stagingUsername

        if ($parsedStagingPrefix -cne $stagingUsernamePrefix) {
            throw 'The staging username prefix does not match the full staging username.'
        }
    }

    $stagingPassword = Read-Host 'Staging password (stored with Windows DPAPI)' -AsSecureString
    $plainPassword = Convert-SecureStringToPlainText $stagingPassword

    if ([string]::IsNullOrWhiteSpace($plainPassword)) {
        $plainPassword = $null
        throw 'Staging password cannot be empty.'
    }

    $plainPassword = $null
    $encryptedPassword = ConvertFrom-SecureString $stagingPassword
    $defaultCa = (& php -r "echo ini_get('openssl.cafile');").Trim()
    $sslCa = Read-RequiredText 'TLS CA bundle path' $defaultCa
    $resolvedCaItem = Get-Item -LiteralPath (Resolve-Path -LiteralPath $sslCa).Path

    if ($resolvedCaItem.PSIsContainer) {
        throw 'TLS CA bundle path must point to a file.'
    }

    $resolvedCa = $resolvedCaItem.FullName

    Write-Host 'Enter KPS-Internal production metadata for identity comparison only.'
    Write-Host 'A production password is not requested or stored.'

    $productionUsername = ''
    $productionUsernamePrefix = ''

    if ($Target -eq 'IndependentInstance') {
        $confirmedProductionInstance = Read-RequiredText 'Production instance name shown in TiDB Cloud'

        if ($confirmedProductionInstance -cne $Profile.ExpectedProductionTarget) {
            throw ('The production instance must be exactly ' + $Profile.ExpectedProductionTarget + '.')
        }
    }

    $productionHost = Read-RequiredText 'Production host'
    $productionPortText = Read-RequiredText 'Production port' '4000'
    $productionPort = 0

    if (-not [int]::TryParse($productionPortText, [ref]$productionPort) -or $productionPort -lt 1 -or $productionPort -gt 65535) {
        throw 'Production port must be between 1 and 65535.'
    }

    if ($Target -eq 'IndependentInstance') {
        $productionUsername = Read-RequiredText 'Production username'
        $productionUsernamePrefix = Read-RequiredText 'Production username prefix shown in Connect'
        $parsedProductionPrefix = Get-StarterUsernamePrefix $productionUsername

        if ($parsedProductionPrefix -cne $productionUsernamePrefix) {
            throw 'The production username prefix does not match the full production username.'
        }

        if ($stagingUsernamePrefix -ceq $productionUsernamePrefix) {
            throw 'The staging and production username prefixes match. Configuration was not saved.'
        }

        if ($stagingUsername -ceq $productionUsername) {
            throw 'The staging and production usernames match. Configuration was not saved.'
        }
    }

    $stagingFingerprint = Get-ConnectionFingerprint $stagingHost $stagingPort $stagingUsername $stagingDatabase
    $stagingEndpointFingerprint = Get-EndpointFingerprint $stagingHost $stagingPort
    $productionEndpointFingerprint = Get-EndpointFingerprint $productionHost $productionPort

    $configDirectory = Split-Path -Parent $ResolvedConfigPath
    New-Item -ItemType Directory -Path $configDirectory -Force | Out-Null

    [ordered]@{
        version = 3
        target_type = $Profile.TargetType
        expected_target = $Profile.ExpectedTarget
        application_database = $Profile.ApplicationDatabase
        connection_metadata_source = if ($Target -eq 'IndependentInstance') { 'tidb_cloud_connect_dialog' } else { '' }
        staging_instance_name = $confirmedStagingInstance
        staging_host = $stagingHost
        staging_port = $stagingPort
        staging_database = $stagingDatabase
        staging_username = $stagingUsername
        staging_username_prefix = $stagingUsernamePrefix
        staging_ssl_ca = $resolvedCa
        staging_fingerprint = $stagingFingerprint
        staging_endpoint_fingerprint = $stagingEndpointFingerprint
        production_endpoint_fingerprint = $productionEndpointFingerprint
        production_instance_name = $confirmedProductionInstance
        production_host = $productionHost
        production_port = $productionPort
        production_username = $productionUsername
        production_username_prefix = $productionUsernamePrefix
        production_username_prefix_fingerprint = if ($Target -eq 'IndependentInstance') {
            Get-Sha256 $productionUsernamePrefix
        } else {
            ''
        }
        shared_gateway = $stagingEndpointFingerprint -eq $productionEndpointFingerprint
        encrypted_password = $encryptedPassword
    } | ConvertTo-Json | Set-Content -LiteralPath $ResolvedConfigPath -Encoding UTF8

    Write-Host "Configuration saved outside Git: $ResolvedConfigPath"
    Write-Host 'Password is protected with Windows DPAPI for the current Windows account.'
    exit 0
}

if (-not (Test-Path -LiteralPath $ResolvedConfigPath)) {
    Write-Host ('FAIL: ' + $Profile.ExpectedTarget + ' connection configuration is missing.')
    Write-Host 'Run this one time, using values copied from the correct TiDB Cloud Connect dialog:'
    Write-Host ('.\deployment\Invoke-KpsStagingDatabaseAudit.ps1 -Target ' + $Target + ' -Configure')
    exit 2
}

if (-not (Test-Path -LiteralPath $AuditScript)) {
    throw 'The staging database audit script is missing.'
}

$config = Get-Content -LiteralPath $ResolvedConfigPath -Raw | ConvertFrom-Json
$isLegacyV1 = $Target -eq 'LegacyBranch' -and $config.version -eq 1

if (-not $isLegacyV1) {
    if ($config.version -ne 3 -or
        $config.target_type -ne $Profile.TargetType -or
        $config.expected_target -ne $Profile.ExpectedTarget -or
        $config.application_database -ne $Profile.ApplicationDatabase) {
        throw 'The saved staging audit configuration is invalid or targets another environment.'
    }

    $savedStagingFingerprint = Get-ConnectionFingerprint (
        [string]$config.staging_host
    ) ([int]$config.staging_port) (
        [string]$config.staging_username
    ) (
        [string]$config.staging_database
    )
    $savedStagingEndpointFingerprint = Get-EndpointFingerprint (
        [string]$config.staging_host
    ) ([int]$config.staging_port)
    $savedProductionEndpointFingerprint = Get-EndpointFingerprint (
        [string]$config.production_host
    ) ([int]$config.production_port)
    $savedSharedGateway = $savedStagingEndpointFingerprint -eq $savedProductionEndpointFingerprint

    if ($savedStagingFingerprint -ne $config.staging_fingerprint -or
        $savedStagingEndpointFingerprint -ne $config.staging_endpoint_fingerprint -or
        $savedProductionEndpointFingerprint -ne $config.production_endpoint_fingerprint -or
        $savedSharedGateway -ne [bool]$config.shared_gateway) {
        throw 'The saved staging audit fingerprints do not match their confirmed metadata.'
    }

    if ($Target -eq 'IndependentInstance') {
        $savedStagingPrefix = Get-StarterUsernamePrefix ([string]$config.staging_username)
        $savedProductionPrefix = Get-StarterUsernamePrefix ([string]$config.production_username)

        if ($config.connection_metadata_source -ne 'tidb_cloud_connect_dialog' -or
            $config.staging_instance_name -cne $Profile.ExpectedTarget -or
            $config.production_instance_name -cne $Profile.ExpectedProductionTarget -or
            $savedStagingPrefix -cne $config.staging_username_prefix -or
            $savedProductionPrefix -cne $config.production_username_prefix -or
            $savedStagingPrefix -ceq $savedProductionPrefix -or
            (Get-Sha256 $savedProductionPrefix) -ne $config.production_username_prefix_fingerprint) {
            throw 'The saved TiDB Cloud Starter identity metadata is invalid or ambiguous.'
        }
    }
}

$securePassword = ConvertTo-SecureString ([string]$config.encrypted_password)
$password = Convert-SecureStringToPlainText $securePassword
$stagingEndpointFingerprint = if ($isLegacyV1) {
    Get-EndpointFingerprint ([string]$config.staging_host) ([int]$config.staging_port)
} else {
    [string]$config.staging_endpoint_fingerprint
}
$environmentValues = [ordered]@{
    KPS_STAGING_DB_HOST = [string]$config.staging_host
    KPS_STAGING_DB_PORT = [string]$config.staging_port
    KPS_STAGING_DB_DATABASE = [string]$config.staging_database
    KPS_STAGING_DB_USERNAME = [string]$config.staging_username
    KPS_STAGING_DB_PASSWORD = $password
    KPS_STAGING_DB_SSL_CA = [string]$config.staging_ssl_ca
    KPS_STAGING_TARGET_TYPE = if ($isLegacyV1) { 'legacy_branch' } else { [string]$config.target_type }
    KPS_STAGING_EXPECTED_TARGET = if ($isLegacyV1) { [string]$config.expected_branch } else { [string]$config.expected_target }
    KPS_STAGING_EXPECTED_FINGERPRINT = [string]$config.staging_fingerprint
    KPS_STAGING_EXPECTED_ENDPOINT_FINGERPRINT = $stagingEndpointFingerprint
    KPS_STAGING_METADATA_SOURCE = if ($isLegacyV1) { '' } else { [string]$config.connection_metadata_source }
    KPS_STAGING_CONFIRMED_USERNAME_PREFIX = if ($isLegacyV1) { '' } else { [string]$config.staging_username_prefix }
    KPS_PRODUCTION_ENDPOINT_FINGERPRINT = if ($isLegacyV1) { '' } else { [string]$config.production_endpoint_fingerprint }
    KPS_PRODUCTION_CONFIRMED_USERNAME = if ($isLegacyV1) { '' } else { [string]$config.production_username }
    KPS_PRODUCTION_CONFIRMED_USERNAME_PREFIX = if ($isLegacyV1) { '' } else { [string]$config.production_username_prefix }
    KPS_PRODUCTION_USERNAME_PREFIX_FINGERPRINT = if ($isLegacyV1) { '' } else { [string]$config.production_username_prefix_fingerprint }
    KPS_PRODUCTION_CONNECTION_FINGERPRINT = if ($isLegacyV1) { [string]$config.production_fingerprint } else { '' }
}
$previousValues = @{}
$auditExitCode = 3

try {
    foreach ($entry in $environmentValues.GetEnumerator()) {
        $previousValues[$entry.Key] = [Environment]::GetEnvironmentVariable($entry.Key, 'Process')
        [Environment]::SetEnvironmentVariable($entry.Key, $entry.Value, 'Process')
    }

    & php $AuditScript
    $auditExitCode = $LASTEXITCODE
} finally {
    $password = $null
    $environmentValues['KPS_STAGING_DB_PASSWORD'] = ''

    foreach ($entry in $previousValues.GetEnumerator()) {
        [Environment]::SetEnvironmentVariable($entry.Key, $entry.Value, 'Process')
    }
}

exit $auditExitCode
