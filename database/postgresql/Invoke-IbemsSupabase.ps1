param(
    [ValidateSet('validate', 'reconcile', 'migrate', 'payment-accounts', 'credit-boundary-test', 'preflight', 'smoke', 'seed', 'snapshot', 'serve', 'serve-background')]
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
$tempDirectory = Join-Path $projectRoot 'writable\temp'
$mysqlTransferPath = Join-Path $tempDirectory 'mysql-transfer.json'
$postgresBackupPath = Join-Path $tempDirectory 'supabase-before-reconcile.json'
$postgresImportedPath = Join-Path $tempDirectory 'supabase-after-import.json'
$postgresRestoredPath = Join-Path $tempDirectory 'supabase-after-restore.json'
$postgresFinalPath = Join-Path $tempDirectory 'supabase-final-transfer.json'
$reconcileLogPath = Join-Path $logDirectory 'supabase-reconcile-latest.log'
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
$storageKeyPointer = [IntPtr]::Zero
$plainStorageKey = $null
if ($Action -notin @('payment-accounts', 'credit-boundary-test')) {
    $secureStorageKey = Read-Host 'Supabase server secret key for Storage' -AsSecureString
    $storageKeyPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureStorageKey)
}
$environmentKeys = @(
    'IBEMS_BASE_URL',
    'IBEMS_DATABASE_HOSTNAME',
    'IBEMS_DATABASE_NAME',
    'IBEMS_DATABASE_USERNAME',
    'IBEMS_DATABASE_PASSWORD',
    'IBEMS_DATABASE_DRIVER',
    'IBEMS_DATABASE_PORT',
    'IBEMS_DATABASE_SCHEMA',
    'IBEMS_DATABASE_SSLMODE',
    'IBEMS_ALLOW_STAGING_RESET',
    'IBEMS_ASSET_STORAGE_DRIVER',
    'IBEMS_SUPABASE_URL',
    'IBEMS_SUPABASE_SECRET_KEY',
    'IBEMS_SUPABASE_STORAGE_BUCKET'
)

try {
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    if ($storageKeyPointer -ne [IntPtr]::Zero) {
        $plainStorageKey = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($storageKeyPointer)
    }
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
    [Environment]::SetEnvironmentVariable('IBEMS_ALLOW_STAGING_RESET', '1', 'Process')
    [Environment]::SetEnvironmentVariable('IBEMS_ASSET_STORAGE_DRIVER', 'supabase', 'Process')
    [Environment]::SetEnvironmentVariable('IBEMS_SUPABASE_URL', 'https://pukjmscgjtmqvhdncjpo.supabase.co', 'Process')
    if ($null -ne $plainStorageKey) {
        [Environment]::SetEnvironmentVariable('IBEMS_SUPABASE_SECRET_KEY', $plainStorageKey, 'Process')
    }
    [Environment]::SetEnvironmentVariable('IBEMS_SUPABASE_STORAGE_BUCKET', 'ibems-assets', 'Process')

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

    function Assert-TransferEqual {
        param(
            [Parameter(Mandatory)] [string] $ExpectedPath,
            [Parameter(Mandatory)] [string] $ActualPath,
            [Parameter(Mandatory)] [string] $Label
        )

        $expected = Get-Content -Raw -LiteralPath $ExpectedPath | ConvertFrom-Json
        $actual = Get-Content -Raw -LiteralPath $ActualPath | ConvertFrom-Json
        $failures = @()
        foreach ($property in $expected.tables.PSObject.Properties) {
            $name = $property.Name
            $expectedTable = $property.Value
            $actualTable = $actual.tables.$name
            if ($null -eq $actualTable -or $expectedTable.count -ne $actualTable.count -or $expectedTable.sha256 -ne $actualTable.sha256) {
                $failures += $name
            }
        }
        if ($failures.Count -gt 0) {
            throw "$Label failed for tables: $($failures -join ', ')"
        }
        "$Label passed for $(@($expected.tables.PSObject.Properties).Count) tables." | Tee-Object -FilePath $reconcileLogPath -Append
    }

    switch ($Action) {
        'validate' {
            "IBEMS Supabase validation started at $(Get-Date -Format o)" | Set-Content -LiteralPath $validationLogPath
            Invoke-LoggedPhpAction -Name 'seed' -Arguments @('spark', 'db:seed', 'InitialSeeder') -OutputPath $validationLogPath -Append
            Invoke-LoggedPhpAction -Name 'preflight' -Arguments @('spark', 'ibems:preflight') -OutputPath $validationLogPath -Append
            Invoke-LoggedPhpAction -Name 'snapshot' -Arguments @('spark', 'ibems:financial-snapshot', '--output', $snapshotPath) -OutputPath $validationLogPath -Append
            Invoke-LoggedPhpAction -Name 'smoke' -Arguments @('spark', 'ibems:smoke') -OutputPath $validationLogPath -Append
        }
        'reconcile' {
            if (-not (Test-Path -LiteralPath $mysqlTransferPath)) {
                throw "Create the canonical MySQL transfer first: $mysqlTransferPath"
            }
            "IBEMS Supabase reconciliation started at $(Get-Date -Format o)" | Set-Content -LiteralPath $reconcileLogPath
            Invoke-LoggedPhpAction -Name 'backup-export' -Arguments @('spark', 'ibems:database-transfer', '--export', $postgresBackupPath) -OutputPath $reconcileLogPath -Append
            Invoke-LoggedPhpAction -Name 'mysql-import' -Arguments @('spark', 'ibems:database-transfer', '--import', $mysqlTransferPath) -OutputPath $reconcileLogPath -Append
            Invoke-LoggedPhpAction -Name 'import-export' -Arguments @('spark', 'ibems:database-transfer', '--export', $postgresImportedPath) -OutputPath $reconcileLogPath -Append
            Assert-TransferEqual -ExpectedPath $mysqlTransferPath -ActualPath $postgresImportedPath -Label 'MySQL to PostgreSQL reconciliation'

            Invoke-LoggedPhpAction -Name 'backup-restore' -Arguments @('spark', 'ibems:database-transfer', '--import', $postgresBackupPath) -OutputPath $reconcileLogPath -Append
            Invoke-LoggedPhpAction -Name 'restore-export' -Arguments @('spark', 'ibems:database-transfer', '--export', $postgresRestoredPath) -OutputPath $reconcileLogPath -Append
            Assert-TransferEqual -ExpectedPath $postgresBackupPath -ActualPath $postgresRestoredPath -Label 'PostgreSQL backup restoration'

            Invoke-LoggedPhpAction -Name 'final-import' -Arguments @('spark', 'ibems:database-transfer', '--import', $mysqlTransferPath) -OutputPath $reconcileLogPath -Append
            Invoke-LoggedPhpAction -Name 'final-export' -Arguments @('spark', 'ibems:database-transfer', '--export', $postgresFinalPath) -OutputPath $reconcileLogPath -Append
            Assert-TransferEqual -ExpectedPath $mysqlTransferPath -ActualPath $postgresFinalPath -Label 'Final PostgreSQL reconciliation'
            Invoke-LoggedPhpAction -Name 'final-snapshot' -Arguments @('spark', 'ibems:financial-snapshot', '--output', $snapshotPath) -OutputPath $reconcileLogPath -Append
            Invoke-LoggedPhpAction -Name 'final-preflight' -Arguments @('spark', 'ibems:preflight') -OutputPath $reconcileLogPath -Append
        }
        'preflight' { $actionOutput = & php spark ibems:preflight 2>&1; $actionExitCode = $LASTEXITCODE; $actionOutput | Tee-Object -FilePath $logPath }
        'migrate' { $actionOutput = & php spark migrate --all 2>&1; $actionExitCode = $LASTEXITCODE; $actionOutput | Tee-Object -FilePath $validationLogPath }
        'payment-accounts' { $actionOutput = & php spark ibems:payment-accounts-migrate 2>&1; $actionExitCode = $LASTEXITCODE; $actionOutput | Tee-Object -FilePath $validationLogPath }
        'credit-boundary-test' { $actionOutput = & php spark ibems:verify-live-credit-boundary 2>&1; $actionExitCode = $LASTEXITCODE; $actionOutput | Tee-Object -FilePath $validationLogPath }
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

    if ($Action -notin @('validate', 'reconcile', 'serve', 'serve-background') -and $actionExitCode -ne 0) {
        throw "IBEMS Supabase action '$Action' failed with exit code $actionExitCode."
    }

    exit $LASTEXITCODE
}
finally {
    Set-Location -LiteralPath $originalLocation
    if ($null -ne $plainPassword) {
        $plainPassword = $null
    }
    if ($null -ne $plainStorageKey) {
        $plainStorageKey = $null
    }
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    if ($storageKeyPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($storageKeyPointer)
    }
    foreach ($key in $environmentKeys) {
        [Environment]::SetEnvironmentVariable($key, $null, 'Process')
    }
    if ($Action -eq 'reconcile') {
        foreach ($artifactPath in @($mysqlTransferPath, $postgresBackupPath, $postgresImportedPath, $postgresRestoredPath, $postgresFinalPath)) {
            if (Test-Path -LiteralPath $artifactPath) {
                Remove-Item -LiteralPath $artifactPath -Force
            }
        }
    }
}
