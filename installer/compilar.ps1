$ErrorActionPreference = "Stop"
$here = $PSScriptRoot
$root = Split-Path -Parent $here
$csc = Join-Path $env:WINDIR "Microsoft.NET\Framework64\v4.0.30319\csc.exe"
if (-not (Test-Path $csc)) {
    $csc = Join-Path $env:WINDIR "Microsoft.NET\Framework\v4.0.30319\csc.exe"
}
if (-not (Test-Path $csc)) {
    throw "csc.exe nao encontrado ( .NET Framework 4 )."
}
$src = Join-Path $root "installer"
$webExt = Join-Path $env:WINDIR "Microsoft.NET\Framework64\v4.0.30319\System.Web.Extensions.dll"
if (-not (Test-Path $webExt)) {
    $webExt = Join-Path $env:WINDIR "Microsoft.NET\Framework\v4.0.30319\System.Web.Extensions.dll"
}

$versionFile = Join-Path $root "scripts\AGENT_VERSION.txt"
if (-not (Test-Path $versionFile)) {
    throw "Faltando scripts\AGENT_VERSION.txt"
}
$agentVersion = (Get-Content $versionFile -Raw).Trim()
if (-not $agentVersion) {
    throw "scripts\AGENT_VERSION.txt esta vazio"
}
$agentVerCs = Join-Path $src "AgentRuntimeVersion.generated.cs"
@(
    "internal static class AgentRuntimeVersion",
    "{",
    "    public const string Value = `"$agentVersion`";",
    "}"
) | Set-Content -Path $agentVerCs -Encoding UTF8
Write-Host "Compilando agente versao $agentVersion ..."

& $csc /nologo /target:winexe /optimize+ /win32manifest:"$src\app.manifest" /r:System.Windows.Forms.dll /out:"$root\Instalar-Hotspot.exe" "$src\Instalar.cs"
if ($LASTEXITCODE -ne 0) { throw "Falha ao gerar Instalar-Hotspot.exe" }
& $csc /nologo /target:winexe /optimize+ /win32manifest:"$src\app.manifest" /r:System.Windows.Forms.dll /out:"$root\Desinstalar-Hotspot.exe" "$src\Desinstalar.cs"
if ($LASTEXITCODE -ne 0) { throw "Falha ao gerar Desinstalar-Hotspot.exe" }
& $csc /nologo /target:winexe /optimize+ /r:System.Windows.Forms.dll /r:System.Drawing.dll /out:"$root\HotspotBandeja.exe" "$src\Bandeja.cs"
if ($LASTEXITCODE -ne 0) {
    throw "Falha ao gerar HotspotBandeja.exe; pare a bandeja e tente novamente."
}
& $csc /nologo /target:exe /optimize+ /out:"$root\DnsProxy.exe" "$src\DnsProxy.cs"
if ($LASTEXITCODE -ne 0) { throw "Falha ao gerar DnsProxy.exe" }
& $csc /nologo /target:exe /optimize+ /out:"$root\CaptiveHttp.exe" "$src\CaptiveHttp.cs"
if ($LASTEXITCODE -ne 0) { throw "Falha ao gerar CaptiveHttp.exe" }

$winRt = Join-Path ${env:ProgramFiles(x86)} "Reference Assemblies\Microsoft\Framework\.NETCore\v4.5\System.Runtime.WindowsRuntime.dll"
$agentRefs = @(
    "/r:System.ServiceProcess.dll",
    "/r:System.Management.dll",
    "/r:$webExt"
)
if (Test-Path $winRt) {
    $agentRefs += "/r:$winRt"
}
$agentOut = Join-Path $root "WiFiDaLojaAgent.exe"
$agentArgs = @("/nologo", "/target:exe", "/optimize+", "/out:$agentOut") + $agentRefs + @("$src\AgentService.cs", $agentVerCs)
& $csc @agentArgs
if ($LASTEXITCODE -ne 0) { throw "Falha ao gerar WiFiDaLojaAgent.exe" }

# Garante AGENT_VERSION ao lado dos binaries (instalacao local / empacote)
Set-Content -Path (Join-Path $root "AGENT_VERSION") -Value $agentVersion -Encoding ASCII -NoNewline

Write-Host "Gerados: Instalar-Hotspot.exe, Desinstalar-Hotspot.exe, HotspotBandeja.exe, DnsProxy.exe, CaptiveHttp.exe e WiFiDaLojaAgent.exe (v$agentVersion)"
