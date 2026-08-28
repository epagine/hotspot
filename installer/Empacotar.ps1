# Gera WiFiDaLoja-Setup.exe (um arquivo) para instalar em outro PC.
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Dist = Join-Path $Root "dist"
$Stage = Join-Path $Dist "payload"
$Zip = Join-Path $Dist "payload.zip"
$Stub = Join-Path $Dist "SetupStub.exe"
$OutExe = Join-Path $Dist "WiFiDaLoja-Setup.exe"

Write-Host "Compilando auxiliares..."
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot "compilar.ps1")
if ($LASTEXITCODE -ne 0) {
    Write-Host "Aviso: compilei com o que ja existia (um .exe pode estar em uso)."
}

if (Test-Path $Dist) { Remove-Item $Dist -Recurse -Force }
New-Item -ItemType Directory -Path $Stage | Out-Null

$copyDirs = @("app", "bin", "public", "scripts", "installer")
foreach ($d in $copyDirs) {
    $src = Join-Path $Root $d
    if (Test-Path $src) {
        Copy-Item $src (Join-Path $Stage $d) -Recurse -Force
    }
}
Copy-Item (Join-Path $Root "index.php") $Stage -Force
if (Test-Path (Join-Path $Root ".htaccess")) {
    Copy-Item (Join-Path $Root ".htaccess") $Stage -Force
}
foreach ($exe in @("HotspotBandeja.exe", "Desinstalar-Hotspot.exe")) {
    $p = Join-Path $Root $exe
    if (Test-Path $p) { Copy-Item $p $Stage -Force }
}

Remove-Item (Join-Path $Stage "app\config.php") -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Path (Join-Path $Stage "storage") | Out-Null
Set-Content -Path (Join-Path $Stage "storage\.gitkeep") -Value "" -Encoding ASCII

$phpSrc = "C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64"
if (-not (Test-Path (Join-Path $phpSrc "php.exe"))) {
    $phpSrc = Get-ChildItem "C:\laragon\bin\php" -Directory -ErrorAction SilentlyContinue |
        Where-Object { Test-Path (Join-Path $_.FullName "php.exe") } |
        Select-Object -First 1 -ExpandProperty FullName
}
if (-not $phpSrc -or -not (Test-Path (Join-Path $phpSrc "php.exe"))) {
    throw "Nao achei o PHP do Laragon para empacotar. Precisa dele para o instalador funcionar em outro PC."
}
Write-Host "Copiando PHP de $phpSrc ..."
$phpDest = Join-Path $Stage "runtime\php"
New-Item -ItemType Directory -Path $phpDest -Force | Out-Null
Copy-Item (Join-Path $phpSrc "*") $phpDest -Recurse -Force
Get-ChildItem $phpDest -Recurse -Include *.pdb, *.md, news.txt, README* -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue

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
$cscArgs = @(
    "/nologo",
    "/optimize+",
    "/target:winexe",
    "/win32manifest:$manifest",
    "/r:System.Windows.Forms.dll",
    "/r:System.Drawing.dll",
    "/r:System.IO.Compression.dll",
    "/r:System.IO.Compression.FileSystem.dll",
    "/out:$Stub",
    $setupCs
)
& $csc @cscArgs
if ($LASTEXITCODE -ne 0) { throw "Falha ao compilar Setup.cs" }

Write-Host "Montando WiFiDaLoja-Setup.exe ..."
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

Copy-Item $OutExe (Join-Path $Root "WiFiDaLoja-Setup.exe") -Force
$mb = [math]::Round((Get-Item $OutExe).Length / 1MB, 1)
Write-Host "Pronto: $OutExe ($mb MB)"
Write-Host "Copie esse arquivo para o outro computador e execute como administrador."
