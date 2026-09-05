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
& $csc /nologo /target:winexe /optimize+ /win32manifest:"$src\app.manifest" /r:System.Windows.Forms.dll /out:"$root\Instalar-Hotspot.exe" "$src\Instalar.cs"
if ($LASTEXITCODE -ne 0) { throw "Falha ao gerar Instalar-Hotspot.exe" }
& $csc /nologo /target:winexe /optimize+ /win32manifest:"$src\app.manifest" /r:System.Windows.Forms.dll /out:"$root\Desinstalar-Hotspot.exe" "$src\Desinstalar.cs"
if ($LASTEXITCODE -ne 0) { throw "Falha ao gerar Desinstalar-Hotspot.exe" }
& $csc /nologo /target:winexe /optimize+ /r:System.Windows.Forms.dll /r:System.Drawing.dll /out:"$root\HotspotBandeja.exe" "$src\Bandeja.cs"
if ($LASTEXITCODE -ne 0) {
    if (Test-Path (Join-Path $root "HotspotBandeja.exe")) {
        Write-Host "HotspotBandeja.exe em uso; mantendo o arquivo existente."
    } else {
        throw "Falha ao gerar HotspotBandeja.exe"
    }
}
& $csc /nologo /target:exe /optimize+ /out:"$root\DnsProxy.exe" "$src\DnsProxy.cs"
if ($LASTEXITCODE -ne 0) { throw "Falha ao gerar DnsProxy.exe" }
& $csc /nologo /target:exe /optimize+ /out:"$root\CaptiveHttp.exe" "$src\CaptiveHttp.cs"
if ($LASTEXITCODE -ne 0) { throw "Falha ao gerar CaptiveHttp.exe" }
Write-Host "Gerados: Instalar-Hotspot.exe, Desinstalar-Hotspot.exe, HotspotBandeja.exe, DnsProxy.exe e CaptiveHttp.exe"
