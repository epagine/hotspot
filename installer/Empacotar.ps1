# Gera WiFiDaLoja-Agent-Setup.exe — agente cloud (sem PHP/MySQL/painel local).
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Dist = Join-Path $Root "dist"
$Stage = Join-Path $Dist "payload"
$Zip = Join-Path $Dist "payload.zip"
$Stub = Join-Path $Dist "SetupStub.exe"
$OutExe = Join-Path $Dist "WiFiDaLoja-Agent-Setup.exe"

Write-Host "Compilando auxiliares..."
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot "compilar.ps1")
if ($LASTEXITCODE -ne 0) {
    Write-Host "Aviso: compilei com o que ja existia."
}

if (Test-Path $Stage) { Remove-Item $Stage -Recurse -Force }
if (Test-Path $Zip) { Remove-Item $Zip -Force -ErrorAction SilentlyContinue }
New-Item -ItemType Directory -Path $Stage -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $Stage "scripts") | Out-Null

$scriptFiles = @(
    "instalar-windows.ps1",
    "desinstalar-windows.ps1",
    "agent-storage.ps1"
)
foreach ($name in $scriptFiles) {
    Copy-Item (Join-Path (Join-Path $Root "scripts") $name) (Join-Path (Join-Path $Stage "scripts") $name) -Force
}

foreach ($exe in @("WiFiDaLojaAgent.exe", "HotspotBandeja.exe", "Desinstalar-Hotspot.exe", "DnsProxy.exe", "CaptiveHttp.exe")) {
    $p = Join-Path $Root $exe
    if (-not (Test-Path $p)) {
        throw "Faltando $exe. Rode installer\compilar.ps1 antes."
    }
    Copy-Item $p $Stage -Force
}

Set-Content -Path (Join-Path $Stage "CLOUD_AGENT") -Value "1" -Encoding ASCII

$versionFile = Join-Path $Root "scripts\AGENT_VERSION.txt"
if (-not (Test-Path $versionFile)) {
    throw "Faltando scripts\AGENT_VERSION.txt"
}
$agentVersion = (Get-Content $versionFile -Raw).Trim()
Set-Content -Path (Join-Path $Stage "AGENT_VERSION") -Value $agentVersion -Encoding ASCII -NoNewline
Write-Host "Versao do agente: $agentVersion"

Add-Type -AssemblyName System.IO.Compression.FileSystem
if (Test-Path $Zip) { Remove-Item $Zip -Force }
Write-Host "Compactando pacote..."
[System.IO.Compression.ZipFile]::CreateFromDirectory($Stage, $Zip, [System.IO.Compression.CompressionLevel]::Optimal, $false)

$csc = Join-Path $env:WINDIR "Microsoft.NET\Framework64\v4.0.30319\csc.exe"
if (-not (Test-Path $csc)) {
    $csc = Join-Path $env:WINDIR "Microsoft.NET\Framework\v4.0.30319\csc.exe"
}
Write-Host "Compilando instalador..."
$manifest = Join-Path $PSScriptRoot "app.manifest"
$setupCs = Join-Path $PSScriptRoot "Setup.cs"
$agentBuildCs = Join-Path $PSScriptRoot "AgentBuild.generated.cs"
@(
    "internal static class AgentBuild",
    "{",
    "    public const string Version = `"$agentVersion`";",
    "}"
) | Set-Content -Path $agentBuildCs -Encoding UTF8
$logo = Join-Path $Root "public\assets\logo-wifidaloja.jpg"
if (-not (Test-Path $logo)) {
    throw "Logo nao encontrada: public\assets\logo-wifidaloja.jpg"
}
$resourceArg = "/resource:{0},WiFiDaLoja.Logo" -f $logo
& $csc /nologo /optimize+ /target:winexe /win32manifest:$manifest /r:System.Windows.Forms.dll /r:System.Drawing.dll /r:System.IO.Compression.dll /r:System.IO.Compression.FileSystem.dll /r:System.ServiceProcess.dll $resourceArg /out:$Stub $setupCs $agentBuildCs
if ($LASTEXITCODE -ne 0) { throw "Falha ao compilar Setup.cs" }

Write-Host "Montando WiFiDaLoja-Agent-Setup.exe ..."
$stubBytes = [System.IO.File]::ReadAllBytes($Stub)
$zipBytes = [System.IO.File]::ReadAllBytes($Zip)
$lenBytes = [BitConverter]::GetBytes([int64]$zipBytes.LongLength)
$tmpOut = Join-Path $Dist ("WiFiDaLoja-Agent-Setup-" + [guid]::NewGuid().ToString("N").Substring(0, 8) + ".exe")
$out = [System.IO.File]::Create($tmpOut)
try {
    $out.Write($stubBytes, 0, $stubBytes.Length)
    $out.Write($zipBytes, 0, $zipBytes.Length)
    $out.Write($lenBytes, 0, $lenBytes.Length)
} finally {
    $out.Close()
}
try {
    if (Test-Path $OutExe) { Remove-Item $OutExe -Force -ErrorAction Stop }
    Move-Item $tmpOut $OutExe -Force
} catch {
    Write-Host "Aviso: nao sobrescrevi dist\WiFiDaLoja-Agent-Setup.exe (em uso). Usando arquivo novo: $tmpOut"
    $OutExe = $tmpOut
}

Copy-Item $OutExe (Join-Path $Root "WiFiDaLoja-Agent-Setup.exe") -Force
$dl = Join-Path $Root "storage\downloads"
New-Item -ItemType Directory -Path $dl -Force | Out-Null
$published = Join-Path $dl "WiFiDaLoja-Agent-Setup.exe"
try {
    Copy-Item $OutExe $published -Force
} catch {
    Write-Host "Aviso: nao copiei para storage\downloads (arquivo em uso). Feche o .exe e copie de dist\ manualmente."
}
$kb = [math]::Round((Get-Item $OutExe).Length / 1KB, 0)
Write-Host ("Pronto: " + $OutExe + " (" + $kb + " KB)")
if (Test-Path $published) {
    Write-Host "Publicado em storage\downloads\WiFiDaLoja-Agent-Setup.exe"
} else {
    Write-Host "Saida em dist\WiFiDaLoja-Agent-Setup.exe (copie para storage\downloads quando puder)."
}
