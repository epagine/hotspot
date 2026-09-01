<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $driver = db_driver();
    $auto = $driver === 'mysql' ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
    $long = $driver === 'mysql' ? 'TEXT' : 'TEXT';
    $int = $driver === 'mysql' ? 'INT NOT NULL' : 'INTEGER NOT NULL';
    $bool = $driver === 'mysql' ? 'TINYINT(1) NOT NULL' : 'INTEGER NOT NULL';

    // Treat stores as hotspots; add hotspot metadata columns
    $cols = array_column($pdo->query($driver === 'mysql'
        ? 'SHOW COLUMNS FROM stores'
        : 'PRAGMA table_info(stores)'
    )->fetchAll(), $driver === 'mysql' ? 'Field' : 'name');

    $add = [
        'description' => "{$text} NOT NULL DEFAULT ''",
        'location' => "{$text} NOT NULL DEFAULT ''",
        'provider' => "{$text} NOT NULL DEFAULT 'windows'",
        'auth_mode' => "{$text} NOT NULL DEFAULT 'name_whatsapp'",
        'speed_limit' => "{$text} NOT NULL DEFAULT ''",
        'max_session_minutes' => "{$int} DEFAULT 120",
        'terms_html' => "{$long} NOT NULL DEFAULT ''",
        'privacy_html' => "{$long} NOT NULL DEFAULT ''",
        'hotspot_status' => "{$text} NOT NULL DEFAULT 'ativo'",
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN {$col} {$def}");
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS portal_configs (
            id {$auto},
            hotspot_id {$int},
            title {$text} NOT NULL DEFAULT 'Bem-vindo',
            subtitle {$long} NOT NULL DEFAULT '',
            button_label {$text} NOT NULL DEFAULT 'Conectar à internet',
            bg_image {$text} NOT NULL DEFAULT '',
            primary_color {$text} NOT NULL DEFAULT '',
            require_name {$bool} DEFAULT 1,
            require_phone {$bool} DEFAULT 1,
            require_email {$bool} DEFAULT 0,
            require_terms {$bool} DEFAULT 1,
            updated_at {$text} NOT NULL,
            UNIQUE (hotspot_id)
        )"
    );

    $clientCols = array_column($pdo->query($driver === 'mysql'
        ? 'SHOW COLUMNS FROM clients'
        : 'PRAGMA table_info(clients)'
    )->fetchAll(), $driver === 'mysql' ? 'Field' : 'name');
    foreach ([
        'company_id' => $driver === 'mysql' ? 'INT NULL' : 'INTEGER',
        'name' => "{$text} NOT NULL DEFAULT ''",
        'email' => "{$text} NOT NULL DEFAULT ''",
        'access_count' => "{$int} DEFAULT 1",
        'first_access_at' => "{$text} NOT NULL DEFAULT ''",
        'last_access_at' => "{$text} NOT NULL DEFAULT ''",
        'blocked' => "{$bool} DEFAULT 0",
    ] as $col => $def) {
        if (!in_array($col, $clientCols, true)) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN {$col} {$def}");
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS client_consents (
            id {$auto},
            client_id {$int},
            company_id {$int},
            consent_type {$text} NOT NULL DEFAULT 'terms',
            accepted {$bool} DEFAULT 1,
            ip {$text} NOT NULL DEFAULT '',
            user_agent {$long} NOT NULL DEFAULT '',
            created_at {$text} NOT NULL
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS access_sessions (
            id {$auto},
            company_id {$int},
            hotspot_id {$int},
            client_id {$int},
            started_at {$text} NOT NULL,
            ended_at {$text} NOT NULL DEFAULT '',
            duration_seconds {$int} DEFAULT 0,
            device {$text} NOT NULL DEFAULT '',
            os_name {$text} NOT NULL DEFAULT '',
            browser {$text} NOT NULL DEFAULT '',
            ip_hash {$text} NOT NULL DEFAULT '',
            auth_status {$text} NOT NULL DEFAULT 'authorized',
            meta_json {$long} NOT NULL DEFAULT '{}'
        )"
    );
};
