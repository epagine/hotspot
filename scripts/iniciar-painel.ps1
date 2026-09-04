param(
    [switch]$NoBrowser
)

$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent $PSScriptRoot
$Storage = Join-Path $Root "storage"
$Port = 8080
$Url = "http://127.0.0.1:$Port/"

function Find-Php {
    $bundled = Join-Path $Root "runtime\php\php.exe"
    if (Test-Path $bundled) { return $bundled }
    $saved = Join-Path $Storage "php-path.txt"
    if (Test-Path $saved) {
        $p = (Get-Content $saved -Raw).Trim()
        if ($p -and (Test-Path $p)) { return $p }
    }
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $found = Get-ChildItem "C:\laragon\bin\php" -Recurse -Filter php.exe -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($found) { return $found.FullName }
    return $null
}

function Test-Listening([int]$ListenPort) {
    try {
        $c = Get-NetTCPConnection -LocalPort $ListenPort -State Listen -ErrorAction SilentlyContinue
        return [bool]$c
    } catch {
        return $false
    }
}

if (-not (Test-Path $Storage)) {
    New-Item -ItemType Directory -Path $Storage | Out-Null
}

$php = Find-Php
if (-not $php) {
    if (-not $NoBrowser) {
        Add-Type -AssemblyName System.Windows.Forms
        [System.Windows.Forms.MessageBox]::Show("PHP nao encontrado. Instale PHP (ex.: Laragon) ou use o agente WiFiDaLoja-Agent-Setup.exe no PC da loja.", "Wi-Fi da loja")
    }
    exit 1
}
$env:PHPRC = Split-Path -Parent $php

if (-not (Test-Listening $Port)) {
    $router = Join-Path $Root "index.php"
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $php
    $psi.Arguments = "-S 0.0.0.0:$Port `"$router`""
    $psi.WorkingDirectory = $Root
    $psi.WindowStyle = "Hidden"
    $psi.CreateNoWindow = $true
    $psi.UseShellExecute = $false
    $psi.EnvironmentVariables["PHPRC"] = $env:PHPRC
    $proc = [System.Diagnostics.Process]::Start($psi)
    Set-Content -Path (Join-Path $Storage "panel.pid") -Value $proc.Id -Encoding ASCII
    $ok = $false
    for ($i = 0; $i -lt 25; $i++) {
        Start-Sleep -Milliseconds 200
        if (Test-Listening $Port) { $ok = $true; break }
    }
    if (-not $ok -and -not $NoBrowser) {
        Add-Type -AssemblyName System.Windows.Forms
        [System.Windows.Forms.MessageBox]::Show("Nao consegui subir o painel na porta $Port.", "Wi-Fi da loja")
        exit 1
    }
}

$config = Join-Path $Root "app\config.php"
if (Test-Path $config) {
    $Url = "http://127.0.0.1:$Port/entrar"
} else {
    $Url = "http://127.0.0.1:$Port/instalar"
}

if (-not $NoBrowser) {
    Start-Process $Url
}
exit 0
