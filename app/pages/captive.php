<?php

declare(strict_types=1);

$client = current_client();
$online = client_is_online($client);

$captiveSuccess = in_array($path, ['/generate_204', '/gen_204'], true);

if ($online) {
    if ($captiveSuccess) {
        http_response_code(204);
        exit;
    }
    if ($path === '/hotspot-detect.html' || $path === '/canonical.html') {
        header('Content-Type: text/html');
        echo '<HTML><HEAD><TITLE>Success</TITLE></HEAD><BODY>Success</BODY></HTML>';
        exit;
    }
    if ($path === '/ncsi.txt' || $path === '/connecttest.txt' || $path === '/success.txt') {
        header('Content-Type: text/plain');
        echo 'Microsoft NCSI';
        exit;
    }
}

header('Location: /', true, 302);
exit;
