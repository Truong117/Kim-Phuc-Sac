[CmdletBinding(DefaultParameterSetName = 'Create')]
param(
    [Parameter(Mandatory = $true, ParameterSetName = 'Create')][string]$DryRunPath,
    [Parameter(Mandatory = $true, ParameterSetName = 'Export')][string]$ProtectedBundlePath,
    [Parameter(Mandatory = $true)][string]$OutputPath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$RepositoryRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
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
$outputAbsolute = [IO.Path]::GetFullPath($OutputPath)
foreach ($root in $forbiddenRoots) {
    if ($outputAbsolute.StartsWith($root.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Credential artifacts must remain outside Git.'
    }
}
if (Test-Path -LiteralPath $outputAbsolute) { throw 'Refusing to overwrite an existing credential artifact.' }
$parent = Split-Path -Parent $outputAbsolute
$ownerSid = [Security.Principal.WindowsIdentity]::GetCurrent().User
$allowedSids = @($ownerSid.Value, 'S-1-5-18', 'S-1-5-32-544')
if (-not (Test-Path -LiteralPath $parent)) {
    $null = New-Item -ItemType Directory -Path $parent
    $directoryAcl = [Security.AccessControl.DirectorySecurity]::new()
    $directoryAcl.SetOwner($ownerSid)
    $directoryAcl.SetAccessRuleProtection($true, $false)
    foreach ($sid in $allowedSids) {
        $rule = [Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.SecurityIdentifier]::new($sid), 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
        $directoryAcl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $parent -AclObject $directoryAcl
}
$acl = Get-Acl -LiteralPath $parent
foreach ($rule in $acl.Access) {
    $sid = $rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
    if ($rule.AccessControlType -eq 'Allow' -and $sid -notin $allowedSids) { throw 'Credential directory is not private to this operator/system/admins.' }
}
$passwords = @{}
if ($PSCmdlet.ParameterSetName -eq 'Create') {
    $dry = Get-Content -LiteralPath $DryRunPath -Encoding UTF8 -Raw | ConvertFrom-Json
    if ($dry.status -cne 'PASS' -or $dry.mode -cne 'dry-run' -or $dry.identity.target -cne 'KPS-Staging' -or $dry.writes_executed -ne $false -or
        $dry.fingerprint -notmatch '^[a-f0-9]{64}$' -or @($dry.plan.accounts.PSObject.Properties).Count -ne 7) {
        throw 'Verified staging dry-run evidence is required.'
    }
    $random = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        foreach ($account in $dry.plan.accounts.PSObject.Properties.Value) {
            if ($account.email -notmatch '^wra76\.[a-z0-9-]+\.[a-z-]+@kps-acceptance\.example\.test$') { throw 'Unexpected account namespace.' }
            $bytes = New-Object byte[] 36
            $random.GetBytes($bytes)
            $password = [Convert]::ToBase64String($bytes)
            $secure = ConvertTo-SecureString $password -AsPlainText -Force
            try { $passwords[$account.email] = ConvertFrom-SecureString $secure } finally { $secure.Dispose() }
            [Array]::Clear($bytes, 0, $bytes.Length)
            $password = $null
        }
    } finally { $random.Dispose() }
    $artifact = @{ version = 1; expected_target = 'KPS-Staging'; batch = $dry.plan.batch; fingerprint = $dry.fingerprint; encrypted_passwords = $passwords }
} else {
    $bundle = Get-Content -LiteralPath $ProtectedBundlePath -Encoding UTF8 -Raw | ConvertFrom-Json
    if ($bundle.version -ne 1 -or $bundle.expected_target -cne 'KPS-Staging' -or @($bundle.encrypted_passwords.PSObject.Properties).Count -ne 7) {
        throw 'Unexpected protected credential bundle.'
    }
    foreach ($entry in $bundle.encrypted_passwords.PSObject.Properties) {
        if ($entry.Name -notmatch '^wra76\.[a-z0-9-]+\.[a-z-]+@kps-acceptance\.example\.test$') { throw 'Unexpected account namespace.' }
        $secure = ConvertTo-SecureString ([string]$entry.Value)
        $bstr = [IntPtr]::Zero
        try {
            $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
            $passwords[$entry.Name] = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
        } finally {
            if ($bstr -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
            $secure.Dispose()
        }
    }
    $artifact = $passwords
}
$stream = [IO.File]::Open($outputAbsolute, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
$writer = [IO.StreamWriter]::new($stream, [Text.UTF8Encoding]::new($false))
try { $writer.Write(($artifact | ConvertTo-Json -Depth 8)) } finally { $writer.Dispose(); $passwords.Clear(); $artifact = $null }
Write-Output (@{ status = 'PASS'; mode = $PSCmdlet.ParameterSetName; accounts = 7; saved_path = $outputAbsolute; passwords_printed = $false } | ConvertTo-Json -Compress)
