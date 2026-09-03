# Padrões de programação — Wi-Fi da loja

Documento de referência para manter consistência no código PHP, nas rotas, no painel admin e nas integrações com o agente Windows.

**Público:** desenvolvedores e assistentes de IA que alteram este repositório.  
**Stack:** PHP 8.1+ procedural, MySQL, HTML/CSS/JS vanilla, sem framework.

---

## 1. Princípios

1. **Escopo mínimo** — altere só o necessário; não refatore áreas não relacionadas.
2. **Procedural, não OOP** — funções em arquivos de módulo; sem classes, sem autoloader.
3. **Uma fonte de verdade por conceito** — URLs em `admin_url()`, status de assinatura em `subscription.php`, lojas em `stores.php`.
4. **Separação de domínios no painel:**
   - **Clientes** — loja, PC, contrato operacional (`active`, token, conexão).
   - **Assinaturas** — plano, vigência, situação financeira, trial, suspensão.
   - **Financeiro** — cobranças, links PagSeguro, recebimentos.
   - **Configurações** — conta do painel, integração PagSeguro, políticas SaaS.
5. **Texto ao usuário em português (BR)**; identificadores de código em inglês.
6. **POST-redirect-GET** — mutações nunca renderizam HTML; redirecionam com flash na sessão.
7. **Compatibilidade** — rotas legadas em inglês e query strings antigas devem redirecionar (301) para URLs amigáveis.

---

## 2. Estrutura de diretórios

```
index.php              # Front controller — único ponto de entrada HTTP
app/
  helpers.php          # Núcleo: DB, settings, auth, utilitários; carrega módulos
  stores.php           # Lojas, migrações, agente, heartbeat, clientes Wi-Fi
  subscription.php     # Ciclo de vida SaaS (trial, grace, suspensão)
  pagseguro.php        # PagBank/PagSeguro, cobranças, webhooks, cron
  schema.sql           # Schema inicial (somente instalação nova)
  config.php           # Gerado no install — NÃO versionar
  pages/               # Páginas HTML e handlers POST do admin
  api/                 # Endpoints JSON (agente, portal, status)
public/assets/         # app.css, admin.js, portal.js (servidos via router)
storage/               # imagens, downloads, logs — NÃO versionar dados
scripts/               # Agente PowerShell — só ambiente Windows local
installer/             # Instalador C# — não vai para hospedagem
```

| Pasta | Vai para hospedagem? |
|-------|----------------------|
| `app/`, `public/`, `index.php`, `.htaccess`, `storage/` (vazia) | Sim |
| `scripts/`, `installer/`, `bin/`, `dist/` | Não |

---

## 3. Convenções PHP

### Cabeçalho obrigatório

Todo arquivo `.php` deve iniciar com:

```php
<?php

declare(strict_types=1);
```

### Nomenclatura

| Elemento | Padrão | Exemplo |
|----------|--------|---------|
| Funções | `snake_case` | `find_store()`, `subscription_transition()` |
| Variáveis | `snake_case` | `$store_id`, `$billing_status` |
| Colunas/tabelas SQL | `snake_case` | `paid_until`, `store_settings` |
| Chaves de setting | `snake_case` | `admin_pass_hash`, `saas_grace_days` |
| Sessão | `snake_case` | `$_SESSION['flash_ok']` |
| Ação de formulário | campo `do` | `create`, `save`, `charge`, `rotate` |

### Organização de código

| Tipo | Onde colocar |
|------|--------------|
| Lógica de negócio reutilizável | `app/{modulo}.php` |
| Renderização + layout admin | `app/pages/admin.php` |
| Handler POST (sem HTML) | `app/pages/admin-{recurso}.php` |
| API JSON | `app/api/{nome}.php` |
| Roteamento | `index.php` |

**Proibido:** duplicar regra de negócio dentro de templates; colocar SQL complexo em `admin.php`.

### Tipagem e retornos

- Parâmetros e retorno tipados sempre que possível.
- Use `match`, `str_starts_with`, null coalescing — PHP 8.1+.
- Lance `RuntimeException` para erros de domínio; capture no handler POST e converta em `flash_error`.

### Saída HTML

```php
<?= h($variavel) ?>   // sempre escapar texto dinâmico
```

Função `h(?string $value): string` — `htmlspecialchars` com `ENT_QUOTES | ENT_SUBSTITUTE`.

### Saída JSON

```php
json_out(['ok' => true, 'data' => $payload], 200);
json_out(['ok' => false, 'error' => 'token'], 401);
```

---

## 4. Roteamento e URLs

### Front controller

- Normalizar path: `parse_url(..., PHP_URL_PATH)`.
- Gate de instalação: se não instalado, redirecionar para `/instalar` (exceto assets).
- `switch (true)` com casos ordenados — rotas mais específicas antes das genéricas.

### URLs amigáveis (canônicas)

| Área | URL |
|------|-----|
| Admin home | `/admin/clientes` |
| Ficha cliente | `/admin/clientes/{id}` |
| Assinaturas | `/admin/assinaturas`, `/admin/assinaturas/{id}` |
| Financeiro | `/admin/financeiro`, `/admin/financeiro/{id}` |
| Configurações | `/admin/configuracoes/conta`, `.../integracao`, `.../politicas` |
| Login / logout | `/admin/entrar`, `/admin/sair` |
| Agente | `/agente/sincronizar` |
| Portal | `/wifi`, `/confirmar` |
| Webhook | `/notificacoes/pagbank` |
| Cron | `/cron/pagseguro/{key}` |

### Construção de links

**Sempre** use `admin_url()` — nunca hardcode `/admin?tab=...`:

```php
admin_url('clientes', $id);
admin_url('assinaturas', $id);
admin_url('financeiro', $lojaId);
admin_url('configuracoes', 0, 'integracao');
admin_url('entrar');
```

### Padrão GET vs POST

```
GET  /admin/recurso/{id}  →  index.php define $_GET['tab'], require admin.php
POST /admin/recurso/{id}  →  index.php define $GLOBALS['route_id'], require admin-{recurso}.php
```

- GET indevido em rota POST-only → redirect 301 para a página GET equivalente.
- Legacy `?tab=&sec=&id=` → `admin_legacy_url()` + redirect 301.

### Injeção de ID na rota

Quando o POST vem de URL amigável sem `id` no body:

```php
$id = (int) ($_POST['id'] ?? $GLOBALS['route_id'] ?? 0);
```

O router preenche `$GLOBALS['route_id']` antes do `require`.

---

## 5. Banco de dados (MySQL)

### Conexão

- Singleton via `db(): PDO` (apenas MySQL/MariaDB).
- Em toda conexão: `migrate_multi_store()` + **`run_migrations()`** (automático).

### Migrations versionadas (preferencial)

Arquivos em `app/migrations/NNN_nome.php`. Ver [MIGRATIONS.md](MIGRATIONS.md).

```bash
php scripts/migrate.php           # aplica pendentes
php scripts/migrate.php status
php scripts/migrate.php make nome
```

Super Admin → Configurações → Sistema.

### Schema legado (ensure_*)

1. **Instalação nova** — `ensure_core_schema()` + migrations (instalador).
2. **Bancos antigos** — `ensure_*` com `db_column_names()` + `db_add_column()`.

Regra para mudanças novas:

```php
// Preferir: nova migration em app/migrations/
// Opcional: espelhar coluna em ensure_core_schema só se for core de install
```

### Settings (key-value)

| Escopo | Tabela | Funções |
|--------|--------|---------|
| Dono / global | `settings` | `setting()`, `set_setting()` + `is_owner_key()` |
| Por loja | `store_settings` | `setting()` com `current_store_id()` |

Upsert padrão:

```sql
INSERT INTO ... ON CONFLICT(...) DO UPDATE SET v = excluded.v
```

### Consultas

- Sempre prepared statements (`?`).
- `PDO::FETCH_ASSOC` — arrays associativos, sem ORM.
- Datas em ISO: `Y-m-d` (vigência) ou `Y-m-d H:i:s` (eventos).

---

## 6. Autenticação e contexto

### Admin

```php
require_admin();  // redirect para /admin/entrar se !$_SESSION['admin']
```

- Login: `password_verify` contra `admin_pass_hash` em settings.
- Sessão: flag booleana `$_SESSION['admin'] = true` (conta única do dono).
- Logout: limpar sessão em `/admin/sair`.

### Contexto de loja

Ordem de resolução em `current_store_id()`:

1. `$GLOBALS['force_store_id']` — APIs (agente)
2. `$_SESSION['store_id']` — admin logado
3. `local_store_id()` — PC local via `storage/cloud.json`

### Agente

- Token no JSON POST → `find_store_by_token()`.
- Resposta 401 se token inválido.
- Definir `$GLOBALS['force_store_id']` antes de ler settings da loja.

### Flash messages

| Chave | Uso |
|-------|-----|
| `flash_ok` | Sucesso (`.hint.flash-ok`) |
| `flash_error` | Erro (`.alert.flash-global`) |
| `flash_pay_url` | Link de cobrança gerado |

Consumir e `unset` em `admin.php` — uma exibição por redirect.

---

## 7. Módulos de domínio

### `stores.php` — operacional

Responsável por:

- CRUD de lojas (dados operacionais: nome, cidade, contato, `active`).
- Token do agente, comandos remotos (`start`/`stop`).
- Heartbeat, saúde de conexão, clientes autorizados no Wi-Fi.
- Migrações base e overview operacional (`saas_overview()`).

**Não** colocar aqui: transições de assinatura, chamadas PagSeguro.

### `subscription.php` — SaaS

Responsável por:

- Status: `trial`, `ativa`, `pendente`, `atrasada`, `suspensa`, `cortesia`, `cancelada`, `encerrada`.
- Normalização de legado (`em_dia` → `ativa`, etc.).
- Trial em loja nova, grace period, suspensão automática.
- Timeline em `subscription_events`.
- Sincronizar contrato → `queue_store_command()` liga/desliga PC.

Políticas globais (settings):

- `saas_trial_days` (padrão 7)
- `saas_grace_days` (padrão 3)
- `saas_auto_suspend` (padrão ligado)

Campo `monthly_fee` = **valor do ciclo** (não mensal × meses).

### `pagseguro.php` — pagamentos

Responsável por:

- OAuth/API PagBank, criar cobranças, webhook.
- Tabela `payments`.
- Cron de cobrança automática.
- Hooks: `subscription_on_charge_created()`, `subscription_on_payment_paid()`.

### Fluxo entre módulos

```
pagseguro_create_charge()
  → subscription_on_charge_created()
webhook / cron confirma pagamento
  → subscription_on_payment_paid()
  → subscription_transition('ativa')
  → subscription_sync_contract()
  → queue_store_command('start'|'stop')
agente poll /agente/sincronizar
  → executa comando no PC
```

**Regra:** módulos chamam funções públicas uns dos outros; não acoplar SQL de um módulo dentro de outro.

---

## 8. Painel admin (UI)

### Shell único

`app/pages/admin.php` — sidebar + conteúdo por `$tab`:

- `clientes`, `assinaturas`, `financeiro`, `instalador`, `configuracoes`

Sub-seções via `$cfgSec` ou filtros (`$subFilter` em assinaturas).

### Handlers POST

Arquivo dedicado por recurso mutável:

| Handler | Rotas POST |
|---------|------------|
| `admin-stores.php` | clientes |
| `admin-subscription.php` | assinaturas |
| `admin-pagseguro.php` | financeiro (charge), integração |
| `admin-save.php` | conta |
| `admin-policies.php` | políticas SaaS |

Esqueleto obrigatório:

```php
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('recurso'));
    exit;
}

// validar → mutar → $_SESSION['flash_*'] → redirect → exit
```

### Formulários

```html
<form method="post" action="<?= h(admin_url('assinaturas', $id)) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="do" value="save">
    <button type="submit" name="do" value="save">Salvar</button>
</form>
```

Classes CSS do projeto:

- Layout: `.app`, `.app-side`, `.app-main`, `.app-subnav`
- Conteúdo: `.card`, `.form`, `.form-grid`, `.stats`, `.saas-table`
- Estado: `.tag`, `.tag.online`, `.tag.blocked`, `.btn`, `.btn.ghost`

### Metadados de página

```php
$pageTitle = match (true) { ... };
$pageLead  = match (true) { ... };
```

Textos explicativos em `$pageLead`, não espalhados no HTML.

---

## 9. APIs JSON

Local: `app/api/`.

| Endpoint | Método | Auth |
|----------|--------|------|
| `/agente/sincronizar` | POST JSON | token da loja |
| `/admin/estado` | GET | sessão admin |
| `/confirmar` | POST | portal (LAN) |

Respostas sempre via `json_out()`. Erros com `{ "ok": false, "error": "codigo" }` e HTTP status adequado.

---

## 10. Frontend

### Assets

| Arquivo | Escopo |
|---------|--------|
| `public/assets/app.css` | Portal + admin + auth (variáveis `--app-*` no admin) |
| `public/assets/admin.js` | Poll `/admin/estado`, atualização KPI |
| `public/assets/portal.js` | Portal cativo, `/confirmar` |

Sem bundler, sem framework JS. `fetch()` nativo.

### Paths no JS

Preferir URLs canônicas (`/admin/estado`, `/confirmar`, `/arte/{code}.png`). Se alterar rota PHP, atualizar JS correspondente.

### Escapamento

- Servidor: `h()`
- Cliente (`admin.js`): função local `esc()` para HTML dinâmico

---

## 11. Agente e instalador

### Arquivos locais (`storage/`)

| Arquivo | Função |
|---------|--------|
| `cloud.json` | URL do painel + token |
| `command.json` | Comando imediato ao hotspot |
| `status.json` | Estado reportado pelo agente |
| `authorized.json` | MACs autorizados |

### Contrato agente ↔ painel

POST `/agente/sincronizar` com body JSON:

```json
{
  "token": "...",
  "status": {},
  "clients": [],
  "ack_command_id": "..."
}
```

Resposta inclui `config`, `command`, `authorized`, `patches`.

Scripts PowerShell em `scripts/` — não referenciar do código PHP em produção na hospedagem.

---

## 12. Segurança — práticas atuais e melhorias

### O que o sistema faz hoje

- Senha admin com `password_hash` / `password_verify`
- Escape HTML sistemático
- Token por loja para agente
- HTTPS esperado em produção (webhook PagBank)

### Limitações conhecidas (documentar, não ignorar)

- Sem CSRF token nos formulários admin
- Conta admin única, sem RBAC
- Token do agente sem rotação automática nem expiração

### Ao adicionar features sensíveis

- Nunca commitar `app/config.php`, tokens reais
- Validar e sanitizar todo input POST/JSON
- Redirecionar após mutação (evita re-submit)
- Cron e webhooks: validar chave/assinatura quando disponível

---

## 13. Checklists

### Nova seção no admin

- [ ] Casos GET e POST em `index.php`
- [ ] Entrada em `admin_url()` e, se legacy, `admin_legacy_url()`
- [ ] Link na sidebar ou subnav em `admin.php`
- [ ] Handler POST em `app/pages/admin-{nome}.php`
- [ ] Lógica de domínio no módulo correto (`stores`, `subscription`, `pagseguro`)
- [ ] Flash + redirect
- [ ] `$pageTitle` / `$pageLead`

### Nova coluna / tabela no banco

- [ ] Criar migration: `php scripts/migrate.php make descricao`
- [ ] DDL idempotente (`db_type_map`, `db_add_column`)
- [ ] Rodar `php scripts/migrate.php` localmente
- [ ] Leitura/escrita no módulo de domínio
- [ ] Documentar em [MIGRATIONS.md](MIGRATIONS.md) se mudar o fluxo

### Nova rota pública/API

- [ ] Caso em `index.php` (antes do `default` do portal)
- [ ] Arquivo em `app/pages/` ou `app/api/`
- [ ] `declare(strict_types=1);`
- [ ] Documentar URL neste arquivo e no README se for endpoint externo

### Nova integração de pagamento

- [ ] Isolar em módulo dedicado (como `pagseguro.php`)
- [ ] Hooks para `subscription.php` — não alterar status da loja direto no webhook
- [ ] Settings em `is_owner_key()` se for global

---

## 14. Glossário de domínio

| Termo | Significado |
|-------|-------------|
| Loja / Cliente | Unidade SaaS (`stores`) — um PC com hotspot |
| Assinatura | Plano + vigência + status financeiro da loja |
| Trial | Período gratuito inicial (`billing_status = trial`) |
| Grace | Dias de tolerância após vencimento antes de suspender |
| Valor do ciclo | Valor cobrado por período do plano (campo `monthly_fee`) |
| Serviço ligado | `active = 1` e hotspot permitido pelo contrato |
| Agente | Script Windows que sincroniza PC com painel |
| Token | Segredo da loja para autenticar o agente |
| Owner | Dono do painel (settings globais) |

---

## 15. Referências no repositório

- [README.md](../README.md) — deploy, instalação, troubleshooting
- [app/schema.sql](../app/schema.sql) — schema inicial
- [.cursor/rules/](../.cursor/rules/) — regras resumidas para assistentes de IA

---

*Última revisão: alinhada à arquitetura Clientes / Assinaturas / Financeiro / Configurações.*
