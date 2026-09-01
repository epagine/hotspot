<?php

declare(strict_types=1);

require_super_admin();

$dest = installer_downloads_dir() . DIRECTORY_SEPARATOR . 'WiFiDaLoja-Setup.exe';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = $_FILES['setup'] ?? null;
    try {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Selecione o arquivo WiFiDaLoja-Setup.exe.');
        }
        if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Não foi possível enviar o instalador. Aumente upload_max_filesize no PHP (precisa de uns 40 MB).');
        }
        if ((int) ($file['size'] ?? 0) > 80 * 1024 * 1024) {
            throw new RuntimeException('Arquivo grande demais.');
        }
        $tmp = (string) $file['tmp_name'];
        $head = (string) file_get_contents($tmp, false, null, 0, 2);
        if ($head !== 'MZ') {
            throw new RuntimeException('Envie o WiFiDaLoja-Setup.exe gerado pelo Empacotar.ps1.');
        }
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Não foi possível gravar em storage/downloads/.');
        }
        $_SESSION['flash_ok'] = 'Instalador publicado.';
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    header('Location: ' . ($returnTo !== '' ? $returnTo : admin_url('instalador')));
    exit;
}

$path = installer_setup_path();
if ($path === null) {
    $_SESSION['flash_error'] = 'Ainda não há instalador neste painel. Envie o WiFiDaLoja-Setup.exe em Instalador.';
    $returnTo = trim((string) ($_GET['return_to'] ?? ''));
    header('Location: ' . ($returnTo !== '' ? $returnTo : admin_url('instalador')));
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="WiFiDaLoja-Setup.exe"');
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
