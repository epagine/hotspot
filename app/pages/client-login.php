<?php

declare(strict_types=1);

if (client_portal_mode() !== null) {
    client_redirect(client_url());
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = (string) ($_POST['email'] ?? '');
    $pass = (string) ($_POST['pass'] ?? '');
    if (portal_try_company_login($email, $pass)) {
        client_redirect(client_url());
    }
    $store = portal_try_login($email, $pass);
    if ($store) {
        unset($_SESSION['user_id'], $_SESSION['company_id']);
        $_SESSION['client_store_id'] = (int) $store['id'];
        client_redirect(client_url());
    }
    $error = 'E-mail ou senha inválidos.';
}
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · Portal do cliente</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="app-auth">
<section class="card">
    <div class="app-brand app-brand-logo">
        <img class="app-logo" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <p class="hint" style="margin:8px 0 0;text-align:center">Portal do cliente</p>
    </div>
    <h1>Entrar</h1>
    <p class="lead">Consulte assinatura, pagamentos e links de cobrança da sua empresa.</p>
    <?php if ($error): ?><p class="alert"><?= h($error) ?></p><?php endif; ?>
    <form method="post" action="<?= h(client_url('entrar')) ?>" class="form">
        <?= csrf_field() ?>
        <label>E-mail<input name="email" type="email" required autofocus autocomplete="username"></label>
        <label>Senha<input name="pass" type="password" required autocomplete="current-password"></label>
        <button class="btn" type="submit">Entrar</button>
    </form>
    <p class="hint" style="margin-top:16px">Use o e-mail da sua conta Wi-Fi da loja. <a href="/entrar">Painel completo</a></p>
</section>
</body>
</html>
