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

Handlers canônicos: `/super/pagseguro`, `/super/politicas`, `/super/instalador`.

## Assinatura

- **Empresa (SaaS):** tabela `subscriptions` + PagSeguro (`wlc-{id}-…`) em `/app?tab=assinatura`
- **Loja (portal):** cobrança via `/cliente` (PagSeguro `wl-{id}-…` quando aplicável)
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
