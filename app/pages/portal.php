<?php

declare(strict_types=1);

$token = trim((string) ($GLOBALS['portal_token'] ?? ''));
$store = portal_store_from_token($token);
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
$ssid = setting_for_store($hotspotId, 'wifi_ssid', setting('wifi_ssid', 'WifiDaLoja'));
$portalIp = setting_for_store($hotspotId, 'portal_ip', '192.168.137.1');
$storeLabel = trim((string) (setting_for_store($hotspotId, 'store_name', '') ?: ($store['name'] ?? 'Wi-Fi')));

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
    <link rel="stylesheet" href="/assets/app.css">
    <style>:root{--portal-primary:<?= h($primary) ?>}</style>
</head>
<body class="saas-portal saas-portal--branded page portal">
<section class="saas-portal-card">
    <?php if ($brand): ?><img class="brand-logo" src="<?= h($brand) ?>" alt=""><?php endif; ?>
    <p class="eyebrow"><?= h($storeLabel) ?></p>
    <?php if ($portalBlocked): ?>
        <h1>Wi-Fi indisponível</h1>
        <p class="lead">Este hotspot está temporariamente fora do ar.</p>
        <p class="hint">Situação: <?= h(portal_blocked_label($store)) ?>.</p>
    <?php else: ?>
        <h1>Conecte-se ao Wi-Fi da loja</h1>
        <p class="lead">A internet é liberada no PC da loja, não neste site. Entre na rede <strong><?= h($ssid) ?></strong> e aguarde a tela de login (ou abra <code>http://<?= h($portalIp) ?></code> no celular).</p>
        <ol class="steps">
            <li>Conecte no Wi-Fi <strong><?= h($ssid) ?></strong></li>
            <li>Publique o status no WhatsApp quando a tela local pedir</li>
            <li>Toque em <strong>Já publiquei — liberar internet</strong></li>
        </ol>
        <p class="hint">Gestão da loja: painel admin e portal do cliente. Conexões: agente Windows.</p>
    <?php endif; ?>
</section>
</body>
</html>
