# WatchRex Agent installer for Windows Server - Fabapars (https://fabapars.com)
# Run in an elevated PowerShell:
#   iwr -UseBasicParsing __WATCHREX_URL__/agent/install.ps1 -OutFile $env:TEMP\wrx.ps1; & $env:TEMP\wrx.ps1 -Token <AGENT_TOKEN>
#Requires -Version 5.1
#Requires -RunAsAdministrator
param(
    [string]$Token,
    [int]$IntervalMinutes = 1,
    [switch]$Uninstall
)
$ErrorActionPreference = 'Stop'
$Url = '__WATCHREX_URL__'
$TaskName = 'WatchRex Agent'
$InstallDir = Join-Path $env:ProgramFiles 'WatchRex'
$DataDir = Join-Path $env:ProgramData 'WatchRex'

if ($Uninstall) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Remove-Item $InstallDir, $DataDir -Recurse -Force -ErrorAction SilentlyContinue
    Write-Host 'WatchRex agent removed.'
    return
}

if (-not $Token) { throw 'Usage: install.ps1 -Token <AGENT_TOKEN> [-IntervalMinutes 1] | -Uninstall' }

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
New-Item -ItemType Directory -Force -Path $InstallDir, $DataDir | Out-Null

$agentPath = Join-Path $InstallDir 'watchrex-agent.ps1'
Invoke-WebRequest -UseBasicParsing -Uri "$Url/agent/watchrex-agent.ps1" -OutFile $agentPath

# Verify the downloaded script parses before scheduling it.
$parseErrors = $null
[System.Management.Automation.Language.Parser]::ParseFile($agentPath, [ref]$null, [ref]$parseErrors) | Out-Null
if ($parseErrors.Count -gt 0) { throw "Downloaded agent is invalid: $($parseErrors[0].Message)" }

# Config readable only by SYSTEM and Administrators.
$configPath = Join-Path $DataDir 'agent.json'
@{ url = $Url; token = $Token } | ConvertTo-Json | Set-Content -Path $configPath -Encoding UTF8
$acl = New-Object System.Security.AccessControl.DirectorySecurity
$acl.SetAccessRuleProtection($true, $false)
foreach ($sid in @('S-1-5-18', 'S-1-5-32-544')) {  # SYSTEM, Administrators
    $identity = (New-Object System.Security.Principal.SecurityIdentifier($sid)).Translate([System.Security.Principal.NTAccount])
    $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')))
}
Set-Acl -Path $DataDir -AclObject $acl

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$agentPath`""
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddSeconds(30) -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes)
$principal = New-ScheduledTaskPrincipal -UserId 'S-1-5-18' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 2) -MultipleInstances IgnoreNew -StartWhenAvailable -Priority 7
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null

& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $agentPath
if ($LASTEXITCODE -eq 0) {
    Write-Host 'WatchRex agent installed and reported successfully.' -ForegroundColor Green
} else {
    Write-Warning "Installed, but the first report failed. Check the token and that $Url is reachable."
}
Write-Host "Uninstall: & `$env:TEMP\wrx.ps1 -Uninstall"
