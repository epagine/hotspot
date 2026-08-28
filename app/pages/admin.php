<?php

declare(strict_types=1);

require_admin();

if ((int) ($_GET['store'] ?? 0) > 0) {
    select_store((int) $_GET['store']);
}

$tab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? 'clientes')) ?: 'clientes';
if (!in_array($tab, ['clientes', 'instalador', 'config'], true)) {
    $tab = 'clientes';
}

$saas = saas_overview();
$k = $saas['kpis'];
$rows = $saas['clients'];
$fichaId = (int) ($_GET['id'] ?? 0);
$ficha = $fichaId > 0 ? find_store($fichaId) : null;
$fichaHealth = $ficha ? store_connection_health($ficha) : null;
$fichaStatus = $ficha ? store_status_payload($ficha) : [];
$setupFile = installer_setup_path();
$setupReady = $setupFile !== null;
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Painel SaaS · Hotspot</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="page admin" data-tab="<?= h($tab) ?>">
<header class="top">
    <div>
        <p class="eyebrow">Painel SaaS</p>
        <h1>Clientes hotspot</h1>
    </div>
    <a class="btn ghost" href="/admin/logout">Sair</a>
</header>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <p class="alert flash-global"><?= h((string) $_SESSION['flash_error']) ?></p>
    <?php unset($_SESSION['flash_error']); ?>
<?php elseif (!empty($_SESSION['flash_ok'])): ?>
    <p class="hint flash-global"><?= h((string) $_SESSION['flash_ok']) ?></p>
    <?php unset($_SESSION['flash_ok']); ?>
<?php endif; ?>

<nav class="tabs" role="tablist">
    <a class="tab <?= $tab === 'clientes' ? 'active' : '' ?>" href="/admin?tab=clientes">Clientes</a>
    <a class="tab <?= $tab === 'instalador' ? 'active' : '' ?>" href="/admin?tab=instalador">Instalador</a>
    <a class="tab <?= $tab === 'config' ? 'active' : '' ?>" href="/admin?tab=config">Configuração</a>
</nav>

<section class="tab-panel <?= $tab === 'clientes' ? 'active' : '' ?>" id="panel-clientes">
    <div class="stats" id="saas-kpis">
        <article><strong id="kpi-ativos"><?= (int) $k['ativos'] ?></strong><span>clientes ativos</span></article>
        <article><strong id="kpi-ok"><?= (int) $k['ok'] ?></strong><span>conexão OK</span></article>
        <article><strong id="kpi-erro"><?= (int) $k['erro'] ?></strong><span>conexão com erro</span></article>
        <article><strong id="kpi-offline"><?= (int) $k['offline'] ?></strong><span>PC offline</span></article>
        <article><strong id="kpi-atrasados"><?= (int) $k['atrasados'] ?></strong><span>financeiro atrasado</span></article>
        <article><strong id="kpi-total"><?= (int) $k['total'] ?></strong><span>clientes no total</span></article>
    </div>

    <?php if ($ficha): ?>
        <section class="card">
            <p class="eyebrow"><a href="/admin?tab=clientes">← Todos os clientes</a></p>
            <h2><?= h((string) $ficha['name']) ?></h2>
            <p>
                <span class="tag <?= !empty($ficha['active']) ? 'online' : 'blocked' ?>"><?= !empty($ficha['active']) ? 'Ativo' : 'Suspenso' ?></span>
                <span class="tag conn-<?= h($fichaHealth['key']) ?>"><?= h($fichaHealth['label']) ?></span>
                <span class="tag <?= ($ficha['billing_status'] ?? '') === 'atrasado' ? 'blocked' : (($ficha['billing_status'] ?? '') === 'em_dia' ? 'online' : 'pending') ?>"><?= h(billing_label((string) ($ficha['billing_status'] ?? 'em_dia'))) ?></span>
            </p>
            <p class="hint" id="ficha-conn"><?= h($fichaHealth['detail']) ?></p>
            <?php
            $fichaBits = [];
            if (!empty($fichaStatus['ssid'])) {
                $fichaBits[] = 'SSID ' . h((string) $fichaStatus['ssid']);
            }
            if (!empty($fichaStatus['internet_ip'])) {
                $fichaBits[] = 'Internet ' . h((string) $fichaStatus['internet_ip']);
            }
            if (!empty($ficha['last_seen_at'])) {
                $fichaBits[] = 'último contato ' . h(date('d/m H:i', parse_time_any((string) $ficha['last_seen_at']) ?: time()));
            }
            ?>
            <?php if ($fichaBits): ?>
                <p class="hint"><?= implode(' · ', $fichaBits) ?></p>
            <?php endif; ?>

            <form method="post" action="/admin/stores" class="form">
                <input type="hidden" name="do" value="save">
                <input type="hidden" name="id" value="<?= (int) $ficha['id'] ?>">
                <h2>Situação</h2>
                <label>Nome do cliente<input name="name" value="<?= h((string) $ficha['name']) ?>" required></label>
                <label>Cidade<input name="city" value="<?= h((string) ($ficha['city'] ?? '')) ?>"></label>
                <label>Contato<input name="contact" value="<?= h((string) ($ficha['contact'] ?? '')) ?>" placeholder="Telefone ou responsável"></label>
                <label>Situação do contrato
                    <select name="active">
                        <option value="1" <?= !empty($ficha['active']) ? 'selected' : '' ?>>Ativo</option>
                        <option value="0" <?= empty($ficha['active']) ? 'selected' : '' ?>>Suspenso</option>
                    </select>
                </label>
                <p class="hint">Suspender envia comando para desligar o hotspot no PC da loja.</p>

                <h2>Financeiro</h2>
                <label>Plano
                    <select name="plan">
                        <?php foreach (['mensal' => 'Mensal', 'trimestral' => 'Trimestral', 'anual' => 'Anual'] as $val => $lab): ?>
                            <option value="<?= h($val) ?>" <?= ($ficha['plan'] ?? 'mensal') === $val ? 'selected' : '' ?>><?= h($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Valor (R$)<input name="monthly_fee" value="<?= h((string) ($ficha['monthly_fee'] ?? '')) ?>" placeholder="0,00"></label>
                <label>Pago até<input name="paid_until" type="date" value="<?= h((string) ($ficha['paid_until'] ?? '')) ?>"></label>
                <label>Situação financeira
                    <select name="billing_status">
                        <?php foreach (['em_dia' => 'Em dia', 'atrasado' => 'Atrasado', 'cortesia' => 'Cortesia', 'cancelado' => 'Cancelado'] as $val => $lab): ?>
                            <option value="<?= h($val) ?>" <?= ($ficha['billing_status'] ?? 'em_dia') === $val ? 'selected' : '' ?>><?= h($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Observações<textarea name="notes" rows="3"><?= h((string) ($ficha['notes'] ?? '')) ?></textarea></label>

                <h2>Acesso do PC</h2>
                <p class="hint">Token do agente: <code class="token"><?= h((string) $ficha['token']) ?></code></p>
                <p class="hint">URL do painel: <code><?= h(guess_panel_url()) ?></code></p>
                <div class="actions row">
                    <button class="btn" type="submit">Salvar gestão</button>
                    <button class="btn ghost" name="do" value="rotate">Novo token</button>
                    <?php if ($setupReady): ?>
                        <a class="btn ghost" href="/admin/instalador">Baixar instalador</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    <?php else: ?>
        <section class="card">
            <h2>Clientes</h2>
            <p class="lead">Situação do contrato, financeiro e se a conexão do PC da loja está ok.</p>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Contrato</th>
                        <th>Conexão</th>
                        <th>Financeiro</th>
                        <th>Pago até</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody id="saas-rows">
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <strong><?= h($r['name']) ?></strong>
                                <br><small><?= h($r['city'] !== '' ? $r['city'] : '—') ?><?= $r['contact'] !== '' ? ' · ' . h($r['contact']) : '' ?></small>
                            </td>
                            <td><span class="tag <?= $r['active'] ? 'online' : 'blocked' ?>"><?= $r['active'] ? 'Ativo' : 'Suspenso' ?></span></td>
                            <td>
                                <span class="tag conn-<?= h($r['health']['key']) ?>"><?= h($r['health']['label']) ?></span>
                                <br><small><?= h($r['health']['detail']) ?></small>
                            </td>
                            <td>
                                <span class="tag <?= $r['billing_status'] === 'atrasado' ? 'blocked' : ($r['billing_status'] === 'em_dia' ? 'online' : 'pending') ?>"><?= h($r['billing_label']) ?></span>
                                <br><small><?= h($r['plan']) ?><?= $r['monthly_fee'] !== '' ? ' · R$ ' . h($r['monthly_fee']) : '' ?></small>
                            </td>
                            <td><?= h($r['paid_until'] !== '' ? date('d/m/Y', strtotime($r['paid_until']) ?: time()) : '—') ?></td>
                            <td><a class="btn ghost" href="/admin?tab=clientes&amp;id=<?= (int) $r['id'] ?>">Gestão</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr class="empty"><td colspan="6">Nenhum cliente ainda.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="card">
            <h2>Novo cliente</h2>
            <form method="post" action="/admin/stores" class="form">
                <input type="hidden" name="do" value="create">
                <label>Nome<input name="name" required placeholder="Ex.: Loja Centro"></label>
                <label>Cidade<input name="city" placeholder="Opcional"></label>
                <label>Contato<input name="contact" placeholder="Opcional"></label>
                <button class="btn" type="submit">Cadastrar cliente</button>
            </form>
        </section>
    <?php endif; ?>
</section>

<section class="tab-panel <?= $tab === 'instalador' ? 'active' : '' ?>" id="panel-instalador">
    <section class="card">
        <h2>Instalador para o PC da loja</h2>
        <?php if ($setupReady): ?>
            <p class="lead">Baixe no Windows da loja, execute como administrador e cole a URL deste painel + o token do cliente.</p>
            <div class="actions row">
                <a class="btn" href="/admin/instalador">Baixar WiFiDaLoja-Setup.exe</a>
            </div>
            <p class="hint">Arquivo atual: <?= h(basename($setupFile)) ?> · <?= h((string) round((int) filesize($setupFile) / 1048576, 1)) ?> MB</p>
        <?php else: ?>
            <p class="lead">O .exe não vai no Git. Gere com Empacotar.ps1 ou envie abaixo.</p>
        <?php endif; ?>
        <form method="post" action="/admin/instalador" class="form" enctype="multipart/form-data">
            <label>Publicar instalador (.exe)
                <input name="setup" type="file" accept=".exe,application/vnd.microsoft.portable-executable" required>
            </label>
            <button class="btn <?= $setupReady ? 'ghost' : '' ?>" type="submit"><?= $setupReady ? 'Substituir instalador' : 'Enviar instalador' ?></button>
        </form>
        <p class="hint">URL do painel: <code><?= h(guess_panel_url()) ?></code></p>
    </section>
</section>

<section class="tab-panel <?= $tab === 'config' ? 'active' : '' ?>" id="panel-config">
    <section class="card">
        <h2>Conta do painel SaaS</h2>
        <form method="post" action="/admin/save" class="form">
            <label>Usuário<input name="admin_user" value="<?= h(setting('admin_user')) ?>" required></label>
            <label>Nova senha (deixe em branco para manter)<input name="admin_pass" type="password"></label>
            <button class="btn" type="submit">Salvar</button>
        </form>
    </section>
</section>
<script src="/assets/admin.js"></script>
</body>
</html>
