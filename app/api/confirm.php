<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'method'], 405);
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$phone = preg_replace('/\D+/', '', (string) ($body['phone'] ?? '')) ?: null;
$client = current_client();
if (!$client) {
    json_out(['ok' => false, 'error' => 'no_session'], 400);
}

if (client_is_online($client)) {
    json_out(['ok' => true, 'state' => 'online']);
}

$mode = setting('approval_mode', 'instant');
if ($mode !== 'manual' && online_count() >= max_clients()) {
    json_out(['ok' => false, 'error' => 'full', 'message' => 'Rede cheia (máximo 8 aparelhos).'], 409);
}

$hours = max(1, (int) setting('session_hours', '2'));
$next = $mode === 'manual' ? 'awaiting_approval' : 'online';
$now = date('Y-m-d H:i:s');
$expires = date('Y-m-d H:i:s', time() + $hours * 3600);

$stmt = db()->prepare(
    'UPDATE clients SET phone = COALESCE(?, phone), state = ?, authorized_at = ?, expires_at = ?, mac = COALESCE(?, mac) WHERE id = ?'
);
$stmt->execute([
    $phone,
    $next,
    $next === 'online' ? $now : null,
    $next === 'online' ? $expires : null,
    lookup_mac(client_ip()),
    $client['id'],
]);
sync_authorized_file();

json_out(['ok' => true, 'state' => $next, 'expires_at' => $next === 'online' ? $expires : null]);
