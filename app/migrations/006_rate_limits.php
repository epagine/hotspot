<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $t = db_type_map();
    $text = $t['text'];
    $int = $t['int'];

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rate_limits (
            bucket {$text} NOT NULL,
            hits {$int} DEFAULT 0,
            window_start {$text} NOT NULL,
            PRIMARY KEY (bucket)
        )"
    );
};
