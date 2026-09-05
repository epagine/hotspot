# Instala a tarefa do agente (uma vez, como Administrador) e inicia agora.

$ErrorActionPreference = "Stop"
$Agent = Join-Path $PSScriptRoot "agente-hotspot.ps1"
$TaskName = "HotspotLoja"
$Root = Split-Path -Parent $PSScriptRoot

function Get-TaskAccount {
    $name = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    if ($name -and $name -match '\\') {
        return $name
    }
    if ($env:USERDOMAIN) {
        return "$($env:USERDOMAIN)\$($env:USERNAME)"
    }
    return ".\$($env:USERNAME)"
}

$arg = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$Agent`""
Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
$ok = $false
try {
    $action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $arg
    $trigger = New-ScheduledTaskTrigger -AtLogOn
    $principal = New-ScheduledTaskPrincipal -UserId (Get-TaskAccount) -LogonType InteractiveToken -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
    $ok = $true
} catch {
    Write-Host $_.Exception.Message
}
if (-not $ok) {
    $tr = "powershell.exe $arg"
    & schtasks.exe /Create /TN $TaskName /SC ONLOGON /RL HIGHEST /F /IT /TR $tr | Out-Host
    if ($LASTEXITCODE -ne 0) {
        & schtasks.exe /Create /TN $TaskName /SC ONLOGON /RL HIGHEST /F /TR $tr
    }
    if ($LASTEXITCODE -ne 0) {
        throw "Nao foi possivel criar a tarefa $TaskName."
    }
}

try { Start-ScheduledTask -TaskName $TaskName } catch { }
Start-Sleep -Seconds 2
if (-not (Test-Path (Join-Path $Root "storage\agent.pid"))) {
    Start-Process powershell.exe -ArgumentList $arg -WorkingDirectory $Root -WindowStyle Hidden
}

Write-Host "Agente instalado. Pode ligar a rede pelo painel."
