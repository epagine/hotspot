# Agente: liga/desliga o hotspot, DNS cativo e status real da rede.
$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot "agent-storage.ps1")
$Storage = Get-AgentStorageDir -InstallRoot $Root
$AuthFile = Join-Path $Storage "authorized.json"
$CmdFile = Join-Path $Storage "command.json"
$StatusFile = Join-Path $Storage "status.json"
$PidFile = Join-Path $Storage "agent.pid"
$SyncErrorFile = Join-Path $Storage "sync-error.json"
$DnsProxy = Join-Path $Root "DnsProxy.exe"
$DnsProxyPhp = Join-Path $Root "bin\dns-proxy.php"
$MaxClients = 8
$script:ServiceAllowed = $true

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

function Test-CloudAgent {
    if (Test-Path (Join-Path $Root "CLOUD_AGENT")) { return $true }
    $modeFile = Join-Path $Storage "install-mode.json"
    if (Test-Path $modeFile) {
        try {
            $raw = Get-Content $modeFile -Raw -ErrorAction SilentlyContinue
            if ($raw -match '"mode"\s*:\s*"cloud"') { return $true }
        } catch {}
    }
    return $false
}

function Start-DnsProxy {
    if ($script:DnsProc -and -not $script:DnsProc.HasExited) { return }
    if (Test-Path $DnsProxy) {
        $script:DnsProc = Start-Process -FilePath $DnsProxy -WorkingDirectory $Root -WindowStyle Hidden -PassThru
        return
    }
    $php = Get-PhpExe
    if (-not $php -or -not (Test-Path $DnsProxyPhp)) {
        throw "DNS cativo indisponivel (DnsProxy.exe ou PHP)."
    }
    $script:DnsProc = Start-Process -FilePath $php -ArgumentList @($DnsProxyPhp) -WindowStyle Hidden -PassThru
}

function Stop-DnsProxy {
    if ($script:DnsProc -and -not $script:DnsProc.HasExited) {
        Stop-Process -Id $script:DnsProc.Id -Force -ErrorAction SilentlyContinue
    }
    $script:DnsProc = $null
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object {
            ($_.Name -eq "DnsProxy.exe") -or
            ($_.Name -eq "php.exe" -and $_.CommandLine -and $_.CommandLine -like "*dns-proxy.php*")
        } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

function Restart-IcsService {
    try {
        $svc = Get-Service icssvc -ErrorAction SilentlyContinue
        if (-not $svc) { return }
        if ($svc.Status -eq "Running") {
            Restart-Service icssvc -Force -ErrorAction SilentlyContinue
        } else {
            Start-Service icssvc -ErrorAction SilentlyContinue
        }
        Start-Sleep -Seconds 2
    } catch {}
}

function Get-TetheringStatusHint {
    param([int]$Code)
    switch ($Code) {
        1 { "Ponto de acesso movel nao iniciou (Windows: desconhecido). Abra Configuracoes > Rede e internet > Ponto de acesso movel, ligue uma vez manualmente, e depois clique Ligar no painel." }
        2 { "Modem movel desligado. Este PC usa Wi-Fi/Ethernet — verifique o adaptador de internet." }
        3 { "Wi-Fi desligado. Ative o Wi-Fi no Windows (o adaptador TP-Link deve estar habilitado)." }
        4 { "Tempo esgotado ao validar tethering com a operadora." }
        5 { "Esta conexao nao permite ponto de acesso movel." }
        6 { "Hotspot ainda iniciando. Aguarde alguns segundos e tente Ligar de novo." }
        7 { "Bluetooth desligado (necessario apenas para tethering via Bluetooth)." }
        8 { "Wi-Fi ocupado ou internet instavel. Desconecte o TP-Link de outras redes Wi-Fi e confira o cabo/Ethernet da loja." }
        9 { "" }
        10 { "Restricao de radio/banda no adaptador Wi-Fi. Tente outro canal ou atualize o driver." }
        11 { "Interferencia de banda entre conexao principal e hotspot. Desconecte o Wi-Fi do adaptador USB." }
        default { "Codigo Windows $Code. Abra Configuracoes > Rede > Ponto de acesso movel e ligue uma vez manualmente." }
    }
}

function Start-TetheringSafe {
    param($Manager)
    $retriedIcs = $false
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        $result = Wait-WinRt ($Manager.StartTetheringAsync())
        $code = -1
        try { $code = [int]$result.Status } catch {}
        if ($code -eq 0 -or $code -eq 9) {
            return
        }
        if ($code -eq 1 -and -not $retriedIcs) {
            Restart-IcsService
            $script:TetheringMgr = $null
            $Manager = Get-TetheringManager
            $retriedIcs = $true
            continue
        }
        if ($code -eq 6 -and $attempt -lt 3) {
            Start-Sleep -Seconds 3
            continue
        }
        $hint = Get-TetheringStatusHint -Code $code
        if ($hint) {
            throw $hint
        }
        throw "Codigo Windows $code ao ligar hotspot."
    }
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
            Start-TetheringSafe -Manager $mgr
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

    if ($on) {
        $script:LastError = $null
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
    if (Test-CloudAgent) { return }
    if (Test-RemoteCloud) { return }
    $cfgPhp = Join-Path $Root "app\config.php"
    if (Test-Path $cfgPhp) { return }
    $php = Get-PhpExe
    $boot = Join-Path $Root "scripts\bootstrap-local.php"
    if ($php -and (Test-Path $boot)) {
        & $php $boot | Out-Null
    }
}

function Test-RemoteCloud {
    $cfg = Get-CloudCfg
    if (-not $cfg -or -not $cfg.panel_url -or -not $cfg.token) { return $false }
    $url = ([string]$cfg.panel_url).TrimEnd("/")
    return $url -notmatch '^https?://(127\.0\.0\.1|localhost)(:\d+)?$'
}

function Write-SyncError {
    param([string]$Message)
    $payload = [ordered]@{
        error      = $Message
        updated_at = (Get-Date).ToString("s")
    }
    [System.IO.File]::WriteAllText($SyncErrorFile, ($payload | ConvertTo-Json -Compress), [System.Text.UTF8Encoding]::new($false))
}

function Clear-SyncError {
    if (Test-Path $SyncErrorFile) {
        Remove-Item $SyncErrorFile -Force -ErrorAction SilentlyContinue
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
    $localCfg = Join-Path $Root "app\config.php"
    if ($php -and (Test-Path $clientScript) -and (Test-Path $localCfg) -and -not (Test-CloudAgent)) {
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
    $url = ([string]$cfg.panel_url).TrimEnd("/") + "/agente/sincronizar"
    $payload = @{
        token          = [string]$cfg.token
        ack_command_id = $script:LastAck
        status         = $statusObj
        clients        = $clients
    }
    try {
        $json = $payload | ConvertTo-Json -Depth 8 -Compress
        $headers = @{ "X-Agent-Token" = [string]$cfg.token }
        $resp = Invoke-RestMethod -Uri $url -Method Post -Headers $headers -Body $json -ContentType "application/json; charset=utf-8" -TimeoutSec 6
        Clear-SyncError
        if ($resp.config -and $resp.config.max_clients) {
            $parsedMax = 0
            if ([void][int]::TryParse([string]$resp.config.max_clients, [ref]$parsedMax) -and $parsedMax -gt 0) {
                $MaxClients = $parsedMax
            }
        }
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
        $sub = $resp.subscription
        $links = $resp.links
        $serviceAllowed = $true
        if ($null -ne $sub.service_allowed) {
            $serviceAllowed = [bool]$sub.service_allowed
        }
        $script:ServiceAllowed = $serviceAllowed
        $info = [ordered]@{
            store_id         = [int]($resp.store_id)
            store_name       = [string]($(if ($resp.store) { $resp.store } elseif ($resp.config.store_name) { $resp.config.store_name } else { "" }))
            store_city       = [string]($(if ($resp.config.store_city) { $resp.config.store_city } else { "" }))
            company_id       = [int]($(if ($resp.company_id) { $resp.company_id } else { 0 }))
            company_name     = [string]($(if ($resp.company_name) { $resp.company_name } else { "" }))
            hotspot_status   = [string]($(if ($resp.hotspot_status) { $resp.hotspot_status } else { "ativo" }))
            subscription_scope = [string]($(if ($sub.scope) { $sub.scope } else { "" }))
            billing_status   = [string]($(if ($sub.billing_status) { $sub.billing_status } else { "" }))
            billing_label    = [string]($(if ($sub.billing_label) { $sub.billing_label } else { "" }))
            plan             = [string]($(if ($sub.plan) { $sub.plan } else { "" }))
            plan_label       = [string]($(if ($sub.plan_label) { $sub.plan_label } else { "" }))
            paid_until       = [string]($(if ($sub.paid_until) { $sub.paid_until } else { "" }))
            trial_ends_at    = [string]($(if ($sub.trial_ends_at) { $sub.trial_ends_at } else { "" }))
            cycle_amount     = [string]($(if ($sub.cycle_amount) { $sub.cycle_amount } else { "" }))
            active           = [bool]($(if ($null -ne $sub.active) { [bool]$sub.active } else { $true }))
            service_allowed  = [bool]$serviceAllowed
            wifi_ssid        = [string]($(if ($resp.config.wifi_ssid) { $resp.config.wifi_ssid } else { "" }))
            wifi_pass        = [string]($(if ($resp.config.wifi_pass) { $resp.config.wifi_pass } else { "" }))
            portal_ip        = [string]($(if ($resp.config.portal_ip) { $resp.config.portal_ip } else { "192.168.137.1" }))
            max_clients      = [string]$MaxClients
            panel_url        = [string]($(if ($links.panel) { $links.panel } else { ([string]$cfg.panel_url).TrimEnd("/") }))
            admin_url        = [string]($(if ($links.admin) { $links.admin } else { ([string]$cfg.panel_url).TrimEnd("/") + "/app/hotspots/" + [string]$resp.store_id }))
            client_url       = [string]($(if ($links.client) { $links.client } else { ([string]$cfg.panel_url).TrimEnd("/") + "/cliente" }))
            portal_url       = [string]($(if ($links.portal) { $links.portal } else { ([string]$cfg.panel_url).TrimEnd("/") + "/portal/" + [uri]::EscapeDataString([string]$cfg.token) }))
            updated_at       = (Get-Date).ToString("s")
        }
        $infoFile = Join-Path $Storage "store-info.json"
        [System.IO.File]::WriteAllText($infoFile, ($info | ConvertTo-Json -Depth 4), [System.Text.UTF8Encoding]::new($false))
        if ($resp.command -and $resp.command.id) {
            $cmd = @{
                id     = [string]$resp.command.id
                action = [string]$resp.command.action
                at     = (Get-Date).ToString("o")
            }
            [System.IO.File]::WriteAllText($CmdFile, ($cmd | ConvertTo-Json), [System.Text.UTF8Encoding]::new($false))
        }
        if ($resp.patches) {
            ($resp.patches | ForEach-Object { $_ }) | ConvertTo-Json -Depth 5 | Set-Content -Path $patchPath -Encoding UTF8
        }
        if ($resp.has_brand -and $resp.store_id) {
            $brandDir = Join-Path $Storage "brand"
            New-Item -ItemType Directory -Path $brandDir -Force | Out-Null
            $brandFile = Join-Path $brandDir ([string]$resp.store_id + ".png")
            $brandUrl = ([string]$cfg.panel_url).TrimEnd("/") + "/agente/marca/" + [uri]::EscapeDataString([string]$cfg.token)
            try {
                Invoke-WebRequest -Uri $brandUrl -OutFile $brandFile -TimeoutSec 8 -UseBasicParsing | Out-Null
            } catch {}
        }
        if (-not $serviceAllowed) {
            try {
                $mgr = Get-TetheringManager
                if ([int]$mgr.TetheringOperationalState -eq 1) {
                    Set-Hotspot -On $false
                }
            } catch {}
        }
        $hotspotStatus = [string]($(if ($resp.hotspot_status) { $resp.hotspot_status } else { "ativo" }))
        if ($hotspotStatus -ne "ativo") {
            try {
                $mgr = Get-TetheringManager
                if ([int]$mgr.TetheringOperationalState -eq 1) {
                    Set-Hotspot -On $false
                }
            } catch {}
        }
    } catch {
        $msg = $_.Exception.Message
        if ($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -eq 401) {
            $msg = "Token inválido ou expirado. Revincule o hotspot no painel."
        }
        Write-SyncError $msg
    }
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
                        "start" {
                            if ($script:ServiceAllowed) {
                                Set-Hotspot -On $true
                            } else {
                                $script:LastError = "Servico suspenso ou hotspot bloqueado no painel."
                            }
                        }
                        "stop" { Set-Hotspot -On $false }
                        "apply" {
                            if ($script:ServiceAllowed) {
                                Set-Hotspot -On $true -ApplyOnly $true
                            }
                        }
                    }
                    $script:LastAck = [string]$cmd.id
                }
            } catch {
                $script:LastError = $_.Exception.Message
            }
        } elseif (-not $script:ServiceAllowed) {
            try {
                $mgr = Get-TetheringManager
                if ([int]$mgr.TetheringOperationalState -eq 1) {
                    Set-Hotspot -On $false
                }
            } catch {}
        }
        Write-Status
        Sync-Cloud
        Start-Sleep -Seconds 2
    }
} finally {
    Stop-DnsProxy
    if (Test-Path $PidFile) { Remove-Item $PidFile -Force -ErrorAction SilentlyContinue }
}
