<?php

declare(strict_types=1);

if (is_installed() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /entrar');
    exit;
}

$error = '';
$ok = false;
$driverChoice = (string) ($_POST['db_driver'] ?? 'sqlite');
if (!in_array($driverChoice, ['sqlite', 'mysql'], true)) {
    $driverChoice = 'sqlite';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminUser = trim((string) ($_POST['admin_user'] ?? 'admin'));
    $adminPass = (string) ($_POST['admin_pass'] ?? '');

    try {
        if ($adminUser === '' || $adminPass === '') {
            throw new InvalidArgumentException('Informe usuário e senha do painel.');
        }
        if (strlen($adminPass) < 8) {
            throw new InvalidArgumentException('A senha do painel precisa ter pelo menos 8 caracteres.');
        }

        $dir = storage_dir();
        $sqlitePath = $dir . DIRECTORY_SEPARATOR . 'hotspot.sqlite';
        $configPath = __DIR__ . '/../config.php';

        if ($driverChoice === 'mysql') {
            if (!extension_loaded('pdo_mysql')) {
                throw new RuntimeException('Extensão PHP pdo_mysql não está habilitada.');
            }
            $host = trim((string) ($_POST['mysql_host'] ?? '127.0.0.1')) ?: '127.0.0.1';
            $port = trim((string) ($_POST['mysql_port'] ?? '3306')) ?: '3306';
            $database = trim((string) ($_POST['mysql_database'] ?? 'wifidaloja')) ?: 'wifidaloja';
            $user = trim((string) ($_POST['mysql_user'] ?? 'root'));
            $pass = (string) ($_POST['mysql_pass'] ?? '');

            mysql_create_database($host, $port, $database, $user, $pass);

            $config = "<?php\n\nreturn [\n"
                . "    'driver' => 'mysql',\n"
                . "    'mysql_host' => " . var_export($host, true) . ",\n"
                . "    'mysql_port' => " . var_export($port, true) . ",\n"
                . "    'mysql_database' => " . var_export($database, true) . ",\n"
                . "    'mysql_user' => " . var_export($user, true) . ",\n"
                . "    'mysql_pass' => " . var_export($pass, true) . ",\n"
                . "    'sqlite' => " . var_export($sqlitePath, true) . ",\n"
                . "];\n";
        } else {
            if (!extension_loaded('pdo_sqlite')) {
                throw new RuntimeException('Extensão PHP pdo_sqlite não está habilitada.');
            }
            $config = "<?php\n\nreturn [\n"
                . "    'driver' => 'sqlite',\n"
                . "    'sqlite' => " . var_export($sqlitePath, true) . ",\n"
                . "];\n";
        }

        if (file_put_contents($configPath, $config) === false) {
            throw new RuntimeException('Não foi possível gravar app/config.php');
        }

        // Reinicia cache estático de config/PDO se a instalação for refeita no mesmo request.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($configPath, true);
        }

        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $upsert = db_upsert_sql('settings', ['k', 'v'], 'k');
        $stmt = db()->prepare($upsert);
        $stmt->execute(['admin_user', $adminUser]);
        $stmt->execute(['admin_pass_hash', $hash]);

        // Garante usuário super_admin na tabela users (SaaS).
        try {
            ensure_legacy_admin_user();
        } catch (Throwable $e) {
            // migrations/users podem ainda estar subindo
        }

        $ok = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if (is_file(__DIR__ . '/../config.php') && !$ok) {
            // Mantém config se parcial; usuário pode corrigir e tentar de novo.
        }
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
        <p class="lead">Banco <?= h($driverChoice === 'mysql' ? 'MySQL' : 'SQLite') ?> configurado. Entre e continue no Super Admin.</p>
        <a class="btn" href="/entrar">Entrar</a>
    <?php else: ?>
        <h1>Criar painel</h1>
        <p class="lead">Escolha o banco e a conta de administração da plataforma.</p>
        <?php if ($error): ?><p class="alert"><?= h($error) ?></p><?php endif; ?>
        <form method="post" action="/instalar" class="form" id="install-form">
            <label>Banco de dados
                <select name="db_driver" id="db_driver">
                    <option value="sqlite" <?= $driverChoice === 'sqlite' ? 'selected' : '' ?>>SQLite (arquivo local)</option>
                    <option value="mysql" <?= $driverChoice === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB</option>
                </select>
            </label>
            <div id="mysql-fields" style="<?= $driverChoice === 'mysql' ? '' : 'display:none' ?>">
                <label>Host<input name="mysql_host" value="<?= h((string) ($_POST['mysql_host'] ?? '127.0.0.1')) ?>"></label>
                <label>Porta<input name="mysql_port" value="<?= h((string) ($_POST['mysql_port'] ?? '3306')) ?>"></label>
                <label>Banco<input name="mysql_database" value="<?= h((string) ($_POST['mysql_database'] ?? 'wifidaloja')) ?>"></label>
                <label>Usuário<input name="mysql_user" value="<?= h((string) ($_POST['mysql_user'] ?? 'root')) ?>"></label>
                <label>Senha<input name="mysql_pass" type="password" value="" autocomplete="new-password"></label>
                <p class="hint">O banco será criado automaticamente se ainda não existir.</p>
            </div>
            <label>Usuário admin<input name="admin_user" value="<?= h((string) ($_POST['admin_user'] ?? 'admin')) ?>" required></label>
            <label>Senha admin<input name="admin_pass" type="password" minlength="8" required></label>
            <button class="btn" type="submit">Criar painel</button>
        </form>
        <script>
            (function () {
                var sel = document.getElementById('db_driver');
                var box = document.getElementById('mysql-fields');
                if (!sel || !box) return;
                sel.addEventListener('change', function () {
                    box.style.display = sel.value === 'mysql' ? '' : 'none';
                });
            })();
        </script>
    <?php endif; ?>
</section>
</body>
</html>
