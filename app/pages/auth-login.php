<?php

declare(strict_types=1);

require_database_or_install();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = (string) ($_POST['email'] ?? $_POST['user'] ?? '');
    $pass = (string) ($_POST['pass'] ?? '');
    $user = auth_attempt($email, $pass);
    if (!$user) {
        // Compat: instalação antiga com "usuário" (sem @) ou e-mail gravado em settings.
        $stored = strtolower(trim(setting('admin_user', 'admin')));
        $storedEmail = str_contains($stored, '@') ? $stored : ($stored . '@wifidaloja.local');
        $legacyLogin = strtolower(trim($email));
        $legacyUser = trim((string) ($_POST['user'] ?? ''));
        if ($legacyUser === '' && str_contains($legacyLogin, '@')) {
            $legacyUser = trim(explode('@', $legacyLogin)[0]);
        } elseif ($legacyUser === '') {
            $legacyUser = $legacyLogin;
        }
        $matchUser = hash_equals($stored, $legacyUser)
            || hash_equals($stored, $legacyLogin)
            || hash_equals($storedEmail, $legacyLogin);
        if ($matchUser && password_verify($pass, setting('admin_pass_hash', ''))) {
            $_SESSION['admin'] = true;
            ensure_legacy_admin_user();
            $stmt = db()->prepare('SELECT * FROM users WHERE role = ? ORDER BY id ASC LIMIT 1');
            $stmt->execute(['super_admin']);
            $user = $stmt->fetch() ?: null;
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
    <p class="hint" style="margin-top:16px">Use o e-mail e a senha definidos na instalação (ou em Começar grátis). <a href="/comecar">Ainda não tem conta?</a></p>
</section>
</body>
</html>
