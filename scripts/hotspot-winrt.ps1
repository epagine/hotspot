# Controle WinRT do Mobile Hotspot (chamado pelo servico WiFiDaLojaAgent).
# Uso: hotspot-winrt.ps1 -Action start|stop|apply|status [-Ssid x] [-Pass y] [-MaxClients n] [-AdapterGuid g]
param(
    [Parameter(Mandatory = $true)][ValidateSet("start", "stop", "apply", "status")][string]$Action,
    [string]$Ssid = "WifiDaLoja",
    [string]$Pass = "loja1234",
    [int]$MaxClients = 8,
    [string]$AdapterGuid = ""
)
$ErrorActionPreference = "Stop"
Add-Type -AssemblyName System.Runtime.WindowsRuntime -ErrorAction SilentlyContinue | Out-Null

function Wait-WinRtAction {
    param($Async)
    if (-not $Async) { return $null }
    $asTask = [System.WindowsRuntimeSystemExtensions].GetMethods() |
        Where-Object { $_.Name -eq "AsTask" -and $_.GetParameters().Count -eq 1 -and -not $_.IsGenericMethod } |
        Select-Object -First 1
    if (-not $asTask) { return $null }
    $task = $asTask.Invoke($null, @($Async))
    if (-not $task.Wait(20000)) { throw "Tempo esgotado ao configurar o hotspot." }
    if ($task.IsFaulted) { throw $task.Exception.GetBaseException().Message }
    return $null
}

function Wait-WinRt {
    param($Operation)
    if (-not $Operation) { return $null }
    $ResultType = [Windows.Networking.NetworkOperators.NetworkOperatorTetheringOperationResult, Windows.Networking.NetworkOperators, ContentType = WindowsRuntime]
    $asTask = [System.WindowsRuntimeSystemExtensions].GetMethods() |
        Where-Object {
            $_.Name -eq "AsTask" -and $_.IsGenericMethod -and $_.GetParameters().Count -eq 1 -and
            $_.GetParameters()[0].ParameterType.Name -eq "IAsyncOperation``1"
        } |
        Select-Object -First 1
    if (-not $asTask) { throw "WinRT AsTask indisponivel." }
    $task = $asTask.MakeGenericMethod($ResultType).Invoke($null, @($Operation))
    if (-not $task.Wait(20000)) { throw "Tempo esgotado ao ligar/desligar o hotspot." }
    if ($task.IsFaulted) { throw $task.Exception.GetBaseException().Message }
    return $task.Result
}

function Get-TetheringManager {
    $mgrType = [Windows.Networking.NetworkOperators.NetworkOperatorTetheringManager, Windows.Networking.NetworkOperators, ContentType = WindowsRuntime]
    $infoType = [Windows.Networking.Connectivity.NetworkInformation, Windows.Networking.Connectivity, ContentType = WindowsRuntime]
    $profiles = @()
    try {
        $inet = $infoType::GetInternetConnectionProfile()
        if ($inet -and [string]$inet.ProfileName -notmatch "Loopback|Topaz|KM-TEST") { $profiles += $inet }
    } catch {}
    try {
        foreach ($p in $infoType::GetConnectionProfiles()) {
            if ([string]$p.ProfileName -match "Loopback|Topaz|KM-TEST") { continue }
            $profiles += $p
        }
    } catch {}

    $wifiAdapter = $null
    if ($AdapterGuid) {
        foreach ($p in $infoType::GetConnectionProfiles()) {
            try {
                $na = $p.NetworkAdapter
                if ($na -and [string]$na.NetworkAdapterId -eq $AdapterGuid) {
                    $wifiAdapter = $na
                    break
                }
            } catch {}
        }
        if (-not $wifiAdapter) {
            Get-NetAdapter -ErrorAction SilentlyContinue | Where-Object {
                $_.InterfaceGuid -eq $AdapterGuid -or $_.Name -eq $AdapterGuid
            } | Out-Null
        }
    }

    foreach ($p in $profiles) {
        try {
            $cap = $mgrType::GetTetheringCapabilityFromConnectionProfile($p)
            if ([int]$cap -ne 0) { continue }
            if ($wifiAdapter) {
                try {
                    return $mgrType::CreateFromConnectionProfile($p, $wifiAdapter)
                } catch {
                    return $mgrType::CreateFromConnectionProfile($p)
                }
            }
            return $mgrType::CreateFromConnectionProfile($p)
        } catch {}
    }
    foreach ($p in $profiles) {
        try {
            if ($wifiAdapter) {
                try { return $mgrType::CreateFromConnectionProfile($p, $wifiAdapter) } catch {}
            }
            return $mgrType::CreateFromConnectionProfile($p)
        } catch {}
    }
    throw "Nao foi possivel usar a internet do PC para o hotspot. Verifique Ethernet/VPN."
}

function Get-Hint([int]$Code) {
    switch ($Code) {
        1 { "Ponto de acesso movel nao iniciou. Abra Configuracoes > Rede > Ponto de acesso movel e ligue uma vez manualmente." }
        3 { "Wi-Fi desligado. Ative o adaptador Wi-Fi de transmissao." }
        5 { "Esta conexao nao permite ponto de acesso movel." }
        8 { "Wi-Fi ocupado. Desconecte o adaptador USB de outras redes." }
        11 { "Interferencia de banda. Escolha o adaptador USB no painel/bandeja." }
        default { "Codigo Windows $Code ao ligar hotspot." }
    }
}

try {
    Get-Service icssvc -ErrorAction SilentlyContinue | Start-Service -ErrorAction SilentlyContinue
    $mgr = Get-TetheringManager
    $on = ([int]$mgr.TetheringOperationalState -eq 1)
    $currentSsid = $Ssid
    try { $currentSsid = [string]$mgr.GetCurrentAccessPointConfiguration().Ssid } catch {}

    if ($Action -eq "status") {
        @{
            ok         = $true
            hotspot_on = [bool]$on
            ssid       = $currentSsid
            clients    = $(try { [int]$mgr.ClientCount } catch { 0 })
        } | ConvertTo-Json -Compress
        exit 0
    }

    if ($Action -in @("start", "apply")) {
        $cfg = $mgr.GetCurrentAccessPointConfiguration()
        $cfg.Ssid = $Ssid
        $cfg.Passphrase = $Pass
        try { $cfg.MaxClientCount = [uint32]$MaxClients } catch {}
        Wait-WinRtAction ($mgr.ConfigureAccessPointAsync($cfg))
        if ($Action -eq "apply") {
            @{ ok = $true; hotspot_on = $on; ssid = $Ssid } | ConvertTo-Json -Compress
            exit 0
        }
        if (-not $on) {
            $retried = $false
            for ($i = 1; $i -le 3; $i++) {
                $result = Wait-WinRt ($mgr.StartTetheringAsync())
                $code = -1
                try { $code = [int]$result.Status } catch {}
                if ($code -eq 0 -or $code -eq 9) { break }
                if ($code -eq 1 -and -not $retried) {
                    try { Restart-Service icssvc -Force -ErrorAction SilentlyContinue } catch {}
                    Start-Sleep -Seconds 2
                    $mgr = Get-TetheringManager
                    $retried = $true
                    continue
                }
                if ($code -eq 6 -and $i -lt 3) { Start-Sleep -Seconds 2; continue }
                throw (Get-Hint $code)
            }
        }
        $on = ([int]$mgr.TetheringOperationalState -eq 1)
        @{ ok = $true; hotspot_on = [bool]$on; ssid = $Ssid } | ConvertTo-Json -Compress
        exit 0
    }

    if ($Action -eq "stop") {
        if ($on) {
            Wait-WinRt ($mgr.StopTetheringAsync()) | Out-Null
        }
        @{ ok = $true; hotspot_on = $false; ssid = $currentSsid } | ConvertTo-Json -Compress
        exit 0
    }
} catch {
    @{ ok = $false; error = $_.Exception.Message; hotspot_on = $false } | ConvertTo-Json -Compress
    exit 1
}
