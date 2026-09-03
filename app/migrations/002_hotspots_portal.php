<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $t = db_type_map();
    $auto = $t['auto'];
    $text = $t['text'];
    $int = $t['int'];
    $bool = $t['bool'];
    $long = db_col_long();
    $jsonObj = db_col_json('{}');

    $add = [
        'description' => "{$text} NOT NULL DEFAULT ''",
        'location' => "{$text} NOT NULL DEFAULT ''",
        'provider' => "{$text} NOT NULL DEFAULT 'windows'",
        'auth_mode' => "{$text} NOT NULL DEFAULT 'name_whatsapp'",
        'speed_limit' => "{$text} NOT NULL DEFAULT ''",
        'max_session_minutes' => "{$int} DEFAULT 120",
        'terms_html' => $long,
        'privacy_html' => $long,
        'hotspot_status' => "{$text} NOT NULL DEFAULT 'ativo'",
    ];
    foreach ($add as $col => $def) {
        db_add_column($pdo, 'stores', $col, $def);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS portal_configs (
            id {$auto},
            hotspot_id {$int},
            title {$text} NOT NULL DEFAULT 'Bem-vindo',
            subtitle {$long},
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

    foreach ([
        'company_id' => $t['int_null'],
        'name' => "{$text} NOT NULL DEFAULT ''",
        'email' => "{$text} NOT NULL DEFAULT ''",
        'access_count' => "{$int} DEFAULT 1",
        'first_access_at' => "{$text} NOT NULL DEFAULT ''",
        'last_access_at' => "{$text} NOT NULL DEFAULT ''",
        'blocked' => "{$bool} DEFAULT 0",
    ] as $col => $def) {
        db_add_column($pdo, 'clients', $col, $def);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS client_consents (
            id {$auto},
            client_id {$int},
            company_id {$int},
            consent_type {$text} NOT NULL DEFAULT 'terms',
            accepted {$bool} DEFAULT 1,
            ip {$text} NOT NULL DEFAULT '',
            user_agent {$long},
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
            meta_json {$jsonObj}
        )"
    );
};
