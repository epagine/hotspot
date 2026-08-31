<?php

declare(strict_types=1);

if (!empty($_SESSION['client_store_id']) && current_client_store() !== null) {
    client_redirect(client_url());
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = (string) ($_POST['email'] ?? '');
    $pass = (string) ($_POST['pass'] ?? '');
    $store = portal_try_login($email, $pass);
    if ($store) {
        $_SESSION['client_store_id'] = (int) $store['id'];
        client_redirect(client_url());
    }
    $error = 'E-mail ou senha inválidos, ou o acesso ao portal não está habilitado.';
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
    <div class="app-brand">
        <span class="app-mark">WL</span>
        <div>
            <strong>Wi-Fi da loja</strong>
            <small>Portal do cliente</small>
        </div>
    </div>
    <h1>Entrar</h1>
    <p class="lead">Consulte sua assinatura, pagamentos e links de cobrança.</p>
    <?php if ($error): ?><p class="alert"><?= h($error) ?></p><?php endif; ?>
    <form method="post" action="<?= h(client_url('entrar')) ?>" class="form">
        <label>E-mail<input name="email" type="email" required autofocus autocomplete="username"></label>
        <label>Senha<input name="pass" type="password" required autocomplete="current-password"></label>
        <button class="btn" type="submit">Entrar</button>
    </form>
</section>
</body>
</html>
