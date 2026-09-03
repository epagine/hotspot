# Wi-Fi da loja

Plataforma SaaS para pequenas empresas criarem e gerenciarem **hotspots / captive portals**: cadastro de clientes, marketing, assinaturas e cobrança online.

O **painel** fica na internet (multi-tenant). O **ponto de acesso Wi-Fi** continua no PC Windows de cada loja.

Repositório: [github.com/epagine/hotspot](https://github.com/epagine/hotspot)

| Documentação | Conteúdo |
|--------------|----------|
| [docs/PADROES-PROGRAMACAO.md](docs/PADROES-PROGRAMACAO.md) | Padrões de código PHP |
| [docs/ARQUITETURA-SAAS.md](docs/ARQUITETURA-SAAS.md) | Arquitetura SaaS, rotas e integrações |

## Como funciona

```
Cliente no Wi-Fi  →  portal /portal/{token}  →  internet liberada
Dono / equipe     →  painel HTTPS (/app)     →  hotspots, clientes, campanhas
PC da loja        →  agente Windows          →  /agente/sincronizar
Plataforma        →  Super Admin (/super)    →  empresas, planos, pagamentos
```

- **Empresa (tenant):** trial, planos, hotspots, clientes, campanhas, cupons, relatórios.
- **PC da loja:** hotspot Windows (Ponto de acesso móvel), DNS cativo, portal local.
- **Assinatura:** cobrança automática via cron (link PagSeguro ou PicPay); renovação por webhook.

Não suba o hotspot para a hospedagem — lá vai só o painel PHP. Branch principal: **`master`**.

---

## Painéis e URLs

| URL | Público |
|-----|---------|
| `/` | Landing comercial |
| `/comecar` | Cadastro + trial |
| `/entrar` | Login unificado |
| `/app` | Painel da empresa (RBAC) |
| `/super` | Super Admin da plataforma |
| `/portal/{token}` | Captive portal (nome, WhatsApp, termos) |
| `/cliente` | Portal de assinatura e pagamentos |
| `/agente/sincronizar` | Sync do agente Windows |

Rotas legadas `/admin/*` redirecionam para `/app` ou `/super`.

---

## 1. Painel na hospedagem (produção)

PHP 8.1+ com PDO MySQL (**recomendado 8.3+**; 8.1 está fora de suporte de segurança). **HTTPS obrigatório** (webhooks de pagamento e sync do agente).

Defina `APP_URL=https://seu-dominio` no `.env` ou em Super → Configurações → Sistema (URL canônica). O status do agente aceita o header `X-Agent-Token` (o query `token` ainda funciona). Headers CSP permitem o Tailwind CDN; um build local com SRI fica para uma etapa futura.

### O que enviar

| Enviar | Função |
|--------|--------|
| `index.php`, `.htaccess` | Entrada HTTP |
| `app/` | PHP, migrations, schema |
| `public/` | CSS e JS |
| `storage/` | Pasta vazia e gravável |
| `.gitignore`, `README.md` | Referência |

**Não envie:** `installer/`, `scripts/`, `bin/`, `dist/`, `runtime/`, `*.exe`, `app/config.php`, dados em `storage/`.

O instalador Windows (`WiFiDaLoja-Setup.exe`) **não vai no Git**. Publique em **Super → Instalador** ou copie para `storage/downloads/`.

```bash
git clone -b master https://github.com/epagine/hotspot.git
chmod -R u+rwX storage
```

Extensões PHP: `pdo_mysql`, `gd`, `curl`, `session`.

### Banco de dados

O painel usa **apenas MySQL / MariaDB**. Na instalação (`/instalar`) informe host, porta, banco, usuário e senha. O database é criado automaticamente (`utf8mb4`) se ainda não existir.

**Atualizações futuras:** ao subir código novo, o schema atualiza sozinho na próxima requisição. Opcional no deploy:

```bash
php scripts/migrate.php
php scripts/migrate.php status
php scripts/migrate.php make minha_alteracao
```

Detalhes em [`docs/MIGRATIONS.md`](docs/MIGRATIONS.md). Super Admin → **Configurações → Sistema**.

Alternativa: `.env` com `DB_DRIVER=mysql` **ou** edite `app/config.php` após o install.

### Primeiro acesso

1. Abra `https://seudominio.com/instalar`.
2. Configure o MySQL e a conta de Super Admin (e-mail + senha).
3. Entre em `/entrar` e continue em `/super` (planos, pagamentos, WhatsApp).
4. Empresas se cadastram em `/comecar` ou você cria em **Super → Empresas**.

### Integrações (Super → Configurações)

| Seção | Função |
|-------|--------|
| **Pagamentos** | PagSeguro/PagBank ou PicPay; cron de cobrança automática |
| **WhatsApp** | Evolution API; templates por evento (cobrança, trial, atraso…) |
| **Políticas** | Trial, tolerância, suspensão automática |

**Cron diário** (URL exibida em Pagamentos): gera cobranças e reconcilia assinaturas.

**Webhooks:**

- PagSeguro: `/notificacoes/pagbank`
- PicPay: `/notificacoes/picpay`

---

## 2. PC da loja (Windows 10/11)

Requisitos: administrador, adaptador Wi-Fi, internet (preferência Ethernet), [VC++ Redistributable x64](https://learn.microsoft.com/pt-br/cpp/windows/latest-supported-vc-redist) se necessário.

### Instalação

1. Gere o setup (seção 5) ou baixe pelo Super Admin.
2. Execute **`WiFiDaLoja-Setup.exe`** como administrador.
3. Informe **URL do painel** (`https://seudominio.com`) e **token** do hotspot (`/app?tab=hotspots`).
4. Ícone na bandeja: status da licença, links do painel, ligar/desligar rede.

Arquivo local: `storage\cloud.json` (URL + token).

### Portal

- LAN redireciona para `/portal/{token}`.
- Cliente se identifica (nome, WhatsApp, termos); campanhas aparecem no portal v2.
- Assinatura suspensa **bloqueia** o portal e envia comando `stop` ao agente.

---

## 3. Painel da empresa (`/app`)

| Aba | Recurso |
|-----|---------|
| Dashboard | KPIs e gráfico de acessos |
| Empresa | Dados, WhatsApp, cores |
| Hotspots | CRUD, token, portal |
| Clientes | Cadastros do Wi-Fi |
| Campanhas / Cupons | Marketing (por plano) |
| Relatórios | Gráficos, CTR, export CSV (plano Empresa) |
| Assinatura | Plano, cobrança, pagamento |
| Usuários | RBAC por empresa |

Limites por plano: hotspots, clientes cadastrados, usuários.

---

## 4. Super Admin (`/super`)

- Empresas, planos, assinaturas, usuários, logs
- Migração de **lojas legadas** (vincular ou promover a empresa)
- Publicar instalador `.exe`
- Configurar PagSeguro, PicPay, Evolution API e mensagens

---

## 5. Gerar o Setup.exe

```powershell
powershell -ExecutionPolicy Bypass -File installer\Empacotar.ps1
```

Saída: `dist\WiFiDaLoja-Setup.exe` (+ cópia em `storage/downloads/` para download pelo Super).

Compilar só auxiliares (bandeja, instalar):

```powershell
powershell -ExecutionPolicy Bypass -File installer\compilar.ps1
```

---

## 6. Desenvolvimento local (Laragon)

1. Clone em `C:\laragon\www\hotspot`.
2. PHP 8.1+, MySQL (Laragon), GD, curl.
3. Acesse `http://hotspot.test/instalar` (ou `http://127.0.0.1:8080/instalar`).
4. MySQL: host `127.0.0.1`, usuário `root`, banco `wifidaloja` (criado automaticamente).
5. Agente: ícone da bandeja ou `scripts\agente-hotspot.ps1` (administrador).

---

## 7. Problemas comuns

| Problema | Verificar |
|----------|-----------|
| Loop em `/instalar` | `storage/` gravável; `app/config.php` criado |
| Loja não sincroniza | Token, HTTPS, URL sem barra final, agente na bandeja |
| Webhook não confirma pagamento | HTTPS público; URL correta no provedor |
| Hotspot não liga | Wi-Fi desocupado; Ponto de acesso móvel ligado 1× manualmente |
| Portal não abre | Celular no Wi-Fi da loja; DNS cativo (porta 53) no PC |

---

## Licença e dados

Não versionar: `app/config.php`, tokens e credenciais reais. Faça backup do MySQL em produção.
