<?php

declare(strict_types=1);

$client = current_client();
$online = client_is_online($client);
$full = !$online && online_count() >= max_clients();

if (!$full && (!$client || in_array($client['state'], ['expired', 'blocked'], true) || ($client['state'] === 'pending' && (time() - strtotime($client['created_at'])) > 3600))) {
    $code = random_code();
    $text = status_message($code);
    $stmt = db()->prepare(
        'INSERT INTO clients (store_id, ip, mac, status_code, status_text, state, user_agent, created_at) VALUES (?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        local_store_id(),
        client_ip(),
        lookup_mac(client_ip()),
        $code,
        $text,
        'pending',
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
        date('Y-m-d H:i:s'),
    ]);
    $client = current_client();
}

$store = setting('store_name', 'nossa loja');
$brand = brand_image_url();
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wi-Fi <?= h($store) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="page portal">
<main class="card">
    <?php if ($brand): ?>
        <img class="brand-logo" src="<?= h($brand) ?>" alt="<?= h($store) ?>">
    <?php endif; ?>
    <p class="eyebrow"><?= h($store) ?></p>
    <?php if ($full && !$online): ?>
        <h1>Rede cheia</h1>
        <p class="lead">Já há 8 aparelhos usando o Wi-Fi. Tente de novo daqui a pouco.</p>
    <?php elseif ($online): ?>
        <h1>Internet liberada</h1>
        <p class="lead">Pode navegar. O acesso vale até <?= h(date('H:i', strtotime((string) $client['expires_at']))) ?>.</p>
        <p class="code-pill">Código do status: <?= h($client['status_code']) ?></p>
    <?php elseif ($client && $client['state'] === 'awaiting_approval'): ?>
        <h1>Aguardando o balcão</h1>
        <p class="lead">Mostre este código para a loja confirmar o status e liberar o Wi-Fi.</p>
        <p class="code-big"><?= h($client['status_code']) ?></p>
        <button class="btn ghost" id="refresh">Já confirmaram? Atualizar</button>
    <?php elseif ($client): ?>
        <h1>Wi-Fi em troca do status</h1>
        <p class="lead">Publique um status no WhatsApp dizendo que está aqui. Use o texto e a arte abaixo — o código prova que foi nesta visita.</p>
        <p class="code-big"><?= h($client['status_code']) ?></p>
        <blockquote class="status-preview"><?= h($client['status_text']) ?></blockquote>
        <img class="story-img" src="/story/<?= h($client['status_code']) ?>.png" alt="Arte para o status">
        <ol class="steps">
            <li>Toque em <strong>Publicar no WhatsApp</strong></li>
            <li>Escolha <strong>Status</strong> (não um contato)</li>
            <li>Publique e volte aqui para liberar a internet</li>
        </ol>
        <p class="hint">O WhatsApp não avisa o sistema se o status saiu. O código <?= h($client['status_code']) ?> fica visível na arte para a loja conferir.</p>
        <label>Seu WhatsApp (opcional)<input id="phone" type="tel" inputmode="tel" placeholder="11 99999-0000"></label>
        <div class="actions">
            <button class="btn" id="share" data-text="<?= h($client['status_text']) ?>" data-code="<?= h($client['status_code']) ?>">Publicar no WhatsApp</button>
            <button class="btn ghost" id="confirm">Já publiquei o status</button>
        </div>
    <?php endif; ?>
</main>
<script src="/assets/portal.js"></script>
</body>
</html>
