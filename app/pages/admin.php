<?php

declare(strict_types=1);

require_admin();

if ((int) ($_GET['store'] ?? 0) > 0) {
    select_store((int) $_GET['store']);
}

$tab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? 'operacao')) ?: 'operacao';
if (!in_array($tab, ['operacao', 'clientes', 'config', 'lojas'], true)) {
    $tab = 'operacao';
}

$stores = all_stores();
$storeId = current_store_id();
$data = dashboard_payload();
$k = $data['kpis'];
$hot = $data['hotspot'];
$clients = $data['clients'];
$agentAlive = !empty($hot['agent_alive']);
$hotOn = !empty($hot['hotspot_on']);
$local = !empty($data['local_store']);
$currentStore = find_store($storeId);
$installer = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Instalar-Hotspot.exe';
$qs = 'store=' . $storeId;
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Painel · <?= h($data['store']) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="page admin" data-tab="<?= h($tab) ?>" data-store="<?= (int) $storeId ?>">
<header class="top">
    <div>
        <p class="eyebrow">Painel central</p>
        <h1><?= h($data['store']) ?></h1>
    </div>
    <a class="btn ghost" href="/admin/logout">Sair</a>
</header>

<nav class="store-switch" aria-label="Lojas">
    <?php foreach ($stores as $s): ?>
        <a class="chip <?= (int) $s['id'] === $storeId ? 'active' : '' ?>" href="/admin?tab=<?= h($tab) ?>&amp;store=<?= (int) $s['id'] ?>">
            <?= h((string) $s['name']) ?>
            <?php
            $seen = parse_time_any((string) ($s['last_seen_at'] ?? ''));
            $onlineChip = $seen > (time() - 45);
            ?>
            <small><?= $onlineChip ? 'PC online' : 'PC offline' ?></small>
        </a>
    <?php endforeach; ?>
</nav>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <p class="alert flash-global"><?= h((string) $_SESSION['flash_error']) ?></p>
    <?php unset($_SESSION['flash_error']); ?>
<?php elseif (!empty($_SESSION['flash_ok'])): ?>
    <p class="hint flash-global"><?= h((string) $_SESSION['flash_ok']) ?></p>
    <?php unset($_SESSION['flash_ok']); ?>
<?php endif; ?>

<nav class="tabs" role="tablist">
    <a class="tab <?= $tab === 'operacao' ? 'active' : '' ?>" href="/admin?tab=operacao&amp;<?= $qs ?>">Operação</a>
    <a class="tab <?= $tab === 'clientes' ? 'active' : '' ?>" href="/admin?tab=clientes&amp;<?= $qs ?>">Clientes</a>
    <a class="tab <?= $tab === 'config' ? 'active' : '' ?>" href="/admin?tab=config&amp;<?= $qs ?>">Configuração</a>
    <a class="tab <?= $tab === 'lojas' ? 'active' : '' ?>" href="/admin?tab=lojas&amp;<?= $qs ?>">Lojas</a>
</nav>

<section class="tab-panel <?= $tab === 'operacao' ? 'active' : '' ?>" id="panel-operacao">
    <div class="stats" id="kpi-grid">
        <article><strong id="kpi-slots"><?= h((string) ($k['slots'] ?? '0/8')) ?></strong><span>conexões (máx. 8)</span></article>
        <article><strong id="kpi-online"><?= (int) $k['online'] ?></strong><span>liberados no portal</span></article>
        <article><strong id="kpi-pending"><?= (int) $k['pending'] ?></strong><span>na fila do status</span></article>
        <article><strong id="kpi-wifi"><?= (int) $k['windows_clients'] ?></strong><span>no Wi-Fi agora</span></article>
        <article><strong id="kpi-visits"><?= (int) $k['visits_today'] ?></strong><span>visitas hoje</span></article>
        <article><strong id="kpi-ssid"><?= h((string) $k['ssid']) ?></strong><span>nome da rede</span></article>
    </div>

    <section class="card">
        <h2><?= $local ? 'Rede neste PC' : 'Rede nesta loja' ?></h2>
        <p class="net-state" id="net-state">
            <?php if ($hotOn): ?>
                Rede ligada<?= !empty($hot['ssid']) ? ' · ' . h((string) $hot['ssid']) : '' ?>
                · portal <?= h((string) ($hot['portal_ip'] ?? $data['portal_ip'])) ?>
            <?php elseif (!$agentAlive && !$local): ?>
                Serviço parado. O PC da loja precisa do agente (instalador + token).
            <?php elseif (!$agentAlive): ?>
                Serviço parado. Use Ligar rede ou o ícone na bandeja (canto da barra de tarefas).
            <?php else: ?>
                Rede desligada.
            <?php endif; ?>
        </p>
        <p class="hint" id="net-real">
            Internet: <?= h((string) (($k['internet_alias'] ?? '') . ' ' . ($k['internet_ip'] ?? 'não detectada'))) ?>
        </p>
        <?php if (!empty($hot['error'])): ?>
            <p class="alert" id="net-error"><?= h((string) $hot['error']) ?></p>
        <?php else: ?>
            <p class="alert hidden" id="net-error"></p>
        <?php endif; ?>
        <div class="actions row">
            <button class="btn" type="button" id="btn-start">Ligar rede</button>
            <button class="btn ghost" type="button" id="btn-stop">Desligar rede</button>
            <?php if ($local): ?>
                <button class="btn ghost" type="button" id="btn-install-agent">Instalar no Windows</button>
            <?php endif; ?>
        </div>
        <p class="hint"><?= $local ? 'O programa fica no canto da barra de tarefas (ícone ao lado do relógio). Clique com o botão direito para ligar, desligar ou abrir o painel.' : 'Ligar/Desligar envia o comando para o PC desta loja. O agente precisa estar online.' ?></p>
        <p class="hint hidden" id="install-hint">Se a permissão não abrir, execute: <code><?= h($installer) ?></code></p>
        <div id="live-devices"></div>
    </section>

    <section class="card">
        <h2>Hoje</h2>
        <p class="lead" id="today-line">
            <?= (int) $k['visits_today'] ?> viram o portal · <?= (int) $k['online_today'] ?> ficaram online
        </p>
        <p class="hint">No modo balcão, confira o código do status antes de Liberar.</p>
    </section>
</section>

<section class="tab-panel <?= $tab === 'clientes' ? 'active' : '' ?>" id="panel-clientes">
    <section class="card">
        <h2>Clientes</h2>
        <div class="toolbar">
            <select id="filter-state">
                <option value="">Todos</option>
                <option value="online">Online</option>
                <option value="fila">Fila</option>
                <option value="blocked">Bloqueados</option>
            </select>
            <input id="filter-q" type="search" placeholder="IP, código ou WhatsApp">
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Quando</th>
                    <th>IP</th>
                    <th>Código</th>
                    <th>WhatsApp</th>
                    <th>Estado</th>
                    <th>Tempo</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="client-rows">
                <?php foreach ($clients as $c): ?>
                    <tr data-state="<?= h((string) $c['state']) ?>" data-q="<?= h(strtolower($c['ip'] . ' ' . $c['status_code'] . ' ' . (string) $c['phone'])) ?>">
                        <td><?= h(date('d/m H:i', strtotime((string) $c['created_at']))) ?></td>
                        <td><?= h((string) $c['ip']) ?><br><small><?= h((string) $c['mac']) ?></small></td>
                        <td><strong><?= h((string) $c['status_code']) ?></strong></td>
                        <td><?= h((string) $c['phone']) ?></td>
                        <td><span class="tag <?= h((string) $c['state']) ?>"><?= h((string) $c['label']) ?></span></td>
                        <td><?= h((string) $c['remaining']) ?><?php if ($c['state'] === 'online' && !empty($c['authorized_at'])): ?><br><small>liberado <?= h(date('H:i', strtotime((string) $c['authorized_at']))) ?></small><?php endif; ?></td>
                        <td>
                            <form class="inline" method="post" action="/admin/action">
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <input type="hidden" name="store_id" value="<?= (int) $storeId ?>">
                                <?php if ($c['state'] !== 'online'): ?>
                                    <button name="do" value="allow">Liberar</button>
                                <?php endif; ?>
                                <?php if ($c['state'] === 'online'): ?>
                                    <button name="do" value="kick">Encerrar</button>
                                <?php endif; ?>
                                <?php if ($c['state'] !== 'blocked'): ?>
                                    <button name="do" value="block">Bloquear</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$clients): ?>
                    <tr class="empty"><td colspan="7">Ninguém conectou ainda.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>

<section class="tab-panel <?= $tab === 'config' ? 'active' : '' ?>" id="panel-config">
    <section class="card">
        <h2>Loja</h2>
        <form method="post" action="/admin/save" class="form" enctype="multipart/form-data">
            <input type="hidden" name="store_id" value="<?= (int) $storeId ?>">
            <label>Nome da loja<input name="store_name" value="<?= h(setting('store_name')) ?>" required></label>
            <label>Cidade<input name="store_city" value="<?= h(setting('store_city')) ?>"></label>
            <label>Texto do status<textarea name="status_template" rows="3"><?= h(setting('status_template')) ?></textarea></label>
            <p class="hint">Use {loja} e {codigo}. O código muda a cada visita.</p>
            <img class="story-img" src="/story/DEMO.png" alt="Prévia da arte do status">

            <h2>Rede</h2>
            <div class="wifi-card">
                <?php if (brand_image_url()): ?>
                    <img src="<?= h(brand_image_url()) ?>" alt="Imagem da conexão">
                <?php else: ?>
                    <div class="wifi-card-placeholder">Wi-Fi</div>
                <?php endif; ?>
                <div>
                    <p class="eyebrow">Cartão da conexão</p>
                    <strong><?= h(setting('wifi_ssid')) ?></strong>
                    <p class="hint">A imagem entra no portal e na arte do WhatsApp. O Windows não permite foto no seletor de redes, mas o cliente vê a marca ao conectar.</p>
                </div>
            </div>
            <label>SSID<input name="wifi_ssid" value="<?= h(setting('wifi_ssid')) ?>" required></label>
            <label>Senha do Wi-Fi<input name="wifi_pass" value="<?= h(setting('wifi_pass')) ?>" minlength="8" required></label>
            <label>Imagem da conexão (logo ou foto)
                <input name="brand_image" type="file" accept="image/png,image/jpeg,image/webp">
            </label>
            <?php if (brand_image_url()): ?>
                <label class="check"><input type="checkbox" name="remove_brand" value="1"> Remover imagem atual</label>
            <?php endif; ?>
            <label>IP do portal<input name="portal_ip" value="<?= h(setting('portal_ip', '192.168.137.1')) ?>"></label>
            <label>Horas de internet após o status<input name="session_hours" type="number" min="1" max="24" value="<?= h(setting('session_hours', '2')) ?>"></label>
            <label>Liberação
                <select name="approval_mode">
                    <option value="instant" <?= setting('approval_mode') === 'instant' ? 'selected' : '' ?>>Na hora, quando o cliente diz que publicou</option>
                    <option value="manual" <?= setting('approval_mode') === 'manual' ? 'selected' : '' ?>>Só depois que o balcão conferir o status</option>
                </select>
            </label>

            <h2>Conta</h2>
            <label>Usuário do painel<input name="admin_user" value="<?= h(setting('admin_user')) ?>" required></label>
            <label>Nova senha (deixe em branco para manter)<input name="admin_pass" type="password"></label>
            <button class="btn" type="submit">Salvar</button>
        </form>
    </section>
</section>

<section class="tab-panel <?= $tab === 'lojas' ? 'active' : '' ?>" id="panel-lojas">
    <section class="card">
        <h2>Lojas</h2>
        <p class="lead">Cada loja tem um token. No PC dela, instale o sistema e cole o endereço deste painel + o token.</p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Loja</th>
                    <th>Token do agente</th>
                    <th>Último contato</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($stores as $s): ?>
                    <tr>
                        <td>
                            <strong><?= h((string) $s['name']) ?></strong>
                            <br><small><?= h((string) $s['city']) ?></small>
                        </td>
                        <td><code class="token"><?= h((string) $s['token']) ?></code></td>
                        <td><?= !empty($s['last_seen_at']) ? h(date('d/m H:i', parse_time_any((string) $s['last_seen_at']) ?: time())) : 'nunca' ?></td>
                        <td>
                            <form class="inline" method="post" action="/admin/stores">
                                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <button name="do" value="select">Abrir</button>
                                <button name="do" value="rotate">Novo token</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <h2>Nova loja</h2>
        <form method="post" action="/admin/stores" class="form">
            <input type="hidden" name="do" value="create">
            <label>Nome<input name="name" required placeholder="Ex.: Loja Centro"></label>
            <label>Cidade<input name="city" placeholder="Opcional"></label>
            <button class="btn" type="submit">Criar loja</button>
        </form>
        <p class="hint">Endereço deste painel para colar no instalador: <code><?= h(guess_panel_url()) ?></code></p>
    </section>
</section>
<script src="/assets/admin.js"></script>
</body>
</html>
