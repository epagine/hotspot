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

if (Test-Path $Dist) { Remove-Item $Dist -Recurse -Force }
New-Item -ItemType Directory -Path $Stage | Out-Null
New-Item -ItemType Directory -Path (Join-Path $Stage "scripts") | Out-Null
New-Item -ItemType Directory -Path (Join-Path $Stage "storage") | Out-Null

$scriptFiles = @(
    "agente-hotspot.ps1",
    "instalar-windows.ps1",
    "desinstalar-windows.ps1"
)
foreach ($name in $scriptFiles) {
    Copy-Item (Join-Path (Join-Path $Root "scripts") $name) (Join-Path (Join-Path $Stage "scripts") $name) -Force
}

foreach ($exe in @("HotspotBandeja.exe", "Desinstalar-Hotspot.exe", "DnsProxy.exe")) {
    $p = Join-Path $Root $exe
    if (-not (Test-Path $p)) {
        throw "Faltando $exe. Rode installer\compilar.ps1 antes."
    }
    Copy-Item $p $Stage -Force
}

Set-Content -Path (Join-Path $Stage "CLOUD_AGENT") -Value "1" -Encoding ASCII
Set-Content -Path (Join-Path $Stage "storage\.gitkeep") -Value "" -Encoding ASCII

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
& $csc /nologo /optimize+ /target:winexe /win32manifest:$manifest /r:System.Windows.Forms.dll /r:System.Drawing.dll /r:System.IO.Compression.dll /r:System.IO.Compression.FileSystem.dll /out:$Stub $setupCs
if ($LASTEXITCODE -ne 0) { throw "Falha ao compilar Setup.cs" }

Write-Host "Montando WiFiDaLoja-Agent-Setup.exe ..."
$stubBytes = [System.IO.File]::ReadAllBytes($Stub)
$zipBytes = [System.IO.File]::ReadAllBytes($Zip)
$lenBytes = [BitConverter]::GetBytes([int64]$zipBytes.LongLength)
$out = [System.IO.File]::Create($OutExe)
try {
    $out.Write($stubBytes, 0, $stubBytes.Length)
    $out.Write($zipBytes, 0, $zipBytes.Length)
    $out.Write($lenBytes, 0, $lenBytes.Length)
} finally {
    $out.Close()
}

Copy-Item $OutExe (Join-Path $Root "WiFiDaLoja-Agent-Setup.exe") -Force
$dl = Join-Path $Root "storage\downloads"
New-Item -ItemType Directory -Path $dl -Force | Out-Null
Copy-Item $OutExe (Join-Path $dl "WiFiDaLoja-Agent-Setup.exe") -Force
$kb = [math]::Round((Get-Item $OutExe).Length / 1KB, 0)
Write-Host ("Pronto: " + $OutExe + " (" + $kb + " KB)")
Write-Host "Publicado em storage\downloads\WiFiDaLoja-Agent-Setup.exe"
