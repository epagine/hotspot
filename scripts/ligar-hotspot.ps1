# Liga o hotspot do Windows e o DNS cativo.
# Execute no PowerShell como Administrador.

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot "agent-storage.ps1")
$Storage = Get-AgentStorageDir -InstallRoot $Root
$AuthFile = Join-Path $Storage "authorized.json"
$DnsProxy = Join-Path $Root "bin\dns-proxy.php"

function Wait-WinRt {
    param($Operation)
    while ([int]$Operation.Status -eq 0) {
        Start-Sleep -Milliseconds 80
    }
    if ([int]$Operation.Status -eq 3) {
        throw "Falha no hotspot do Windows (status Error). Ative 'Ponto de acesso movel' em Configuracoes > Rede."
    }
    try { return $Operation.GetResults() } catch { return $null }
}

Add-Type -AssemblyName System.Runtime.WindowsRuntime | Out-Null

$ssid = "WifiDaLoja"
$pass = "loja1234"
if (Test-Path $AuthFile) {
    $cfg = Get-Content $AuthFile -Raw | ConvertFrom-Json
    if ($cfg.ssid) { $ssid = [string]$cfg.ssid }
    if ($cfg.wifi_pass) { $pass = [string]$cfg.wifi_pass }
}

$profile = [Windows.Networking.Connectivity.NetworkInformation, Windows.Networking.Connectivity, ContentType = WindowsRuntime]::GetInternetConnectionProfile()
if (-not $profile) {
    throw "Nenhuma internet encontrada neste PC. Conecte o cabo/Wi-Fi da loja primeiro."
}

$mgr = [Windows.Networking.NetworkOperators.NetworkOperatorTetheringManager, Windows.Networking.NetworkOperators, ContentType = WindowsRuntime]::CreateFromConnectionProfile($profile)
$config = $mgr.GetCurrentAccessPointConfiguration()
$config.Ssid = $ssid
$config.Passphrase = $pass
Wait-WinRt ($mgr.ConfigureAccessPointAsync($config)) | Out-Null

if ([int]$mgr.TetheringOperationalState -ne 1) {
    $result = Wait-WinRt ($mgr.StartTetheringAsync())
    Write-Host "Hotspot: $($result.Status)"
} else {
    Write-Host "Hotspot ja estava ligado."
}

Write-Host "Rede Wi-Fi: $ssid"
Write-Host "IPs deste PC (o do hotspot costuma ser 192.168.137.1):"
Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -notlike "127.*" } |
    ForEach-Object { Write-Host ("  {0}  ({1})" -f $_.IPAddress, $_.InterfaceAlias) }

$phpExe = $null
$cmd = Get-Command php -ErrorAction SilentlyContinue
if ($cmd) { $phpExe = $cmd.Source }
if (-not $phpExe) {
    $found = Get-ChildItem "C:\laragon\bin\php" -Recurse -Filter php.exe -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($found) { $phpExe = $found.FullName }
}
if (-not $phpExe) {
    throw "PHP nao encontrado no PATH nem no Laragon."
}

Write-Host "Subindo DNS cativo (deixe esta janela aberta)..."
Write-Host "No painel, ajuste o IP do portal se nao for 192.168.137.1"
& $phpExe $DnsProxy
