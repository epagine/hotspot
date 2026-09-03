<?php

declare(strict_types=1);

/**
 * CLI de migrations.
 *
 * Uso:
 *   php scripts/migrate.php           # aplica pendentes
 *   php scripts/migrate.php status    # lista aplicadas / pendentes
 *   php scripts/migrate.php make nome # cria stub NNN_nome.php
 */

$root = dirname(__DIR__);
require $root . '/app/helpers.php';

if (!is_installed()) {
    fwrite(STDERR, "Painel não instalado (app/config.php ausente).\n");
    exit(1);
}

$cmd = strtolower(trim((string) ($argv[1] ?? 'run')));
$arg = trim((string) ($argv[2] ?? ''));

try {
    $pdo = db();

    if ($cmd === 'status') {
        $rows = migrations_status($pdo);
        $pending = 0;
        foreach ($rows as $row) {
            $mark = $row['status'] === 'applied' ? 'OK' : 'PENDENTE';
            if ($row['status'] !== 'applied') {
                $pending++;
            }
            $when = $row['applied_at'] ?? '-';
            echo sprintf("%-10s %-40s %s\n", $mark, $row['id'], $when);
        }
        echo $pending === 0
            ? "Nenhuma migration pendente.\n"
            : "Pendentes: {$pending}\n";
        exit(0);
    }

    if ($cmd === 'make' || $cmd === 'create') {
        if ($arg === '') {
            fwrite(STDERR, "Informe o nome: php scripts/migrate.php make adiciona_coluna_x\n");
            exit(1);
        }
        $path = migration_create($arg);
        echo "Criada: {$path}\n";
        exit(0);
    }

    if ($cmd === 'run' || $cmd === 'up' || $cmd === '') {
        $result = run_migrations($pdo, true);
        if ($result['error']) {
            fwrite(STDERR, $result['error'] . "\n");
            exit(1);
        }
        if ($result['ran'] === []) {
            echo "Nada a aplicar. Schema atualizado.\n";
        } else {
            echo "Aplicadas:\n";
            foreach ($result['ran'] as $id) {
                echo "  - {$id}\n";
            }
        }
        exit(0);
    }

    fwrite(STDERR, "Comando desconhecido: {$cmd}\n");
    fwrite(STDERR, "Use: run | status | make <nome>\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
