$ErrorActionPreference = 'Stop'

$allowedMigration = '2026_10_27_000000_add_google_docs_source_to_weekly_plans.php'
$runnerPath = (Resolve-Path (Join-Path $PSScriptRoot '..\Invoke-KpsStagingIncrementalMigration.ps1')).Path
$repositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$backendRoot = Join-Path $repositoryRoot 'backend'

. $runnerPath -Migration $allowedMigration

function New-FakeInspectionResult {
    param(
        [string]$InspectorMode = 'preflight',
        [string]$StatusValue = 'PASS',
        [int]$ActiveDdlJobs = 0,
        [int]$CompetingSessions = 0
    )

    return [pscustomobject]@{
        status = $StatusValue
        mode = $InspectorMode
        migration = $allowedMigration
        violations = if ($StatusValue -eq 'PASS') { @() } else { @('fake_violation') }
        identity = [pscustomobject]@{
            target = 'KPS-Staging'
            database_verified = $true
            migration_operator_verified = $true
            tls_verified = $true
            tidb_verified = $true
            foreign_key_checks = 1
            migration_grants_verified = $true
        }
        ledger = [pscustomobject]@{
            expected_count = 17
            applied_count = 15
            pending_migrations = @(
                '2026_10_27_000000_add_google_docs_source_to_weekly_plans',
                '2026_10_27_010000_create_weekly_plan_google_docs_employee_mapping_tables'
            )
            unexpected_migrations = @()
        }
        schema = [pscustomobject]@{
            migration_16_state = 'absent'
            migration_17_state = 'absent'
            parent_prerequisites_verified = $true
            source_row_count = $null
            mapping_row_count = $null
            event_row_count = $null
        }
        concurrency = [pscustomobject]@{
            ddl_jobs_verified = $true
            active_ddl_job_count = $ActiveDdlJobs
            processlist_verified = $true
            competing_migrator_session_count = $CompetingSessions
        }
        baseline = [pscustomobject]@{
            weekly_plan_count = 4
            weekly_plan_id_fingerprint = ('d' * 64)
        }
        plan = [pscustomobject]@{}
    }
}

function New-FakeInspectionEnvelope {
    param([Parameter(Mandatory = $true)]$Result)

    return [pscustomobject]@{
        ExitCode = if ($Result.status -eq 'PASS') { 0 } else { 1 }
        Result = $Result
        Json = ($Result | ConvertTo-Json -Depth 20 -Compress)
    }
}

function Set-ExecuteTestMode {
    $script:Mode = 'Execute'
    $script:Status = [Management.Automation.SwitchParameter]$false
    $script:Plan = [Management.Automation.SwitchParameter]$false
    $script:Execute = [Management.Automation.SwitchParameter]$true
    $script:AcknowledgeBackupAvailable = [Management.Automation.SwitchParameter]$true
    $script:AcknowledgeRecoveryPoint = [Management.Automation.SwitchParameter]$true
    $script:AcknowledgeNewInstanceRestore = [Management.Automation.SwitchParameter]$true
    $script:AcknowledgeRpo = [Management.Automation.SwitchParameter]$true
    $script:PlanReceiptPath = 'C:\outside-repository\plan.dpapi'
}

Describe 'KPS staging incremental migration orchestration' {
    BeforeEach {
        $script:Mode = 'Status'
        $script:Status = [Management.Automation.SwitchParameter]$true
        $script:Plan = [Management.Automation.SwitchParameter]$false
        $script:Execute = [Management.Automation.SwitchParameter]$false
        $script:AcknowledgeBackupAvailable = [Management.Automation.SwitchParameter]$false
        $script:AcknowledgeRecoveryPoint = [Management.Automation.SwitchParameter]$false
        $script:AcknowledgeNewInstanceRestore = [Management.Automation.SwitchParameter]$false
        $script:AcknowledgeRpo = [Management.Automation.SwitchParameter]$false
        $script:PlanReceiptPath = ''
        $script:FixedNow = [datetimeoffset]'2026-10-28T02:00:00Z'
        $script:MigrationHash = 'a' * 64
        $script:PreflightCallCount = 0
        $script:FinalPreflightFailure = $null
        $script:PostflightFailure = $false
        $script:PostflightThrows = $false
        $script:MigrationThrows = $false

        $script:FakeConfig = [pscustomobject]@{
            target_type = 'independent_instance'
            expected_target = 'KPS-Staging'
            connection_metadata_source = 'tidb_cloud_connect_dialog'
            staging_host = 'gateway.example.test'
            staging_port = 4000
            staging_database = 'sys'
            staging_username = 'StagePrefix.root'
            staging_ssl_ca = 'C:\outside-repository\ca.pem'
            staging_username_prefix = 'StagePrefix'
            staging_fingerprint = 'b' * 64
            staging_endpoint_fingerprint = 'c' * 64
            production_endpoint_fingerprint = 'c' * 64
            production_username = 'ProdPrefix.root'
            production_username_prefix = 'ProdPrefix'
            production_username_prefix_fingerprint = 'd' * 64
            encrypted_password = 'protected-audit-password'
        }
        $script:FakeSecrets = [pscustomobject]@{
            migration_username = 'StagePrefix.kps_migrator'
            encrypted_migration_password = 'protected-migration-password'
        }
        $script:BasePreflight = New-FakeInspectionResult
        $script:Receipt = [pscustomobject]@{
            version = 1
            mode = 'PLAN'
            status = 'PASS'
            migration = $allowedMigration
            migration_sha256 = $script:MigrationHash
            target = [pscustomobject]@{
                host = $script:FakeConfig.staging_host
                port = $script:FakeConfig.staging_port
                database = 'kps_internal'
                migrator_username = $script:FakeSecrets.migration_username
            }
            ledger = $script:BasePreflight.ledger
            preflight_digest = Get-InspectionStateDigest $script:BasePreflight
            issued_at = $script:FixedNow.AddMinutes(-1).UtcDateTime.ToString('o')
            expires_at = $script:FixedNow.AddMinutes(14).UtcDateTime.ToString('o')
            nonce = 'f' * 32
        }

        Mock Get-ValidatedAuditConfig { $script:FakeConfig }
        Mock Get-ValidatedMigrationSecrets { $script:FakeSecrets }
        Mock Unprotect-RunnerSecret {
            param($ProtectedValue)
            if ($ProtectedValue -eq 'protected-audit-password') { return 'audit-password' }
            return 'migration-password'
        }
        Mock Get-RunnerUtcNow { $script:FixedNow }
        Mock Get-FileSha256 { $script:MigrationHash }
        Mock Read-ProtectedPlanReceipt { $script:Receipt }
        Mock New-RunnerMutex { [pscustomobject]@{ Name = 'fake' } }
        Mock Enter-RunnerMutex {}
        Mock Exit-RunnerMutex {}
        Mock Read-RunnerConfirmation {
            'MIGRATE KPS-STAGING ' + $allowedMigration + ' ' + $script:Receipt.nonce.Substring(0, 12)
        }
        Mock Open-VerifiedMigrationFile { [IO.MemoryStream]::new() }
        Mock Use-PlanReceiptOnce {}
        Mock Write-ProtectedPlanReceipt { 'C:\outside-repository\written-plan.dpapi' }
        Mock Write-FailurePolicy {}
        Mock Invoke-ArtisanMigration {
            param($EnvironmentValues, $MigrationFile)
            if ($script:MigrationThrows) {
                throw 'fake migration failure'
            }
            [pscustomobject]@{ Output = 'fake migration completed'; EnvironmentNames = @($EnvironmentValues.Keys) }
        }
        Mock Invoke-Inspector {
            param($EnvironmentValues)
            $inspectorMode = [string]$EnvironmentValues.KPS_STAGING_INCREMENTAL_MODE

            if ($inspectorMode -eq 'plan') {
                return New-FakeInspectionEnvelope (New-FakeInspectionResult -InspectorMode 'plan')
            }

            if ($inspectorMode -eq 'status') {
                return New-FakeInspectionEnvelope (New-FakeInspectionResult -InspectorMode 'status')
            }

            if ($inspectorMode -eq 'preflight') {
                $script:PreflightCallCount++
                if ($script:PreflightCallCount -eq 2 -and $null -ne $script:FinalPreflightFailure) {
                    return New-FakeInspectionEnvelope $script:FinalPreflightFailure
                }
                return New-FakeInspectionEnvelope $script:BasePreflight
            }

            if ($script:PostflightThrows) {
                throw 'fake invalid postflight output'
            }

            $result = New-FakeInspectionResult -InspectorMode 'postflight'
            if ($script:PostflightFailure) {
                $result.status = 'FAIL'
                $result.violations = @('fake_postflight_failure')
            }
            return New-FakeInspectionEnvelope $result
        }
    }

    It 'never calls the migration child when Execute acknowledgements are incomplete' {
        Set-ExecuteTestMode
        $script:AcknowledgeRpo = [Management.Automation.SwitchParameter]$false

        { Invoke-KpsStagingIncrementalRunner } | Should Throw
        Assert-MockCalled Invoke-ArtisanMigration -Times 0
        Assert-MockCalled Get-ValidatedAuditConfig -Times 0
    }

    It 'does not create a receipt or call the migration child for Status' {
        $result = Invoke-KpsStagingIncrementalRunner

        $result.ExitCode | Should Be 0
        Assert-MockCalled Write-ProtectedPlanReceipt -Times 0
        Assert-MockCalled Invoke-ArtisanMigration -Times 0
    }

    It 'does not create a receipt for a failed Plan' {
        $script:Mode = 'Plan'
        $script:Plan = [Management.Automation.SwitchParameter]$true
        Mock Invoke-Inspector {
            New-FakeInspectionEnvelope (New-FakeInspectionResult -InspectorMode 'plan' -StatusValue 'FAIL')
        }

        $result = Invoke-KpsStagingIncrementalRunner

        $result.ExitCode | Should Be 1
        Assert-MockCalled Write-ProtectedPlanReceipt -Times 0
        Assert-MockCalled Invoke-ArtisanMigration -Times 0
    }

    It 'creates a DPAPI receipt only for a successful Plan' {
        $script:Mode = 'Plan'
        $script:Plan = [Management.Automation.SwitchParameter]$true

        $result = Invoke-KpsStagingIncrementalRunner

        $result.ExitCode | Should Be 0
        Assert-MockCalled Write-ProtectedPlanReceipt -Times 1
        Assert-MockCalled Invoke-ArtisanMigration -Times 0
    }

    It 'rejects a Status receipt before migration invocation' {
        Set-ExecuteTestMode
        $script:Receipt.mode = 'STATUS'

        { Invoke-KpsStagingIncrementalRunner } | Should Throw
        Assert-MockCalled Invoke-ArtisanMigration -Times 0
    }

    It 'rejects a failed Plan receipt before migration invocation' {
        Set-ExecuteTestMode
        $script:Receipt.status = 'FAIL'

        { Invoke-KpsStagingIncrementalRunner } | Should Throw
        Assert-MockCalled Invoke-ArtisanMigration -Times 0
    }

    It 'rejects an expired Plan receipt before migration invocation' {
        Set-ExecuteTestMode
        $script:Receipt.issued_at = $script:FixedNow.AddMinutes(-20).UtcDateTime.ToString('o')
        $script:Receipt.expires_at = $script:FixedNow.AddMinutes(-5).UtcDateTime.ToString('o')

        { Invoke-KpsStagingIncrementalRunner } | Should Throw
        Assert-MockCalled Invoke-ArtisanMigration -Times 0
    }

    It 'rejects migration and target mismatches before migration invocation' {
        Set-ExecuteTestMode
        $script:Receipt.migration = '2026_10_27_010000_create_weekly_plan_google_docs_employee_mapping_tables.php'

        { Invoke-KpsStagingIncrementalRunner } | Should Throw
        Assert-MockCalled Invoke-ArtisanMigration -Times 0

        $script:Receipt.migration = $allowedMigration
        $script:Receipt.target.host = 'wrong.example.test'
        { Invoke-KpsStagingIncrementalRunner } | Should Throw
        Assert-MockCalled Invoke-ArtisanMigration -Times 0
    }

    It 'stops when the second preflight detects active DDL' {
        Set-ExecuteTestMode
        $script:FinalPreflightFailure = New-FakeInspectionResult `
            -StatusValue 'FAIL' `
            -ActiveDdlJobs 1

        { Invoke-KpsStagingIncrementalRunner } | Should Throw
        Assert-MockCalled Invoke-ArtisanMigration -Times 0
        Assert-MockCalled Exit-RunnerMutex -Times 1
    }

    It 'stops when the second preflight detects a competing migrator' {
        Set-ExecuteTestMode
        $script:FinalPreflightFailure = New-FakeInspectionResult `
            -StatusValue 'FAIL' `
            -CompetingSessions 1

        { Invoke-KpsStagingIncrementalRunner } | Should Throw
        Assert-MockCalled Invoke-ArtisanMigration -Times 0
    }

    It 'stops if the migration hash changes after confirmation' {
        Set-ExecuteTestMode
        $script:HashCallCount = 0
        Mock Get-FileSha256 {
            $script:HashCallCount++
            if ($script:HashCallCount -eq 1) { return 'a' * 64 }
            return 'b' * 64
        }

        { Invoke-KpsStagingIncrementalRunner } | Should Throw
        Assert-MockCalled Invoke-ArtisanMigration -Times 0
    }

    It 'invokes exactly one allowlisted migration child on success and releases the mutex' {
        Set-ExecuteTestMode

        $result = Invoke-KpsStagingIncrementalRunner

        $result.ExitCode | Should Be 0
        Assert-MockCalled Invoke-ArtisanMigration -Times 1 -ParameterFilter {
            $MigrationFile -eq $allowedMigration
        }
        Assert-MockCalled Use-PlanReceiptOnce -Times 1
        Assert-MockCalled Exit-RunnerMutex -Times 1
    }

    It 'does not retry or run another migration after a returned postflight failure' {
        Set-ExecuteTestMode
        $script:PostflightFailure = $true

        { Invoke-KpsStagingIncrementalRunner } | Should Throw
        Assert-MockCalled Invoke-ArtisanMigration -Times 1 -ParameterFilter {
            $MigrationFile -eq $allowedMigration
        }
        Assert-MockCalled Write-FailurePolicy -Times 1
        Assert-MockCalled Exit-RunnerMutex -Times 1
    }

    It 'prints the failure policy and does not retry when postflight throws' {
        Set-ExecuteTestMode
        $script:PostflightThrows = $true

        { Invoke-KpsStagingIncrementalRunner } | Should Throw
        Assert-MockCalled Invoke-ArtisanMigration -Times 1
        Assert-MockCalled Write-FailurePolicy -Times 1
    }

    It 'releases the mutex when the migration child throws' {
        Set-ExecuteTestMode
        $script:MigrationThrows = $true

        { Invoke-KpsStagingIncrementalRunner } | Should Throw
        Assert-MockCalled Invoke-ArtisanMigration -Times 1
        Assert-MockCalled Write-FailurePolicy -Times 1
        Assert-MockCalled Exit-RunnerMutex -Times 1
    }
}

Describe 'Status inspector zero-argument regression' {
    BeforeEach {
        $script:Mode = 'Status'
        $script:Status = [Management.Automation.SwitchParameter]$true
        $script:Plan = [Management.Automation.SwitchParameter]$false
        $script:Execute = [Management.Automation.SwitchParameter]$false
        $script:PlanReceiptPath = ''
        $script:StatusConfig = [pscustomobject]@{
            target_type = 'independent_instance'
            expected_target = 'KPS-Staging'
            connection_metadata_source = 'tidb_cloud_connect_dialog'
            staging_host = 'gateway.example.test'
            staging_port = 4000
            staging_database = 'sys'
            staging_username = 'StagePrefix.root'
            staging_ssl_ca = 'C:\outside-repository\ca.pem'
            staging_username_prefix = 'StagePrefix'
            staging_fingerprint = 'b' * 64
            staging_endpoint_fingerprint = 'c' * 64
            production_endpoint_fingerprint = 'c' * 64
            production_username = 'ProdPrefix.root'
            production_username_prefix = 'ProdPrefix'
            production_username_prefix_fingerprint = 'd' * 64
            encrypted_password = 'protected-audit-password'
        }
        $script:StatusSecrets = [pscustomobject]@{
            migration_username = 'StagePrefix.kps_migrator'
            encrypted_migration_password = 'protected-migration-password'
        }

        Mock Get-ValidatedAuditConfig { $script:StatusConfig }
        Mock Get-ValidatedMigrationSecrets { $script:StatusSecrets }
        Mock Unprotect-RunnerSecret { 'test-password' }
        Mock Write-ProtectedPlanReceipt { throw 'Status must not create a receipt.' }
        Mock Invoke-ArtisanMigration { throw 'Status must not invoke a migration child.' }
    }

    It 'routes Status through a real zero-argument inspector child' {
        $probePath = Join-Path $TestDrive 'status-zero-arguments.php'
        $probe = @'
<?php
echo json_encode([
    'status' => 'PASS',
    'mode' => getenv('KPS_STAGING_INCREMENTAL_MODE'),
    'extra_argument_count' => count($argv) - 1,
], JSON_THROW_ON_ERROR);
'@
        Set-Content -LiteralPath $probePath -Value $probe -Encoding ASCII
        $oldInspectorScript = $script:InspectorScript

        try {
            $script:InspectorScript = $probePath
            $result = Invoke-KpsStagingIncrementalRunner
            $statusResult = $result.Output[0] | ConvertFrom-Json

            $result.ExitCode | Should Be 0
            $statusResult.mode | Should Be 'status'
            $statusResult.extra_argument_count | Should Be 0
        } finally {
            $script:InspectorScript = $oldInspectorScript
        }

        Assert-MockCalled Write-ProtectedPlanReceipt -Times 0
        Assert-MockCalled Invoke-ArtisanMigration -Times 0
    }
}

Describe 'isolated child environment and file integrity' {
    It 'atomically rejects reuse of a consumed receipt nonce' {
        $oldStore = $script:ReceiptStoreRoot
        $script:ReceiptStoreRoot = Join-Path $TestDrive 'receipts'
        $receipt = [pscustomobject]@{ nonce = '1' * 32 }

        try {
            Use-PlanReceiptOnce $receipt
            { Use-PlanReceiptOnce $receipt } | Should Throw
        } finally {
            $script:ReceiptStoreRoot = $oldStore
        }
    }

    It 'constructs exactly one migrate command with no seeder or bootstrap command' {
        $probe = @'
require $argv[1].'/vendor/autoload.php';
require $argv[1].'/scripts/run-staging-incremental-migration.php';
$input = runnerMigrationInput($argv[2]);
echo json_encode([
    'command' => $input->getFirstArgument(),
    'database' => $input->getParameterOption('--database'),
    'path' => $input->getParameterOption('--path'),
    'force' => $input->hasParameterOption('--force'),
    'no_interaction' => $input->hasParameterOption('--no-interaction'),
], JSON_THROW_ON_ERROR);
'@
        $json = & php -r $probe $backendRoot $allowedMigration
        $LASTEXITCODE | Should Be 0
        $input = $json | ConvertFrom-Json
        $input.command | Should Be 'migrate'
        $input.database | Should Be 'mysql'
        $input.path | Should Be ('database/migrations/' + $allowedMigration)
        $input.force | Should Be $true
        $input.no_interaction | Should Be $true
        $json | Should Not Match 'seed|bootstrap'
    }

    It 'preserves non-empty isolated child arguments exactly' {
        $probePath = Join-Path $TestDrive 'inspect-non-empty-arguments.php'
        Set-Content -LiteralPath $probePath -Value '<?php echo json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR);' -Encoding ASCII -NoNewline
        $environment = @{ KPS_ARGUMENT_PROBE = 'true' }
        $allowed = @('SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'KPS_ARGUMENT_PROBE')
        $process = Invoke-IsolatedPhpProcess `
            -ScriptPath $probePath `
            -Arguments @('first value', 'second') `
            -EnvironmentValues $environment `
            -AllowedEnvironmentNames $allowed `
            -WorkingDirectory $backendRoot
        $arguments = [string[]]($process.StdOut | ConvertFrom-Json)

        $process.ExitCode | Should Be 0
        $arguments.Count | Should Be 2
        $arguments[0] | Should Be 'first value'
        $arguments[1] | Should Be 'second'
    }

    It 'does not inherit DB_SOCKET, DB_URL, credentials, or unrelated caller values' {
        $oldSocket = $env:DB_SOCKET
        $oldUrl = $env:DB_URL
        $oldToken = $env:UNRELATED_TEST_TOKEN

        try {
            $env:DB_SOCKET = 'attacker-socket'
            $env:DB_URL = 'mysql://attacker'
            $env:UNRELATED_TEST_TOKEN = 'secret'
            $explicit = @{ KPS_RUNNER_DB_HOST = 'gateway.example.test' }
            $allowed = @('SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'KPS_RUNNER_DB_HOST')
            $childEnvironment = New-IsolatedProcessEnvironment $explicit $allowed

            $probePath = Join-Path $TestDrive 'dump-child-environment.php'
            Set-Content -LiteralPath $probePath -Value '<?php echo json_encode(array_keys(getenv()), JSON_THROW_ON_ERROR);' -Encoding ASCII -NoNewline
            $process = Invoke-IsolatedPhpProcess `
                -ScriptPath $probePath `
                -Arguments @('probe') `
                -EnvironmentValues $explicit `
                -AllowedEnvironmentNames $allowed `
                -WorkingDirectory $backendRoot
            $actualNames = [string[]]($process.StdOut | ConvertFrom-Json)

            $childEnvironment.ContainsKey('DB_SOCKET') | Should Be $false
            $childEnvironment.ContainsKey('DB_URL') | Should Be $false
            $childEnvironment.ContainsKey('UNRELATED_TEST_TOKEN') | Should Be $false
            @($childEnvironment.Keys | Where-Object { $_ -notin $allowed }).Count | Should Be 0
            $process.ExitCode | Should Be 0
            ($actualNames -contains 'DB_SOCKET') | Should Be $false
            ($actualNames -contains 'DB_URL') | Should Be $false
            ($actualNames -contains 'UNRELATED_TEST_TOKEN') | Should Be $false
            @($actualNames | Where-Object { $_ -notin $allowed }).Count | Should Be 0
        } finally {
            $env:DB_SOCKET = $oldSocket
            $env:DB_URL = $oldUrl
            $env:UNRELATED_TEST_TOKEN = $oldToken
        }
    }

    It 'boots with a malformed env file without reading it and forces socket-free mysql config' {
        $fakeEnvironmentPath = Join-Path $TestDrive 'malformed-environment'
        New-Item -ItemType Directory -Path $fakeEnvironmentPath | Out-Null
        Set-Content -LiteralPath (Join-Path $fakeEnvironmentPath '.env') -Value 'BROKEN="unterminated' -Encoding ASCII
        $probePath = Join-Path $TestDrive 'probe-isolated-launcher.php'
        $probe = @'
<?php
require $argv[1].'/vendor/autoload.php';
require $argv[1].'/scripts/run-staging-incremental-migration.php';
runnerAssertIsolatedEnvironment();
$app = runnerBootstrapApplication($argv[2]);
$config = $app['config']->get('database.connections.mysql');
echo json_encode([
    'host' => $config['host'],
    'url' => $config['url'],
    'socket' => $config['unix_socket'],
    'verify' => in_array(true, $config['options'], true),
], JSON_THROW_ON_ERROR);
'@
        Set-Content -LiteralPath $probePath -Value $probe -Encoding UTF8
        $environment = @{
            APP_ENV = 'staging'
            APP_DEBUG = 'false'
            APP_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
            KPS_DATABASE_ENVIRONMENT = 'staging'
            KPS_RUNNER_DB_HOST = 'gateway.example.test'
            KPS_RUNNER_DB_PORT = '4000'
            KPS_RUNNER_DB_DATABASE = 'kps_internal'
            KPS_RUNNER_DB_USERNAME = 'StagePrefix.kps_migrator'
            KPS_RUNNER_DB_PASSWORD = 'not-used-by-probe'
            KPS_RUNNER_DB_SSL_CA = (Join-Path $backendRoot 'scripts\run-staging-incremental-migration.php')
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
        $allowed = @(
            'SystemRoot', 'WINDIR', 'TEMP', 'TMP',
            'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'KPS_DATABASE_ENVIRONMENT',
            'KPS_RUNNER_DB_HOST', 'KPS_RUNNER_DB_PORT', 'KPS_RUNNER_DB_DATABASE',
            'KPS_RUNNER_DB_USERNAME', 'KPS_RUNNER_DB_PASSWORD', 'KPS_RUNNER_DB_SSL_CA',
            'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION', 'FILESYSTEM_DISK',
            'WEEKLY_PLAN_STORAGE_DISK', 'KPS_STAGING_EPHEMERAL_FILES',
            'KPS_AI_DRIVER', 'WEEKLY_PLAN_DOCUMENT_AI_DRIVER', 'LOG_CHANNEL', 'LOG_LEVEL'
        )
        $process = Invoke-IsolatedPhpProcess `
            -ScriptPath $probePath `
            -Arguments @($backendRoot, $fakeEnvironmentPath) `
            -EnvironmentValues $environment `
            -AllowedEnvironmentNames $allowed `
            -WorkingDirectory $backendRoot

        $process.ExitCode | Should Be 0
        $result = $process.StdOut | ConvertFrom-Json
        $result.host | Should Be 'gateway.example.test'
        $result.socket | Should Be ''
        $result.url | Should BeNullOrEmpty
        $result.verify | Should Be $true
    }

    It 'detects a post-approval mutation and holds a deny-write handle' {
        $path = Join-Path $TestDrive 'migration.php'
        Set-Content -LiteralPath $path -Value '<?php // approved' -Encoding ASCII -NoNewline
        $approvedHash = Get-FileSha256 $path
        Set-Content -LiteralPath $path -Value '<?php // changed' -Encoding ASCII -NoNewline

        { Open-VerifiedMigrationFile $path $approvedHash } | Should Throw

        $currentHash = Get-FileSha256 $path
        $lock = Open-VerifiedMigrationFile $path $currentHash
        try {
            { Set-Content -LiteralPath $path -Value '<?php // swap' -Encoding ASCII -NoNewline } | Should Throw
        } finally {
            $lock.Dispose()
        }
    }

    It 'rejects traversal at PowerShell parameter binding before orchestration' {
        $stdout = Join-Path $TestDrive 'traversal.stdout'
        $stderr = Join-Path $TestDrive 'traversal.stderr'
        $process = Start-Process powershell.exe -WindowStyle Hidden -Wait -PassThru `
            -RedirectStandardOutput $stdout `
            -RedirectStandardError $stderr `
            -ArgumentList @(
                '-NoProfile',
                '-ExecutionPolicy', 'Bypass',
                '-File', $runnerPath,
                '-Migration', '..\2026_10_27_000000_add_google_docs_source_to_weekly_plans.php',
                '-Status'
            )
        $process.ExitCode | Should Not Be 0
    }

    It 'rejects an unknown migration at PowerShell parameter binding before orchestration' {
        $stdout = Join-Path $TestDrive 'unknown.stdout'
        $stderr = Join-Path $TestDrive 'unknown.stderr'
        $process = Start-Process powershell.exe -WindowStyle Hidden -Wait -PassThru `
            -RedirectStandardOutput $stdout `
            -RedirectStandardError $stderr `
            -ArgumentList @(
                '-NoProfile',
                '-ExecutionPolicy', 'Bypass',
                '-File', $runnerPath,
                '-Migration', '2099_01_01_000000_unknown.php',
                '-Status'
            )
        $process.ExitCode | Should Not Be 0
    }
}
