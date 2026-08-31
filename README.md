# Wi-Fi da loja

Sistema de hotspot para loja: o PC Windows compartilha a internet por Wi-Fi. O cliente só navega depois de publicar um status no WhatsApp com um código da visita.

O **painel** pode ficar na internet (várias lojas). O **ponto de acesso** continua no PC de cada loja.

Repositório: [github.com/epagine/hotspot](https://github.com/epagine/hotspot)

**Padrões de código:** [docs/PADROES-PROGRAMACAO.md](docs/PADROES-PROGRAMACAO.md)

## Como funciona

```
Celular do cliente  →  Wi-Fi do PC da loja  →  portal local (código + arte)
Dono (casa/celular) →  painel HTTPS na hospedagem  →  comandos para o PC
PC da loja          →  agente Windows  →  /agent/sync no painel
```

- **Painel central:** login, lojas, tokens, SSID, imagem, clientes, Ligar/Desligar remoto.
- **PC da loja:** hotspot do Windows (Ponto de acesso móvel), DNS cativo, portal em `192.168.137.1`.
- O Windows **não** coloca foto no seletor de redes. A imagem da loja aparece no portal e na arte do status.

Não suba o hotspot para a hospedagem. Lá vai só o painel PHP. Use o branch **`master`**.

---

## 1. Painel na hospedagem (produção)

Use um VPS ou hospedagem com PHP 8.1+ (PDO SQLite ou, no mínimo, permissão de escrita). HTTPS é obrigatório para as lojas falarem com o painel pela internet.

### O que enviar (do master)

Envie **apenas** estes caminhos:

| Enviar | Função |
|--------|--------|
| `index.php` | Entrada do site |
| `.htaccess` | Apache (se a hospedagem usar Apache) |
| `app/` | PHP do painel, API do agente, schema |
| `public/` | CSS e JavaScript |
| `storage/` | Só a pasta (e `.gitkeep` / `downloads/.gitkeep`). Vazia, gravável. |
| `.gitignore` | Evita versionar senha e SQLite |
| `README.md` | Este guia |

**Não envie:**

- `installer/`, `scripts/`, `bin/`
- `*.exe`, `*.bat`, `HotspotBandeja.exe`
- `app/config.php` (criado em `/install`)
- `storage/hotspot.sqlite`, `storage/brand/`, `storage/cloud.json`
- `runtime/`, `dist/`

O instalador Windows **não entra no Git** (~32 MB). Depois do painel no ar, publique o `.exe` na aba **Clientes** (enviar arquivo) ou copie `WiFiDaLoja-Setup.exe` para `storage/downloads/`. O dono baixa pelo botão **Baixar WiFiDaLoja-Setup.exe**.

O DocumentRoot deve apontar para a pasta que contém `index.php`.

```bash
git clone -b master https://github.com/epagine/hotspot.git
```

Na hospedagem você pode apagar `installer/`, `scripts/` e `bin/` se tiver clonado o master inteiro.

### Permissões

A pasta `storage/` precisa ser gravável pelo PHP (banco, imagem, logs).

```bash
chmod -R u+rwX storage
```

Extensões PHP: `pdo_sqlite`, `gd` (arte do status e upload de logo), `session`.

### Primeiro acesso

1. Abra `https://seudominio.com/install` (ou a URL do site).
2. Preencha nome da loja, cidade, SSID, senha do Wi-Fi (mínimo 8 caracteres), usuário e senha do painel.
3. Opcional: imagem da conexão (PNG, JPG ou WEBP, até 3 MB).
4. Entre em **Configuração** e **Lojas**.

Anote o endereço público (`https://seudominio.com`) e o **token** de cada loja (aba Lojas). Isso vai no instalador de cada PC.

Se o site estiver em subpasta (`https://seudominio.com/hotspot/`), configure o virtual host ou o rewrite para a raiz da aplicação; o agente usa a URL que você colar, sem barra no final.

### Firewall do servidor

Só a porta 443 (HTTPS). Os PCs das lojas **saem** na internet; não precisam de IP fixo nem de porta aberta na loja.

---

## 2. PC da loja (Windows 10/11)

Cada loja precisa de:

- Conta **administrador**
- Adaptador **Wi-Fi** (USB ou interno). O hotspot usa o **Ponto de acesso móvel** do Windows, não o `hostednetwork` antigo.
- Internet no PC (de preferência **cabo/Ethernet**). VPN/loopback (ex.: Topaz) não pode ser a conexão compartilhada.
- [Visual C++ Redistributable 2015–2022 (x64)](https://learn.microsoft.com/pt-br/cpp/windows/latest-supported-vc-redist) se o PHP empacotado não abrir.

Máximo **8** aparelhos na rede.

### Instalador completo (recomendado)

1. No PC de desenvolvimento, gere o `.exe` (veja [Gerar o Setup.exe](#5-gerar-o-setupexe)).
2. Copie **`WiFiDaLoja-Setup.exe`** para a loja (pendrive ou download). Não use o `Instalar-Hotspot.exe` da pasta do projeto: ele só funciona se o código já estiver naquela máquina.
3. Clique com o botão direito → **Executar como administrador**.
4. Pasta padrão: `C:\Program Files\WiFiDaLoja`.
5. Se **esta máquina não é o servidor do painel**:
   - **Painel central:** `https://seudominio.com` (sem `/` no final)
   - **Token da loja:** o código hex da aba Lojas
6. Se o painel **é este mesmo PC** (só uma loja, Laragon/local): deixe painel e token em branco e complete `/install` no navegador depois.
7. **Instalar agora**.

Atalho no Desktop, ícone na bandeja (ao lado do relógio) e tarefas no logon.

### Vincular depois da instalação

No ícone da bandeja: botão direito → **Vincular ao painel central** → URL + token.

Arquivo gerado: `storage\cloud.json`.

### Primeira vez no Windows (hotspot)

Se Ligar rede falhar, abra **Configurações → Rede e Internet → Ponto de acesso móvel** e ligue **uma vez** à mão. Depois use o painel ou a bandeja.

O adaptador Wi-Fi deve ficar **Desconectado** de outras redes (ele vira o ponto de acesso). Códigos frequentes do Windows:

| Código | Significado |
|--------|-------------|
| 8 | Wi-Fi ocupado (ainda conectado a uma rede) |
| 10 | Rádio Wi-Fi desligado |
| 3 | Senha do SSID inválida (mínimo 8 caracteres) |

---

## 3. Configurar no painel

Entre em `/admin` (ou `/admin/login`).

### Operação

- **Ligar rede / Desligar rede** — no PC local escreve o comando do agente; em loja remota o agente busca em `/agent/sync`.
- Acompanhe conexões (máx. 8), fila do status, visitas do dia.

### Clientes

- **Liberar** / **Encerrar** / **Bloquear**.
- Modo **balcão:** o cliente confirma o status e o atendente libera pelo código.

### Configuração

- Nome, cidade, texto do status (`{loja}`, `{codigo}`).
- SSID, senha Wi-Fi, IP do portal (padrão `192.168.137.1`).
- Imagem da conexão (portal + arte do WhatsApp).
- Horas de internet após o status.
- Liberação na hora ou só no balcão.
- Usuário/senha do **dono** (vale para o painel inteiro, não por loja).

### Lojas (várias unidades)

1. **Criar loja** (nome e cidade).
2. Copie o **token do agente**.
3. No PC dessa loja, instale o Setup com a URL do painel + token.
4. Use os chips no topo para trocar de loja: operação, clientes e Wi-Fi são por loja.
5. **Novo token** invalida o token antigo; atualize o PC.

O PC precisa alcançar o painel (HTTPS). Teste no navegador do PC da loja: `https://seudominio.com/admin/login`.

---

## 4. Desenvolvimento neste PC (Laragon)

1. Clone em `C:\laragon\www\hotspot` (ou a pasta do site).
2. PHP 8.3 do Laragon, SQLite e GD ligados.
3. Painel: `http://127.0.0.1:8080` (agente/bandeja) ou o host virtual Laragon (`http://hotspot.test`) se o DocumentRoot for esta pasta.
4. Primeira vez: `/install`.
5. Agente: `scripts\agente-hotspot.ps1` ou o ícone da bandeja (administrador).

`Instalar.bat` / `Instalar-Hotspot.exe` só instalam tarefas e firewall **nesta** pasta. Em outro computador use o Setup gerado.

---

## 5. Gerar o Setup.exe

No Windows, com .NET Framework 4 (csc) e o PHP do Laragon para empacotar:

```powershell
powershell -ExecutionPolicy Bypass -File installer\Empacotar.ps1
```

Saída:

- `dist\WiFiDaLoja-Setup.exe`
- cópia na raiz: `WiFiDaLoja-Setup.exe` (não vai para o Git; arquivo grande)

Se `HotspotBandeja.exe` estiver em uso, feche o ícone da bandeja e gere de novo.

Compilar só os `.exe` pequenos (sem PHP):

```powershell
powershell -ExecutionPolicy Bypass -File installer\compilar.ps1
```

---

## 6. Desinstalar no Windows

`Desinstalar-Hotspot.exe` (administrador) ou `scripts\desinstalar-windows.ps1`. Remove tarefas, atalhos e regras de firewall. A pasta do programa pode ser apagada depois.

---

## 7. Problemas comuns

**Painel: “não instalado” em loop** — `storage/` sem permissão de escrita; `app/config.php` não foi criado.

**Loja remota não liga a rede** — token errado; URL com `/` no final ou sem `https`; PC sem internet; agente parado (abra a bandeja). Arquivo `storage\cloud.json` no PC da loja.

**“Nenhum adaptador Wi-Fi”** — dongle desconectado ou driver. Alguns Realtek USB **não** suportam hosted network clássico; use o Ponto de acesso móvel.

**Internet não detectada / sem SSID no painel** — agente parado. Reinicie a bandeja. No painel central, a loja só atualiza enquanto o PC envia `/agent/sync`.

**Cliente não abre o portal** — DNS cativo (porta 53 UDP) e painel 8080 no PC da loja. O celular tem que estar no Wi-Fi da loja, não no 4G.

---

## Licença e dados

O banco e as senhas ficam em `storage/` e `app/config.php` (fora do Git). Faça backup de `storage/hotspot.sqlite` se o painel estiver na hospedagem.
