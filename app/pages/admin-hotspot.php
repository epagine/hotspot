<?php

declare(strict_types=1);

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false], 405);
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
$action = (string) ($body['action'] ?? '');
$sid = (int) ($body['store_id'] ?? current_store_id());
if ($sid > 0) {
    select_store($sid);
}

if ($action === 'install-agent') {
    if (!is_local_store()) {
        json_out(['ok' => false, 'message' => 'Instale o Windows no PC da própria loja.'], 400);
    }
    $script = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'instalar-windows.ps1';
    $arg = '-ExecutionPolicy Bypass -File "' . $script . '"';
    $cmd = 'powershell.exe -NoProfile -Command ' . escapeshellarg(
        'Start-Process powershell.exe -Verb RunAs -ArgumentList ' . escapeshellarg($arg)
    );
    pclose(popen($cmd . ' >NUL 2>&1', 'r'));
    json_out(['ok' => true, 'hint' => 'Confirme a permissão do Windows, se aparecer.']);
}

if (!in_array($action, ['start', 'stop', 'apply'], true)) {
    json_out(['ok' => false, 'error' => 'action'], 400);
}

queue_store_command(current_store_id(), $action);

$bandeja = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'HotspotBandeja.exe';
if (is_file($bandeja) && $action === 'start' && is_local_store()) {
    pclose(popen('cmd /c start "" ' . escapeshellarg($bandeja), 'r'));
}

json_out(['ok' => true]);
