<?php

declare(strict_types=1);

require_client_login();

$mode = client_portal_mode();
$sec = preg_replace('/[^a-z]/', '', (string) ($_GET['sec'] ?? 'assinatura')) ?: 'assinatura';
if (!in_array($sec, ['assinatura', 'conta'], true)) {
    $sec = 'assinatura';
}

function client_nav_item(string $sec, string $key, string $label): void
{
    $active = $sec === $key ? ' active' : '';
    echo '<a class="' . $active . '" href="' . h(client_url($key === 'assinatura' ? 'painel' : 'conta')) . '">' . h($label) . '</a>';
}

if ($mode === 'company') {
    $company = current_client_company();
    $companyId = (int) ($company['id'] ?? 0);
    $user = current_user();
    $sub = company_subscription_effective($companyId) ?? company_subscription($companyId);
    $payments = company_payments($companyId, 12);
    $pending = company_pending_payment($companyId) ?? portal_pending_payment($payments);
    $canCharge = portal_can_request_company_charge($sub);
    $availablePlans = all_plans(true);
    $brandName = (string) ($company['trade_name'] ?? '');
    $brandSub = 'Portal do cliente';
    $userLabel = (string) ($user['email'] ?? '');
    $pageTitle = match ($sec) {
        'conta' => 'Minha conta',
        default => 'Minha assinatura',
    };
    $pageLead = match ($sec) {
        'conta' => 'Altere a senha de acesso à sua conta.',
        default => 'Plano, vigência e pagamentos da sua empresa.',
    };
} else {
    $store = current_client_store();
    if (!$store) {
        client_redirect(client_url('entrar'));
    }
    $storeId = (int) $store['id'];
    $sr = subscription_row($store);
    $events = subscription_events($storeId, 15);
    $payments = store_payments($storeId, 12);
    $pending = portal_pending_payment($payments);
    $canCharge = portal_can_request_charge($store);
    $brandName = (string) $store['name'];
    $brandSub = 'Portal do cliente (loja)';
    $userLabel = portal_normalize_email((string) ($store['portal_email'] ?? ''));
    $pageTitle = match ($sec) {
        'conta' => 'Minha conta',
        default => 'Minha assinatura',
    };
    $pageLead = match ($sec) {
        'conta' => 'Altere a senha de acesso ao portal.',
        default => 'Plano, vigência, situação e pagamentos da sua loja.',
    };
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
        <img class="app-logo app-logo-side" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <div>
            <strong><?= h($brandName) ?></strong>
            <small><?= h($brandSub) ?></small>
        </div>
    </a>
    <nav class="app-nav">
        <div class="app-nav-label">Área</div>
        <?php client_nav_item($sec, 'assinatura', 'Assinatura'); ?>
        <?php client_nav_item($sec, 'conta', 'Conta'); ?>
        <?php if ($mode === 'company'): ?>
            <a href="/app">Painel completo</a>
        <?php endif; ?>
    </nav>
    <div class="app-side-foot">
        <div class="app-user"><?= h($userLabel) ?></div>
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

        <?php if ($mode === 'company' && $sec === 'assinatura'): ?>
            <?php
            $alert = match ((string) ($sub['billing_status'] ?? '')) {
                'atrasada' => 'Sua assinatura está atrasada. Regularize o pagamento para evitar suspensão.',
                'suspensa' => 'Assinatura suspensa. Pague a cobrança em aberto para reativar.',
                'pendente' => 'Trial encerrado ou cobrança pendente. Escolha um plano e pague para continuar.',
                'trial' => ($sub['trial_ends_at'] ?? '') !== ''
                    ? 'Trial até ' . date('d/m/Y', strtotime((string) $sub['trial_ends_at']) ?: time()) . '.'
                    : 'Você está no período de trial.',
                default => '',
            };
            ?>
            <?php if ($alert !== ''): ?><p class="alert"><?= h($alert) ?></p><?php endif; ?>

            <div class="stats">
                <article><span>Situação</span><strong><span class="tag <?= h((string) ($sub['tag_class'] ?? 'online')) ?>"><?= h((string) ($sub['billing_label'] ?? '')) ?></span></strong></article>
                <article><span>Plano</span><strong><?= h((string) ($sub['plan_name'] ?? '')) ?></strong></article>
                <article><span>Valor</span><strong><?= (int) ($sub['price_cents'] ?? 0) === 0 ? 'Grátis' : h(cents_label((int) $sub['price_cents'])) . '/mês' ?></strong></article>
                <article><span>Vigência</span><strong><?= h(($sub['ends_at'] ?? '') !== '' ? date('d/m/Y', strtotime((string) $sub['ends_at']) ?: time()) : '—') ?></strong></article>
                <article><span>Serviço</span><strong><span class="tag <?= !empty($sub['service_allowed']) ? 'online' : 'blocked' ?>"><?= !empty($sub['service_allowed']) ? 'Ativo' : 'Suspenso' ?></span></strong></article>
            </div>

            <?php if ($pending && !empty($pending['pay_url'])): ?>
                <section class="card">
                    <h2>Pagamento em aberto</h2>
                    <p class="hint">Valor: <?= h(cents_label((int) $pending['amount_cents'])) ?></p>
                    <a class="btn" href="<?= h((string) $pending['pay_url']) ?>" target="_blank" rel="noopener">Pagar agora</a>
                </section>
            <?php endif; ?>

            <section class="card">
                <h2>Planos</h2>
                <?php if (!payment_configured()): ?>
                    <p class="hint">Pagamento online não configurado. Fale com o suporte.</p>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Plano</th><th>Preço</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($availablePlans as $p): ?>
                            <tr>
                                <td><strong><?= h((string) $p['name']) ?></strong></td>
                                <td><?= (int) $p['price_cents'] === 0 ? 'Grátis' : h(cents_label((int) $p['price_cents'])) . '/mês' ?></td>
                                <td>
                                    <?php if ((int) ($sub['plan_id'] ?? 0) === (int) $p['id']): ?>
                                        <span class="tag online">Atual</span>
                                    <?php elseif ((int) $p['price_cents'] === 0): ?>
                                        <form method="post" action="/app/assinatura" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="do" value="plan">
                                            <input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
                                            <button class="btn ghost btn-sm" type="submit">Selecionar</button>
                                        </form>
                                    <?php elseif ($canCharge || $pending === null): ?>
                                        <form method="post" action="<?= h(client_url()) ?>" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="do" value="charge">
                                            <input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
                                            <button class="btn btn-sm" type="submit">Assinar</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if ($payments): ?>
            <section class="card">
                <h2>Pagamentos</h2>
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
            </section>
            <?php endif; ?>

        <?php elseif ($mode === 'store' && $sec === 'assinatura'): ?>
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
            <?php if ($alert !== ''): ?><p class="alert"><?= h($alert) ?></p><?php endif; ?>

            <div class="stats">
                <article><span>Situação</span><strong><span class="tag <?= h($sr['tag_class']) ?>"><?= h($sr['billing_label']) ?></span></strong></article>
                <article><span>Plano</span><strong><?= h($sr['plan_label']) ?></strong></article>
                <article><span>Valor do ciclo</span><strong><?= $sr['cycle_amount'] !== '' ? 'R$ ' . h($sr['cycle_amount']) : '—' ?></strong></article>
                <article><span>Vigente até</span><strong><?= h($sr['paid_until'] !== '' ? date('d/m/Y', strtotime($sr['paid_until']) ?: time()) : '—') ?></strong></article>
                <article><span>Serviço</span><strong><span class="tag <?= $sr['active'] ? 'online' : 'blocked' ?>"><?= $sr['active'] ? 'Ligado' : 'Suspenso' ?></span></strong></article>
            </div>

            <?php if ($pending): ?>
                <section class="card">
                    <h2>Pagamento em aberto</h2>
                    <p class="hint">Valor: <?= h(cents_label((int) $pending['amount_cents'])) ?></p>
                    <a class="btn" href="<?= h((string) $pending['pay_url']) ?>" target="_blank" rel="noopener">Pagar agora</a>
                </section>
            <?php elseif ($canCharge): ?>
                <section class="card">
                    <h2>Regularizar assinatura</h2>
                    <form method="post" action="<?= h(client_url()) ?>" class="form">
                        <?= csrf_field() ?>
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

            <?php if ($payments): ?>
            <section class="card">
                <h2>Pagamentos</h2>
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
            </section>
            <?php endif; ?>

        <?php else: ?>
            <section class="card card-narrow">
                <h2>Alterar senha</h2>
                <form method="post" action="<?= h(client_url()) ?>" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="password">
                    <label>Senha atual<input name="current_pass" type="password" required autocomplete="current-password"></label>
                    <label>Nova senha<input name="new_pass" type="password" required minlength="8" autocomplete="new-password"></label>
                    <label>Confirmar nova senha<input name="new_pass2" type="password" required minlength="8" autocomplete="new-password"></label>
                    <button class="btn" type="submit">Salvar senha</button>
                </form>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
