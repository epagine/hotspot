# Caminho persistente do agente (sobrevive a reinstalacao/atualizacao em Program Files).
function Test-AgentPanelUrl {
    param([string]$PanelUrl)
    $uri = $null
    if (-not [Uri]::TryCreate($PanelUrl, [UriKind]::Absolute, [ref]$uri)) { return $false }
    if ($uri.UserInfo -or $uri.Query -or $uri.Fragment -or $uri.AbsolutePath -ne '/') { return $false }
    if ($uri.Scheme -eq 'https') { return $true }
    return $uri.Scheme -eq 'http' -and $uri.IsLoopback
}

function Set-AgentStoragePermissions {
    param([string]$Path)
    $userSid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $rules = @(
        '*S-1-5-18:(OI)(CI)F',
        '*S-1-5-32-544:(OI)(CI)F',
        ('*{0}:(OI)(CI)F' -f $userSid)
    )
    $arguments = @($Path, '/inheritance:r', '/grant:r') + $rules + @('/T', '/C')
    $output = @(& (Join-Path $env:WINDIR 'system32\icacls.exe') @arguments 2>&1)
    if ($LASTEXITCODE -ne 0 -or ($output -join "`n") -match '(?i)access is denied|acesso negado') {
        throw 'Nao foi possivel restringir as permissoes dos dados do agente.'
    }
    $privateConfig = Join-Path $Path 'agent-config.json'
    if (Test-Path -LiteralPath $privateConfig) {
        $privateRules = @($privateConfig, '/inheritance:r', '/grant:r', '*S-1-5-18:F', '*S-1-5-32-544:F')
        $privateOutput = @(& (Join-Path $env:WINDIR 'system32\icacls.exe') @privateRules 2>&1)
        if ($LASTEXITCODE -ne 0 -or ($privateOutput -join "`n") -match '(?i)access is denied|acesso negado') {
            throw 'Nao foi possivel restringir agent-config.json a SYSTEM e Administradores.'
        }
    }
    $paths = @($Path) + @(Get-ChildItem -LiteralPath $Path -Recurse -Force -ErrorAction Stop | ForEach-Object FullName)
    $untrustedSids = @('S-1-1-0', 'S-1-5-11', 'S-1-5-32-545')
    $requiredSids = @('S-1-5-18', 'S-1-5-32-544')
    foreach ($itemPath in $paths) {
        $acl = Get-Acl -LiteralPath $itemPath -ErrorAction Stop
        $sids = @()
        foreach ($rule in $acl.Access) {
            try {
                $sid = $rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
            } catch {
                $sid = $rule.IdentityReference.Value
            }
            if ($sid -in $untrustedSids) {
                throw "Permissao ampla permanece em $itemPath. Execute a instalacao como Administrador."
            }
            $sids += $sid
        }
        foreach ($requiredSid in $requiredSids) {
            if ($requiredSid -notin $sids) {
                throw "ACL incompleta em $itemPath. Execute a instalacao como Administrador."
            }
        }
        if ($itemPath -eq $privateConfig) {
            if ($userSid -in $sids) {
                throw 'agent-config.json ainda concede acesso direto ao usuario interativo.'
            }
        } elseif ($userSid -notin $sids) {
            throw "ACL sem acesso para a bandeja em $itemPath. Execute a instalacao como Administrador."
        }
    }
}

function New-AgentWifiPassphrase {
    $bytes = New-Object byte[] 16
    $random = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $random.GetBytes($bytes)
    } finally {
        $random.Dispose()
    }
    return ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant()
}

function Get-AgentStorageDir {
    param([string]$InstallRoot = "", [switch]$RestrictAccess)
    $dir = Join-Path $env:ProgramData "WiFiDaLoja"
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    if ($RestrictAccess) {
        Set-AgentStoragePermissions -Path $dir
    }
    if ($InstallRoot) {
        Import-LegacyAgentStorage -InstallRoot $InstallRoot -TargetDir $dir
    }
    if ($RestrictAccess) {
        Set-AgentStoragePermissions -Path $dir
    }
    return $dir
}

function Get-AgentWifiConfig {
    param([string]$StorageDir)
    $path = Join-Path $StorageDir 'authorized.json'
    $config = [ordered]@{ ssid = 'WifiDaLoja'; wifi_pass = '' }
    if (Test-Path $path) {
        try {
            $loaded = Get-Content $path -Raw | ConvertFrom-Json
            if ($loaded) {
                foreach ($property in $loaded.PSObject.Properties) {
                    $config[$property.Name] = $property.Value
                }
            }
        } catch {}
    }
    if ([string]::IsNullOrWhiteSpace([string]$config.wifi_pass)) {
        $config.wifi_pass = New-AgentWifiPassphrase
        [System.IO.File]::WriteAllText($path, ($config | ConvertTo-Json -Depth 6), [System.Text.UTF8Encoding]::new($false))
    }
    return [pscustomobject]$config
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
    return ($token.Length -ge 8 -and (Test-AgentPanelUrl $url))
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

function Test-AgentProcessElevated {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    $p = New-Object Security.Principal.WindowsPrincipal($id)
    return $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Start-AgentProcess {
    param([string]$InstallRoot)
    $storage = Get-AgentStorageDir -InstallRoot $InstallRoot
    foreach ($taskName in @("HotspotLoja")) {
        try {
            Start-ScheduledTask -TaskName $taskName -ErrorAction Stop
        } catch {
            & schtasks.exe /Run /TN $taskName 2>$null | Out-Null
        }
        for ($i = 0; $i -lt 8; $i++) {
            Start-Sleep -Milliseconds 500
            if (Test-AgentProcessAlive -StorageDir $storage) {
                return $true
            }
        }
    }
    return $false
}
