[CmdletBinding()]
param(
    [string]$Image = 'kps-backend-smoke',

    [ValidateRange(1024, 65535)]
    [int]$HostPort = 18080,

    [switch]$SkipBuild
)

$ErrorActionPreference = 'Stop'
$backendRoot = Split-Path -Parent $PSScriptRoot
$dockerfile = Join-Path $backendRoot 'Dockerfile'
$slowFixture = Join-Path $backendRoot 'tests/Deployment/slow-request.php'
$containerName = "kps-backend-smoke-$PID"
$baseUri = "http://127.0.0.1:$HostPort"
$containerStarted = $false
$slowJob = $null

function Assert-LastExitCode {
    param([string]$Action)

    if ($LASTEXITCODE -ne 0) {
        throw "$Action failed with exit code $LASTEXITCODE."
    }
}

function Get-HttpStatusCode {
    param([Parameter(Mandatory)][string]$Uri)

    try {
        return [int](Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 5).StatusCode
    }
    catch {
        if ($null -eq $_.Exception.Response) {
            throw
        }

        return [int]$_.Exception.Response.StatusCode
    }
}

if ($null -eq (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker CLI is required for the production-container smoke test.'
}

if (-not (Test-Path -LiteralPath $slowFixture -PathType Leaf)) {
    throw "Slow-request fixture not found: $slowFixture"
}

try {
    if (-not $SkipBuild) {
        & docker build --file $dockerfile --tag $Image $backendRoot
        Assert-LastExitCode 'Docker image build'
    }

    $runArguments = @(
        'run',
        '--detach',
        '--rm',
        '--name', $containerName,
        '--publish', "127.0.0.1:${HostPort}:10000",
        '--env', 'PORT=10000',
        '--env', 'APP_ENV=testing',
        '--env', 'APP_DEBUG=false',
        '--env', 'APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        '--env', "APP_URL=$baseUri",
        '--env', "FRONTEND_URL=$baseUri",
        '--env', "SANCTUM_STATEFUL_DOMAINS=127.0.0.1:$HostPort",
        '--env', 'SESSION_SECURE_COOKIE=true',
        '--env', 'DB_CONNECTION=sqlite',
        '--env', 'DB_DATABASE=:memory:',
        '--env', 'DB_URL=',
        '--env', 'KPS_DATABASE_ENVIRONMENT=testing',
        '--env', 'CACHE_STORE=array',
        '--env', 'SESSION_DRIVER=array',
        '--env', 'QUEUE_CONNECTION=sync',
        '--env', 'MAIL_MAILER=array',
        '--env', 'FILESYSTEM_DISK=local',
        '--env', 'WEEKLY_PLAN_STORAGE_DISK=local',
        '--env', 'KPS_AI_DRIVER=fake',
        '--env', 'WEEKLY_PLAN_DOCUMENT_AI_DRIVER=none',
        '--env', 'LOG_CHANNEL=stderr',
        $Image
    )
    $containerId = & docker @runArguments
    Assert-LastExitCode 'Container startup'
    if ([string]::IsNullOrWhiteSpace(($containerId | Out-String))) {
        throw 'Docker did not return a container ID.'
    }
    $containerStarted = $true

    $ready = $false
    for ($attempt = 1; $attempt -le 60; $attempt++) {
        try {
            $ready = (Invoke-WebRequest -Uri "$baseUri/up" -UseBasicParsing -TimeoutSec 2).StatusCode -eq 200
        }
        catch {
            $ready = $false
        }
        if ($ready) {
            break
        }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) {
        throw 'The production container did not become healthy within 60 seconds.'
    }

    & docker cp $slowFixture "${containerName}:/app/public/__kps_concurrency_smoke.php"
    Assert-LastExitCode 'Copying the temporary slow-request fixture'

    $slowJob = Start-Job -ScriptBlock {
        param([string]$Uri)

        $response = Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 20 -Headers @{
            'X-Forwarded-For' = '203.0.113.10'
            'X-Forwarded-Proto' = 'https'
        }

        [pscustomobject]@{
            StatusCode = [int]$response.StatusCode
            Content = [string]$response.Content
        }
    } -ArgumentList "$baseUri/__kps_concurrency_smoke.php"

    Start-Sleep -Milliseconds 750
    if ((Get-Job -Id $slowJob.Id).State -ne 'Running') {
        throw 'The simulated slow request did not remain active long enough for the concurrency check.'
    }

    $healthTimer = [System.Diagnostics.Stopwatch]::StartNew()
    $healthResponse = Invoke-WebRequest -Uri "$baseUri/up" -UseBasicParsing -TimeoutSec 3
    $healthTimer.Stop()

    if ($healthResponse.StatusCode -ne 200) {
        throw "Concurrent /up returned HTTP $($healthResponse.StatusCode)."
    }
    if ($healthTimer.Elapsed.TotalSeconds -ge 3) {
        throw "Concurrent /up took $($healthTimer.Elapsed.TotalSeconds) seconds."
    }
    if ((Get-Job -Id $slowJob.Id).State -ne 'Running') {
        throw 'The slow request completed before /up proved concurrent handling.'
    }

    if ($null -eq (Wait-Job -Id $slowJob.Id -Timeout 15)) {
        throw 'The simulated slow request did not finish.'
    }
    $slowResult = Receive-Job -Id $slowJob.Id
    $slowBody = $slowResult.Content | ConvertFrom-Json
    if ($slowResult.StatusCode -ne 200 -or
        $slowBody.status -ne 'slow-request-complete' -or
        $slowBody.forwarded_for -ne '203.0.113.10' -or
        $slowBody.forwarded_proto -ne 'https') {
        throw 'The simulated slow request did not complete successfully.'
    }

    $apiHealth = Invoke-WebRequest -Uri "$baseUri/api/health" -UseBasicParsing -TimeoutSec 5
    $apiHealthBody = $apiHealth.Content | ConvertFrom-Json
    if ($apiHealth.StatusCode -ne 200 -or $apiHealthBody.status -ne 'ok' -or $apiHealthBody.service -ne 'kps-backend') {
        throw '/api/health did not return the expected Laravel response.'
    }

    $csrfArguments = @{
        Uri = "$baseUri/sanctum/csrf-cookie"
        UseBasicParsing = $true
        TimeoutSec = 5
        Headers = @{
            Origin = $baseUri
            Referer = "$baseUri/"
            'X-Forwarded-For' = '203.0.113.10'
            'X-Forwarded-Proto' = 'https'
        }
    }
    $csrfResponse = Invoke-WebRequest @csrfArguments
    $setCookie = [string]($csrfResponse.Headers['Set-Cookie'] -join '; ')
    if ($csrfResponse.StatusCode -ne 204 -or $setCookie -notmatch 'XSRF-TOKEN=') {
        throw 'Sanctum did not return its expected CSRF cookie through forwarded proxy headers.'
    }

    foreach ($privatePath in @('/.env', '/composer.json', '/storage/logs/laravel.log')) {
        $statusCode = Get-HttpStatusCode "$baseUri$privatePath"
        if ($statusCode -ne 404) {
            throw "Private path $privatePath returned HTTP $statusCode instead of 404."
        }
    }

    Write-Output 'PRODUCTION_CONTAINER_SMOKE=PASS'
    Write-Output "CONCURRENT_UP_STATUS=$($healthResponse.StatusCode)"
    Write-Output ("CONCURRENT_UP_SECONDS={0:N3}" -f $healthTimer.Elapsed.TotalSeconds)
    Write-Output "API_HEALTH_STATUS=$($apiHealth.StatusCode)"
    Write-Output "SANCTUM_CSRF_STATUS=$($csrfResponse.StatusCode)"
    Write-Output 'FORWARDED_HEADERS_STATUS=PASS'
    Write-Output 'PRIVATE_PATHS_STATUS=404'
}
catch {
    if ($containerStarted) {
        & docker logs $containerName
    }
    throw
}
finally {
    if ($null -ne $slowJob) {
        if ((Get-Job -Id $slowJob.Id).State -eq 'Running') {
            Stop-Job -Id $slowJob.Id
        }
        Remove-Job -Id $slowJob.Id -Force
    }
    if ($containerStarted) {
        & docker rm --force $containerName | Out-Null
    }
}
