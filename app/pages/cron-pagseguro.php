<?php

declare(strict_types=1);

$key = (string) ($GLOBALS['cron_key'] ?? $_GET['key'] ?? '');
if ($key === '' || !hash_equals(pagseguro_cron_key(), $key)) {
    json_out(['ok' => false, 'error' => 'forbidden'], 403);
}

$result = subscription_run_daily();
json_out(['ok' => true] + $result);
