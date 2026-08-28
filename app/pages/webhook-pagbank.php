<?php

declare(strict_types=1);

$raw = (string) file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST ?: $_GET;
}
if (!is_array($payload)) {
    $payload = [];
}

try {
    pagseguro_handle_notification($payload);
} catch (Throwable $e) {
    json_out(['ok' => false], 500);
}

json_out(['ok' => true]);
