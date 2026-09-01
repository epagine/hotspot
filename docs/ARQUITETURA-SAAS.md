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
| `/admin/financeiro`, `/admin/assinaturas` | Legado (somente lojas sem empresa) |
| `/cliente` | Portal de assinatura (empresa ou loja legada) |

## Redirecionamentos da migração

| Legado | Novo destino |
|--------|----------------|
| `/admin/clientes` | `/app?tab=hotspots` |
| `/admin/instalador` | `/super?tab=instalador` |
| `/admin/entrar` | `/entrar` |

## Assinatura

- **Empresa (SaaS):** tabela `subscriptions` + PagSeguro (`wlc-{id}-…`) em `/app?tab=assinatura`
- **Loja (legado):** colunas `stores.billing_*` + PagSeguro (`wl-{id}-…`) em `/admin/financeiro`
- Configuração PagSeguro: `/super?tab=configuracoes&sec=integracao`

## Banco

- Driver: SQLite (padrão) ou MySQL via `app/config.php` / `.env`
- Migrations versionadas em `app/migrations/`
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

## Instalador Windows

- Gerar: `powershell -ExecutionPolicy Bypass -File installer\Empacotar.ps1`
- Saída: `dist/WiFiDaLoja-Setup.exe` (cópia em `storage/downloads/` para download pelo Super Admin)
- Inclui PHP embarcado, portal v2 (`/portal/{token}`) e sync com painel SaaS

## Próximas etapas

1. Desativar `/admin` por completo quando não houver lojas legadas
2. Publicar commits (`git push`)
