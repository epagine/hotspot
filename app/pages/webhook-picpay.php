<?php

declare(strict_types=1);

$expected = picpay_seller_token();
$received = trim((string) ($_SERVER['HTTP_X_SELLER_TOKEN'] ?? ''));
if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

try {
    picpay_handle_notification((string) ($payload['referenceId'] ?? ''), $payload);
} catch (Throwable $e) {
    error_log('PicPay webhook: ' . $e->getMessage());
}

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'OK';
