<?php

declare(strict_types=1);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $pass = (string) ($_POST['pass'] ?? '');
    if (hash_equals(setting('admin_user', 'admin'), $user) && password_verify($pass, setting('admin_pass_hash', ''))) {
        $_SESSION['admin'] = true;
        header('Location: ' . admin_url());
        exit;
    }
    $error = 'Usuário ou senha inválidos.';
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
    <div class="app-brand">
        <span class="app-mark">WL</span>
        <div>
            <strong>Wi-Fi da loja</strong>
            <small>Painel de gestão</small>
        </div>
    </div>
    <h1>Entrar</h1>
    <p class="lead">Clientes, conexão do PC e financeiro.</p>
    <?php if ($error): ?><p class="alert"><?= h($error) ?></p><?php endif; ?>
    <form method="post" action="/admin/entrar" class="form">
        <label>Usuário<input name="user" required autofocus></label>
        <label>Senha<input name="pass" type="password" required></label>
        <button class="btn" type="submit">Entrar</button>
    </form>
</section>
</body>
</html>
