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
$portalRoot = '/portal/' . rawurlencode($token);
$error = '';
$campaign = null;
$guestName = '';
$ip = client_ip();
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

$client = current_client();
$online = client_is_online($client);
$maxClients = portal_store_max_clients($hotspotId);
$full = !$online && portal_store_online_count($hotspotId) >= $maxClients;

if (!$portalBlocked && !$full && !$online) {
    $stale = $client && (
        in_array($client['state'], ['expired', 'blocked'], true)
        || (($client['state'] ?? '') === 'pending' && (time() - strtotime((string) $client['created_at'])) > 3600)
    );
    if ($stale) {
        $client = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$portalBlocked && !$full && !$online && (!$client || ($client['state'] ?? '') !== 'pending')) {
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
                portal_begin_guest($store, $companyId, $hotspotId, $name, $phone, $email, $terms, $ip, $ua);
                $client = current_client();
                $guestName = $name;
                rate_limit_clear('portal');
            } catch (RuntimeException $e) {
                rate_limit_fail('portal');
                $error = $e->getMessage();
            }
        }
    }
}

if ($online) {
    $guestName = (string) ($client['name'] ?? '');
    $campaign = portal_guest_campaign($companyId, $hotspotId, (int) $client['id']);
}

$brand = '';
try {
    $path = brand_image_path_for($hotspotId);
    if (is_file($path)) {
        $brand = '/hotspots/' . $hotspotId . '/marca.png';
    }
} catch (Throwable $e) {
}

$storeLabel = trim((string) (setting_for_store($hotspotId, 'store_name', '') ?: ($store['name'] ?? 'Wi-Fi')));
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
<body class="saas-portal saas-portal--branded page portal" data-portal-root="<?= h($portalRoot) ?>">
<section class="saas-portal-card">
    <?php if ($brand): ?><img class="brand-logo" src="<?= h($brand) ?>" alt=""><?php endif; ?>
    <p class="eyebrow"><?= h($storeLabel) ?></p>

    <?php if ($portalBlocked): ?>
        <h1>Wi-Fi indisponível</h1>
        <p class="lead">Este hotspot está temporariamente fora do ar.</p>
        <p class="hint">Situação: <?= h(portal_blocked_label($store)) ?>. Entre em contato com o estabelecimento.</p>

    <?php elseif ($full && !$online): ?>
        <h1>Rede cheia</h1>
        <p class="lead">Já há <?= (int) $maxClients ?> aparelhos usando o Wi-Fi. Tente de novo daqui a pouco.</p>

    <?php elseif ($online): ?>
        <h1>Internet liberada</h1>
        <p class="lead">Obrigado<?= $guestName !== '' ? ', ' . h($guestName) : '' ?>! Você já pode navegar<?= !empty($client['expires_at']) ? ' até ' . h(date('H:i', strtotime((string) $client['expires_at']))) : '' ?>.</p>
        <?php if (!empty($client['status_code'])): ?>
            <p class="code-pill">Código do status: <?= h((string) $client['status_code']) ?></p>
        <?php endif; ?>
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

    <?php elseif ($client && ($client['state'] ?? '') === 'awaiting_approval'): ?>
        <h1>Aguardando o balcão</h1>
        <p class="lead">Mostre este código para a loja confirmar o status e liberar o Wi-Fi.</p>
        <p class="code-big"><?= h((string) $client['status_code']) ?></p>
        <button class="btn ghost" type="button" id="refresh">Já confirmaram? Atualizar</button>

    <?php elseif ($client && ($client['state'] ?? '') === 'pending'): ?>
        <h1>Wi-Fi em troca do status</h1>
        <p class="lead">Publique um status no WhatsApp dizendo que está aqui. Use o texto e a arte abaixo — o código prova que foi nesta visita.</p>
        <p class="code-big"><?= h((string) $client['status_code']) ?></p>
        <blockquote class="status-preview"><?= h((string) $client['status_text']) ?></blockquote>
        <img class="story-img" src="<?= h($portalRoot) ?>/arte/<?= h((string) $client['status_code']) ?>.png" alt="Arte para o status">
        <ol class="steps">
            <li>Toque em <strong>Publicar no WhatsApp</strong></li>
            <li>Escolha <strong>Status</strong> (não um contato)</li>
            <li>Publique e volte aqui para liberar a internet</li>
        </ol>
        <p class="hint">O WhatsApp não avisa o sistema se o status saiu. O código <?= h((string) $client['status_code']) ?> fica visível na arte para a loja conferir.</p>
        <?php if (empty($pc['require_phone'])): ?>
            <label>Seu WhatsApp (opcional)<input id="phone" type="tel" inputmode="tel" placeholder="11 99999-0000"></label>
        <?php endif; ?>
        <div class="actions">
            <button class="btn btn-primary" type="button" id="share"
                data-text="<?= h((string) $client['status_text']) ?>"
                data-code="<?= h((string) $client['status_code']) ?>">Publicar no WhatsApp</button>
            <button class="btn ghost" type="button" id="confirm">Já publiquei o status</button>
        </div>

    <?php else: ?>
        <h1><?= h((string) ($pc['title'] ?? 'Bem-vindo')) ?></h1>
        <p class="lead"><?= h((string) ($pc['subtitle'] ?? 'Conecte-se gratuitamente ao Wi-Fi')) ?></p>
        <p class="hint">Depois de continuar, publique um status no WhatsApp para liberar a internet.</p>
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
            <button type="submit" class="btn btn-primary"><?= h((string) ($pc['button_label'] ?? 'Continuar')) ?></button>
        </form>
    <?php endif; ?>
</section>
<script src="/assets/portal.js"></script>
</body>
</html>
