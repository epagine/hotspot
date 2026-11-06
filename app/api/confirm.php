<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'method'], 405);
}

json_out([
    'ok' => false,
    'error' => 'agent',
    'message' => 'A internet é liberada no Wi-Fi da loja pelo agente Windows, não neste painel.',
], 400);
