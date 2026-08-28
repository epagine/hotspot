<?php

declare(strict_types=1);

if (is_installed() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/login');
    exit;
}

$error = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $store = trim($_POST['store_name'] ?? '');
    $adminUser = trim($_POST['admin_user'] ?? 'admin');
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $ssid = trim($_POST['wifi_ssid'] ?? 'WifiDaLoja');
    $wifiPass = trim($_POST['wifi_pass'] ?? '');

    try {
        if ($store === '' || $adminPass === '' || strlen($wifiPass) < 8) {
            throw new InvalidArgumentException('Preencha loja, senha do painel e senha do Wi-Fi (mínimo 8 caracteres).');
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

        $created = create_store($store, trim($_POST['store_city'] ?? ''), [
            'wifi_ssid' => $ssid,
            'wifi_pass' => $wifiPass,
        ]);
        select_store((int) $created['id']);
        $GLOBALS['force_store_id'] = (int) $created['id'];
        save_brand_upload($_FILES['brand_image'] ?? null);
        write_cloud_config(guess_panel_url(), (string) $created['token']);
        sync_authorized_file();
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
    <title>Configurar painel</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="page admin">
<header class="top">
    <div>
        <p class="eyebrow">Painel da loja</p>
        <h1>Configuração inicial</h1>
    </div>
</header>
<main class="card">
    <?php if ($ok): ?>
        <p class="lead">Pronto. Entre no painel para ligar a rede e acompanhar os clientes.</p>
        <a class="btn" href="/admin/login">Abrir painel</a>
    <?php else: ?>
        <p class="lead">Tudo o que a loja precisa fica neste painel: rede, status do WhatsApp e quem está online.</p>
        <?php if ($error): ?><p class="alert"><?= h($error) ?></p><?php endif; ?>
        <form method="post" class="form" enctype="multipart/form-data">
            <h2>Loja</h2>
            <label>Nome da loja<input name="store_name" required placeholder="Ex.: Café Central"></label>
            <label>Cidade<input name="store_city" placeholder="Opcional"></label>
            <label>Imagem da conexão (logo ou foto da loja)<input name="brand_image" type="file" accept="image/png,image/jpeg,image/webp"></label>
            <p class="hint">Aparece no portal do cliente e na arte do status. PNG, JPG ou WEBP até 3 MB.</p>
            <h2>Rede</h2>
            <label>Nome da rede Wi-Fi (SSID)<input name="wifi_ssid" value="WifiDaLoja" required></label>
            <label>Senha do Wi-Fi<input name="wifi_pass" type="password" minlength="8" required></label>
            <h2>Conta do painel</h2>
            <label>Usuário<input name="admin_user" value="admin" required></label>
            <label>Senha<input name="admin_pass" type="password" required></label>
            <button class="btn" type="submit">Salvar e abrir o painel</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
