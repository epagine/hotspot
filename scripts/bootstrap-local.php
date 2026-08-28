<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/helpers.php';

if (is_installed()) {
    exit(0);
}

$cloud = cloud_config();
$token = trim((string) ($cloud['token'] ?? ''));
if ($token === '') {
    fwrite(STDERR, "sem token\n");
    exit(1);
}

$dir = storage_dir();
$sqlite = $dir . DIRECTORY_SEPARATOR . 'hotspot.sqlite';
$config = "<?php\n\nreturn [\n    'sqlite' => " . var_export($sqlite, true) . ",\n];\n";
file_put_contents(dirname(__DIR__) . '/app/config.php', $config);

$pdo = new PDO('sqlite:' . $sqlite, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/app/schema.sql'));

$stmt = db()->prepare(
    'INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v'
);
$stmt->execute(['admin_user', 'admin']);
$stmt->execute(['admin_pass_hash', password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)]);

create_store('Loja', '', null, $token);
echo "ok\n";
