<?php

declare(strict_types=1);

if (is_installed() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('entrar'));
    exit;
}

$error = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminUser = trim($_POST['admin_user'] ?? 'admin');
    $adminPass = (string) ($_POST['admin_pass'] ?? '');

    try {
        if ($adminUser === '' || $adminPass === '') {
            throw new InvalidArgumentException('Informe usuário e senha do painel.');
        }
        $dir = storage_dir();
        $sqlite = $dir . DIRECTORY_SEPARATOR . 'hotspot.sqlite';
        $config = "<?php\n\nreturn [\n    'sqlite' => " . var_export($sqlite, true) . ",\n];\n";
        if (file_put_contents(__DIR__ . '/../config.php', $config) === false) {
            throw new RuntimeException('Não foi possível gravar app/config.php');
        }

        $pdo = new PDO('sqlite:' . $sqlite, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));

        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $stmt = db()->prepare(
            'INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v'
        );
        $stmt->execute(['admin_user', $adminUser]);
        $stmt->execute(['admin_pass_hash', $hash]);
        $ok = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Criar painel · Wi-Fi da loja</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="app-auth">
<section class="card">
    <div class="app-brand app-brand-logo">
        <img class="app-logo" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
    </div>
    <?php if ($ok): ?>
        <h1>Painel pronto</h1>
        <p class="lead">Cadastre as lojas e acompanhe o PC e o financeiro.</p>
        <a class="btn" href="<?= h(admin_url('entrar')) ?>">Entrar</a>
    <?php else: ?>
        <h1>Criar painel</h1>
        <p class="lead">Somente a conta de gestão. O hotspot fica no PC da loja.</p>
        <?php if ($error): ?><p class="alert"><?= h($error) ?></p><?php endif; ?>
        <form method="post" action="/instalar" class="form">
            <label>Usuário<input name="admin_user" value="admin" required></label>
            <label>Senha<input name="admin_pass" type="password" required></label>
            <button class="btn" type="submit">Criar painel</button>
        </form>
    <?php endif; ?>
</section>
</body>
</html>
