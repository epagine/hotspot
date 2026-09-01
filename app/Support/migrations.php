<?php

declare(strict_types=1);

function migrations_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'migrations';
}

function ensure_migrations_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id VARCHAR(120) NOT NULL PRIMARY KEY,
            applied_at VARCHAR(40) NOT NULL
        )'
    );
}

function applied_migrations(PDO $pdo): array
{
    ensure_migrations_table($pdo);
    $rows = $pdo->query('SELECT id FROM schema_migrations')->fetchAll();
    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (string) $row['id'];
    }
    return $ids;
}

function run_migrations(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ensure_migrations_table($pdo);
    $applied = applied_migrations($pdo);
    $dir = migrations_dir();
    if (!is_dir($dir)) {
        return;
    }
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
    sort($files);
    foreach ($files as $file) {
        $id = basename($file, '.php');
        if (in_array($id, $applied, true)) {
            continue;
        }
        /** @var callable $migration */
        $migration = require $file;
        if (!is_callable($migration)) {
            throw new RuntimeException('Invalid migration: ' . $id);
        }
        $migration($pdo);
        $pdo->prepare('INSERT INTO schema_migrations (id, applied_at) VALUES (?, ?)')->execute([
            $id,
            date('Y-m-d H:i:s'),
        ]);
    }
}
