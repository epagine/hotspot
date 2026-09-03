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
    <?php require __DIR__ . '/../partials/tw-head.php'; ?>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="font-sans bg-surface text-ink min-h-screen grid grid-cols-1 lg:grid-cols-[260px_1fr]">
<aside id="app-sidebar" class="bg-white border-r border-line p-4 flex flex-col gap-6 sticky top-0 h-screen overflow-y-auto max-lg:h-auto max-lg:sticky max-lg:z-20 max-lg:flex-row max-lg:flex-wrap max-lg:items-center max-lg:gap-3 max-lg:p-3 max-lg:border-b max-lg:border-r-0 transition-all" data-sidebar>
    <a class="flex items-center gap-3 no-underline text-inherit" href="<?= h(client_url()) ?>">
        <img class="w-10 h-10 rounded-[10px] bg-white object-cover object-left-center flex-shrink-0" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <div class="max-lg:hidden">
            <strong class="block text-sm"><?= h($brandName) ?></strong>
            <span class="text-xs text-muted"><?= h($brandSub) ?></span>
        </div>
    </a>
    <button type="button" id="app-hamburger" aria-label="Menu" aria-expanded="false"
            class="hidden max-lg:flex ml-auto flex-col gap-[5px] items-center justify-center p-1.5 bg-transparent border-0 cursor-pointer">
        <span class="block w-[22px] h-[2px] bg-ink rounded-sm transition-transform"></span>
        <span class="block w-[22px] h-[2px] bg-ink rounded-sm transition-opacity"></span>
        <span class="block w-[22px] h-[2px] bg-ink rounded-sm transition-transform"></span>
    </button>
    <nav class="flex flex-col gap-1 flex-1 max-lg:hidden" data-nav>
        <div class="text-[11px] tracking-wider uppercase text-muted px-3 pt-4 pb-1">Área</div>
        <?php
        $clientNavItems = [
            ['assinatura', 'Assinatura', client_url('painel')],
            ['conta', 'Conta', client_url('conta')],
        ];
        foreach ($clientNavItems as [$navKey, $navLabel, $navHref]):
            $navActive = $sec === $navKey;
        ?>
            <a href="<?= h($navHref) ?>" class="flex items-center gap-2.5 px-3 py-2.5 rounded-[10px] text-sm font-semibold no-underline transition <?= $navActive ? 'bg-accent/10 text-accent' : 'text-muted hover:bg-hover hover:text-ink' ?>"><?= h($navLabel) ?></a>
        <?php endforeach; ?>
        <?php if ($mode === 'company'): ?>
            <a href="/app" class="flex items-center gap-2.5 px-3 py-2.5 rounded-[10px] text-sm font-semibold text-muted hover:bg-hover hover:text-ink no-underline transition">Painel completo</a>
        <?php endif; ?>
    </nav>
    <div class="border-t border-line pt-3 max-lg:hidden" data-foot>
        <div class="text-xs text-muted px-3 mb-2"><?= h($userLabel) ?></div>
        <a class="inline-block text-sm font-semibold text-muted border border-line rounded-btn px-3 py-2 hover:text-ink hover:border-ink/20 transition no-underline" href="<?= h(client_url('sair')) ?>">Sair</a>
    </div>
</aside>
<div class="min-w-0 flex flex-col">
    <header class="px-8 pt-6 pb-0 max-md:px-4">
        <h1 class="text-2xl font-bold tracking-tight"><?= h($pageTitle) ?></h1>
        <p class="text-muted text-sm mt-1"><?= h($pageLead) ?></p>
    </header>
    <main class="px-8 py-6 max-w-[1180px] w-full max-md:px-4">
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
<script>
(function(){
  var btn=document.getElementById('app-hamburger'),side=document.getElementById('app-sidebar');
  if(!btn||!side)return;
  var nav=side.querySelector('[data-nav]'),foot=side.querySelector('[data-foot]');
  btn.addEventListener('click',function(){
    var open=!nav.classList.contains('max-lg:hidden')||nav.classList.contains('!flex');
    if(open){nav.classList.remove('!flex','!flex-col');nav.classList.add('max-lg:hidden');if(foot)foot.classList.add('max-lg:hidden');}
    else{nav.classList.add('!flex','!flex-col');nav.classList.remove('max-lg:hidden');if(foot){foot.classList.remove('max-lg:hidden');}}
    btn.setAttribute('aria-expanded',(!open)?'true':'false');
  });
  side.querySelectorAll('[data-nav] a').forEach(function(a){
    a.addEventListener('click',function(){nav.classList.add('max-lg:hidden');if(foot)foot.classList.add('max-lg:hidden');btn.setAttribute('aria-expanded','false');});
  });
})();
</script>
</body>
</html>
