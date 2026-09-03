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
    $jsonArr = db_col_json('[]');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id {$auto},
            name {$text} NOT NULL DEFAULT '',
            email {$text} NOT NULL,
            pass_hash {$text} NOT NULL DEFAULT '',
            role {$text} NOT NULL DEFAULT 'company_admin',
            status {$text} NOT NULL DEFAULT 'active',
            created_at {$text} NOT NULL,
            UNIQUE (email)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS companies (
            id {$auto},
            legal_name {$text} NOT NULL DEFAULT '',
            trade_name {$text} NOT NULL DEFAULT '',
            document {$text} NOT NULL DEFAULT '',
            phone {$text} NOT NULL DEFAULT '',
            whatsapp {$text} NOT NULL DEFAULT '',
            email {$text} NOT NULL DEFAULT '',
            address {$long},
            city {$text} NOT NULL DEFAULT '',
            state {$text} NOT NULL DEFAULT '',
            logo_path {$text} NOT NULL DEFAULT '',
            primary_color {$text} NOT NULL DEFAULT '#c8892a',
            secondary_color {$text} NOT NULL DEFAULT '#15202b',
            social_json {$jsonObj},
            status {$text} NOT NULL DEFAULT 'active',
            created_at {$text} NOT NULL
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS company_users (
            id {$auto},
            company_id {$int},
            user_id {$int},
            permissions {$jsonArr},
            created_at {$text} NOT NULL,
            UNIQUE (company_id, user_id)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS plans (
            id {$auto},
            code {$text} NOT NULL,
            name {$text} NOT NULL,
            price_cents {$int} DEFAULT 0,
            billing_period {$text} NOT NULL DEFAULT 'mensal',
            max_hotspots {$int} DEFAULT 1,
            max_clients {$int} DEFAULT 100,
            max_users {$int} DEFAULT 2,
            features_json {$jsonArr},
            active {$bool} DEFAULT 1,
            sort_order {$int} DEFAULT 0,
            created_at {$text} NOT NULL,
            UNIQUE (code)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS subscriptions (
            id {$auto},
            company_id {$int},
            plan_id {$int},
            status {$text} NOT NULL DEFAULT 'trial',
            trial_ends_at {$text} NOT NULL DEFAULT '',
            starts_at {$text} NOT NULL DEFAULT '',
            ends_at {$text} NOT NULL DEFAULT '',
            cancelled_at {$text} NOT NULL DEFAULT '',
            notes {$long},
            created_at {$text} NOT NULL,
            UNIQUE (company_id)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id {$auto},
            company_id {$int} NULL,
            actor_user_id {$int} NULL,
            action {$text} NOT NULL,
            meta_json {$jsonObj},
            ip {$text} NOT NULL DEFAULT '',
            created_at {$text} NOT NULL
        )"
    );

    $cols = db_column_names($pdo, 'stores');
    if ($cols !== [] && !in_array('company_id', $cols, true)) {
        $pdo->exec('ALTER TABLE stores ADD COLUMN company_id ' . $t['int_null']);
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn();
    if ($count === 0) {
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO plans (code, name, price_cents, billing_period, max_hotspots, max_clients, max_users, features_json, active, sort_order, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute(['gratuito', 'Gratuito', 0, 'mensal', 1, 100, 1, json_encode(['stats_basic']), 1, 1, $now]);
        $stmt->execute(['essencial', 'Essencial', 2990, 'mensal', 1, 0, 2, json_encode(['stats', 'portal']), 1, 2, $now]);
        $stmt->execute(['profissional', 'Profissional', 4990, 'mensal', 0, 0, 5, json_encode(['stats', 'portal', 'campaigns', 'coupons']), 1, 3, $now]);
        $stmt->execute(['empresa', 'Empresa', 9990, 'mensal', 0, 0, 20, json_encode(['stats', 'portal', 'campaigns', 'coupons', 'multi_unit', 'reports']), 1, 4, $now]);
    }
};
