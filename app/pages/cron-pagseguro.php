<?php

declare(strict_types=1);

$key = (string) ($_GET['key'] ?? '');
if ($key === '' || !hash_equals(pagseguro_cron_key(), $key)) {
    json_out(['ok' => false, 'error' => 'forbidden'], 403);
}

$result = pagseguro_run_billing();
json_out(['ok' => true] + $result);
