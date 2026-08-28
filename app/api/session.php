<?php

declare(strict_types=1);

$client = current_client();
json_out([
    'ip' => client_ip(),
    'state' => $client['state'] ?? 'none',
    'code' => $client['status_code'] ?? null,
    'expires_at' => $client['expires_at'] ?? null,
    'online' => client_is_online($client),
]);
