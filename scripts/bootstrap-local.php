<?php

declare(strict_types=1);

/**
 * Bootstrap local mínimo via MySQL (dev). Prefira /instalar no browser.
 *
 * Uso: php scripts/bootstrap-local.php
 */

$root = dirname(__DIR__);
require $root . '/app/helpers.php';

if (is_installed() && database_ready()) {
    echo "já instalado\n";
    exit(0);
}

$host = env('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
$port = env('DB_PORT', '3306') ?? '3306';
$database = env('DB_DATABASE', 'wifidaloja') ?? 'wifidaloja';
$user = env('DB_USERNAME', 'root') ?? 'root';
$pass = env('DB_PASSWORD', '') ?? '';

try {
    mysql_create_database($host, $port, $database, $user, $pass);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$configPath = $root . '/app/config.php';
$config = "<?php\n\nreturn [\n"
    . "    'driver' => 'mysql',\n"
    . "    'mysql_host' => " . var_export($host, true) . ",\n"
    . "    'mysql_port' => " . var_export($port, true) . ",\n"
    . "    'mysql_database' => " . var_export($database, true) . ",\n"
    . "    'mysql_user' => " . var_export($user, true) . ",\n"
    . "    'mysql_pass' => " . var_export($pass, true) . ",\n"
    . "];\n";
file_put_contents($configPath, $config);
app_config_reset();
database_ready_reset();

$cloud = cloud_config();
$token = trim((string) ($cloud['token'] ?? ''));
if ($token === '') {
    $token = new_store_token();
}

$hash = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
$upsert = db_upsert_sql('settings', ['k', 'v'], 'k');
$stmt = db()->prepare($upsert);
$stmt->execute(['admin_user', 'admin@wifidaloja.local']);
$stmt->execute(['admin_pass_hash', $hash]);
ensure_legacy_admin_user();
create_store('Loja', '', null, $token);
echo "ok (mysql)\n";
