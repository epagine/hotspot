<?php

declare(strict_types=1);

if (is_installed() && database_ready() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /entrar');
    exit;
}

$installReason = (string) ($_SESSION['install_reason'] ?? '');
unset($_SESSION['install_reason']);
$dbBroken = is_installed() && !database_ready();

$error = '';
$ok = false;
$loginEmail = '';

$hostVal = (string) ($_POST['mysql_host'] ?? '127.0.0.1');
$portVal = (string) ($_POST['mysql_port'] ?? '3306');
$dbVal = (string) ($_POST['mysql_database'] ?? 'wifidaloja');
$userVal = (string) ($_POST['mysql_user'] ?? 'root');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminEmail = strtolower(trim((string) ($_POST['admin_email'] ?? $_POST['admin_user'] ?? '')));
    $adminPass = (string) ($_POST['admin_pass'] ?? '');

    try {
        if ($adminEmail === '' || $adminPass === '') {
            throw new InvalidArgumentException('Informe e-mail e senha do administrador.');
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe um e-mail válido para entrar no painel.');
        }
        if (strlen($adminPass) < 8) {
            throw new InvalidArgumentException('A senha do painel precisa ter pelo menos 8 caracteres.');
        }
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('Extensão PHP pdo_mysql não está habilitada.');
        }

        $host = trim((string) ($_POST['mysql_host'] ?? '127.0.0.1')) ?: '127.0.0.1';
        $port = trim((string) ($_POST['mysql_port'] ?? '3306')) ?: '3306';
        $database = trim((string) ($_POST['mysql_database'] ?? 'wifidaloja')) ?: 'wifidaloja';
        $user = trim((string) ($_POST['mysql_user'] ?? 'root'));
        $pass = (string) ($_POST['mysql_pass'] ?? '');
        $configPath = __DIR__ . '/../config.php';

        mysql_create_database($host, $port, $database, $user, $pass);

        $config = "<?php\n\nreturn [\n"
            . "    'driver' => 'mysql',\n"
            . "    'mysql_host' => " . var_export($host, true) . ",\n"
            . "    'mysql_port' => " . var_export($port, true) . ",\n"
            . "    'mysql_database' => " . var_export($database, true) . ",\n"
            . "    'mysql_user' => " . var_export($user, true) . ",\n"
            . "    'mysql_pass' => " . var_export($pass, true) . ",\n"
            . "];\n";

        if (file_put_contents($configPath, $config) === false) {
            throw new RuntimeException('Não foi possível gravar app/config.php');
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($configPath, true);
        }
        app_config_reset();
        database_ready_reset();

        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $upsert = db_upsert_sql('settings', ['k', 'v'], 'k');
        $stmt = db()->prepare($upsert);
        $stmt->execute(['admin_user', $adminEmail]);
        $stmt->execute(['admin_pass_hash', $hash]);

        try {
            ensure_legacy_admin_user();
        } catch (Throwable $e) {
            // migrations/users podem ainda estar subindo
        }

        database_ready_reset();
        $loginEmail = $adminEmail;
        $ok = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        database_ready_reset();
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
        <p class="lead">MySQL configurado.</p>
        <p class="hint">Entre em <strong>/entrar</strong> com o e-mail <strong><?= h($loginEmail) ?></strong> e a senha que você definiu.</p>
        <a class="btn" href="/entrar">Entrar</a>
    <?php else: ?>
        <h1><?= $dbBroken ? 'Reconfigurar painel' : 'Criar painel' ?></h1>
        <p class="lead"><?= $dbBroken
            ? 'O MySQL configurado não foi encontrado ou está inacessível. Informe a conexão e o e-mail de Super Admin novamente.'
            : 'Configure o MySQL e o e-mail de Super Admin (o mesmo do login).' ?></p>
        <?php if ($installReason === 'db_unavailable' || $installReason === 'sqlite_removed' || $dbBroken): ?>
            <p class="alert"><?= $installReason === 'sqlite_removed'
                ? 'SQLite não é mais suportado. Configure o MySQL para continuar.'
                : 'Não foi possível conectar ao MySQL. Conclua a instalação para continuar.' ?></p>
        <?php endif; ?>
        <?php if ($error): ?><p class="alert"><?= h($error) ?></p><?php endif; ?>
        <form method="post" action="/instalar" class="form">
            <label>Host MySQL<input name="mysql_host" value="<?= h($hostVal !== '' ? $hostVal : '127.0.0.1') ?>" required></label>
            <label>Porta<input name="mysql_port" value="<?= h($portVal !== '' ? $portVal : '3306') ?>" required></label>
            <label>Banco<input name="mysql_database" value="<?= h($dbVal !== '' ? $dbVal : 'wifidaloja') ?>" required></label>
            <label>Usuário MySQL<input name="mysql_user" value="<?= h($userVal !== '' ? $userVal : 'root') ?>" autocomplete="off" required></label>
            <label>Senha MySQL<input name="mysql_pass" type="password" value="" autocomplete="new-password"></label>
            <p class="hint">O banco será criado automaticamente se ainda não existir (utf8mb4).</p>
            <label>E-mail do admin<input name="admin_email" type="email" value="<?= h((string) ($_POST['admin_email'] ?? '')) ?>" required autocomplete="username" placeholder="voce@empresa.com"></label>
            <label>Senha do admin<input name="admin_pass" type="password" minlength="8" required autocomplete="new-password"></label>
            <p class="hint">Use este mesmo e-mail e senha em /entrar.</p>
            <button class="btn" type="submit">Criar painel</button>
        </form>
    <?php endif; ?>
</section>
</body>
</html>
