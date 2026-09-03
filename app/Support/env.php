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

function db_is_mysql(): bool
{
    return db_driver() === 'mysql';
}

/** @return array{auto:string,int:string,int_null:string,bool:string,text:string,long:string} */
function db_type_map(): array
{
    if (db_is_mysql()) {
        return [
            'auto' => 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'int' => 'INT NOT NULL',
            'int_null' => 'INT NULL',
            'bool' => 'TINYINT(1) NOT NULL',
            'text' => 'VARCHAR(255)',
            'long' => 'TEXT',
        ];
    }
    return [
        'auto' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'int' => 'INTEGER NOT NULL',
        'int_null' => 'INTEGER',
        'bool' => 'INTEGER NOT NULL',
        'text' => 'TEXT',
        'long' => 'TEXT',
    ];
}

function db_column_names(PDO $pdo, string $table): array
{
    $table = preg_replace('/[^a-z0-9_]/i', '', $table) ?? '';
    if ($table === '') {
        return [];
    }
    try {
        if (db_is_mysql()) {
            $rows = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll() ?: [];
            return array_column($rows, 'Field');
        }
        $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() ?: [];
        return array_column($rows, 'name');
    } catch (Throwable $e) {
        return [];
    }
}

function db_ensure_index(PDO $pdo, string $name, string $table, string $columns): void
{
    $name = preg_replace('/[^a-z0-9_]/i', '', $name) ?? '';
    $table = preg_replace('/[^a-z0-9_]/i', '', $table) ?? '';
    $columns = preg_replace('/[^a-z0-9_,\s()]/i', '', $columns) ?? '';
    if ($name === '' || $table === '' || $columns === '') {
        return;
    }
    try {
        if (db_is_mysql()) {
            $exists = $pdo->prepare(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1'
            );
            $exists->execute([$table, $name]);
            if ($exists->fetch()) {
                return;
            }
            $pdo->exec("CREATE INDEX {$name} ON {$table} ({$columns})");
            return;
        }
        $pdo->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");
    } catch (Throwable $e) {
        // índice pode já existir
    }
}

function db_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $cols = db_column_names($pdo, $table);
    if (in_array($column, $cols, true)) {
        return;
    }
    $table = preg_replace('/[^a-z0-9_]/i', '', $table) ?? '';
    $column = preg_replace('/[^a-z0-9_]/i', '', $column) ?? '';
    if ($table === '' || $column === '') {
        return;
    }
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}

function mysql_create_database(string $host, string $port, string $database, string $user, string $pass): void
{
    $database = trim($database);
    if ($database === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
        throw new InvalidArgumentException('Nome do banco MySQL inválido (use letras, números e _).');
    }
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec(
        'CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
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
