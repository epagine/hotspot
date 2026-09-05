# Agente Windows v2.0

## Arquitetura

- **WiFiDaLojaAgent.exe** — serviço Windows (LocalSystem), dono do hotspot, DNS e sync
- **HotspotBandeja.exe** — UI na bandeja; comunica via named pipe `\\.\pipe\WiFiDaLojaAgent`
- **DnsProxy.exe** / **CaptiveHttp.exe** — filhos supervisionados pelo serviço

Dados: `%ProgramData%\WiFiDaLoja\`

## Instalação

1. Execute `WiFiDaLoja-Agent-Setup.exe` **como administrador**
2. Informe URL do painel e token (ou vincule depois na bandeja)
3. O instalador registra o serviço `WiFiDaLojaAgent` e inicia a bandeja

## Adaptador Wi-Fi (multi-NIC)

No painel → Hotspot → **Adaptador Wi-Fi (transmissão)**:

- **Automático** — prefere USB TP-Link não conectado a outra rede
- **Manual** — escolha o GUID listado após o agente sincronizar

Com mais de um Wi-Fi, o agente pode desabilitar temporariamente os outros ao ligar (`wifi_isolate_others`).

## Testes manuais

### PC com Ethernet + 2 Wi-Fi (onboard + USB)

1. Instale v2.0; confirme serviço `Running` em `services.msc`
2. Painel: diagnóstico mostra 2 adaptadores
3. Selecione USB TP-Link → Salvar → Ligar rede na bandeja
4. Repita ligar/desligar 10× via bandeja
5. Painel: comando remoto Desligar/Ligar (situação do hotspot)
6. Reinicie o PC; serviço deve subir automaticamente e sync retomar

### Comandos úteis (PowerShell admin)

```powershell
Get-Service WiFiDaLojaAgent
Get-Content "$env:ProgramData\WiFiDaLoja\status.json" | ConvertFrom-Json
Get-Content "$env:ProgramData\WiFiDaLoja\logs\agent-$(Get-Date -Format yyyyMMdd).log" -Tail 20
```

### Modo console (debug)

```powershell
cd "C:\Program Files\WiFiDaLoja"
.\WiFiDaLojaAgent.exe --console
```

## Migração da v1.x

A instalação v2 remove a tarefa agendada `HotspotLoja` (PowerShell) e substitui pelo serviço.
Reinstale o setup como administrador; `cloud.json` e dados em `%ProgramData%\WiFiDaLoja` são preservados.
