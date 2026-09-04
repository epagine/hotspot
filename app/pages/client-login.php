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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="saas-auth">
<section class="saas-auth-card saas-auth">
    <div class="saas-auth-brand">
        <img class="saas-auth-logo" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <span class="saas-auth-kicker">Portal do cliente</span>
    </div>
    <h1 class="saas-auth-title">Entrar</h1>
    <p class="saas-auth-lead">Consulte assinatura, pagamentos e links de cobrança da sua empresa.</p>
    <?php if ($error): ?>
        <div class="alert"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= h(client_url('entrar')) ?>" class="form">
        <?= csrf_field() ?>
        <label>
            E-mail
            <input name="email" type="email" required autofocus autocomplete="username">
        </label>
        <label>
            Senha
            <input name="pass" type="password" required autocomplete="current-password">
        </label>
        <button type="submit" class="btn btn-block">Entrar</button>
    </form>
    <p class="saas-auth-footer">Use o e-mail da sua conta Wi-Fi da loja. <a href="/entrar">Painel completo</a></p>
</section>
</body>
</html>
