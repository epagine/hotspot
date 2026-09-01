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
| `/portal/{token}` | Captive portal personalizado |
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

## Próximas etapas

1. Desativar abas legadas restantes em `/admin` (configurações/conta)
2. Reempacotar `WiFiDaLoja-Setup.exe` com código SaaS atual
3. Portal cativo legado `/wifi` → `/portal/{token}` universal
4. Bloquear marketing avançado por `plan_has_feature()` (opcional)
