<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $driver = db_driver();
    $auto = $driver === 'mysql' ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
    $long = $driver === 'mysql' ? 'TEXT' : 'TEXT';
    $intNull = $driver === 'mysql' ? 'INT NULL' : 'INTEGER';

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS message_log (
            id {$auto},
            company_id {$intNull},
            store_id {$intNull},
            phone {$text} NOT NULL DEFAULT '',
            event_type {$text} NOT NULL DEFAULT '',
            body {$long} NOT NULL,
            status {$text} NOT NULL DEFAULT 'pending',
            provider_ref {$text} NOT NULL DEFAULT '',
            error {$long} NOT NULL DEFAULT '',
            created_at {$text} NOT NULL
        )"
    );
    db_ensure_index($pdo, 'idx_message_log_created', 'message_log', 'created_at');
    db_ensure_index($pdo, 'idx_message_log_company', 'message_log', 'company_id, event_type');
};
