<?php

declare(strict_types=1);

$token = trim((string) ($GLOBALS['portal_token'] ?? ''));
$store = find_store_by_token($token);
if (!$store) {
    http_response_code(404);
    echo 'Portal não encontrado.';
    exit;
}

$hotspotId = (int) $store['id'];
$companyId = (int) ($store['company_id'] ?? 0);
$pc = portal_config_for($hotspotId);
$company = $companyId > 0 ? find_company($companyId) : null;
$primary = (string) (($pc['primary_color'] ?? '') ?: ($company['primary_color'] ?? '#c8892a'));
$portalBlocked = !portal_access_allowed($store);
$error = '';
$done = false;
$campaign = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$portalBlocked) {
    if (rate_limit_is_blocked('portal')) {
        $error = rate_limit_reject_message();
    } else {
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?? '';
    $email = trim((string) ($_POST['email'] ?? ''));
    $terms = !empty($_POST['terms']);
    if (!empty($pc['require_name']) && $name === '') {
        rate_limit_fail('portal');
        $error = 'Informe seu nome.';
    } elseif (!empty($pc['require_phone']) && strlen($phone) < 10) {
        rate_limit_fail('portal');
        $error = 'Informe um WhatsApp válido.';
    } elseif (!empty($pc['require_terms']) && !$terms) {
        rate_limit_fail('portal');
        $error = 'Aceite os termos para continuar.';
    } else {
        try {
            $result = portal_register_guest($store, $companyId, $hotspotId, $name, $phone, $email, $terms, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $campaign = $result['campaign'];
            $done = true;
            $guestName = $name;
            rate_limit_clear('portal');
        } catch (RuntimeException $e) {
            rate_limit_fail('portal');
            $error = $e->getMessage();
        }
    }
    }
}

$brand = '';
try {
    $path = brand_image_path_for($hotspotId);
    if (is_file($path)) {
        $brand = '/hotspots/' . $hotspotId . '/marca.png';
    }
} catch (Throwable $e) {
}
$guestName = $guestName ?? '';
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h((string) ($pc['title'] ?? 'Wi-Fi')) ?></title>
    <?php require __DIR__ . '/../partials/tw-head.php'; ?>
    <style>:root{--portal-primary:<?= h($primary) ?>}</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 sm:p-6 font-sans" style="background:linear-gradient(180deg,<?= h($primary) ?>22,#f3f5f8 45%)">
<section class="w-full max-w-md bg-white border border-line rounded-2xl shadow-lg p-7 sm:p-8">
    <?php if ($brand): ?><img class="h-16 max-w-[180px] object-contain mx-auto mb-3" src="<?= h($brand) ?>" alt=""><?php endif; ?>
    <?php if ($portalBlocked): ?>
        <h1 class="text-2xl font-bold text-ink text-center">Wi-Fi indisponível</h1>
        <p class="text-muted text-center mt-2">Este hotspot está temporariamente fora do ar.</p>
        <p class="text-sm text-muted text-center mt-2">Situação: <?= h(portal_blocked_label($store)) ?>. Entre em contato com o estabelecimento.</p>
    <?php elseif ($done): ?>
        <h1 class="text-2xl font-bold text-ink text-center">Internet liberada</h1>
        <p class="text-muted text-center mt-2">Obrigado<?= $guestName !== '' ? ', ' . h($guestName) : '' ?>! Você já pode navegar.</p>
        <?php if ($campaign): ?>
            <div class="mt-5 p-4 rounded-xl bg-warn-bg border border-warn/30">
                <strong class="block text-ink"><?= h((string) $campaign['title']) ?></strong>
                <p class="text-sm text-ink/80 mt-1"><?= h((string) $campaign['description']) ?></p>
                <?php if (($campaign['button_url'] ?? '') !== ''): ?>
                    <a class="mt-3 inline-block font-bold text-white py-2.5 px-5 rounded-btn transition" style="background:var(--portal-primary)" href="<?= h((string) $campaign['button_url']) ?>" target="_blank" rel="noopener"
                       onclick="fetch('/api/v1/campaign/click',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:<?= json_encode($token) ?>,campaign_id:<?= (int) $campaign['id'] ?>})})">
                        <?= h((string) ($campaign['button_label'] ?: 'Ver oferta')) ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <h1 class="text-2xl font-bold text-ink text-center"><?= h((string) ($pc['title'] ?? 'Bem-vindo')) ?></h1>
        <p class="text-muted text-center mt-1 mb-5"><?= h((string) ($pc['subtitle'] ?? 'Conecte-se gratuitamente')) ?></p>
        <?php if ($error): ?><div class="bg-danger-bg text-danger border border-danger/20 rounded-xl px-4 py-3 text-sm mb-4"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="space-y-4">
            <?php if (!empty($pc['require_name'])): ?>
                <label class="block">
                    <span class="text-sm font-medium text-muted">Nome</span>
                    <input name="name" required value="<?= h((string) ($_POST['name'] ?? '')) ?>" class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-[var(--portal-primary)]/30 focus:border-[var(--portal-primary)] transition">
                </label>
            <?php endif; ?>
            <?php if (!empty($pc['require_phone'])): ?>
                <label class="block">
                    <span class="text-sm font-medium text-muted">WhatsApp</span>
                    <input name="phone" inputmode="tel" required value="<?= h((string) ($_POST['phone'] ?? '')) ?>" class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-[var(--portal-primary)]/30 focus:border-[var(--portal-primary)] transition">
                </label>
            <?php endif; ?>
            <?php if (!empty($pc['require_email'])): ?>
                <label class="block">
                    <span class="text-sm font-medium text-muted">E-mail</span>
                    <input type="email" name="email" value="<?= h((string) ($_POST['email'] ?? '')) ?>" class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-[var(--portal-primary)]/30 focus:border-[var(--portal-primary)] transition">
                </label>
            <?php endif; ?>
            <?php if (!empty($pc['require_terms'])): ?>
                <label class="flex items-start gap-2 text-sm text-ink cursor-pointer">
                    <input type="checkbox" name="terms" value="1" required class="mt-0.5 w-4 h-4 rounded border-line">
                    Aceito os termos e a política de privacidade
                </label>
                <?php if (($store['terms_html'] ?? '') !== ''): ?><p class="text-xs text-muted"><?= nl2br(h((string) $store['terms_html'])) ?></p><?php endif; ?>
            <?php endif; ?>
            <button type="submit" class="w-full font-bold text-white py-3 px-4 rounded-btn transition hover:opacity-90" style="background:var(--portal-primary)"><?= h((string) ($pc['button_label'] ?? 'Conectar à internet')) ?></button>
        </form>
    <?php endif; ?>
</section>
</body>
</html>
