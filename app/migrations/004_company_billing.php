<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $driver = db_driver();
    $cols = array_column($pdo->query($driver === 'mysql'
        ? 'SHOW COLUMNS FROM payments'
        : 'PRAGMA table_info(payments)')->fetchAll(), $driver === 'mysql' ? 'Field' : 'name');

    if (!in_array('company_id', $cols, true)) {
        $type = $driver === 'mysql' ? 'INT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
        $pdo->exec('ALTER TABLE payments ADD COLUMN company_id ' . $type);
    }
    if (!in_array('plan_id', $cols, true)) {
        $type = $driver === 'mysql' ? 'INT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
        $pdo->exec('ALTER TABLE payments ADD COLUMN plan_id ' . $type);
    }

    db_ensure_index($pdo, 'idx_payments_company', 'payments', 'company_id, id');
};
