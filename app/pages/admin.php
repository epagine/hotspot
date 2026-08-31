<?php

declare(strict_types=1);

require_admin();

pagseguro_maybe_run_billing();

$tab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? 'clientes')) ?: 'clientes';
if ($tab === 'pagamentos') {
    $tab = 'financeiro';
}
if (in_array($tab, ['config', 'conta'], true)) {
    $tab = 'configuracoes';
}
if (!in_array($tab, ['clientes', 'instalador', 'financeiro', 'configuracoes', 'assinaturas'], true)) {
    $tab = 'clientes';
}
$cfgSec = preg_replace('/[^a-z]/', '', (string) ($_GET['sec'] ?? 'conta')) ?: 'conta';
if ($tab === 'configuracoes' && !in_array($cfgSec, ['conta', 'integracao', 'politicas'], true)) {
    $cfgSec = match ($_GET['sec'] ?? '') {
        'pagseguro' => 'integracao',
        default => 'conta',
    };
}
$subFilter = preg_replace('/[^a-z]/', '', (string) ($_GET['status'] ?? ''));
$subId = (int) ($_GET['id'] ?? 0);
$subStore = $subId > 0 ? find_store($subId) : null;
if ($tab === 'assinaturas' && $subId > 0 && $subStore === null) {
    admin_redirect(admin_url('assinaturas'));
}
$subs = $tab === 'assinaturas' ? subscriptions_overview($subFilter !== '' ? $subFilter : null) : null;
$subEvents = ($subStore && $tab === 'assinaturas') ? subscription_events($subId) : [];
$subPayments = ($subStore && $tab === 'assinaturas') ? store_payments($subId, 12) : [];

$saas = saas_overview();
$k = $saas['kpis'];
$rows = $saas['clients'];
$fichaId = (int) ($_GET['id'] ?? 0);
$ficha = $fichaId > 0 ? find_store($fichaId) : null;
if ($tab === 'clientes' && $fichaId > 0 && $ficha === null) {
    admin_redirect(admin_url('clientes'));
}
$fichaHealth = $ficha ? store_connection_health($ficha) : null;
$fichaStatus = $ficha ? store_status_payload($ficha) : [];
$lojaFocus = (int) ($_GET['loja'] ?? 0);
$setupFile = installer_setup_path();
$setupReady = $setupFile !== null;
$me = setting('admin_user', 'admin');

$pageTitle = match (true) {
    $ficha !== null => (string) $ficha['name'],
    $subStore !== null && $tab === 'assinaturas' => (string) $subStore['name'],
    $tab === 'instalador' => 'Instalador',
    $tab === 'assinaturas' => 'Assinaturas',
    $tab === 'financeiro' => 'Financeiro',
    $tab === 'configuracoes' && $cfgSec === 'integracao' => 'Integração financeira',
    $tab === 'configuracoes' && $cfgSec === 'politicas' => 'Políticas SaaS',
    $tab === 'configuracoes' => 'Configurações',
    default => 'Clientes',
};
$pageLead = match (true) {
    $ficha !== null => 'Dados da loja e contrato operacional. Assinatura fica em Assinaturas.',
    $subStore !== null && $tab === 'assinaturas' => 'Plano, vigência, situação e histórico da assinatura.',
    $tab === 'instalador' => 'Programa que a loja instala no Windows.',
    $tab === 'assinaturas' => 'Planos, vigência e situação de cada loja.',
    $tab === 'financeiro' => 'Cobranças abertas, links e recebimentos.',
    $tab === 'configuracoes' && $cfgSec === 'integracao' => 'PagSeguro / PagBank para as mensalidades das lojas.',
    $tab === 'configuracoes' && $cfgSec === 'politicas' => 'Trial, tolerância e suspensão automática.',
    $tab === 'configuracoes' => 'Conta de acesso e integrações deste painel.',
    default => 'Lojas, contrato e se o PC está online.',
};

function app_nav_item(string $tab, string $key, string $label): void
{
    $active = $tab === $key ? ' active' : '';
    echo '<a class="' . $active . '" href="' . h(admin_url($key)) . '">' . h($label) . '</a>';
}
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> · Wi-Fi da loja</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="app" data-tab="<?= h($tab) ?>">
<aside class="app-side">
    <a class="app-brand" href="<?= h(admin_url()) ?>">
        <span class="app-mark">WL</span>
        <div>
            <strong>Wi-Fi da loja</strong>
            <small>Painel de gestão</small>
        </div>
    </a>
    <nav class="app-nav">
        <div class="app-nav-label">Gestão</div>
        <?php app_nav_item($tab, 'clientes', 'Clientes'); ?>
        <?php app_nav_item($tab, 'assinaturas', 'Assinaturas'); ?>
        <?php app_nav_item($tab, 'financeiro', 'Financeiro'); ?>
        <div class="app-nav-label">Entrega</div>
        <?php app_nav_item($tab, 'instalador', 'Instalador'); ?>
        <div class="app-nav-label">Sistema</div>
        <?php app_nav_item($tab, 'configuracoes', 'Configurações'); ?>
    </nav>
    <div class="app-side-foot">
        <div class="app-user"><?= h($me) ?></div>
        <a class="btn ghost btn-sm" href="<?= h(admin_url('sair')) ?>">Sair</a>
    </div>
</aside>
<div class="app-body">
    <header class="app-top">
        <div>
            <?php if ($ficha): ?>
                <a class="back-link" href="<?= h(admin_url()) ?>">← Todos os clientes</a>
            <?php elseif ($subStore && $tab === 'assinaturas'): ?>
                <a class="back-link" href="<?= h(admin_url('assinaturas')) ?>">← Todas as assinaturas</a>
            <?php endif; ?>
            <h1><?= h($pageTitle) ?></h1>
            <p class="lead"><?= h($pageLead) ?></p>
        </div>
    </header>
    <main class="app-main">
<?php if (!empty($_SESSION['flash_error'])): ?>
    <p class="alert flash-global"><?= h((string) $_SESSION['flash_error']) ?></p>
    <?php unset($_SESSION['flash_error']); ?>
<?php elseif (!empty($_SESSION['flash_ok'])): ?>
    <p class="hint flash-ok"><?= h((string) $_SESSION['flash_ok']) ?></p>
    <?php unset($_SESSION['flash_ok']); ?>
    <?php if (!empty($_SESSION['flash_pay_url'])): ?>
        <p class="pay-box">Link PagSeguro<br><a href="<?= h((string) $_SESSION['flash_pay_url']) ?>" target="_blank" rel="noopener"><?= h((string) $_SESSION['flash_pay_url']) ?></a></p>
        <?php unset($_SESSION['flash_pay_url']); ?>
    <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'clientes'): ?>
    <div class="stats" id="saas-kpis">
        <article><span>Ativos</span><strong id="kpi-ativos"><?= (int) $k['ativos'] ?></strong></article>
        <article><span>PC ok</span><strong id="kpi-ok"><?= (int) $k['ok'] ?></strong></article>
        <article><span>PC com erro</span><strong id="kpi-erro"><?= (int) $k['erro'] ?></strong></article>
        <article><span>PC offline</span><strong id="kpi-offline"><?= (int) $k['offline'] ?></strong></article>
        <article><span>Total</span><strong id="kpi-total"><?= (int) $k['total'] ?></strong></article>
    </div>

    <?php if ($ficha): ?>
        <section class="card">
            <div class="ficha-head">
                <p>
                    <span class="tag <?= !empty($ficha['active']) ? 'online' : 'blocked' ?>"><?= !empty($ficha['active']) ? 'Ativo' : 'Suspenso' ?></span>
                    <span class="tag conn-<?= h($fichaHealth['key']) ?>"><?= h($fichaHealth['label']) ?></span>
                    <?php $subSt = normalize_subscription_status((string) ($ficha['billing_status'] ?? 'ativa')); ?>
                    <span class="tag <?= subscription_tag_class($subSt) ?>"><?= h(subscription_label($subSt)) ?></span>
                </p>
            </div>
            <div class="conn-banner conn-<?= h($fichaHealth['key']) ?>">
                <strong>Conexão do PC</strong>
                <span id="ficha-conn"><?= h($fichaHealth['detail']) ?></span>
                <?php
                $fichaBits = [];
                if (!empty($fichaStatus['ssid'])) {
                    $fichaBits[] = 'rede ' . h((string) $fichaStatus['ssid']);
                }
                if (!empty($fichaStatus['internet_ip'])) {
                    $fichaBits[] = 'IP ' . h((string) $fichaStatus['internet_ip']);
                }
                if (!empty($ficha['last_seen_at'])) {
                    $fichaBits[] = 'contato ' . h(date('d/m H:i', parse_time_any((string) $ficha['last_seen_at']) ?: time()));
                }
                ?>
                <?php if ($fichaBits): ?>
                    <small><?= implode(' · ', $fichaBits) ?></small>
                <?php endif; ?>
            </div>

            <form method="post" action="<?= h(admin_url('clientes', (int) $ficha['id'])) ?>" class="form">
                <input type="hidden" name="do" value="save">
                <input type="hidden" name="id" value="<?= (int) $ficha['id'] ?>">
                <div class="form-grid">
                    <fieldset>
                        <legend>Situação</legend>
                        <label>Nome<input name="name" value="<?= h((string) $ficha['name']) ?>" required></label>
                        <label>Cidade<input name="city" value="<?= h((string) ($ficha['city'] ?? '')) ?>"></label>
                        <label>Contato<input name="contact" value="<?= h((string) ($ficha['contact'] ?? '')) ?>" placeholder="Telefone ou responsável"></label>
                        <label>Contrato
                            <select name="active">
                                <option value="1" <?= !empty($ficha['active']) ? 'selected' : '' ?>>Ativo</option>
                                <option value="0" <?= empty($ficha['active']) ? 'selected' : '' ?>>Suspenso</option>
                            </select>
                        </label>
                        <p class="hint">Suspender desliga o hotspot no PC da loja.</p>
                        <p><a class="back-link" href="<?= h(admin_url('assinaturas', (int) $ficha['id'])) ?>">Ver assinatura →</a></p>
                    </fieldset>
                </div>
                <details class="vinculo">
                    <summary>Vínculo do PC da loja</summary>
                    <p class="hint">Cole no instalador Windows. Não altera SSID, senha ou o portal.</p>
                    <p>Token<br><code class="token"><?= h((string) $ficha['token']) ?></code></p>
                    <p>URL do painel<br><code><?= h(guess_panel_url()) ?></code></p>
                    <div class="actions row">
                        <button class="btn ghost btn-sm" name="do" value="rotate">Gerar novo token</button>
                        <?php if ($setupReady): ?>
                            <a class="btn ghost btn-sm" href="<?= h(admin_url('instalador', 0, 'baixar')) ?>">Baixar instalador</a>
                        <?php endif; ?>
                    </div>
                </details>
                <div class="actions row">
                    <button class="btn" type="submit">Salvar alterações</button>
                </div>
            </form>
        </section>
    <?php else: ?>
        <section class="card">
            <div class="table-wrap">
                <table class="saas-table">
                    <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Contrato</th>
                        <th>PC da loja</th>
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
                            <td><a class="btn ghost btn-sm" href="<?= h(admin_url('clientes', (int) $r['id'])) ?>">Abrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr class="empty"><td colspan="4">Nenhum cliente cadastrado.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="card">
            <h2>Novo cliente</h2>
            <form method="post" action="<?= h(admin_url()) ?>" class="form form-inline">
                <input type="hidden" name="do" value="create">
                <label>Nome<input name="name" required placeholder="Ex.: Loja Centro"></label>
                <label>Cidade<input name="city" placeholder="Opcional"></label>
                <label>Contato<input name="contact" placeholder="Opcional"></label>
                <button class="btn" type="submit">Cadastrar</button>
            </form>
        </section>
    <?php endif; ?>

<?php elseif ($tab === 'instalador'): ?>
    <section class="card card-narrow">
        <?php if ($setupReady): ?>
            <div class="actions row">
                <a class="btn" href="<?= h(admin_url('instalador', 0, 'baixar')) ?>">Baixar WiFiDaLoja-Setup.exe</a>
            </div>
            <p class="hint"><?= h(basename($setupFile)) ?> · <?= h((string) round((int) filesize($setupFile) / 1048576, 1)) ?> MB</p>
        <?php else: ?>
            <p class="hint">Ainda não há arquivo publicado. Envie o .exe gerado com Empacotar.ps1.</p>
        <?php endif; ?>
        <form method="post" action="<?= h(admin_url('instalador')) ?>" class="form" enctype="multipart/form-data">
            <label>Publicar .exe
                <input name="setup" type="file" accept=".exe,application/vnd.microsoft.portable-executable" required>
            </label>
            <button class="btn <?= $setupReady ? 'ghost' : '' ?>" type="submit"><?= $setupReady ? 'Substituir arquivo' : 'Enviar arquivo' ?></button>
        </form>
        <p class="hint">URL deste painel: <code><?= h(guess_panel_url()) ?></code></p>
    </section>

<?php elseif ($tab === 'assinaturas'): ?>
    <?php $sk = $subs['kpis']; ?>
    <div class="stats">
        <article><span>Ativas</span><strong><?= (int) $sk['ativas'] ?></strong></article>
        <article><span>Trial</span><strong><?= (int) $sk['trial'] ?></strong></article>
        <article><span>Pendentes</span><strong><?= (int) $sk['pendentes'] ?></strong></article>
        <article><span>Atrasadas</span><strong><?= (int) $sk['atrasadas'] ?></strong></article>
        <article><span>Suspensas</span><strong><?= (int) $sk['suspensas'] ?></strong></article>
        <article><span>MRR</span><strong><?= h(cents_label((int) $sk['mrr_cents'])) ?></strong></article>
    </div>
    <?php if ($subStore): ?>
        <?php $sr = subscription_row($subStore); ?>
        <section class="card">
            <p>
                <span class="tag <?= h($sr['tag_class']) ?>"><?= h($sr['billing_label']) ?></span>
                <span class="tag <?= $sr['active'] ? 'online' : 'blocked' ?>"><?= $sr['active'] ? 'Serviço ligado' : 'Serviço suspenso' ?></span>
            </p>
            <form method="post" action="<?= h(admin_url('assinaturas', $subId)) ?>" class="form">
                <input type="hidden" name="id" value="<?= $subId ?>">
                <div class="form-grid">
                    <fieldset>
                        <legend>Plano</legend>
                        <label>Plano
                            <select name="plan">
                                <?php foreach (['mensal' => 'Mensal', 'trimestral' => 'Trimestral', 'anual' => 'Anual'] as $val => $lab): ?>
                                    <option value="<?= h($val) ?>" <?= $sr['plan'] === $val ? 'selected' : '' ?>><?= h($lab) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Valor do ciclo (R$)<input name="monthly_fee" value="<?= h($sr['cycle_amount']) ?>" placeholder="0,00"></label>
                        <label>Vigente até<input name="paid_until" type="date" value="<?= h($sr['paid_until']) ?>"></label>
                        <?php if ($sr['trial_ends_at'] !== ''): ?>
                            <p class="hint">Trial até <?= h(date('d/m/Y', strtotime($sr['trial_ends_at']) ?: time())) ?></p>
                        <?php endif; ?>
                        <label>Situação
                            <select name="billing_status">
                                <?php foreach (subscription_statuses() as $val => $lab): ?>
                                    <option value="<?= h($val) ?>" <?= $sr['billing_status'] === $val ? 'selected' : '' ?>><?= h($lab) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="check"><input type="checkbox" name="auto_billing" value="1" <?= $sr['auto_billing'] ? 'checked' : '' ?>> Cobrança automática</label>
                        <label>Observações<textarea name="notes" rows="3"><?= h($sr['notes']) ?></textarea></label>
                    </fieldset>
                </div>
                <div class="actions row">
                    <button class="btn" type="submit" name="do" value="save">Salvar assinatura</button>
                    <?php if (pagseguro_configured()): ?>
                        <button class="btn ghost" type="submit" name="do" value="charge">Gerar cobrança</button>
                    <?php endif; ?>
                    <button class="btn ghost" type="submit" name="do" value="extend">+7 dias</button>
                    <button class="btn ghost" type="submit" name="do" value="cortesia">Cortesia</button>
                    <button class="btn ghost" type="submit" name="do" value="reactivate">Reativar</button>
                    <button class="btn ghost" type="submit" name="do" value="cancel">Cancelar</button>
                </div>
            </form>
            <p class="hint"><a href="<?= h(admin_url('clientes', $subId)) ?>">Ficha da loja</a> · <a href="<?= h(admin_url('financeiro', $subId)) ?>">Cobranças no Financeiro</a></p>
        </section>
        <section class="card">
            <h2>Histórico</h2>
            <?php if ($subEvents): ?>
                <ul class="steps">
                <?php foreach ($subEvents as $ev): ?>
                    <li><?= h(date('d/m/Y H:i', strtotime((string) $ev['created_at']) ?: time())) ?> — <?= h(subscription_label((string) $ev['to_status'])) ?><?= ($ev['note'] ?? '') !== '' ? ': ' . h((string) $ev['note']) : '' ?></li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="hint">Nenhum evento registrado ainda.</p>
            <?php endif; ?>
        </section>
        <section class="card">
            <h2>Pagamentos</h2>
            <?php if ($subPayments): ?>
                <div class="table-wrap">
                <table class="saas-table">
                    <thead><tr><th>Quando</th><th>Valor</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($subPayments as $p): ?>
                        <tr>
                            <td><?= h(date('d/m/Y H:i', strtotime((string) $p['created_at']) ?: time())) ?></td>
                            <td><?= h(cents_label((int) $p['amount_cents'])) ?></td>
                            <td><?= ($p['status'] ?? '') === 'paid' ? 'Pago' : 'Aguardando' ?></td>
                            <td><?php if (($p['status'] ?? '') !== 'paid' && ($p['pay_url'] ?? '') !== ''): ?><a class="btn ghost btn-sm" href="<?= h((string) $p['pay_url']) ?>" target="_blank" rel="noopener">Abrir</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <p class="hint">Nenhuma cobrança ainda.</p>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <nav class="app-subnav">
            <a class="<?= $subFilter === '' ? 'active' : '' ?>" href="<?= h(admin_url('assinaturas')) ?>">Todas</a>
            <?php foreach ($subs['filters'] as $fval => $flab): ?>
                <a class="<?= $subFilter === $fval ? 'active' : '' ?>" href="<?= h(admin_url('assinaturas')) ?>?status=<?= h($fval) ?>"><?= h($flab) ?></a>
            <?php endforeach; ?>
        </nav>
        <section class="card">
            <div class="table-wrap">
                <table class="saas-table">
                    <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Plano</th>
                        <th>Valor</th>
                        <th>Vigência</th>
                        <th>Situação</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($subs['rows'] as $r): ?>
                        <tr>
                            <td><strong><?= h($r['name']) ?></strong><br><small><?= h($r['city'] !== '' ? $r['city'] : '—') ?></small></td>
                            <td><?= h($r['plan_label']) ?></td>
                            <td><?= $r['cycle_amount'] !== '' ? 'R$ ' . h($r['cycle_amount']) : '—' ?></td>
                            <td><?= h($r['paid_until'] !== '' ? date('d/m/Y', strtotime($r['paid_until']) ?: time()) : '—') ?></td>
                            <td><span class="tag <?= h($r['tag_class']) ?>"><?= h($r['billing_label']) ?></span></td>
                            <td><a class="btn ghost btn-sm" href="<?= h(admin_url('assinaturas', (int) $r['id'])) ?>">Abrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$subs['rows']): ?>
                        <tr class="empty"><td colspan="6">Nenhuma assinatura<?= $subFilter !== '' ? ' neste filtro' : '' ?>.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

<?php elseif ($tab === 'financeiro'): ?>
    <div class="stats">
        <article><span>Atrasados</span><strong id="kpi-atrasados"><?= (int) $k['atrasados'] ?></strong></article>
        <article><span>Receita recorrente</span><strong><?= h(cents_label(subscription_mrr_cents(all_stores()))) ?></strong></article>
        <article><span>Clientes</span><strong><?= (int) $k['total'] ?></strong></article>
    </div>
    <?php if (!pagseguro_configured()): ?>
        <p class="hint">Para gerar o link de pagamento, configure o PagSeguro em <a href="<?= h(admin_url('configuracoes', 0, 'integracao')) ?>">Configurações → Integração financeira</a>.</p>
    <?php endif; ?>
    <section class="card">
        <div class="table-wrap">
            <table class="saas-table">
                <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Plano</th>
                    <th>Situação</th>
                    <th>Pago até</th>
                    <th>Último link</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $pays = store_payments((int) $r['id'], 1);
                    $lastPay = $pays[0] ?? null;
                    $planLab = match ($r['plan'] ?? 'mensal') {
                        'trimestral' => 'Trimestral',
                        'anual' => 'Anual',
                        default => 'Mensal',
                    };
                    ?>
                    <tr id="loja-<?= (int) $r['id'] ?>" class="<?= $lojaFocus === (int) $r['id'] ? 'row-focus' : '' ?>">
                        <td>
                            <strong><?= h($r['name']) ?></strong>
                            <br><small><a href="<?= h(admin_url('assinaturas', (int) $r['id'])) ?>">Assinatura</a> · <a href="<?= h(admin_url('clientes', (int) $r['id'])) ?>">Loja</a></small>
                        </td>
                        <td>
                            <?= h($planLab) ?>
                            <br><small><?= $r['monthly_fee'] !== '' ? 'R$ ' . h($r['monthly_fee']) : 'Sem valor' ?></small>
                        </td>
                        <td><span class="tag <?= subscription_tag_class((string) $r['billing_status']) ?>"><?= h($r['billing_label']) ?></span></td>
                        <td><?= h($r['paid_until'] !== '' ? date('d/m/Y', strtotime($r['paid_until']) ?: time()) : '—') ?></td>
                        <td>
                            <?php if ($lastPay && ($lastPay['status'] ?? '') !== 'paid' && ($lastPay['pay_url'] ?? '') !== ''): ?>
                                <a class="btn ghost btn-sm" href="<?= h((string) $lastPay['pay_url']) ?>" target="_blank" rel="noopener">Abrir cobrança</a>
                                <br><small>Aguardando</small>
                            <?php elseif ($lastPay && ($lastPay['status'] ?? '') === 'paid'): ?>
                                <small>Pago <?= h(cents_label((int) $lastPay['amount_cents'])) ?></small>
                            <?php else: ?>
                                <small>—</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (pagseguro_configured()): ?>
                                <form method="post" action="<?= h(admin_url('financeiro', (int) $r['id'])) ?>">
                                    <input type="hidden" name="do" value="charge">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn ghost btn-sm" type="submit">Gerar cobrança</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr class="empty"><td colspan="6">Cadastre clientes em Clientes. Plano e valor ficam em Assinaturas.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($tab === 'configuracoes'): ?>
    <nav class="app-subnav">
        <a class="<?= $cfgSec === 'conta' ? 'active' : '' ?>" href="<?= h(admin_url('configuracoes', 0, 'conta')) ?>">Conta</a>
        <a class="<?= $cfgSec === 'integracao' ? 'active' : '' ?>" href="<?= h(admin_url('configuracoes', 0, 'integracao')) ?>">Integração financeira</a>
        <a class="<?= $cfgSec === 'politicas' ? 'active' : '' ?>" href="<?= h(admin_url('configuracoes', 0, 'politicas')) ?>">Políticas SaaS</a>
    </nav>
    <?php if ($cfgSec === 'integracao'): ?>
    <section class="card card-narrow">
        <h2>PagSeguro / PagBank</h2>
        <ol class="steps">
            <li>Abra a conta em <a href="https://acesso.pagseguro.uol.com.br/" target="_blank" rel="noopener">PagSeguro / PagBank</a>.</li>
            <li>Sandbox: token em <a href="https://portaldev.pagbank.com.br/" target="_blank" rel="noopener">portaldev.pagbank.com.br</a> (Tokens). Produção: Vendas → Integrações → Gerar token.</li>
            <li>O ambiente tem que ser o mesmo da origem do token. Cole só o código, sem Bearer.</li>
            <li>Notificação: <code><?= h(pagseguro_webhook_url()) ?></code></li>
        </ol>
        <form method="post" action="<?= h(admin_url('configuracoes', 0, 'integracao')) ?>" class="form">
            <input type="hidden" name="do" value="save">
            <label>Ambiente
                <select name="pagseguro_env">
                    <option value="sandbox" <?= pagseguro_env() === 'sandbox' ? 'selected' : '' ?>>Sandbox (testes)</option>
                    <option value="production" <?= pagseguro_env() === 'production' ? 'selected' : '' ?>>Produção</option>
                </select>
            </label>
            <label>Token da API
                <input name="pagseguro_token" type="password" autocomplete="off" placeholder="<?= pagseguro_configured() ? h(pagseguro_mask_token()) : 'Cole o token da API' ?>">
            </label>
            <p class="hint"><?= pagseguro_configured() ? 'Token salvo (' . h(pagseguro_mask_token()) . '). Deixe em branco para manter.' : 'O token não aparece inteiro depois de salvar.' ?></p>
            <label class="check"><input type="hidden" name="pagseguro_auto" value="0"><input type="checkbox" name="pagseguro_auto" value="1" <?= pagseguro_auto_enabled() ? 'checked' : '' ?>> Gerar cobrança automática pelos planos</label>
            <label>Antecedência (dias)
                <input name="pagseguro_advance_days" type="number" min="0" max="30" value="<?= (int) pagseguro_advance_days() ?>">
            </label>
            <div class="actions row">
                <button class="btn" type="submit">Salvar integração</button>
            </div>
        </form>
        <?php if (pagseguro_configured()): ?>
            <p class="hint">Cron diário:<br><code><?= h(pagseguro_cron_url()) ?></code></p>
            <div class="actions row">
                <form method="post" action="<?= h(admin_url('configuracoes', 0, 'integracao')) ?>">
                    <input type="hidden" name="do" value="test">
                    <button class="btn ghost" type="submit">Testar token</button>
                </form>
                <form method="post" action="<?= h(admin_url('configuracoes', 0, 'integracao')) ?>">
                    <input type="hidden" name="do" value="run">
                    <button class="btn ghost" type="submit">Gerar cobranças agora</button>
                </form>
            </div>
        <?php endif; ?>
        <p class="hint">As cobranças do dia a dia ficam em <a href="<?= h(admin_url('financeiro')) ?>">Financeiro</a>.</p>
        <p class="hint">Webhook HTTPS: <code><?= h(pagseguro_webhook_url()) ?></code></p>
    </section>
    <?php elseif ($cfgSec === 'politicas'): ?>
    <section class="card card-narrow">
        <h2>Políticas SaaS</h2>
        <form method="post" action="<?= h(admin_url('configuracoes', 0, 'politicas')) ?>" class="form">
            <label>Dias de trial em lojas novas
                <input name="saas_trial_days" type="number" min="0" max="90" value="<?= (int) saas_trial_days() ?>">
            </label>
            <label>Tolerância após vencimento (dias)
                <input name="saas_grace_days" type="number" min="0" max="30" value="<?= (int) saas_grace_days() ?>">
            </label>
            <label class="check"><input type="hidden" name="saas_auto_suspend" value="0"><input type="checkbox" name="saas_auto_suspend" value="1" <?= saas_auto_suspend_enabled() ? 'checked' : '' ?>> Suspender serviço automaticamente após a tolerância</label>
            <p class="hint">Com suspensão automática, o hotspot da loja é desligado quando a assinatura fica suspensa.</p>
            <button class="btn" type="submit">Salvar políticas</button>
        </form>
    </section>
    <?php else: ?>
    <section class="card card-narrow">
        <h2>Conta do painel</h2>
        <form method="post" action="<?= h(admin_url('conta')) ?>" class="form">
            <label>Usuário<input name="admin_user" value="<?= h(setting('admin_user')) ?>" required></label>
            <label>Nova senha (em branco mantém a atual)<input name="admin_pass" type="password" autocomplete="new-password"></label>
            <button class="btn" type="submit">Salvar conta</button>
        </form>
    </section>
    <?php endif; ?>
<?php endif; ?>
    </main>
</div>
<script src="/assets/admin.js"></script>
</body>
</html>
