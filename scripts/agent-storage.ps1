# Caminho persistente do agente (sobrevive a reinstalacao/atualizacao em Program Files).
function Get-AgentStorageDir {
    param([string]$InstallRoot = "")
    $dir = Join-Path $env:ProgramData "WiFiDaLoja"
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    if ($InstallRoot) {
        Import-LegacyAgentStorage -InstallRoot $InstallRoot -TargetDir $dir
    }
    return $dir
}

function Import-LegacyAgentStorage {
    param([string]$InstallRoot, [string]$TargetDir)
    $legacy = Join-Path $InstallRoot "storage"
    if (-not (Test-Path $legacy)) {
        return
    }
    foreach ($name in @(
        "cloud.json", "authorized.json", "store-info.json", "install-mode.json",
        "sync-error.json", "client-patches.json", "php-path.txt"
    )) {
        $src = Join-Path $legacy $name
        $dst = Join-Path $TargetDir $name
        if ((Test-Path $src) -and -not (Test-Path $dst)) {
            Copy-Item $src $dst -Force
        }
    }
    $legacyBrand = Join-Path $legacy "brand"
    $targetBrand = Join-Path $TargetDir "brand"
    if ((Test-Path $legacyBrand) -and -not (Test-Path $targetBrand)) {
        Copy-Item $legacyBrand $targetBrand -Recurse -Force
    }
}

function Read-AgentCloudConfig {
    param([string]$StorageDir)
    $path = Join-Path $StorageDir "cloud.json"
    if (-not (Test-Path $path)) {
        return $null
    }
    try {
        return Get-Content $path -Raw -ErrorAction Stop | ConvertFrom-Json
    } catch {
        return $null
    }
}

function Test-AgentCloudConfig {
    param([string]$StorageDir)
    $cfg = Read-AgentCloudConfig -StorageDir $StorageDir
    if (-not $cfg) {
        return $false
    }
    $url = ([string]$cfg.panel_url).Trim()
    $token = ([string]$cfg.token).Trim()
    return ($url.Length -ge 8 -and $token.Length -ge 8)
}

function Get-AgentVersion {
    param([string]$InstallRoot = "")
    if ($InstallRoot) {
        $fromRoot = Join-Path $InstallRoot "AGENT_VERSION"
        if (Test-Path $fromRoot) {
            return (Get-Content $fromRoot -Raw -ErrorAction SilentlyContinue).Trim()
        }
    }
    $fromRepo = Join-Path (Split-Path -Parent $PSScriptRoot) "scripts\AGENT_VERSION.txt"
    if (Test-Path $fromRepo) {
        return (Get-Content $fromRepo -Raw -ErrorAction SilentlyContinue).Trim()
    }
    return "0.0.0"
}

function Get-PendingCommandFile {
    param([string]$InstallRoot, [string]$StorageDir)
    $primary = Join-Path $StorageDir "command.json"
    $legacy = Join-Path $InstallRoot "storage\command.json"
    if (Test-Path $primary) {
        return $primary
    }
    if (Test-Path $legacy) {
        try {
            Copy-Item $legacy $primary -Force -ErrorAction Stop
            return $primary
        } catch {
            return $legacy
        }
    }
    return $primary
}

function Test-AgentProcessAlive {
    param([string]$StorageDir)
    $pidPath = Join-Path $StorageDir "agent.pid"
    if (-not (Test-Path $pidPath)) {
        return $false
    }
    $oldPid = 0
    [void][int]::TryParse(((Get-Content $pidPath -ErrorAction SilentlyContinue | Select-Object -First 1)), [ref]$oldPid)
    if ($oldPid -le 0) {
        return $false
    }
    return $null -ne (Get-Process -Id $oldPid -ErrorAction SilentlyContinue)
}

function Start-AgentProcess {
    param([string]$InstallRoot)
    $agent = Join-Path $InstallRoot "scripts\agente-hotspot.ps1"
    if (-not (Test-Path $agent)) {
        return $false
    }
    Start-Process powershell.exe -ArgumentList @(
        "-NoProfile", "-ExecutionPolicy", "Bypass", "-WindowStyle", "Hidden", "-File", $agent
    ) -WorkingDirectory $InstallRoot -WindowStyle Hidden | Out-Null
    return $true
}
