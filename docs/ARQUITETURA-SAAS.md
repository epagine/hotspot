# Arquitetura SaaS (resumo)

Evolução do monólito procedural para multi-tenant.

## Painéis

| URL | Público |
|-----|---------|
| `/` | Landing comercial |
| `/comecar` | Cadastro + trial 14 dias |
| `/entrar` | Login unificado |
| `/app` | Painel da empresa (RBAC) |
| `/super` | Super Admin da plataforma |
| `/portal/{token}` | Captive portal personalizado (v2) |
| `/wifi`, `/` (LAN) | Redirecionam para `/portal/{token}` |
| `/api/v1/*` | API para equipamentos |
| `/cliente` | Portal de assinatura (empresa ou loja) |

## Rotas `/admin` (compatibilidade)

Todas redirecionam para os painéis SaaS. POSTs legados ainda aceitos onde necessário:

| Legado | Destino |
|--------|---------|
| `/admin`, `/admin/clientes` | `/app?tab=hotspots` |
| `/admin/entrar`, `/admin/login` | `/entrar` |
| `/admin/sair` | `/sair` |
| `/admin/instalador` | `/super?tab=instalador` |
| `/admin/financeiro` | `/cliente` |
| `/admin/assinaturas` | `/super?tab=assinaturas` ou `/app?tab=assinatura` |
| `/admin/configuracoes/*` | `/super?tab=configuracoes` |

Handlers canônicos: `/super/pagseguro`, `/super/politicas`, `/super/whatsapp`, `/super/instalador`.

## Assinatura

- **Empresa (SaaS):** tabela `subscriptions` + cobrança em `/app?tab=assinatura`
- **Loja (legado):** cobrança via `/cliente` (`wl-{id}-…` quando aplicável)
- Configuração de pagamentos: `/super?tab=configuracoes&sec=integracao`
- **PicPay (alternativo):** OAuth + `/ecommerce/v2/payments`; webhook `/notificacoes/picpay`
- Provedor ativo: `payment_provider` (`pagseguro` ou `picpay`)
- Cobrança automática: cron gera links; cliente paga manualmente; webhook renova vigência

## WhatsApp (Evolution API)

- Configuração: `/super?tab=configuracoes&sec=whatsapp`
- Envio via `POST /message/sendText/{instancia}` (Evolution API)
- Templates por evento: cobrança, pagamento, trial, trial acabando, atraso, suspensão
- Log de envios: tabela `message_log`
- Telefone: `companies.whatsapp` (SaaS) ou `stores.contact` (legado)

## Suspensão e hotspot

Quando a assinatura da empresa fica **suspensa** (ou empresa `blocked`):

1. `company_sync_hotspots()` desliga hotspots (`active = 0`) e enfileira comando `stop` no agente
2. Portal `/portal/{token}` exibe “Wi-Fi indisponível”
3. Sync do agente (`/agente/sincronizar`) reforça o estado a cada heartbeat

Reativação ocorre ao pagar ou regularizar a assinatura (`company_sync_hotspots` + comando `start`).

## Banco

- Driver: **MySQL / MariaDB** (SQLite removido)
- Instalação: `/instalar` — host/porta/banco/usuário/senha (cria o database se não existir)
- Config gerada em `app/config.php`
- Opcional: `.env` com `DB_DRIVER=mysql`
- Migrations versionadas em `app/migrations/`
- Helpers: `db_driver()`, `db_upsert_sql()`, `db_column_names()`, `db_ensure_index()`
- Migrations automáticas: `run_migrations()` em cada `db()` + CLI `scripts/migrate.php` + Super → Sistema
- Detalhes: [MIGRATIONS.md](MIGRATIONS.md)
- Tenant: `company_id` em stores/clients/sessions/campaigns

## NetworkProviders

`app/Integrations/NetworkProviders/providers.php`

- `windows` (MVP ativo)
- `mikrotik`, `openwrt`, `unifi` (stubs Fase 5)

## Limites de plano

- Hotspots, clientes cadastrados e usuários da empresa
- Validação no backend (portal, sync do agente, convite de usuário)
- Uso visível no `/app` (dashboard, abas e assinatura)
- `0` no plano = ilimitado

## Recursos por plano

Features em `plans.features_json`:

| Feature | Gratuito | Essencial | Profissional | Empresa |
|---------|----------|-----------|--------------|---------|
| `stats_basic` | ✓ | | | |
| `stats` | | ✓ | ✓ | ✓ |
| `portal` | | ✓ | ✓ | ✓ |
| `campaigns` | | | ✓ | ✓ |
| `coupons` | | | ✓ | ✓ |
| `reports` | | | | ✓ |

Validação via `company_has_feature()` no `/app` e nos handlers POST.

## Relatórios (plano Empresa)

Aba `/app?tab=relatorios` (feature `reports`):

- Período 7 / 30 / 90 dias
- KPIs: acessos, média/dia, clientes únicos, novos, duração média, CTR
- Gráficos: acessos por dia, horários de pico
- Rankings: hotspots, dispositivos/SO, clientes frequentes
- Tabelas: campanhas (views/clicks/CTR) e cupons emitidos/usados
- Export CSV: acessos, clientes, campanhas

## Instalador Windows

| Pacote | Comando | Uso |
|--------|---------|-----|
| **Agente cloud** | `installer\Empacotar-Cloud.ps1` | Produção SaaS (~40 KB) — bandeja, hotspot, `DnsProxy.exe`, sync |
| **Completo** | `installer\Empacotar.ps1` | Dev/Laragon (~40 MB) — PHP embarcado + painel local |

Saídas: `WiFiDaLoja-Agent-Setup.exe`, `WiFiDaLoja-Setup.exe` (cópia em `storage/downloads/`).

Modo cloud: sem PHP/MySQL local; DNS cativo via `DnsProxy.exe`; portal em `/portal/{token}`.

## Migração de lojas legadas

No Super Admin → **Empresas**, a seção **Lojas legadas (sem empresa)** lista hotspots com `company_id` vazio:

- **Vincular** — associa a uma empresa existente (respeita limite de hotspots do plano)
- **Promover** — cria empresa + admin + assinatura a partir da loja (copia vigência quando houver)

Clientes da loja recebem o `company_id` no vínculo.

## Próximas etapas

1. Fase 5 — integrações MikroTik / OpenWrt / UniFi
2. Feature `multi_unit` (filiais / unidades)
3. Commit/push das integrações recentes (PicPay, WhatsApp, suspensão, relatórios)
