<?php

declare(strict_types=1);

/**
 * Load optional .env into getenv/$_ENV (KEY=VALUE lines).
 */
function load_env_file(?string $path = null): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $path = $path ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
        }
    }
}

function env(string $key, ?string $default = null): ?string
{
    load_env_file();
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    return (string) $v;
}

function app_config(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($path)) {
        throw new RuntimeException('not_installed');
    }
    $cfg = require $path;
    if (!is_array($cfg)) {
        $cfg = [];
    }
    return $cfg;
}

function db_driver(): string
{
    $cfg = app_config();
    $driver = strtolower((string) ($cfg['driver'] ?? env('DB_DRIVER', 'sqlite')));
    return in_array($driver, ['mysql', 'mariadb'], true) ? 'mysql' : 'sqlite';
}

function db_upsert_sql(string $table, array $columns, string $conflictTarget): string
{
    $cols = implode(', ', $columns);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    if (db_driver() === 'mysql') {
        $updates = [];
        foreach ($columns as $c) {
            if ($c === $conflictTarget || str_contains($conflictTarget, $c)) {
                continue;
            }
            // skip pure PK parts for update list when multi-col
            $updates[] = "{$c} = VALUES({$c})";
        }
        if ($updates === []) {
            $updates[] = $columns[count($columns) - 1] . ' = VALUES(' . $columns[count($columns) - 1] . ')';
        }
        return "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
    }
    $updates = [];
    foreach ($columns as $c) {
        if ($c === explode(',', str_replace(' ', '', $conflictTarget))[0] && !str_contains($conflictTarget, ',')) {
            continue;
        }
        $parts = array_map('trim', explode(',', $conflictTarget));
        if (in_array($c, $parts, true)) {
            continue;
        }
        $updates[] = "{$c} = excluded.{$c}";
    }
    return "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders}) ON CONFLICT({$conflictTarget}) DO UPDATE SET " . implode(', ', $updates);
}
