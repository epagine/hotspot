<?php

declare(strict_types=1);

require_client_login();

$store = current_client_store();
if (!$store) {
    client_redirect(client_url('entrar'));
}

$storeId = (int) $store['id'];
$sec = preg_replace('/[^a-z]/', '', (string) ($_GET['sec'] ?? 'assinatura')) ?: 'assinatura';
if (!in_array($sec, ['assinatura', 'conta'], true)) {
    $sec = 'assinatura';
}

$sr = subscription_row($store);
$events = subscription_events($storeId, 15);
$payments = store_payments($storeId, 12);
$pending = portal_pending_payment($payments);
$canCharge = portal_can_request_charge($store);

$pageTitle = match ($sec) {
    'conta' => 'Minha conta',
    default => 'Minha assinatura',
};
$pageLead = match ($sec) {
    'conta' => 'Altere a senha de acesso ao portal.',
    default => 'Plano, vigência, situação e pagamentos da sua loja.',
};

function client_nav_item(string $sec, string $key, string $label): void
{
    $active = $sec === $key ? ' active' : '';
    echo '<a class="' . $active . '" href="' . h(client_url($key === 'assinatura' ? 'painel' : 'conta')) . '">' . h($label) . '</a>';
}
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> · Portal do cliente</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="app">
<aside class="app-side">
    <a class="app-brand" href="<?= h(client_url()) ?>">
        <span class="app-mark">WL</span>
        <div>
            <strong><?= h((string) $store['name']) ?></strong>
            <small>Portal do cliente</small>
        </div>
    </a>
    <nav class="app-nav">
        <div class="app-nav-label">Área</div>
        <?php client_nav_item($sec, 'assinatura', 'Assinatura'); ?>
        <?php client_nav_item($sec, 'conta', 'Conta'); ?>
    </nav>
    <div class="app-side-foot">
        <div class="app-user"><?= h(portal_normalize_email((string) ($store['portal_email'] ?? ''))) ?></div>
        <a class="btn ghost btn-sm" href="<?= h(client_url('sair')) ?>">Sair</a>
    </div>
</aside>
<div class="app-body">
    <header class="app-top">
        <div>
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
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_pay_url'])): ?>
            <p class="pay-box">Link de pagamento<br><a href="<?= h((string) $_SESSION['flash_pay_url']) ?>" target="_blank" rel="noopener"><?= h((string) $_SESSION['flash_pay_url']) ?></a></p>
            <?php unset($_SESSION['flash_pay_url']); ?>
        <?php endif; ?>

        <?php if ($sec === 'assinatura'): ?>
            <?php
            $alert = match ($sr['billing_status']) {
                'atrasada' => 'Sua assinatura está atrasada. Regularize o pagamento para evitar suspensão do serviço.',
                'suspensa' => 'O serviço está suspenso por inadimplência. Pague a cobrança em aberto para reativar.',
                'pendente' => 'Há uma cobrança aguardando pagamento.',
                'trial' => $sr['trial_ends_at'] !== ''
                    ? 'Período de trial até ' . date('d/m/Y', strtotime($sr['trial_ends_at']) ?: time()) . '.'
                    : 'Você está no período de trial.',
                default => '',
            };
            ?>
            <?php if ($alert !== ''): ?>
                <p class="alert"><?= h($alert) ?></p>
            <?php endif; ?>

            <div class="stats">
                <article><span>Situação</span><strong><span class="tag <?= h($sr['tag_class']) ?>"><?= h($sr['billing_label']) ?></span></strong></article>
                <article><span>Plano</span><strong><?= h($sr['plan_label']) ?></strong></article>
                <article><span>Valor do ciclo</span><strong><?= $sr['cycle_amount'] !== '' ? 'R$ ' . h($sr['cycle_amount']) : '—' ?></strong></article>
                <article><span>Vigente até</span><strong><?= h($sr['paid_until'] !== '' ? date('d/m/Y', strtotime($sr['paid_until']) ?: time()) : '—') ?></strong></article>
                <article><span>Serviço</span><strong><span class="tag <?= $sr['active'] ? 'online' : 'blocked' ?>"><?= $sr['active'] ? 'Ligado' : 'Suspenso' ?></span></strong></article>
                <article><span>Cobrança auto</span><strong><?= $sr['auto_billing'] ? 'Sim' : 'Não' ?></strong></article>
            </div>

            <?php if ($pending): ?>
                <section class="card">
                    <h2>Pagamento em aberto</h2>
                    <p class="hint">Valor: <?= h(cents_label((int) $pending['amount_cents'])) ?> · gerado em <?= h(date('d/m/Y H:i', strtotime((string) $pending['created_at']) ?: time())) ?></p>
                    <div class="actions row">
                        <a class="btn" href="<?= h((string) $pending['pay_url']) ?>" target="_blank" rel="noopener">Pagar agora</a>
                    </div>
                </section>
            <?php elseif ($canCharge): ?>
                <section class="card">
                    <h2>Regularizar assinatura</h2>
                    <p class="hint">Gere um link de pagamento para o plano <?= h(strtolower($sr['plan_label'])) ?> (<?= $sr['cycle_amount'] !== '' ? 'R$ ' . h($sr['cycle_amount']) : 'valor não definido' ?>).</p>
                    <form method="post" action="<?= h(client_url()) ?>" class="form">
                        <input type="hidden" name="do" value="charge">
                        <button class="btn" type="submit">Gerar link de pagamento</button>
                    </form>
                </section>
            <?php endif; ?>

            <section class="card">
                <h2>Histórico da assinatura</h2>
                <?php if ($events): ?>
                    <ul class="steps">
                    <?php foreach ($events as $ev): ?>
                        <li><?= h(date('d/m/Y H:i', strtotime((string) $ev['created_at']) ?: time())) ?> — <?= h(subscription_label((string) $ev['to_status'])) ?><?= ($ev['note'] ?? '') !== '' ? ': ' . h((string) $ev['note']) : '' ?></li>
                    <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="hint">Nenhum evento registrado ainda.</p>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2>Pagamentos</h2>
                <?php if ($payments): ?>
                    <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Quando</th><th>Valor</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($payments as $p): ?>
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
                    <p class="hint">Nenhum pagamento registrado ainda.</p>
                <?php endif; ?>
            </section>

        <?php else: ?>
            <section class="card card-narrow">
                <h2>Alterar senha</h2>
                <form method="post" action="<?= h(client_url()) ?>" class="form">
                    <input type="hidden" name="do" value="password">
                    <label>Senha atual<input name="current_pass" type="password" required autocomplete="current-password"></label>
                    <label>Nova senha<input name="new_pass" type="password" required minlength="8" autocomplete="new-password"></label>
                    <label>Confirmar nova senha<input name="new_pass2" type="password" required minlength="8" autocomplete="new-password"></label>
                    <button class="btn" type="submit">Salvar senha</button>
                </form>
                <p class="hint">Use no mínimo 8 caracteres.</p>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
