#Requires -RunAsAdministrator

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$serviceName = 'mysql'
$xamppRoot = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
$mysqlExecutable = Join-Path $xamppRoot 'mysql\bin\mysqld.exe'
$defaultsFile = Join-Path $xamppRoot 'mysql\bin\my.ini'

foreach ($requiredPath in @($mysqlExecutable, $defaultsFile)) {
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
        throw "Required XAMPP MariaDB file not found: $requiredPath"
    }
}

$existingService = Get-CimInstance Win32_Service -Filter "Name='$serviceName'" -ErrorAction SilentlyContinue
if ($null -ne $existingService) {
    $normalizedServicePath = $existingService.PathName.Replace('"', '').ToLowerInvariant()
    $normalizedMysqlPath = $mysqlExecutable.ToLowerInvariant()
    if (-not $normalizedServicePath.Contains($normalizedMysqlPath)) {
        throw "The '$serviceName' service already belongs to another MySQL installation: $($existingService.PathName)"
    }
} else {
    & $mysqlExecutable --install $serviceName "--defaults-file=$defaultsFile"
    if ($LASTEXITCODE -ne 0) {
        throw "MariaDB service installation failed with exit code $LASTEXITCODE."
    }
}

Set-Service -Name $serviceName -StartupType Automatic
$service = Get-Service -Name $serviceName
if ($service.Status -ne 'Running') {
    Start-Service -Name $serviceName
    $service = Get-Service -Name $serviceName
    $service.WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
}

$service = Get-Service -Name $serviceName
[PSCustomObject]@{
    Service = $service.Name
    Status = $service.Status
    StartupType = $service.StartType
    DatabasePort = 3306
    Executable = $mysqlExecutable
}
