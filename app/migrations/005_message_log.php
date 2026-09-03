<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $t = db_type_map();
    $auto = $t['auto'];
    $text = $t['text'];
    $body = db_col_long(true);
    $error = db_col_long();
    $intNull = $t['int_null'];

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS message_log (
            id {$auto},
            company_id {$intNull},
            store_id {$intNull},
            phone {$text} NOT NULL DEFAULT '',
            event_type {$text} NOT NULL DEFAULT '',
            body {$body},
            status {$text} NOT NULL DEFAULT 'pending',
            provider_ref {$text} NOT NULL DEFAULT '',
            error {$error},
            created_at {$text} NOT NULL
        )"
    );
    db_ensure_index($pdo, 'idx_message_log_created', 'message_log', 'created_at');
    db_ensure_index($pdo, 'idx_message_log_company', 'message_log', 'company_id, event_type');
};
