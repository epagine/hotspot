$ErrorActionPreference = 'Stop'

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Execute este script em um PowerShell aberto como Administrador.'
}

. (Join-Path $PSScriptRoot 'agent-storage.ps1')
$storage = Join-Path $env:ProgramData 'WiFiDaLoja'
if (-not (Test-Path -LiteralPath $storage)) {
    throw "Dados do agente nao encontrados em $storage."
}

Set-AgentStoragePermissions -Path $storage
Write-Host 'Permissoes dos dados do agente corrigidas e verificadas.'
