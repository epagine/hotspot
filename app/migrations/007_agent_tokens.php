<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $text = db_type_map()['text'];
    if (!in_array('agent_token', db_column_names($pdo, 'stores'), true)) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN agent_token {$text} NULL");
    }

    $rows = $pdo->query("SELECT id FROM stores WHERE agent_token IS NULL OR agent_token = ''")->fetchAll(PDO::FETCH_COLUMN);
    $update = $pdo->prepare("UPDATE stores SET agent_token = ? WHERE id = ? AND (agent_token IS NULL OR agent_token = '')");
    foreach ($rows as $id) {
        $update->execute([bin2hex(random_bytes(32)), (int) $id]);
    }

    if (db_is_mysql()) {
        $exists = $pdo->prepare(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1'
        );
        $exists->execute(['stores', 'uq_stores_agent_token']);
    } else {
        $exists = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ? LIMIT 1");
        $exists->execute(['uq_stores_agent_token']);
    }
    if (!$exists->fetchColumn()) {
        $pdo->exec('CREATE UNIQUE INDEX uq_stores_agent_token ON stores (agent_token)');
    }

    if (function_exists('cloud_config') && function_exists('write_cloud_config')) {
        try {
            $cloud = cloud_config();
            $publicToken = trim((string) ($cloud['token'] ?? ''));
            if ($publicToken !== '') {
                $stmt = $pdo->prepare('SELECT agent_token FROM stores WHERE token = ? LIMIT 1');
                $stmt->execute([$publicToken]);
                $agentToken = trim((string) $stmt->fetchColumn());
                if ($agentToken !== '') {
                    write_cloud_config((string) ($cloud['panel_url'] ?? ''), $agentToken);
                }
            }
        } catch (Throwable $e) {
            migrations_log('Could not migrate local cloud credentials: ' . $e->getMessage());
        }
    }
};
