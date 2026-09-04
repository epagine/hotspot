<?php

declare(strict_types=1);

require_super_admin();

$downloadsDir = installer_downloads_dir();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $file = $_FILES['setup'] ?? null;
    try {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Selecione o arquivo .exe do instalador.');
        }
        if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Não foi possível enviar o instalador. Aumente upload_max_filesize no PHP.');
        }
        if ((int) ($file['size'] ?? 0) > 80 * 1024 * 1024) {
            throw new RuntimeException('Arquivo grande demais.');
        }
        $tmp = (string) $file['tmp_name'];
        $head = (string) file_get_contents($tmp, false, null, 0, 2);
        if ($head !== 'MZ') {
            throw new RuntimeException('Envie um .exe gerado por Empacotar.ps1 ou Empacotar-Cloud.ps1.');
        }
        $kind = preg_replace('/[^a-z]/', '', (string) ($_POST['setup_kind'] ?? ''));
        $orig = basename((string) ($file['name'] ?? ''));
        if ($kind === 'full') {
            $destName = 'WiFiDaLoja-Setup.exe';
        } elseif ($kind === 'agent') {
            $destName = 'WiFiDaLoja-Agent-Setup.exe';
        } elseif (stripos($orig, 'Agent') !== false) {
            $destName = 'WiFiDaLoja-Agent-Setup.exe';
        } else {
            $destName = 'WiFiDaLoja-Setup.exe';
        }
        $dest = $downloadsDir . DIRECTORY_SEPARATOR . $destName;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Não foi possível gravar em storage/downloads/.');
        }
        $_SESSION['flash_ok'] = 'Instalador publicado: ' . $destName;
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    safe_internal_redirect($returnTo, admin_url('instalador'));
}

$downloadKind = preg_replace('/[^a-z]/', '', (string) ($_GET['kind'] ?? ''));
if ($downloadKind === 'full') {
    if (!stream_installer_setup('full')) {
        $_SESSION['flash_error'] = 'Instalador completo não disponível.';
        safe_internal_redirect('', admin_url('instalador'));
    }
}
if (!stream_installer_setup()) {
    $_SESSION['flash_error'] = 'Ainda não há instalador neste painel. Envie o .exe em Instalador.';
    $returnTo = trim((string) ($_GET['return_to'] ?? ''));
    safe_internal_redirect($returnTo, admin_url('instalador'));
}
