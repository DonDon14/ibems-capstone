param(
    [ValidateSet('validate', 'preflight', 'smoke', 'seed', 'snapshot', 'serve', 'serve-background')]
    [string] $Action = 'preflight',

    [ValidateRange(1024, 65535)]
    [int] $Port = 8083,

    [switch] $CredentialDialog
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$logDirectory = Join-Path $projectRoot 'writable\logs'
$logPath = Join-Path $logDirectory 'supabase-preflight-latest.log'
$seedLogPath = Join-Path $logDirectory 'supabase-seed-latest.log'
$snapshotLogPath = Join-Path $logDirectory 'supabase-snapshot-latest.log'
$snapshotPath = Join-Path $logDirectory 'supabase-financial-snapshot-latest.json'
$validationLogPath = Join-Path $logDirectory 'supabase-validation-latest.log'
$serverOutputPath = Join-Path $logDirectory 'supabase-server-latest.log'
$serverErrorPath = Join-Path $logDirectory 'supabase-server-error-latest.log'
$serverPidPath = Join-Path $logDirectory 'supabase-server-latest.pid'
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
    'IBEMS_BASE_URL',
    'IBEMS_DATABASE_HOSTNAME',
    'IBEMS_DATABASE_NAME',
    'IBEMS_DATABASE_USERNAME',
    'IBEMS_DATABASE_PASSWORD',
    'IBEMS_DATABASE_DRIVER',
    'IBEMS_DATABASE_PORT',
    'IBEMS_DATABASE_SCHEMA',
    'IBEMS_DATABASE_SSLMODE'
)

try {
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    Set-Location -LiteralPath $projectRoot

    [Environment]::SetEnvironmentVariable('IBEMS_BASE_URL', "http://localhost:$Port/", 'Process')
    [Environment]::SetEnvironmentVariable('IBEMS_DATABASE_HOSTNAME', 'aws-0-ap-southeast-1.pooler.supabase.com', 'Process')
    [Environment]::SetEnvironmentVariable('IBEMS_DATABASE_NAME', 'postgres', 'Process')
    [Environment]::SetEnvironmentVariable('IBEMS_DATABASE_USERNAME', 'postgres.pukjmscgjtmqvhdncjpo', 'Process')
    [Environment]::SetEnvironmentVariable('IBEMS_DATABASE_PASSWORD', $plainPassword, 'Process')
    [Environment]::SetEnvironmentVariable('IBEMS_DATABASE_DRIVER', 'Postgre', 'Process')
    [Environment]::SetEnvironmentVariable('IBEMS_DATABASE_PORT', '5432', 'Process')
    [Environment]::SetEnvironmentVariable('IBEMS_DATABASE_SCHEMA', 'public', 'Process')
    [Environment]::SetEnvironmentVariable('IBEMS_DATABASE_SSLMODE', 'require', 'Process')

    function Invoke-LoggedPhpAction {
        param(
            [Parameter(Mandatory)]
            [string] $Name,

            [Parameter(Mandatory)]
            [string[]] $Arguments,

            [Parameter(Mandatory)]
            [string] $OutputPath,

            [switch] $Append
        )

        $actionOutput = & php @Arguments 2>&1
        $actionExitCode = $LASTEXITCODE
        if ($Append) {
            $actionOutput | Tee-Object -FilePath $OutputPath -Append
        } else {
            $actionOutput | Tee-Object -FilePath $OutputPath
        }
        $actionText = $actionOutput -join [Environment]::NewLine
        $reportedFrameworkFailure = $actionText -match '\[(?:CodeIgniter\\[^\]]+|ErrorException|Exception)\]'
        if ($actionExitCode -ne 0 -or $reportedFrameworkFailure) {
            throw "IBEMS Supabase action '$Name' failed with exit code $actionExitCode."
        }
    }

    switch ($Action) {
        'validate' {
            "IBEMS Supabase validation started at $(Get-Date -Format o)" | Set-Content -LiteralPath $validationLogPath
            Invoke-LoggedPhpAction -Name 'seed' -Arguments @('spark', 'db:seed', 'InitialSeeder') -OutputPath $validationLogPath -Append
            Invoke-LoggedPhpAction -Name 'preflight' -Arguments @('spark', 'ibems:preflight') -OutputPath $validationLogPath -Append
            Invoke-LoggedPhpAction -Name 'snapshot' -Arguments @('spark', 'ibems:financial-snapshot', '--output', $snapshotPath) -OutputPath $validationLogPath -Append
            Invoke-LoggedPhpAction -Name 'smoke' -Arguments @('spark', 'ibems:smoke') -OutputPath $validationLogPath -Append
        }
        'preflight' { $actionOutput = & php spark ibems:preflight 2>&1; $actionExitCode = $LASTEXITCODE; $actionOutput | Tee-Object -FilePath $logPath }
        'smoke' { $actionOutput = & php spark ibems:smoke 2>&1; $actionExitCode = $LASTEXITCODE; $actionOutput | Tee-Object -FilePath $logPath }
        'seed' { $actionOutput = & php spark db:seed InitialSeeder 2>&1; $actionExitCode = $LASTEXITCODE; $actionOutput | Tee-Object -FilePath $seedLogPath }
        'snapshot' { $actionOutput = & php spark ibems:financial-snapshot --output $snapshotPath 2>&1; $actionExitCode = $LASTEXITCODE; $actionOutput | Tee-Object -FilePath $snapshotLogPath }
        'serve' { & php spark serve --port $Port }
        'serve-background' {
            $serverProcess = Start-Process -FilePath 'php' -ArgumentList @('spark', 'serve', '--port', $Port) -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput $serverOutputPath -RedirectStandardError $serverErrorPath -PassThru
            Set-Content -LiteralPath $serverPidPath -Value $serverProcess.Id
            Write-Output "IBEMS Supabase server started on port $Port with process ID $($serverProcess.Id)."
        }
    }

    if ($Action -notin @('validate', 'serve', 'serve-background') -and $actionExitCode -ne 0) {
        throw "IBEMS Supabase action '$Action' failed with exit code $actionExitCode."
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
