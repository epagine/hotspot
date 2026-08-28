# Agente: liga/desliga o hotspot, DNS cativo e status real da rede.
$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent $PSScriptRoot
$Storage = Join-Path $Root "storage"
$AuthFile = Join-Path $Storage "authorized.json"
$CmdFile = Join-Path $Storage "command.json"
$StatusFile = Join-Path $Storage "status.json"
$PidFile = Join-Path $Storage "agent.pid"
$DnsProxy = Join-Path $Root "bin\dns-proxy.php"
$MaxClients = 8

if (-not (Test-Path $Storage)) {
    New-Item -ItemType Directory -Path $Storage | Out-Null
}

if (Test-Path $PidFile) {
    $oldPid = 0
    [void][int]::TryParse(((Get-Content $PidFile -ErrorAction SilentlyContinue | Select-Object -First 1)), [ref]$oldPid)
    if ($oldPid -and (Get-Process -Id $oldPid -ErrorAction SilentlyContinue) -and $oldPid -ne $PID) {
        exit 0
    }
}
Set-Content -Path $PidFile -Value $PID

Add-Type -AssemblyName System.Runtime.WindowsRuntime -ErrorAction SilentlyContinue | Out-Null

$script:DnsProc = $null
$script:LastCmdId = ""
$script:LastError = $null
$script:TetheringMgr = $null

function Wait-WinRtAction {
    param($Async)
    if (-not $Async) { return $null }
    $asTask = [System.WindowsRuntimeSystemExtensions].GetMethods() |
        Where-Object { $_.Name -eq "AsTask" -and $_.GetParameters().Count -eq 1 -and -not $_.IsGenericMethod } |
        Select-Object -First 1
    if (-not $asTask) { return $null }
    $task = $asTask.Invoke($null, @($Async))
    if (-not $task.Wait(25000)) {
        throw "Tempo esgotado ao configurar o hotspot do Windows."
    }
    if ($task.IsFaulted) {
        throw $task.Exception.GetBaseException().Message
    }
    return $null
}

function Wait-WinRt {
    param($Operation, [Type]$ResultType = $null)
    if (-not $Operation) { return $null }
    if (-not $ResultType) {
        $ResultType = [Windows.Networking.NetworkOperators.NetworkOperatorTetheringOperationResult, Windows.Networking.NetworkOperators, ContentType = WindowsRuntime]
    }
    $asTask = [System.WindowsRuntimeSystemExtensions].GetMethods() |
        Where-Object {
            $_.Name -eq "AsTask" -and $_.IsGenericMethod -and $_.GetParameters().Count -eq 1 -and
            $_.GetParameters()[0].ParameterType.Name -eq "IAsyncOperation``1"
        } |
        Select-Object -First 1
    if (-not $asTask) {
        throw "Nao foi possivel aguardar o hotspot (AsTask)."
    }
    $task = $asTask.MakeGenericMethod($ResultType).Invoke($null, @($Operation))
    if (-not $task.Wait(25000)) {
        throw "Tempo esgotado ao ligar o hotspot. Verifique o adaptador TP-Link (Wi-Fi 5)."
    }
    if ($task.IsFaulted) {
        throw $task.Exception.GetBaseException().Message
    }
    return $task.Result
}

function Get-WifiAdapters {
    $list = @()
    Get-NetAdapter -ErrorAction SilentlyContinue | Where-Object {
        $_.Status -ne "Not Present" -and (
            $_.MediaType -eq "Native 802.11" -or
            $_.PhysicalMediaType -eq "Native 802.11" -or
            $_.InterfaceDescription -match "Wi-Fi|WiFi|Wireless|802\.11|WLAN|TP-Link|Realtek.*WLAN|USB Adapter" -or
            $_.Name -match "Wi-Fi|WiFi|Wireless|WLAN"
        ) -and $_.InterfaceDescription -notmatch "Loopback|KM-TEST|VirtualBox|VMware|Hyper-V|Bluetooth"
    } | ForEach-Object {
        $list += @{
            name   = [string]$_.Name
            status = [string]$_.Status
            desc   = [string]$_.InterfaceDescription
        }
    }
    return $list
}

function Get-TetheringManager {
    if ($script:TetheringMgr) { return $script:TetheringMgr }
    $mgrType = [Windows.Networking.NetworkOperators.NetworkOperatorTetheringManager, Windows.Networking.NetworkOperators, ContentType = WindowsRuntime]
    $infoType = [Windows.Networking.Connectivity.NetworkInformation, Windows.Networking.Connectivity, ContentType = WindowsRuntime]
    $profiles = @()
    try {
        $inet = $infoType::GetInternetConnectionProfile()
        if ($inet -and [string]$inet.ProfileName -notmatch "Loopback|Topaz|KM-TEST") {
            $profiles += $inet
        }
    } catch {}
    try {
        foreach ($p in $infoType::GetConnectionProfiles()) {
            $n = [string]$p.ProfileName
            if ($n -match "Loopback|Topaz|KM-TEST") { continue }
            $profiles += $p
        }
    } catch {}
    foreach ($p in $profiles) {
        try {
            $cap = $mgrType::GetTetheringCapabilityFromConnectionProfile($p)
            if ([int]$cap -eq 0) {
                $script:TetheringMgr = $mgrType::CreateFromConnectionProfile($p)
                return $script:TetheringMgr
            }
        } catch {}
    }
    foreach ($p in $profiles) {
        try {
            $script:TetheringMgr = $mgrType::CreateFromConnectionProfile($p)
            return $script:TetheringMgr
        } catch {}
    }
    throw "Nao foi possivel usar a internet da Ethernet para o hotspot. Desative VPN/loopback (Topaz) e tente de novo."
}

function Get-PhpExe {
    $bundled = Join-Path $Root "runtime\php\php.exe"
    if (Test-Path $bundled) { return $bundled }
    $saved = Join-Path $Storage "php-path.txt"
    if (Test-Path $saved) {
        $p = (Get-Content $saved -Raw).Trim()
        if ($p -and (Test-Path $p)) { return $p }
    }
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $found = Get-ChildItem "C:\laragon\bin\php" -Recurse -Filter php.exe -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($found) { return $found.FullName }
    return $null
}

function Get-WifiConfig {
    $ssid = "WifiDaLoja"
    $pass = "loja1234"
    if (Test-Path $AuthFile) {
        $cfg = Get-Content $AuthFile -Raw -ErrorAction SilentlyContinue | ConvertFrom-Json
        if ($cfg.ssid) { $ssid = [string]$cfg.ssid }
        if ($cfg.wifi_pass) { $pass = [string]$cfg.wifi_pass }
    }
    return @{ Ssid = $ssid; Pass = $pass }
}

function Start-DnsProxy {
    if ($script:DnsProc -and -not $script:DnsProc.HasExited) { return }
    $php = Get-PhpExe
    if (-not $php) { throw "PHP nao encontrado no PATH nem no Laragon." }
    $script:DnsProc = Start-Process -FilePath $php -ArgumentList @($DnsProxy) -WindowStyle Hidden -PassThru
}

function Stop-DnsProxy {
    if ($script:DnsProc -and -not $script:DnsProc.HasExited) {
        Stop-Process -Id $script:DnsProc.Id -Force -ErrorAction SilentlyContinue
    }
    $script:DnsProc = $null
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine -like "*dns-proxy.php*" } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

function Set-Hotspot {
    param([bool]$On, [bool]$ApplyOnly = $false)
    $wifiCards = @(Get-WifiAdapters)
    if ($On -and $wifiCards.Count -eq 0) {
        throw "Nenhum adaptador Wi-Fi encontrado. O TP-Link USB precisa aparecer como Wi-Fi no Windows."
    }
    foreach ($card in $wifiCards) {
        try { Enable-NetAdapter -Name $card.name -Confirm:$false -ErrorAction SilentlyContinue } catch {}
    }
    Get-Service icssvc -ErrorAction SilentlyContinue | Start-Service -ErrorAction SilentlyContinue
    $wifi = Get-WifiConfig
    $mgr = Get-TetheringManager
    $config = $mgr.GetCurrentAccessPointConfiguration()
    $config.Ssid = $wifi.Ssid
    $config.Passphrase = $wifi.Pass
    try { $config.MaxClientCount = [uint32]$MaxClients } catch {}
    Wait-WinRtAction ($mgr.ConfigureAccessPointAsync($config))
    if ($ApplyOnly) { return }
    if ($On) {
        if ([int]$mgr.TetheringOperationalState -ne 1) {
            $result = Wait-WinRt ($mgr.StartTetheringAsync())
            $code = -1
            try { $code = [int]$result.Status } catch {}
            if ($code -ne 0) {
                $hint = switch ($code) {
                    8 { "O Wi-Fi TP-Link esta ocupado. Desconecte de qualquer rede (deixe Desconectado) e tente de novo." }
                    10 { "O radio Wi-Fi esta desligado. Ligue o Wi-Fi no Windows." }
                    3 { "Falha de autenticacao do ponto de acesso. Confira a senha do Wi-Fi (minimo 8 caracteres)." }
                    default { "Codigo Windows $code. Abra Configuracoes > Rede > Ponto de acesso movel e ligue uma vez manualmente." }
                }
                throw $hint
            }
        }
        Start-DnsProxy
    } else {
        $script:TetheringMgr = $null
        if ([int]$mgr.TetheringOperationalState -eq 1) {
            Wait-WinRt ($mgr.StopTetheringAsync()) | Out-Null
        }
        Stop-DnsProxy
    }
}

function Get-HotspotIp {
    $addrs = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue)
    $hit = $addrs | Where-Object {
        $_.InterfaceAlias -match 'Direct|Hosted|Local Area Connection' -or $_.IPAddress -like '192.168.137.*'
    } | Select-Object -First 1
    if ($hit) { return [string]$hit.IPAddress }
    return $null
}

function Get-InternetRoute {
    $r = Get-NetRoute -DestinationPrefix "0.0.0.0/0" -ErrorAction SilentlyContinue |
        Sort-Object RouteMetric |
        Select-Object -First 1
    if (-not $r) { return @{ ip = $null; alias = $null } }
    $ip = Get-NetIPAddress -InterfaceIndex $r.InterfaceIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike "127.*" -and $_.PrefixOrigin -ne "WellKnown" } |
        Select-Object -First 1
    $alias = $null
    try { $alias = (Get-NetAdapter -InterfaceIndex $r.InterfaceIndex -ErrorAction SilentlyContinue).Name } catch {}
    return @{ ip = $(if ($ip) { [string]$ip.IPAddress } else { $null }); alias = [string]$alias }
}

function Get-LiveNeighbors {
    $list = @()
    $idx = @()
    Get-NetAdapter -ErrorAction SilentlyContinue | Where-Object {
        $_.MediaType -eq "Native 802.11" -or
        $_.Name -match "Wi-Fi|Direct|Local Area Connection\*" -or
        $_.InterfaceDescription -match "Wireless|Wi-Fi|TP-Link|802\.11"
    } | ForEach-Object { $idx += $_.ifIndex }
    $lanPrefix = $null
    $inet = Get-InternetRoute
    if ($inet.ip) {
        $lanPrefix = ($inet.ip -replace '\.\d+$', '.')
    }
    Get-NetNeighbor -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object {
            ($idx -contains $_.InterfaceIndex -or $_.IPAddress -like "192.168.137.*") -and
            (-not $lanPrefix -or $_.IPAddress -notlike "$lanPrefix*") -and
            $_.State -in @("Reachable", "Stale", "Delay", "Probe") -and
            $_.IPAddress -notlike "127.*" -and
            $_.IPAddress -notlike "169.254.*" -and
            $_.LinkLayerAddress -and
            $_.LinkLayerAddress -ne "00-00-00-00-00-00"
        } | ForEach-Object {
            $list += @{
                ip  = [string]$_.IPAddress
                mac = ([string]$_.LinkLayerAddress).Replace("-", ":")
            }
        }
    return $list
}

function Write-Status {
    $on = $false
    $ssid = (Get-WifiConfig).Ssid
    $clients = 0
    $tether = @()
    $wifiCards = @(Get-WifiAdapters)
    try {
        $mgr = Get-TetheringManager
        $on = ([int]$mgr.TetheringOperationalState -eq 1)
        try { $clients = [int]$mgr.ClientCount } catch { $clients = 0 }
        try { $ssid = [string]$mgr.GetCurrentAccessPointConfiguration().Ssid } catch {}
        try {
            $got = Wait-WinRt ($mgr.GetTetheringClientsAsync())
            if ($got) {
                foreach ($c in $got) {
                    $mac = $null
                    try { $mac = [string]$c.MacAddress } catch {}
                    $tether += @{ mac = $mac }
                }
            }
        } catch {}
    } catch {
        if (-not $script:LastError) {
            $script:LastError = $_.Exception.Message
        }
    }

    $inet = Get-InternetRoute
    $portal = Get-HotspotIp
    $ips = @()
    Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object {
            $_.IPAddress -notlike "127.*" -and
            $_.InterfaceAlias -notmatch "Loopback|Topaz|VirtualBox|VMware|Hyper-V|Bluetooth"
        } |
        ForEach-Object {
            $ips += @{ ip = $_.IPAddress; alias = $_.InterfaceAlias }
        }

    $dnsUp = [bool]($script:DnsProc -and -not $script:DnsProc.HasExited)
    $payload = [ordered]@{
        hotspot_on       = [bool]$on
        ssid             = $ssid
        portal_ip        = $portal
        internet_ip      = $inet.ip
        internet_alias   = $inet.alias
        wifi_adapters    = @($wifiCards)
        neighbors        = @(Get-LiveNeighbors)
        tethering_clients = @($tether)
        ips              = @($ips)
        windows_clients  = $clients
        max_clients      = $MaxClients
        dns_up           = $dnsUp
        error            = $script:LastError
        agent_seen_at    = (Get-Date).ToString("s")
        agent_pid        = $PID
    }
    $json = ($payload | ConvertTo-Json -Depth 6)
    [System.IO.File]::WriteAllText($StatusFile, $json, [System.Text.UTF8Encoding]::new($false))
}

$script:LastAck = ""

function Get-CloudCfg {
    $f = Join-Path $Storage "cloud.json"
    if (-not (Test-Path $f)) { return $null }
    try { return Get-Content $f -Raw -ErrorAction SilentlyContinue | ConvertFrom-Json } catch { return $null }
}

function Ensure-LocalDb {
    $cfgPhp = Join-Path $Root "app\config.php"
    if (Test-Path $cfgPhp) { return }
    $php = Get-PhpExe
    $boot = Join-Path $Root "scripts\bootstrap-local.php"
    if ($php -and (Test-Path $boot)) {
        & $php $boot | Out-Null
    }
}

function Sync-Cloud {
    $cfg = Get-CloudCfg
    if (-not $cfg -or -not $cfg.token -or -not $cfg.panel_url) { return }
    Ensure-LocalDb
    $php = Get-PhpExe
    $clients = @()
    $patchPath = Join-Path $Storage "client-patches.json"
    $clientScript = Join-Path $Root "scripts\agent-clients.php"
    if ($php -and (Test-Path $clientScript)) {
        try {
            $arg = if (Test-Path $patchPath) { $patchPath } else { "" }
            $raw = & $php $clientScript $arg 2>$null
            if ($raw) {
                $parsed = $raw | ConvertFrom-Json
                if ($parsed) { $clients = @($parsed) }
            }
        } catch {}
    }
    $statusObj = $null
    if (Test-Path $StatusFile) {
        try { $statusObj = Get-Content $StatusFile -Raw | ConvertFrom-Json } catch {}
    }
    $url = ([string]$cfg.panel_url).TrimEnd("/") + "/agent/sync"
    $payload = @{
        token          = [string]$cfg.token
        ack_command_id = $script:LastAck
        status         = $statusObj
        clients        = $clients
    }
    try {
        $json = $payload | ConvertTo-Json -Depth 8 -Compress
        $resp = Invoke-RestMethod -Uri $url -Method Post -Body $json -ContentType "application/json; charset=utf-8" -TimeoutSec 6
        if ($resp.config -and $resp.config.wifi_ssid) {
            $suffixes = @()
            if ($resp.config.dns_allowlist) {
                $suffixes = @(([string]$resp.config.dns_allowlist) -split "[\r\n]+" | Where-Object { $_ })
            }
            $auth = [ordered]@{
                portal_ip      = [string]$resp.config.portal_ip
                authorized     = @($resp.authorized)
                allow_suffixes = $suffixes
                ssid           = [string]$resp.config.wifi_ssid
                wifi_pass      = [string]$resp.config.wifi_pass
                updated_at     = (Get-Date).ToString("s")
            }
            [System.IO.File]::WriteAllText($AuthFile, ($auth | ConvertTo-Json -Depth 5), [System.Text.UTF8Encoding]::new($false))
        }
        if ($resp.command -and $resp.command.id) {
            $cmd = @{
                id     = [string]$resp.command.id
                action = [string]$resp.command.action
                at     = (Get-Date).ToString("o")
            }
            [System.IO.File]::WriteAllText($CmdFile, ($cmd | ConvertTo-Json), [System.Text.UTF8Encoding]::new($false))
            $script:LastAck = [string]$resp.command.id
        }
        if ($resp.patches) {
            ($resp.patches | ForEach-Object { $_ }) | ConvertTo-Json -Depth 5 | Set-Content -Path $patchPath -Encoding UTF8
        }
        if ($resp.has_brand) {
            $brandDir = Join-Path $Storage "brand"
            New-Item -ItemType Directory -Path $brandDir -Force | Out-Null
            $brandUrl = ([string]$cfg.panel_url).TrimEnd("/") + "/agent/brand?token=" + [uri]::EscapeDataString([string]$cfg.token)
            try {
                Invoke-WebRequest -Uri $brandUrl -OutFile (Join-Path $brandDir "1.png") -TimeoutSec 8 -UseBasicParsing | Out-Null
            } catch {}
        }
    } catch {}
}

try {
    while ($true) {
        if (Test-Path $CmdFile) {
            try {
                $cmd = Get-Content $CmdFile -Raw | ConvertFrom-Json
                if ($cmd.id -and $cmd.id -ne $script:LastCmdId) {
                    $script:LastCmdId = [string]$cmd.id
                    $script:LastError = $null
                    $script:TetheringMgr = $null
                    switch ([string]$cmd.action) {
                        "start" { Set-Hotspot -On $true }
                        "stop" { Set-Hotspot -On $false }
                        "apply" { Set-Hotspot -On $true -ApplyOnly $true }
                    }
                }
            } catch {
                $script:LastError = $_.Exception.Message
            }
        }
        Write-Status
        Sync-Cloud
        Start-Sleep -Seconds 2
    }
} finally {
    Stop-DnsProxy
    if (Test-Path $PidFile) { Remove-Item $PidFile -Force -ErrorAction SilentlyContinue }
}
