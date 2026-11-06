param(
    [string]$SigningCertThumbprint = $env:WIFIDALOJA_SIGNING_CERT_THUMBPRINT,
    [string]$TimestampServer = $env:WIFIDALOJA_TIMESTAMP_SERVER,
    [switch]$AllowUnsignedForTesting
)

# Gera WiFiDaLoja-Agent-Setup.exe — agente cloud (sem PHP/MySQL/painel local).
# Em ambiente interno livre, o build pode ser gerado sem assinatura. Quando houver um certificado
# de Code Signing, basta informar WIFIDALOJA_SIGNING_CERT_THUMBPRINT + TIMESTAMP_SERVER.
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Dist = Join-Path $Root "dist"
$Stage = Join-Path $Dist "payload"
$Zip = Join-Path $Dist "payload.zip"
$Stub = Join-Path $Dist "SetupStub.exe"
$OutExe = Join-Path $Dist "WiFiDaLoja-Agent-Setup.exe"
$script:SigningCertificate = $null

if ($SigningCertThumbprint) {
    $thumbprint = $SigningCertThumbprint.Replace(' ', '').ToUpperInvariant()
    foreach ($store in @('Cert:\CurrentUser\My', 'Cert:\LocalMachine\My')) {
        if (-not (Test-Path $store)) { continue }
        $script:SigningCertificate = Get-ChildItem $store -ErrorAction SilentlyContinue |
            Where-Object { $_.Thumbprint -eq $thumbprint -and $_.HasPrivateKey } |
            Select-Object -First 1
        if ($script:SigningCertificate) { break }
    }
    if (-not $script:SigningCertificate) {
        throw "Certificado de assinatura com chave privada nao encontrado: $thumbprint"
    }
    if (-not $TimestampServer) {
        throw 'Defina WIFIDALOJA_TIMESTAMP_SERVER para assinar builds de release.'
    }
} elseif (-not $AllowUnsignedForTesting) {
    Write-Warning 'Build sem Authenticode: permitido para distribuicao interna/controle. Passe -AllowUnsignedForTesting para continuar sem certificado.'
    $AllowUnsignedForTesting = $true
}

if (-not $script:SigningCertificate) {
    Write-Warning 'Build sem assinatura Authenticode. Use somente para ambiente interno ou teste controlado; nao e adequado para distribuicao publica.'
}

function Set-AgentArtifactSignature {
    param([string]$Path)
    if (-not $script:SigningCertificate) { return }
    $signature = Set-AuthenticodeSignature -FilePath $Path -Certificate $script:SigningCertificate `
        -HashAlgorithm SHA256 -TimestampServer $TimestampServer -ErrorAction Stop
    if ($signature.Status -ne [System.Management.Automation.SignatureStatus]::Valid) {
        throw "Assinatura Authenticode falhou em ${Path}: $($signature.StatusMessage)"
    }
}

Write-Host "Compilando auxiliares..."
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot "compilar.ps1")
if ($LASTEXITCODE -ne 0) {
    throw "A compilacao dos auxiliares falhou; nao vou empacotar binarios existentes."
}

if (Test-Path $Stage) { Remove-Item $Stage -Recurse -Force }
if (Test-Path $Zip) { Remove-Item $Zip -Force -ErrorAction SilentlyContinue }
New-Item -ItemType Directory -Path $Stage -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $Stage "scripts") | Out-Null

$scriptFiles = @(
    "instalar-windows.ps1",
    "desinstalar-windows.ps1",
    "agent-storage.ps1",
    "hotspot-winrt.ps1"
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
if ($script:SigningCertificate) {
    Get-ChildItem -LiteralPath $Stage -Filter '*.exe' -File | ForEach-Object { Set-AgentArtifactSignature $_.FullName }
}

Set-Content -Path (Join-Path $Stage "CLOUD_AGENT") -Value "1" -Encoding ASCII

$versionFile = Join-Path $Root "scripts\AGENT_VERSION.txt"
if (-not (Test-Path $versionFile)) {
    throw "Faltando scripts\AGENT_VERSION.txt"
}
$agentVersion = (Get-Content $versionFile -Raw).Trim()
if (-not $agentVersion) {
    throw "scripts\AGENT_VERSION.txt esta vazio"
}
Set-Content -Path (Join-Path $Stage "AGENT_VERSION") -Value $agentVersion -Encoding ASCII -NoNewline
Set-Content -Path (Join-Path $Root "app\AGENT_VERSION.txt") -Value $agentVersion -Encoding ASCII
# Copia tambem o .txt para depuracao
Copy-Item $versionFile (Join-Path $Stage "scripts\AGENT_VERSION.txt") -Force
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

$versionedOut = Join-Path $Dist ("WiFiDaLoja-Agent-Setup-$agentVersion.exe")
Copy-Item $tmpOut $versionedOut -Force

try {
    if (Test-Path $OutExe) { Remove-Item $OutExe -Force -ErrorAction Stop }
    Move-Item $tmpOut $OutExe -Force
} catch {
    Write-Host "Aviso: nao sobrescrevi dist\WiFiDaLoja-Agent-Setup.exe (em uso)."
    Write-Host "Use o arquivo versionado: $versionedOut"
    if (Test-Path $tmpOut) {
        $OutExe = $versionedOut
        Remove-Item $tmpOut -Force -ErrorAction SilentlyContinue
    } else {
        $OutExe = $versionedOut
    }
}

Set-AgentArtifactSignature $versionedOut
if ($OutExe -ne $versionedOut -and (Test-Path $OutExe)) {
    Set-AgentArtifactSignature $OutExe
}

try {
    Copy-Item $OutExe (Join-Path $Root "WiFiDaLoja-Agent-Setup.exe") -Force -ErrorAction Stop
} catch {
    Write-Host "Aviso: nao atualizei WiFiDaLoja-Agent-Setup.exe na raiz (em uso)."
}

$dl = Join-Path $Root "storage\downloads"
New-Item -ItemType Directory -Path $dl -Force | Out-Null
$published = Join-Path $dl "WiFiDaLoja-Agent-Setup.exe"
$publishedVer = Join-Path $dl ("WiFiDaLoja-Agent-Setup-$agentVersion.exe")
Copy-Item $versionedOut $publishedVer -Force
Set-Content -Path (Join-Path $dl "WiFiDaLoja-Agent-Setup.version") -Value $agentVersion -Encoding ASCII -NoNewline
try {
    Copy-Item $OutExe $published -Force -ErrorAction Stop
    # Confere se o download publicado e o mesmo arquivo que acabamos de gerar
    $srcHash = (Get-FileHash $OutExe -Algorithm SHA256).Hash
    $dstHash = (Get-FileHash $published -Algorithm SHA256).Hash
    if ($srcHash -ne $dstHash) {
        Write-Host "ERRO: storage\downloads\WiFiDaLoja-Agent-Setup.exe NAO bate com o build novo (arquivo antigo em uso?)."
        Write-Host "Baixe/use: $publishedVer"
    }
} catch {
    Write-Host "Aviso: nao atualizei storage\downloads\WiFiDaLoja-Agent-Setup.exe (em uso)."
    Write-Host "Baixe/use a copia versionada: $publishedVer"
}

$kb = [math]::Round((Get-Item $versionedOut).Length / 1KB, 0)
Write-Host ("Pronto v$agentVersion : " + $versionedOut + " (" + $kb + " KB)")
if (Test-Path $published) {
    $pubTime = (Get-Item $published).LastWriteTime.ToString("yyyy-MM-dd HH:mm:ss")
    Write-Host "Publicado: storage\downloads\WiFiDaLoja-Agent-Setup.exe (mtime $pubTime)"
}
Write-Host "Sempre confira o titulo do instalador: 'Agente Windows v$agentVersion'"
