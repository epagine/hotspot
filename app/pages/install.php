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
    <?php require __DIR__ . '/../partials/tw-head.php'; ?>
    <style>
        @keyframes install-spin { to { transform: rotate(360deg); } }
        .install-spinner { animation: install-spin .7s linear infinite; }
    </style>
</head>
<body class="bg-gradient-to-b from-surface to-white min-h-screen flex items-center justify-center p-4 font-sans">
<div id="install-loading" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/50 backdrop-blur-sm" hidden aria-live="polite" aria-busy="true">
    <div class="bg-white rounded-xl p-8 text-center shadow-xl min-w-[220px]">
        <div class="install-spinner w-9 h-9 mx-auto border-[3px] border-line border-t-accent rounded-full" role="status" aria-label="Carregando"></div>
        <p class="mt-3 text-ink text-sm">Criando painel…</p>
    </div>
</div>
<section class="w-full max-w-lg bg-card border border-line rounded-2xl shadow-lg p-8">
    <div class="flex flex-col items-center mb-6">
        <img class="h-14 w-auto rounded-xl bg-white object-contain" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
    </div>
    <?php if ($ok): ?>
        <h1 class="text-2xl font-bold text-ink text-center">Painel pronto</h1>
        <p class="text-muted text-center mt-2">MySQL configurado.</p>
        <p class="text-sm text-muted text-center mt-3">Entre em <strong class="text-ink">/entrar</strong> com o e-mail <strong class="text-ink"><?= h($loginEmail) ?></strong> e a senha que você definiu.</p>
        <div class="mt-6 text-center">
            <a href="/entrar" class="inline-block bg-accent hover:bg-accent/90 text-white font-bold py-3 px-8 rounded-btn transition">Entrar</a>
        </div>
    <?php else: ?>
        <h1 class="text-2xl font-bold text-ink text-center"><?= $dbBroken ? 'Reconfigurar painel' : 'Criar painel' ?></h1>
        <p class="text-muted text-sm text-center mt-2 mb-6"><?= $dbBroken
            ? 'O MySQL configurado não foi encontrado ou está inacessível. Informe a conexão e o e-mail de Super Admin novamente.'
            : 'Configure o MySQL e o e-mail de Super Admin (o mesmo do login).' ?></p>
        <?php if ($installReason === 'db_unavailable' || $installReason === 'sqlite_removed' || $dbBroken): ?>
            <div class="bg-danger-bg text-danger border border-danger/20 rounded-xl px-4 py-3 text-sm mb-4"><?= $installReason === 'sqlite_removed'
                ? 'SQLite não é mais suportado. Configure o MySQL para continuar.'
                : 'Não foi possível conectar ao MySQL. Conclua a instalação para continuar.' ?></div>
        <?php endif; ?>
        <?php if ($error): ?><div class="bg-danger-bg text-danger border border-danger/20 rounded-xl px-4 py-3 text-sm mb-4"><?= h($error) ?></div><?php endif; ?>
        <form method="post" action="/instalar" class="space-y-4" id="install-form">
            <div class="grid grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-muted">Host MySQL</span>
                    <input name="mysql_host" value="<?= h($hostVal !== '' ? $hostVal : '127.0.0.1') ?>" required class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-muted">Porta</span>
                    <input name="mysql_port" value="<?= h($portVal !== '' ? $portVal : '3306') ?>" required class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
                </label>
            </div>
            <label class="block">
                <span class="text-sm font-medium text-muted">Banco</span>
                <input name="mysql_database" value="<?= h($dbVal !== '' ? $dbVal : 'wifidaloja') ?>" required class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
            </label>
            <div class="grid grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-muted">Usuário MySQL</span>
                    <input name="mysql_user" value="<?= h($userVal !== '' ? $userVal : 'root') ?>" autocomplete="off" required class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-muted">Senha MySQL</span>
                    <input name="mysql_pass" type="password" value="" autocomplete="new-password" class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
                </label>
            </div>
            <p class="text-xs text-muted">O banco será criado automaticamente se ainda não existir (utf8mb4).</p>
            <hr class="border-line">
            <label class="block">
                <span class="text-sm font-medium text-muted">E-mail do admin</span>
                <input name="admin_email" type="email" value="<?= h((string) ($_POST['admin_email'] ?? '')) ?>" required autocomplete="username" placeholder="voce@empresa.com" class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-muted">Senha do admin</span>
                <input name="admin_pass" type="password" minlength="8" required autocomplete="new-password" class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
            </label>
            <p class="text-xs text-muted">Use este mesmo e-mail e senha em /entrar.</p>
            <button type="submit" id="install-submit" class="w-full bg-accent hover:bg-accent/90 text-white font-bold py-3 px-4 rounded-btn transition">Criar painel</button>
        </form>
        <script>
            (function () {
                var form = document.getElementById('install-form');
                var overlay = document.getElementById('install-loading');
                var btn = document.getElementById('install-submit');
                if (!form || !overlay) return;
                form.addEventListener('submit', function () {
                    if (!form.checkValidity()) return;
                    overlay.hidden = false;
                    document.body.classList.add('overflow-hidden');
                    if (btn) { btn.disabled = true; btn.textContent = 'Criando…'; }
                });
            })();
        </script>
    <?php endif; ?>
</section>
</body>
</html>
