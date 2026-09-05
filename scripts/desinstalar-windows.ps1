$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot "agent-storage.ps1")
$Storage = Get-AgentStorageDir -InstallRoot $Root
$TaskAgent = "HotspotLoja"
$TaskPanel = "HotspotLojaPainel"

$id = [Security.Principal.WindowsIdentity]::GetCurrent()
$p = New-Object Security.Principal.WindowsPrincipal($id)
if (-not $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "A desinstalacao precisa de administrador."
    exit 1
}

foreach ($name in @($TaskAgent, $TaskPanel, "HotspotBandeja")) {
    Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue
    & schtasks.exe /Delete /TN $name /F 2>$null | Out-Null
}

Get-Process HotspotBandeja -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue

foreach ($pidFile in @("agent.pid", "panel.pid")) {
    $path = Join-Path $Storage $pidFile
    if (Test-Path $path) {
        $old = 0
        [void][int]::TryParse((Get-Content $path | Select-Object -First 1), [ref]$old)
        if ($old -gt 0) {
            Stop-Process -Id $old -Force -ErrorAction SilentlyContinue
        }
        Remove-Item $path -Force -ErrorAction SilentlyContinue
    }
}

Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
    Where-Object {
        ($_.Name -eq "DnsProxy.exe") -or
        ($_.Name -eq "php.exe" -and $_.CommandLine -and ($_.CommandLine -like "*dns-proxy.php*" -or $_.CommandLine -like "*0.0.0.0:8080*"))
    } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

foreach ($rule in @("HotspotLoja-Painel-8080", "HotspotLoja-DNS-53")) {
    Get-NetFirewallRule -DisplayName $rule -ErrorAction SilentlyContinue | Remove-NetFirewallRule -ErrorAction SilentlyContinue
}

$desktop = [Environment]::GetFolderPath("Desktop")
$programs = Join-Path ([Environment]::GetFolderPath("StartMenu")) "Programs\Wi-Fi da loja"
Remove-Item (Join-Path $desktop "Wi-Fi da loja.lnk") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $desktop "Painel Wi-Fi da loja.lnk") -Force -ErrorAction SilentlyContinue
Remove-Item $programs -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item "HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\HotspotLoja" -Recurse -Force -ErrorAction SilentlyContinue

Write-Host "Removido. Dados do agente mantidos em $Storage"
exit 0
