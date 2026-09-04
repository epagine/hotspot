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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="saas-auth">
<div id="install-loading" class="saas-loading-overlay" hidden aria-live="polite" aria-busy="true">
    <div class="saas-loading-panel">
        <div class="saas-spinner" role="status" aria-label="Carregando"></div>
        <p class="saas-loading-text">Criando painel…</p>
    </div>
</div>
<section class="saas-auth-card saas-auth-card--wide saas-auth">
    <div class="saas-auth-brand">
        <img class="saas-auth-logo" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
    </div>
    <?php if ($ok): ?>
        <h1 class="saas-auth-title">Painel pronto</h1>
        <p class="saas-auth-lead">MySQL configurado.</p>
        <p class="hint" style="text-align:center;margin:12px 0 0">Entre em <strong>/entrar</strong> com o e-mail <strong><?= h($loginEmail) ?></strong> e a senha que você definiu.</p>
        <div class="saas-auth-actions" style="margin-top:20px">
            <a href="/entrar" class="btn">Entrar</a>
        </div>
    <?php else: ?>
        <h1 class="saas-auth-title"><?= $dbBroken ? 'Reconfigurar painel' : 'Criar painel' ?></h1>
        <p class="saas-auth-lead"><?= $dbBroken
            ? 'O MySQL configurado não foi encontrado ou está inacessível. Informe a conexão e o e-mail de Super Admin novamente.'
            : 'Configure o MySQL e o e-mail de Super Admin (o mesmo do login).' ?></p>
        <?php if ($installReason === 'db_unavailable' || $installReason === 'sqlite_removed' || $dbBroken): ?>
            <div class="alert"><?= $installReason === 'sqlite_removed'
                ? 'SQLite não é mais suportado. Configure o MySQL para continuar.'
                : 'Não foi possível conectar ao MySQL. Conclua a instalação para continuar.' ?></div>
        <?php endif; ?>
        <?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>
        <form method="post" action="/instalar" class="form" id="install-form">
            <div class="saas-auth-grid-2">
                <label>
                    Host MySQL
                    <input name="mysql_host" value="<?= h($hostVal !== '' ? $hostVal : '127.0.0.1') ?>" required>
                </label>
                <label>
                    Porta
                    <input name="mysql_port" value="<?= h($portVal !== '' ? $portVal : '3306') ?>" required>
                </label>
            </div>
            <label>
                Banco
                <input name="mysql_database" value="<?= h($dbVal !== '' ? $dbVal : 'wifidaloja') ?>" required>
            </label>
            <div class="saas-auth-grid-2">
                <label>
                    Usuário MySQL
                    <input name="mysql_user" value="<?= h($userVal !== '' ? $userVal : 'root') ?>" autocomplete="off" required>
                </label>
                <label>
                    Senha MySQL
                    <input name="mysql_pass" type="password" value="" autocomplete="new-password">
                </label>
            </div>
            <p class="saas-auth-note">O banco será criado automaticamente se ainda não existir (utf8mb4).</p>
            <hr class="saas-auth-divider">
            <label>
                E-mail do admin
                <input name="admin_email" type="email" value="<?= h((string) ($_POST['admin_email'] ?? '')) ?>" required autocomplete="username" placeholder="voce@empresa.com">
            </label>
            <label>
                Senha do admin
                <input name="admin_pass" type="password" minlength="8" required autocomplete="new-password">
            </label>
            <p class="saas-auth-note">Use este mesmo e-mail e senha em /entrar.</p>
            <button type="submit" id="install-submit" class="btn btn-block">Criar painel</button>
        </form>
        <script>
            (function () {
                var form = document.getElementById('install-form');
                var overlay = document.getElementById('install-loading');
                var btn = document.getElementById('install-submit');
                if (!form || !overlay) return;

                function hideOverlay() {
                    overlay.hidden = true;
                    document.body.classList.remove('modal-open');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Criar painel';
                    }
                }

                hideOverlay();
                window.addEventListener('pageshow', hideOverlay);

                form.addEventListener('submit', function () {
                    if (!form.checkValidity()) return;
                    overlay.hidden = false;
                    document.body.classList.add('modal-open');
                    if (btn) { btn.disabled = true; btn.textContent = 'Criando…'; }
                    window.setTimeout(function () {
                        if (!overlay.hidden) {
                            hideOverlay();
                            alert('A instalação está demorando. Verifique se o MySQL está rodando e tente de novo.');
                        }
                    }, 120000);
                });
            })();
        </script>
    <?php endif; ?>
</section>
</body>
</html>
