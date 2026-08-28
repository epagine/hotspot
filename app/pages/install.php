<?php

declare(strict_types=1);

if (is_installed() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/login');
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
<body class="saas">
<header class="saas-bar">
    <div class="saas-bar-inner">
        <div class="saas-brand">
            <span class="saas-mark">WL</span>
            <div>
                <p class="eyebrow">Wi-Fi da loja</p>
                <strong>Gestão</strong>
            </div>
        </div>
    </div>
</header>
<main class="saas-main">
<section class="card card-narrow">
    <?php if ($ok): ?>
        <h1>Painel pronto</h1>
        <p class="lead">Cadastre as lojas, acompanhe o PC e o financeiro. O hotspot em si roda no Windows da loja.</p>
        <a class="btn" href="/admin/login">Entrar</a>
    <?php else: ?>
        <h1>Criar painel</h1>
        <p class="lead">Somente a conta de gestão. SSID, senha do Wi-Fi e portal ficam no instalador da loja.</p>
        <?php if ($error): ?><p class="alert"><?= h($error) ?></p><?php endif; ?>
        <form method="post" class="form">
            <label>Usuário<input name="admin_user" value="admin" required></label>
            <label>Senha<input name="admin_pass" type="password" required></label>
            <button class="btn" type="submit">Criar painel</button>
        </form>
    <?php endif; ?>
</section>
</main>
</body>
</html>
