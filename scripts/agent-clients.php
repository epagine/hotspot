<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/helpers.php';

if (!is_installed()) {
    fwrite(STDERR, "not_installed\n");
    exit(1);
}

$sid = local_store_id();
$GLOBALS['force_store_id'] = $sid;
$patchFile = $argv[1] ?? '';
if ($patchFile !== '' && is_file($patchFile)) {
    $patches = json_decode((string) file_get_contents($patchFile), true);
    if (is_array($patches)) {
        apply_client_patches($sid, $patches);
        sync_authorized_file();
    }
}

echo json_encode(clients_for_sync($sid), JSON_UNESCAPED_UNICODE);
