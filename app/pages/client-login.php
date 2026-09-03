<?php

declare(strict_types=1);

if (client_portal_mode() !== null) {
    client_redirect(client_url());
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (rate_limit_is_blocked('login')) {
        $error = rate_limit_reject_message();
    } else {
    $email = (string) ($_POST['email'] ?? '');
    $pass = (string) ($_POST['pass'] ?? '');
    if (portal_try_company_login($email, $pass)) {
        rate_limit_clear('login');
        client_redirect(client_url());
    }
    $store = portal_try_login($email, $pass);
    if ($store) {
        unset($_SESSION['user_id'], $_SESSION['company_id']);
        $_SESSION['client_store_id'] = (int) $store['id'];
        rate_limit_clear('login');
        client_redirect(client_url());
    }
    rate_limit_fail('login');
    $error = 'E-mail ou senha inválidos.';
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · Portal do cliente</title>
    <?php require __DIR__ . '/../partials/tw-head.php'; ?>
</head>
<body class="bg-gradient-to-b from-surface to-white min-h-screen flex items-center justify-center p-4 font-sans">
<section class="w-full max-w-md bg-card border border-line rounded-2xl shadow-lg p-8">
    <div class="flex flex-col items-center mb-4">
        <img class="h-14 w-auto rounded-xl bg-black object-contain" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <span class="mt-2 text-xs text-muted font-semibold">Portal do cliente</span>
    </div>
    <h1 class="text-2xl font-bold text-ink text-center">Entrar</h1>
    <p class="text-muted text-sm text-center mt-1 mb-6">Consulte assinatura, pagamentos e links de cobrança da sua empresa.</p>
    <?php if ($error): ?>
        <div class="bg-danger-bg text-danger border border-danger/20 rounded-xl px-4 py-3 text-sm mb-4"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= h(client_url('entrar')) ?>" class="space-y-4">
        <?= csrf_field() ?>
        <label class="block">
            <span class="text-sm font-medium text-muted">E-mail</span>
            <input name="email" type="email" required autofocus autocomplete="username"
                   class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
        </label>
        <label class="block">
            <span class="text-sm font-medium text-muted">Senha</span>
            <input name="pass" type="password" required autocomplete="current-password"
                   class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
        </label>
        <button type="submit" class="w-full bg-accent hover:bg-accent/90 text-white font-bold py-3 px-4 rounded-btn transition">Entrar</button>
    </form>
    <p class="text-muted text-sm text-center mt-6">Use o e-mail da sua conta Wi-Fi da loja. <a href="/entrar" class="text-accent hover:underline font-semibold">Painel completo</a></p>
</section>
</body>
</html>
