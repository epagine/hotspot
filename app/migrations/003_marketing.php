<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $driver = db_driver();
    $auto = $driver === 'mysql' ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
    $long = $driver === 'mysql' ? 'TEXT' : 'TEXT';
    $int = $driver === 'mysql' ? 'INT NOT NULL' : 'INTEGER NOT NULL';
    $bool = $driver === 'mysql' ? 'TINYINT(1) NOT NULL' : 'INTEGER NOT NULL';

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS campaigns (
            id {$auto},
            company_id {$int},
            name {$text} NOT NULL,
            type {$text} NOT NULL DEFAULT 'banner',
            title {$text} NOT NULL DEFAULT '',
            description {$long} NOT NULL DEFAULT '',
            image_path {$text} NOT NULL DEFAULT '',
            button_label {$text} NOT NULL DEFAULT '',
            button_url {$text} NOT NULL DEFAULT '',
            starts_at {$text} NOT NULL DEFAULT '',
            ends_at {$text} NOT NULL DEFAULT '',
            hotspot_ids_json {$long} NOT NULL DEFAULT '[]',
            status {$text} NOT NULL DEFAULT 'ativa',
            created_at {$text} NOT NULL
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS campaign_views (
            id {$auto},
            campaign_id {$int},
            company_id {$int},
            client_id {$int} NULL,
            hotspot_id {$int} NULL,
            created_at {$text} NOT NULL
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS campaign_clicks (
            id {$auto},
            campaign_id {$int},
            company_id {$int},
            client_id {$int} NULL,
            hotspot_id {$int} NULL,
            created_at {$text} NOT NULL
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS coupons (
            id {$auto},
            company_id {$int},
            campaign_id {$int} NULL,
            code {$text} NOT NULL,
            title {$text} NOT NULL DEFAULT '',
            description {$long} NOT NULL DEFAULT '',
            valid_until {$text} NOT NULL DEFAULT '',
            status {$text} NOT NULL DEFAULT 'ativo',
            created_at {$text} NOT NULL,
            UNIQUE (company_id, code)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS coupon_redemptions (
            id {$auto},
            coupon_id {$int},
            company_id {$int},
            client_id {$int} NULL,
            campaign_id {$int} NULL,
            status {$text} NOT NULL DEFAULT 'gerado',
            generated_at {$text} NOT NULL,
            used_at {$text} NOT NULL DEFAULT ''
        )"
    );
};
