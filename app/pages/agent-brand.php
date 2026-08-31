<?php

declare(strict_types=1);

$token = trim((string) ($GLOBALS['agent_token'] ?? $_GET['token'] ?? ''));
$store = find_store_by_token($token);
if (!$store) {
    http_response_code(401);
    exit;
}
$file = brand_image_path_for((int) $store['id']);
if (!is_file($file)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/png');
header('Cache-Control: private, max-age=300');
readfile($file);
