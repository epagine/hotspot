<?php

declare(strict_types=1);

function migrations_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'migrations';
}

function migrations_lock_path(): string
{
    return storage_dir() . DIRECTORY_SEPARATOR . 'migrations.lock';
}

function migrations_log_path(): string
{
    $dir = storage_dir() . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'migrations.log';
}

function migrations_log(string $message): void
{
    try {
        file_put_contents(
            migrations_log_path(),
            date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    } catch (Throwable $e) {
        // silencioso — log não pode derrubar o painel
    }
}

function ensure_migrations_table(PDO $pdo): void
{
    $text = db_is_mysql() ? 'VARCHAR(120)' : 'TEXT';
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schema_migrations (
            id {$text} NOT NULL PRIMARY KEY,
            applied_at {$text} NOT NULL
        )"
    );
}

/** @return list<string> */
function migration_files(): array
{
    $dir = migrations_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
    sort($files, SORT_STRING);
    return $files;
}

/** @return list<string> */
function applied_migrations(PDO $pdo): array
{
    ensure_migrations_table($pdo);
    $rows = $pdo->query('SELECT id FROM schema_migrations ORDER BY id ASC')->fetchAll() ?: [];
    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (string) $row['id'];
    }
    return $ids;
}

/**
 * @return list<array{id:string,file:string,status:string,applied_at:?string}>
 */
function migrations_status(PDO $pdo): array
{
    ensure_migrations_table($pdo);
    $appliedAt = [];
    foreach ($pdo->query('SELECT id, applied_at FROM schema_migrations') ?: [] as $row) {
        $appliedAt[(string) $row['id']] = (string) $row['applied_at'];
    }
    $out = [];
    foreach (migration_files() as $file) {
        $id = basename($file, '.php');
        $isApplied = array_key_exists($id, $appliedAt);
        $out[] = [
            'id' => $id,
            'file' => $file,
            'status' => $isApplied ? 'applied' : 'pending',
            'applied_at' => $isApplied ? $appliedAt[$id] : null,
        ];
    }
    return $out;
}

/** @return list<string> */
function pending_migrations(PDO $pdo): array
{
    $pending = [];
    foreach (migrations_status($pdo) as $row) {
        if ($row['status'] === 'pending') {
            $pending[] = $row['id'];
        }
    }
    return $pending;
}

function migrations_pending_count(PDO $pdo): int
{
    return count(pending_migrations($pdo));
}

/**
 * @return resource|null
 */
function migrations_acquire_lock(PDO $pdo)
{
    if (db_is_mysql()) {
        $stmt = $pdo->query("SELECT GET_LOCK('wifidaloja_schema_migrations', 30)");
        $ok = $stmt ? (int) $stmt->fetchColumn() : 0;
        if ($ok !== 1) {
            throw new RuntimeException('Timeout ao obter lock de migrations (MySQL).');
        }
        return null;
    }
    $path = migrations_lock_path();
    $fh = fopen($path, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Não foi possível criar lock de migrations.');
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new RuntimeException('Timeout ao obter lock de migrations (arquivo).');
    }
    return $fh;
}

/**
 * @param resource|null $fileLock
 */
function migrations_release_lock(PDO $pdo, $fileLock): void
{
    if (db_is_mysql()) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('wifidaloja_schema_migrations')");
        } catch (Throwable $e) {
        }
        return;
    }
    if (is_resource($fileLock)) {
        flock($fileLock, LOCK_UN);
        fclose($fileLock);
    }
}

/**
 * Aplica migrations pendentes. Rodado automaticamente em db().
 *
 * @return array{ran:list<string>,pending:int,skipped:bool,error:?string}
 */
function run_migrations(PDO $pdo, bool $force = false): array
{
    static $done = false;
    if ($done && !$force) {
        return ['ran' => [], 'pending' => 0, 'skipped' => true, 'error' => null];
    }

    $ran = [];
    $error = null;
    $lock = null;

    try {
        $lock = migrations_acquire_lock($pdo);
        ensure_migrations_table($pdo);
        $applied = applied_migrations($pdo);

        foreach (migration_files() as $file) {
            $id = basename($file, '.php');
            if (in_array($id, $applied, true)) {
                continue;
            }

            migrations_log("APPLY {$id}");
            /** @var mixed $migration */
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new RuntimeException('Migration inválida (precisa retornar callable): ' . $id);
            }

            try {
                $migration($pdo);
            } catch (Throwable $e) {
                migrations_log("FAIL {$id}: " . $e->getMessage());
                throw new RuntimeException("Falha na migration {$id}: " . $e->getMessage(), 0, $e);
            }

            $pdo->prepare('INSERT INTO schema_migrations (id, applied_at) VALUES (?, ?)')->execute([
                $id,
                date('Y-m-d H:i:s'),
            ]);
            $ran[] = $id;
            migrations_log("OK {$id}");
        }

        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if (!$force) {
            throw $e;
        }
    } finally {
        migrations_release_lock($pdo, $lock);
    }

    $pending = 0;
    try {
        $pending = migrations_pending_count($pdo);
    } catch (Throwable $e) {
        $pending = count($ran) > 0 ? 0 : -1;
    }

    return [
        'ran' => $ran,
        'pending' => $pending,
        'skipped' => false,
        'error' => $error,
    ];
}

function migration_next_filename(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? 'change';
    $slug = trim($slug, '_') ?: 'change';

    $max = 0;
    foreach (migration_files() as $file) {
        if (preg_match('/^(\d+)_/', basename($file), $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return sprintf('%03d_%s.php', $max + 1, $slug);
}

function migration_stub_contents(string $id): string
{
    return '<?php

declare(strict_types=1);

/**
 * Migration: ' . $id . '
 *
 * Preferir operacoes idempotentes:
 * - CREATE TABLE IF NOT EXISTS
 * - db_add_column()
 * - db_ensure_index()
 */
return static function (PDO $pdo): void {
    $t = db_type_map();

    // Exemplo:
    // db_add_column($pdo, \'stores\', \'exemplo\', $t[\'text\'] . " NOT NULL DEFAULT \'\'");
};

';
}

function migration_create(string $slug): string
{
    $name = migration_next_filename($slug);
    $path = migrations_dir() . DIRECTORY_SEPARATOR . $name;
    if (is_file($path)) {
        throw new RuntimeException('Migration já existe: ' . $name);
    }
    if (!is_dir(migrations_dir())) {
        mkdir(migrations_dir(), 0750, true);
    }
    $id = basename($name, '.php');
    if (file_put_contents($path, migration_stub_contents($id)) === false) {
        throw new RuntimeException('Não foi possível gravar ' . $path);
    }
    return $path;
}
