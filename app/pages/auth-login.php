<?php

declare(strict_types=1);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = (string) ($_POST['email'] ?? $_POST['user'] ?? '');
    $pass = (string) ($_POST['pass'] ?? '');
    $user = auth_attempt($email, $pass);
    if (!$user) {
        $legacyUser = trim((string) ($_POST['user'] ?? ''));
        if ($legacyUser === '') {
            $legacyUser = trim(explode('@', $email)[0]);
        }
        $legacyPass = $pass;
        if ($legacyUser !== '' && hash_equals(setting('admin_user', 'admin'), $legacyUser)
            && password_verify($legacyPass, setting('admin_pass_hash', ''))) {
            ensure_legacy_admin_user();
            $user = current_user();
            if ($user) {
                auth_login($user);
            }
        }
    } else {
        auth_login($user);
    }
    if (current_user()) {
        $u = current_user();
        if (($u['role'] ?? '') === 'super_admin') {
            header('Location: /super');
            exit;
        }
        header('Location: /app');
        exit;
    }
    $error = 'E-mail ou senha inválidos.';
}
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · Wi-Fi da loja</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="app-auth">
<section class="card">
    <div class="app-brand app-brand-logo">
        <img class="app-logo" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
    </div>
    <h1>Entrar</h1>
    <p class="lead">Gerencie hotspots, clientes e assinatura.</p>
    <?php if ($error): ?><p class="alert"><?= h($error) ?></p><?php endif; ?>
    <form method="post" action="/entrar" class="form">
        <?= csrf_field() ?>
        <label>E-mail<input name="email" type="email" required autofocus autocomplete="username"></label>
        <label>Senha<input name="pass" type="password" required autocomplete="current-password"></label>
        <button class="btn" type="submit">Entrar</button>
    </form>
    <p class="hint" style="margin-top:16px">Ainda não tem conta? <a href="/comecar">Começar grátis</a></p>
</section>
</body>
</html>
