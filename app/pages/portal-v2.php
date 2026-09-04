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
$primary = (string) (($pc['primary_color'] ?? '') ?: ($company['primary_color'] ?? '#18181b'));
$portalBlocked = !portal_access_allowed($store);
$error = '';
$done = false;
$campaign = null;
$guestName = '';

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
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h((string) ($pc['title'] ?? 'Wi-Fi')) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/app.css">
    <style>:root{--portal-primary:<?= h($primary) ?>}</style>
</head>
<body class="saas-portal saas-portal--branded">
<section class="saas-portal-card">
    <?php if ($brand): ?><img class="brand-logo" src="<?= h($brand) ?>" alt=""><?php endif; ?>
    <?php if ($portalBlocked): ?>
        <h1>Wi-Fi indisponível</h1>
        <p class="lead">Este hotspot está temporariamente fora do ar.</p>
        <p class="hint">Situação: <?= h(portal_blocked_label($store)) ?>. Entre em contato com o estabelecimento.</p>
    <?php elseif ($done): ?>
        <h1>Internet liberada</h1>
        <p class="lead">Obrigado<?= $guestName !== '' ? ', ' . h($guestName) : '' ?>! Você já pode navegar.</p>
        <?php if ($campaign): ?>
            <div class="saas-portal-campaign">
                <strong><?= h((string) $campaign['title']) ?></strong>
                <p><?= h((string) $campaign['description']) ?></p>
                <?php if (($campaign['button_url'] ?? '') !== ''): ?>
                    <a class="btn btn-sm" href="<?= h((string) $campaign['button_url']) ?>" target="_blank" rel="noopener"
                       onclick="fetch('/api/v1/campaign/click',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:<?= json_encode($token) ?>,campaign_id:<?= (int) $campaign['id'] ?>})})">
                        <?= h((string) ($campaign['button_label'] ?: 'Ver oferta')) ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <h1><?= h((string) ($pc['title'] ?? 'Bem-vindo')) ?></h1>
        <p class="lead"><?= h((string) ($pc['subtitle'] ?? 'Conecte-se gratuitamente')) ?></p>
        <?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="form">
            <?php if (!empty($pc['require_name'])): ?>
                <label>
                    Nome
                    <input name="name" required value="<?= h((string) ($_POST['name'] ?? '')) ?>">
                </label>
            <?php endif; ?>
            <?php if (!empty($pc['require_phone'])): ?>
                <label>
                    WhatsApp
                    <input name="phone" inputmode="tel" required value="<?= h((string) ($_POST['phone'] ?? '')) ?>">
                </label>
            <?php endif; ?>
            <?php if (!empty($pc['require_email'])): ?>
                <label>
                    E-mail
                    <input type="email" name="email" value="<?= h((string) ($_POST['email'] ?? '')) ?>">
                </label>
            <?php endif; ?>
            <?php if (!empty($pc['require_terms'])): ?>
                <label class="check">
                    <input type="checkbox" name="terms" value="1" required>
                    Aceito os termos e a política de privacidade
                </label>
                <?php if (($store['terms_html'] ?? '') !== ''): ?><p class="hint"><?= nl2br(h((string) $store['terms_html'])) ?></p><?php endif; ?>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary"><?= h((string) ($pc['button_label'] ?? 'Conectar à internet')) ?></button>
        </form>
    <?php endif; ?>
</section>
</body>
</html>
