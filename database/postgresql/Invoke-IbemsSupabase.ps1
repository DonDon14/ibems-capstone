param(
    [ValidateSet('preflight', 'smoke', 'serve')]
    [string] $Action = 'preflight',

    [ValidateRange(1024, 65535)]
    [int] $Port = 8083,

    [switch] $CredentialDialog
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$logDirectory = Join-Path $projectRoot 'writable\logs'
$logPath = Join-Path $logDirectory 'supabase-preflight-latest.log'
$originalLocation = Get-Location

if (-not (Test-Path -LiteralPath $logDirectory)) {
    New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
}

if ($CredentialDialog) {
    $credential = Get-Credential -UserName 'postgres.pukjmscgjtmqvhdncjpo' -Message 'Enter the Supabase staging database password.'
    if ($null -eq $credential) {
        throw 'Supabase credential entry was cancelled.'
    }
    $securePassword = $credential.Password
} else {
    $securePassword = Read-Host 'Supabase staging database password' -AsSecureString
}
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
$environmentKeys = @(
    'database.default.hostname',
    'database.default.database',
    'database.default.username',
    'database.default.password',
    'database.default.DBDriver',
    'database.default.port',
    'database.default.schema',
    'database.default.sslmode'
)

try {
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    Set-Location -LiteralPath $projectRoot

    [Environment]::SetEnvironmentVariable('database.default.hostname', 'aws-0-ap-southeast-1.pooler.supabase.com', 'Process')
    [Environment]::SetEnvironmentVariable('database.default.database', 'postgres', 'Process')
    [Environment]::SetEnvironmentVariable('database.default.username', 'postgres.pukjmscgjtmqvhdncjpo', 'Process')
    [Environment]::SetEnvironmentVariable('database.default.password', $plainPassword, 'Process')
    [Environment]::SetEnvironmentVariable('database.default.DBDriver', 'Postgre', 'Process')
    [Environment]::SetEnvironmentVariable('database.default.port', '5432', 'Process')
    [Environment]::SetEnvironmentVariable('database.default.schema', 'public', 'Process')
    [Environment]::SetEnvironmentVariable('database.default.sslmode', 'require', 'Process')

    switch ($Action) {
        'preflight' { & php spark ibems:preflight 2>&1 | Tee-Object -FilePath $logPath }
        'smoke' { & php spark ibems:smoke 2>&1 | Tee-Object -FilePath $logPath }
        'serve' { & php spark serve --port $Port }
    }

    exit $LASTEXITCODE
}
finally {
    Set-Location -LiteralPath $originalLocation
    if ($null -ne $plainPassword) {
        $plainPassword = $null
    }
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    foreach ($key in $environmentKeys) {
        [Environment]::SetEnvironmentVariable($key, $null, 'Process')
    }
}
