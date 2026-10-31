param([switch]$V3Disabled, [Parameter(ValueFromRemainingArguments=$true)][string[]]$PlaywrightArgs)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$backend = Join-Path $repo 'backend'
$testRoot = Join-Path $backend 'storage/framework/testing/weekly-plan-v3-e2e'
$tmp = Join-Path $testRoot 'tmp'
$resultRoot = Join-Path $repo 'test-results'
$database = Join-Path $testRoot 'database/weekly-plan-v3-e2e.sqlite'

foreach ($port in @(8001, 5174)) {
    if (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) {
        throw "Port $port is already occupied; refusing to reuse a server."
    }
}

Push-Location $backend
try {
    $env:KPS_V3_E2E_SCENARIO = if ($V3Disabled) { 'v3-disabled' } else { 'enabled' }
    if (Test-Path -LiteralPath $database) {
        & php 'tests/Infrastructure/weekly-plan-v3-environment-preflight.php' e2e verified-existing
        if ($LASTEXITCODE -ne 0) { throw 'Existing E2E database is unverified.' }
        & php 'tests/Infrastructure/weekly-plan-v3-e2e-cleanup.php'
        if ($LASTEXITCODE -ne 0) { throw 'Cannot clean the verified E2E fixture.' }
    }
    & php 'tests/Infrastructure/weekly-plan-v3-e2e-setup.php'
    if ($LASTEXITCODE -ne 0) { throw 'Cannot create the isolated E2E fixture.' }
}
finally { Pop-Location }

New-Item -ItemType Directory -Path $resultRoot -Force | Out-Null
$backendProcess = $null
$frontendProcess = $null
try {
    $backendProcess = Start-Process -FilePath 'php' -ArgumentList @(
        '-d', 'display_errors=0',
        '-d', "upload_tmp_dir=$tmp",
        '-d', "sys_temp_dir=$tmp",
        '-S', '127.0.0.1:8001',
        '-t', (Join-Path $backend 'public'),
        (Join-Path $backend 'tests/Infrastructure/weekly-plan-v3-e2e-router.php')
    ) -WorkingDirectory $backend -RedirectStandardOutput (Join-Path $resultRoot 'backend.stdout.log') -RedirectStandardError (Join-Path $resultRoot 'backend.stderr.log') -PassThru -WindowStyle Hidden

    $env:VITE_API_BASE_URL = 'http://127.0.0.1:8001'
    $frontendProcess = Start-Process -FilePath 'node' -ArgumentList @(
        'node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', '5174', '--strictPort'
    ) -WorkingDirectory $repo -RedirectStandardOutput (Join-Path $resultRoot 'frontend.stdout.log') -RedirectStandardError (Join-Path $resultRoot 'frontend.stderr.log') -PassThru -WindowStyle Hidden

    & (Join-Path $repo 'node_modules/.bin/playwright.cmd') test @PlaywrightArgs
    $testExit = $LASTEXITCODE
}
finally {
    if ($frontendProcess) { Stop-Process -Id $frontendProcess.Id -Force -ErrorAction SilentlyContinue }
    if ($backendProcess) { Stop-Process -Id $backendProcess.Id -Force -ErrorAction SilentlyContinue }
    Remove-Item Env:VITE_API_BASE_URL -ErrorAction SilentlyContinue
    Remove-Item Env:KPS_V3_E2E_SCENARIO -ErrorAction SilentlyContinue
    Start-Sleep -Milliseconds 300
    Push-Location $backend
    try {
        & php 'tests/Infrastructure/weekly-plan-v3-e2e-cleanup.php'
        if ($LASTEXITCODE -ne 0) { throw 'Isolated E2E cleanup failed.' }
    }
    finally { Pop-Location }
}

exit $testExit
