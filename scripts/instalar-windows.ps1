param(
    [string]$PanelUrl = "",
    [string]$Token = "",
    [switch]$Cloud,
    [switch]$Update
)
# Instalador do agente Wi-Fi da Loja (administrador).
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot "agent-storage.ps1")
$Storage = Get-AgentStorageDir -InstallRoot $Root
$Scripts = Join-Path $Root "scripts"
$Log = Join-Path $Storage "install.log"
$TaskAgent = "HotspotLoja"
$TaskPanel = "HotspotLojaPainel"
$ServiceAgent = "WiFiDaLojaAgent"

function Write-Log([string]$Message) {
    $line = "{0}  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Add-Content -Path $Log -Value $line -Encoding UTF8
    Write-Host $Message
}

function Test-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    $p = New-Object Security.Principal.WindowsPrincipal($id)
    return $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Find-Php {
    $bundled = Join-Path $Root "runtime\php\php.exe"
    if (Test-Path $bundled) { return $bundled }
    $saved = Join-Path $Storage "php-path.txt"
    if (Test-Path $saved) {
        $p = (Get-Content $saved -Raw).Trim()
        if ($p -and (Test-Path $p)) { return $p }
    }
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $found = Get-ChildItem "C:\laragon\bin\php" -Recurse -Filter php.exe -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($found) { return $found.FullName }
    return $null
}

function Get-TaskAccount {
    $name = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    if ($name -and $name -match '\\') {
        return $name
    }
    if ($env:USERDOMAIN) {
        return "$($env:USERDOMAIN)\$($env:USERNAME)"
    }
    return ".\$($env:USERNAME)"
}

function Register-TaskSafe {
    param($Name, $Execute, $Arguments = "")
    Remove-ScheduledTaskSafe -Name $Name
    try {
        $account = Get-TaskAccount
        if ($Arguments) {
            $action = New-ScheduledTaskAction -Execute $Execute -Argument $Arguments
        } else {
            $action = New-ScheduledTaskAction -Execute $Execute
        }
        $trigger = New-ScheduledTaskTrigger -AtLogOn
        $principal = New-ScheduledTaskPrincipal -UserId $account -LogonType Interactive -RunLevel Highest
        $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
        Register-ScheduledTask -TaskName $Name -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
        return
    } catch {
        Write-Log ("Tarefa $Name via cmdlet falhou, tentando schtasks: " + $_.Exception.Message)
    }

    $tr = if ($Arguments) { "$Execute $Arguments" } elseif ($Execute -match '\s') { "`"$Execute`"" } else { $Execute }
    & schtasks.exe /Create /TN $Name /SC ONLOGON /RL HIGHEST /F /IT /TR $tr | Out-Host
    if ($LASTEXITCODE -ne 0) {
        throw "Nao foi possivel registrar tarefa agendada: $Name"
    }
}

function New-AppShortcut {
    param([string]$Path, [string]$Target, [string]$Arguments = "")
    $shell = New-Object -ComObject WScript.Shell
    $lnk = $shell.CreateShortcut($Path)
    $lnk.TargetPath = $Target
    $lnk.Arguments = $Arguments
    $lnk.WorkingDirectory = $Root
    $lnk.WindowStyle = 7
    $lnk.IconLocation = "imageres.dll,109"
    $lnk.Description = "Wi-Fi da loja"
    $lnk.Save()
}

function Remove-ScheduledTaskSafe {
    param([string]$Name)
    Unregister-ScheduledTask -TaskName $Name -Confirm:$false -ErrorAction SilentlyContinue | Out-Null
    $prevEap = $ErrorActionPreference
    $ErrorActionPreference = "SilentlyContinue"
    try {
        & schtasks.exe /Delete /TN $Name /F *>$null
    } catch {}
    $ErrorActionPreference = $prevEap
}

function Stop-AgentProcesses {
    param([string]$InstallRoot = $Root)
    Write-Log "Encerrando agente e bandeja antes da instalacao..."
    $svc = Get-Service -Name $ServiceAgent -ErrorAction SilentlyContinue
    if ($svc -and $svc.Status -eq "Running") {
        try { Stop-Service -Name $ServiceAgent -Force -ErrorAction SilentlyContinue } catch {}
        & sc.exe stop $ServiceAgent 2>$null | Out-Null
        Start-Sleep -Seconds 2
    }
    foreach ($name in @("HotspotBandeja", "WiFiDaLojaAgent", "DnsProxy", "CaptiveHttp")) {
        Get-Process -Name $name -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    }
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object {
            ($_.Name -eq "powershell.exe") -and $_.CommandLine -and ($_.CommandLine -like "*agente-hotspot.ps1*")
        } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    Start-Sleep -Seconds 2
}

function Install-AgentService {
    param([string]$AgentExe)
    if (-not (Test-Path $AgentExe)) {
        throw "WiFiDaLojaAgent.exe nao encontrado em $AgentExe"
    }
    foreach ($name in @($TaskAgent, "HotspotBandeja")) {
        Remove-ScheduledTaskSafe -Name $name
    }
    $svc = Get-Service -Name $ServiceAgent -ErrorAction SilentlyContinue
    if ($svc) {
        try {
            if ($svc.Status -eq "Running") {
                Stop-Service -Name $ServiceAgent -Force -ErrorAction SilentlyContinue
            }
        } catch {}
        & sc.exe stop $ServiceAgent 2>$null | Out-Null
        Start-Sleep -Seconds 2
    }
    & $AgentExe --install-service 2>&1 | Out-Host
    if ($LASTEXITCODE -ne 0) {
        Write-Log "Instalacao via --install-service falhou; tentando sc create..."
        $bin = "`"$AgentExe`""
        & sc.exe create $ServiceAgent binPath= $bin start= auto DisplayName= "Wi-Fi da Loja Agent" 2>&1 | Out-Host
        & sc.exe description $ServiceAgent "Agente hotspot Wi-Fi da Loja v2" 2>&1 | Out-Host
        & sc.exe start $ServiceAgent 2>&1 | Out-Host
    }
    Start-Sleep -Seconds 2
    $svc = Get-Service -Name $ServiceAgent -ErrorAction SilentlyContinue
    if (-not $svc) {
        throw "Nao foi possivel registrar o servico $ServiceAgent"
    }
    Write-Log "Servico $ServiceAgent registrado (Status: $($svc.Status))"
}

function Test-CloudAgentInstall {
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

function Test-RemoteCloudInstall {
    param([string]$PanelUrl = "", [string]$Token = "")
    if ($PanelUrl -and $Token) { return $true }
    $cloudPath = Join-Path $Storage "cloud.json"
    if (-not (Test-Path $cloudPath)) { return $false }
    try {
        $c = Get-Content $cloudPath -Raw | ConvertFrom-Json
        $u = ([string]$c.panel_url).TrimEnd("/")
        return ($u -ne "" -and $u -notmatch '^https?://(127\.0\.0\.1|localhost)(:\d+)?$')
    } catch {
        return $false
    }
}

if (-not (Test-Admin)) {
    Write-Host "Este instalador precisa ser executado como administrador."
    exit 1
}

$isCloudAgent = $Cloud -or (Test-CloudAgentInstall)

if (-not $isCloudAgent -and -not (Test-Path (Join-Path $Root "index.php"))) {
    Write-Host "Pasta invalida: nao achei index.php em $Root"
    exit 1
}
if ($isCloudAgent -and -not (Test-Path (Join-Path $Root "WiFiDaLojaAgent.exe"))) {
    Write-Host "Pacote cloud invalido: falta WiFiDaLojaAgent.exe"
    exit 1
}
if ($isCloudAgent -and -not (Test-Path (Join-Path $Root "DnsProxy.exe"))) {
    Write-Host "Pacote cloud invalido: falta DnsProxy.exe"
    exit 1
}

if (-not (Test-Path $Storage)) {
    New-Item -ItemType Directory -Path $Storage | Out-Null
}

Write-Log "Instalando em $Root$(if ($isCloudAgent) { ' (modo cloud)' } else { '' })"
Write-Log "Dados persistentes em $Storage"
$agentVersion = Get-AgentVersion -InstallRoot $Root
Write-Log "Versao do agente: $agentVersion"

$php = $null
if (-not $isCloudAgent) {
    $php = Find-Php
    if (-not $php) {
        Write-Log "PHP nao encontrado. Instale o Laragon ou coloque o php.exe no PATH."
        exit 1
    }
    Set-Content -Path (Join-Path $Storage "php-path.txt") -Value $php -Encoding ASCII
    Write-Log "PHP: $php"
} else {
    Set-Content -Path (Join-Path $Storage "install-mode.json") -Value '{"mode":"cloud"}' -Encoding UTF8
    Write-Log "Modo cloud: sem PHP/MySQL local."
}

if ($PanelUrl -and $Token) {
    $cloudJson = @{ panel_url = $PanelUrl.TrimEnd("/"); token = $Token; updated_at = (Get-Date).ToString("s") } | ConvertTo-Json
    Set-Content -Path (Join-Path $Storage "cloud.json") -Value $cloudJson -Encoding UTF8
    Write-Log "Hotspot vinculado ao painel $PanelUrl"
} elseif ($Update -and (Test-AgentCloudConfig -StorageDir $Storage)) {
    $cfg = Read-AgentCloudConfig -StorageDir $Storage
    Write-Log "Vinculo ao painel mantido (atualizacao): $($cfg.panel_url)"
} elseif (-not (Test-AgentCloudConfig -StorageDir $Storage)) {
    Write-Log "AVISO: cloud.json ausente. Informe URL e token do painel."
}

$isRemoteCloud = (Test-RemoteCloudInstall -PanelUrl $PanelUrl -Token $Token) -or $isCloudAgent -or (Test-AgentCloudConfig -StorageDir $Storage)

$oldPidPath = Join-Path $Storage "agent.pid"
if (Test-Path $oldPidPath) {
    $old = 0
    [void][int]::TryParse((Get-Content $oldPidPath | Select-Object -First 1), [ref]$old)
    if ($old -gt 0) { Stop-Process -Id $old -Force -ErrorAction SilentlyContinue }
    Remove-Item $oldPidPath -Force -ErrorAction SilentlyContinue
}

$bandeja = Join-Path $Root "HotspotBandeja.exe"
if (-not (Test-Path $bandeja)) {
    Write-Log "Compile o icone da bandeja (installer\compilar.ps1)."
}

Stop-AgentProcesses -InstallRoot $Root

$agentExe = Join-Path $Root "WiFiDaLojaAgent.exe"
Install-AgentService -AgentExe $agentExe
if (Test-Path $bandeja) {
    Register-TaskSafe -Name "HotspotBandeja" -Execute $bandeja
}
Write-Log "Servico agente e bandeja configurados"

foreach ($rule in @("HotspotLoja-Painel-8080", "HotspotLoja-DNS-53", "HotspotLoja-HTTP-80")) {
    Get-NetFirewallRule -DisplayName $rule -ErrorAction SilentlyContinue | Remove-NetFirewallRule -ErrorAction SilentlyContinue
}
if (-not $isCloudAgent) {
    New-NetFirewallRule -DisplayName "HotspotLoja-Painel-8080" -Direction Inbound -Protocol TCP -LocalPort 8080 -Action Allow -Profile Any | Out-Null
}
New-NetFirewallRule -DisplayName "HotspotLoja-DNS-53" -Direction Inbound -Protocol UDP -LocalPort 53 -Action Allow -Profile Any | Out-Null
New-NetFirewallRule -DisplayName "HotspotLoja-HTTP-80" -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow -Profile Any | Out-Null
Write-Log $(if ($isCloudAgent) { "Regras de firewall (53 UDP, 80 TCP)" } else { "Regras de firewall (8080 TCP, 53 UDP, 80 TCP)" })

$desktop = [Environment]::GetFolderPath("Desktop")
$programs = Join-Path ([Environment]::GetFolderPath("StartMenu")) "Programs\Wi-Fi da loja"
if (-not (Test-Path $programs)) {
    New-Item -ItemType Directory -Path $programs | Out-Null
}
$iniciar = if (Test-Path $bandeja) { $bandeja } else { Join-Path $Scripts "iniciar-painel.ps1" }
$desinst = Join-Path $Root "Desinstalar-Hotspot.exe"
if (-not (Test-Path $desinst)) { $desinst = Join-Path $Scripts "desinstalar-windows.ps1" }
if (Test-Path $bandeja) {
    New-AppShortcut -Path (Join-Path $desktop "Wi-Fi da loja.lnk") -Target $bandeja
    New-AppShortcut -Path (Join-Path $programs "Wi-Fi da loja.lnk") -Target $bandeja
} else {
    New-AppShortcut -Path (Join-Path $desktop "Wi-Fi da loja.lnk") -Target "powershell.exe" -Arguments "-NoProfile -ExecutionPolicy Bypass -File `"$iniciar`""
}
if (Test-Path $desinst) {
    New-AppShortcut -Path (Join-Path $programs "Desinstalar Wi-Fi da loja.lnk") -Target $desinst
}
Write-Log "Atalhos no Desktop e no Menu Iniciar"

$uninst = "HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\HotspotLoja"
New-Item -Path $uninst -Force | Out-Null
New-ItemProperty -Path $uninst -Name "DisplayName" -Value "Wi-Fi da loja" -PropertyType String -Force | Out-Null
New-ItemProperty -Path $uninst -Name "Publisher" -Value "Hotspot Loja" -PropertyType String -Force | Out-Null
New-ItemProperty -Path $uninst -Name "DisplayVersion" -Value $agentVersion -PropertyType String -Force | Out-Null
New-ItemProperty -Path $uninst -Name "InstallLocation" -Value $Root -PropertyType String -Force | Out-Null
New-ItemProperty -Path $uninst -Name "UninstallString" -Value ("powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$desinst`"") -PropertyType String -Force | Out-Null
New-ItemProperty -Path $uninst -Name "NoModify" -Value 1 -PropertyType DWord -Force | Out-Null

try { Start-Service -Name $ServiceAgent -ErrorAction SilentlyContinue } catch { Write-Log $_.Exception.Message }
Start-Sleep -Seconds 2
if (Test-Path $bandeja) {
    Start-Process $bandeja -WorkingDirectory $Root
}
Write-Log "Agente (servico) e bandeja iniciados"

if ($isRemoteCloud) {
    Write-Log "Modo nuvem: painel PHP local nao sera iniciado (portal em /portal/{token})."
} else {
    & (Join-Path $Scripts "iniciar-painel.ps1")
}
Write-Log "Instalacao concluida."
exit 0
