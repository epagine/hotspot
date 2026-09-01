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
$error = '';
$done = false;
$campaign = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?? '';
    $email = trim((string) ($_POST['email'] ?? ''));
    $terms = !empty($_POST['terms']);
    if (!empty($pc['require_name']) && $name === '') {
        $error = 'Informe seu nome.';
    } elseif (!empty($pc['require_phone']) && strlen($phone) < 10) {
        $error = 'Informe um WhatsApp válido.';
    } elseif (!empty($pc['require_terms']) && !$terms) {
        $error = 'Aceite os termos para continuar.';
    } else {
        try {
            $result = portal_register_guest($store, $companyId, $hotspotId, $name, $phone, $email, $terms, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $campaign = $result['campaign'];
            $done = true;
            $guestName = $name;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

$brand = '';
try {
    $path = brand_image_path_for($hotspotId);
    if (is_file($path)) {
        $brand = '/admin/clientes/' . $hotspotId . '/marca.png';
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
    <link rel="stylesheet" href="/assets/app.css">
    <style>
        body.portal-v2 { background: linear-gradient(180deg, <?= h($primary) ?>22, #f3f5f8 45%); min-height:100vh; padding:24px 16px; }
        .portal-card { max-width:420px; margin:0 auto; background:#fff; border:1px solid #dde3ea; border-radius:18px; padding:28px 22px; }
        .portal-card h1 { font-size:24px; margin:12px 0; color:#15202b; }
        .portal-logo { max-height:64px; max-width:180px; display:block; margin:0 auto 8px; }
    </style>
</head>
<body class="portal-v2">
<section class="portal-card">
    <?php if ($brand): ?><img class="portal-logo" src="<?= h($brand) ?>" alt=""><?php endif; ?>
    <?php if ($done): ?>
        <h1>Internet liberada</h1>
        <p class="lead">Obrigado<?= $guestName !== '' ? ', ' . h($guestName) : '' ?>! Você já pode navegar.</p>
        <?php if ($campaign): ?>
            <div style="margin-top:20px;padding:16px;border-radius:12px;background:#fff4df;border:1px solid #e8c988">
                <strong><?= h((string) $campaign['title']) ?></strong>
                <p><?= h((string) $campaign['description']) ?></p>
                <?php if (($campaign['button_url'] ?? '') !== ''): ?>
                    <a class="btn" href="<?= h((string) $campaign['button_url']) ?>" target="_blank" rel="noopener"
                       onclick="fetch('/api/v1/campaign/click',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({campaign_id:<?= (int) $campaign['id'] ?>,company_id:<?= (int) $companyId ?>})})">
                        <?= h((string) ($campaign['button_label'] ?: 'Ver oferta')) ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <h1><?= h((string) ($pc['title'] ?? 'Bem-vindo')) ?></h1>
        <p class="lead"><?= h((string) ($pc['subtitle'] ?? 'Conecte-se gratuitamente')) ?></p>
        <?php if ($error): ?><p class="alert"><?= h($error) ?></p><?php endif; ?>
        <form method="post" class="form">
            <?php if (!empty($pc['require_name'])): ?>
                <label>Nome<input name="name" required value="<?= h((string) ($_POST['name'] ?? '')) ?>"></label>
            <?php endif; ?>
            <?php if (!empty($pc['require_phone'])): ?>
                <label>WhatsApp<input name="phone" inputmode="tel" required value="<?= h((string) ($_POST['phone'] ?? '')) ?>"></label>
            <?php endif; ?>
            <?php if (!empty($pc['require_email'])): ?>
                <label>E-mail<input type="email" name="email" value="<?= h((string) ($_POST['email'] ?? '')) ?>"></label>
            <?php endif; ?>
            <?php if (!empty($pc['require_terms'])): ?>
                <label class="check"><input type="checkbox" name="terms" value="1" required> Aceito os termos e a política de privacidade</label>
                <?php if (($store['terms_html'] ?? '') !== ''): ?><p class="hint"><?= nl2br(h((string) $store['terms_html'])) ?></p><?php endif; ?>
            <?php endif; ?>
            <button class="btn" type="submit" style="background:<?= h($primary) ?>"><?= h((string) ($pc['button_label'] ?? 'Conectar à internet')) ?></button>
        </form>
    <?php endif; ?>
</section>
</body>
</html>
