# WatchRex Agent for Windows Server 1.0 - Fabapars (https://fabapars.com)
# Windows PowerShell 5.1+ - no modules required - runs as SYSTEM via Task Scheduler.
# Uses non-localised CIM performance classes so it works on any Windows language.
#Requires -Version 5.1
$ErrorActionPreference = 'SilentlyContinue'
$ProgressPreference = 'SilentlyContinue'

$Version = '1.0.0'
$ConfigPath = Join-Path $env:ProgramData 'WatchRex\agent.json'
$StatePath = Join-Path $env:ProgramData 'WatchRex\state.json'

if (-not (Test-Path $ConfigPath)) { Write-Error "Missing $ConfigPath"; exit 1 }
$Config = Get-Content $ConfigPath -Raw | ConvertFrom-Json
$Url = if ($Config.url) { $Config.url } else { '__WATCHREX_URL__' }
if (-not $Config.token) { Write-Error 'Token not configured'; exit 1 }

$State = @{ last_run = (Get-Date).AddMinutes(-1).ToString('o'); rx = 0; tx = 0; net_at = 0; accounts_at = 0 }
if (Test-Path $StatePath) {
    try { (Get-Content $StatePath -Raw | ConvertFrom-Json).PSObject.Properties | ForEach-Object { $State[$_.Name] = $_.Value } } catch {}
}
$Since = [datetime]::Parse($State.last_run)
$Now = Get-Date
$Epoch = [int][double]::Parse((Get-Date -UFormat %s))

function Round1($v) { if ($null -eq $v) { return $null }; [math]::Round([double]$v, 1) }

# -- System --------------------------------------------------------------
$os = Get-CimInstance Win32_OperatingSystem
$cs = Get-CimInstance Win32_ComputerSystem
$cpuPerf = Get-CimInstance Win32_PerfFormattedData_PerfOS_Processor -Filter "Name='_Total'"
$sysPerf = Get-CimInstance Win32_PerfFormattedData_PerfOS_System
$cores = [int]$cs.NumberOfLogicalProcessors

$memTotal = [double]$os.TotalVisibleMemorySize * 1024
$memFree = [double]$os.FreePhysicalMemory * 1024
$pageFiles = @(Get-CimInstance Win32_PageFileUsage)
$pfSize = ($pageFiles | Measure-Object AllocatedBaseSize -Sum).Sum
$pfUsed = ($pageFiles | Measure-Object CurrentUsage -Sum).Sum

$disks = @()
foreach ($d in Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3') {
    if ($d.Size -gt 0) {
        $used = [double]$d.Size - [double]$d.FreeSpace
        $disks += [ordered]@{ mount = $d.DeviceID; fs = $d.FileSystem; total = [double]$d.Size; used = $used; percent = Round1 ($used / $d.Size * 100); inodes = $null }
    }
}
$systemDrive = $disks | Where-Object { $_.mount -eq $env:SystemDrive } | Select-Object -First 1

# Network throughput from cumulative counters (delta against last run).
$nics = @(Get-CimInstance Win32_PerfRawData_Tcpip_NetworkInterface)
$rx = [double](($nics | Measure-Object BytesReceivedPersec -Sum).Sum)
$tx = [double](($nics | Measure-Object BytesSentPersec -Sum).Sum)
$rxRate = $null; $txRate = $null
$elapsed = $Epoch - [int]$State.net_at
if ($State.net_at -gt 0 -and $elapsed -gt 0 -and $rx -ge [double]$State.rx) {
    $rxRate = [math]::Round(($rx - [double]$State.rx) / $elapsed)
    $txRate = [math]::Round(($tx - [double]$State.tx) / $elapsed)
}
$State.rx = $rx; $State.tx = $tx; $State.net_at = $Epoch

# Top processes (CPU is per-core in the counter -> normalise to whole machine).
$top = @()
Get-CimInstance Win32_PerfFormattedData_PerfProc_Process |
    Where-Object { $_.Name -notin @('_Total', 'Idle') } |
    Sort-Object PercentProcessorTime -Descending | Select-Object -First 6 | ForEach-Object {
        $top += [ordered]@{ cmd = $_.Name; user = ''; cpu = Round1 ($_.PercentProcessorTime / [math]::Max(1, $cores)); mem = Round1 ($_.WorkingSetPrivate / $memTotal * 100) }
    }

# -- Services (+ IIS sites / app pools) ---------------------------------
$watch = @('W3SVC', 'WAS', 'MSSQLSERVER', 'SQLSERVERAGENT', 'MySQL', 'MySQL80', 'MariaDB', 'postgresql-x64-16', 'TermService', 'WinRM', 'DNS', 'DHCPServer',
    'NTDS', 'MSExchangeTransport', 'MSExchangeFrontEndTransport', 'MSExchangeIS', 'MSExchangeRPC', 'hMailServer', 'SMTPSVC', 'MailEnableSMTP',
    'PleskControlPanel', 'plesksrv', 'PleskSQLServer', 'FileZilla Server', 'WinDefend', 'wuauserv', 'Docker', 'redis')
$services = [ordered]@{}
foreach ($s in Get-Service | Where-Object { $watch -contains $_.Name -or $_.Name -like 'MSSQL$*' -or $_.Name -like 'MySQL*' }) {
    if ($s.StartType -eq 'Disabled') { continue }
    $services[$s.Name] = switch ($s.Status) { 'Running' { 'active' } 'Stopped' { 'inactive' } default { "$($s.Status)".ToLower() } }
}
if (Get-Module -ListAvailable WebAdministration) {
    Import-Module WebAdministration
    foreach ($site in (Get-ChildItem IIS:\Sites | Select-Object -First 40)) {
        $services["iis/$($site.Name)"] = if ($site.State -eq 'Started') { 'active' } else { 'inactive' }
    }
    foreach ($pool in (Get-ChildItem IIS:\AppPools | Select-Object -First 40)) {
        if ($pool.autoStart) { $services["pool/$($pool.Name)"] = if ($pool.State -eq 'Started') { 'active' } else { 'inactive' } }
    }
}

# -- Hosting panel -------------------------------------------------------
$panel = ''
if ($env:plesk_dir -or (Test-Path 'HKLM:\SOFTWARE\WOW6432Node\PLESK\PSA Config\Config')) { $panel = 'plesk' }
elseif (Get-Service -Name 'SolidCPServer' -ErrorAction SilentlyContinue) { $panel = 'solidcp' }

# -- Mail ---------------------------------------------------------------
$mail = [ordered]@{ mta = ''; queue = $null; sent = $null; received = $null; bounced = $null; deferred = $null; rejected = $null; login_ok = $null; login_failed = $null; failed_ips = @(); failed_users = @(); recent_bounces = @() }
if (Get-Command Get-Queue -ErrorAction SilentlyContinue) {
    $mail.mta = 'exchange'
    $mail.queue = [int](Get-Queue | Where-Object { $_.DeliveryType -ne 'ShadowRedundancy' } | Measure-Object MessageCount -Sum).Sum
    if (Get-Command Get-MessageTrackingLog -ErrorAction SilentlyContinue) {
        $events = @(Get-MessageTrackingLog -Start $Since -End $Now -ResultSize 20000 | Select-Object EventId)
        $mail.sent = @($events | Where-Object EventId -eq 'SEND').Count
        $mail.received = @($events | Where-Object EventId -eq 'RECEIVE').Count
        $mail.bounced = @($events | Where-Object EventId -in @('FAIL', 'DSN')).Count
        $mail.deferred = @($events | Where-Object EventId -eq 'DEFER').Count
    }
} elseif (Test-Path 'C:\inetpub\mailroot\Queue') {
    $mail.mta = 'iis-smtp'
    $mail.queue = @(Get-ChildItem 'C:\inetpub\mailroot\Queue' -File).Count
    $mail.bounced = @(Get-ChildItem 'C:\inetpub\mailroot\Badmail' -File | Where-Object LastWriteTime -gt $Since).Count
} elseif (Get-Service hMailServer -ErrorAction SilentlyContinue) {
    $mail.mta = 'hmailserver'
    try {
        $h = New-Object -ComObject hMailServer.Application
        $mail.queue = [int]$h.Status.UndeliveredMessages.Split("`n").Where({ $_ -match '\S' }).Count
    } catch {}
}

# -- Security: logons since last run (Security event log) ----------------
$failed = @(Get-WinEvent -FilterHashtable @{ LogName = 'Security'; Id = 4625; StartTime = $Since } -MaxEvents 5000)
$remoteOk = @(Get-WinEvent -FilterHashtable @{ LogName = 'Security'; Id = 4624; StartTime = $Since } -MaxEvents 5000 |
    Where-Object { $_.Properties[8].Value -eq 10 })  # logon type 10 = RDP
function TopValues($items, $key) {
    @($items | Where-Object { $_ -and $_ -ne '-' } | Group-Object | Sort-Object Count -Descending | Select-Object -First 10 |
        ForEach-Object { [ordered]@{ $key = $_.Name; count = $_.Count } })
}
$security = [ordered]@{
    source = 'windows'
    failed_logons = $failed.Count
    remote_logons = $remoteOk.Count
    failed_ips = TopValues ($failed | ForEach-Object { $_.Properties[19].Value }) 'ip'
    failed_users = TopValues ($failed | ForEach-Object { $_.Properties[5].Value }) 'user'
}

# -- Report -------------------------------------------------------------
$report = [ordered]@{
    version = $Version
    platform = 'windows'
    hostname = [System.Net.Dns]::GetHostEntry('').HostName
    os = "$($os.Caption) (build $($os.BuildNumber))"
    kernel = $os.Version
    panel = $panel
    uptime = [int]($Now - $os.LastBootUpTime).TotalSeconds
    cores = $cores
    cpu = Round1 $cpuPerf.PercentProcessorTime
    iowait = $null
    ram = Round1 (($memTotal - $memFree) / $memTotal * 100)
    mem_total = $memTotal
    mem_used = $memTotal - $memFree
    swap = if ($pfSize -gt 0) { Round1 ($pfUsed / $pfSize * 100) } else { 0 }
    disk = if ($systemDrive) { $systemDrive.percent } else { $null }
    disks = $disks
    load = @([double]$sysPerf.ProcessorQueueLength)
    procs = [int]$sysPerf.Processes
    temp = $null
    net = [ordered]@{ rx_rate = $rxRate; tx_rate = $txRate }
    services = $services
    containers = @()
    top = $top
    mail = $mail
    security = $security
    ssh_failed = $failed.Count
}

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$body = [System.Text.Encoding]::UTF8.GetBytes(($report | ConvertTo-Json -Depth 6 -Compress))
$ok = $false
foreach ($attempt in 1..3) {
    try {
        Invoke-RestMethod -Uri "$Url/api/agent/report" -Method Post -Body $body -ContentType 'application/json; charset=utf-8' `
            -Headers @{ Authorization = "Bearer $($Config.token)"; 'User-Agent' = "WatchRex-Agent-Windows/$Version" } -TimeoutSec 20 -UseBasicParsing | Out-Null
        $ok = $true; break
    } catch { Start-Sleep -Seconds 3 }
}

$State.last_run = $Now.ToString('o')
$State | ConvertTo-Json | Set-Content $StatePath -Encoding UTF8
if (-not $ok) { exit 1 }
