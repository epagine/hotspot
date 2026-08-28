<?php

declare(strict_types=1);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $pass = (string) ($_POST['pass'] ?? '');
    if (hash_equals(setting('admin_user', 'admin'), $user) && password_verify($pass, setting('admin_pass_hash', ''))) {
        $_SESSION['admin'] = true;
        header('Location: /admin');
        exit;
    }
    $error = 'Usuário ou senha inválidos.';
}
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Painel SaaS</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="page admin">
<header class="top">
    <div>
        <p class="eyebrow">Painel SaaS</p>
        <h1>Entrar</h1>
    </div>
</header>
<main class="card">
    <?php if ($error): ?><p class="alert"><?= h($error) ?></p><?php endif; ?>
    <form method="post" class="form">
        <label>Usuário<input name="user" required></label>
        <label>Senha<input name="pass" type="password" required></label>
        <button class="btn" type="submit">Entrar</button>
    </form>
</main>
</body>
</html>
