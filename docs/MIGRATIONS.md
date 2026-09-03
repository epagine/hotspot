# Migrations automáticas

O painel aplica migrations **sozinho** em toda conexão PDO (`db()` → `run_migrations()`).

Banco suportado: **MySQL / MariaDB**.

## Como funciona

1. Arquivos em `app/migrations/NNN_descricao.php`, ordenados pelo prefixo numérico.
2. Cada arquivo retorna um `callable(PDO): void`.
3. Tabela `schema_migrations` registra o que já rodou.
4. Lock via `GET_LOCK` no MySQL.
5. Log em `storage/logs/migrations.log`.

## Deploy / atualização

Depois de atualizar o código no servidor:

```bash
php scripts/migrate.php
# ou
php scripts/migrate.php status
```

Também roda ao abrir qualquer página autenticada. No Super Admin: **Configurações → Sistema**.

## Criar uma migration nova

```bash
php scripts/migrate.php make adiciona_campo_x
```

Edite o stub gerado. Prefira operações idempotentes:

- `CREATE TABLE IF NOT EXISTS`
- `db_add_column($pdo, 'tabela', 'coluna', $definicao)`
- `db_ensure_index($pdo, 'nome', 'tabela', 'col1, col2')`
- `db_type_map()` para tipos MySQL

Não edite migrations já aplicadas em produção — crie outra (`007_...`).

## Checklist

- [ ] Stub criado com `migrate.php make`
- [ ] DDL MySQL idempotente
- [ ] Testar localmente (`php scripts/migrate.php`)
- [ ] Commit do arquivo em `app/migrations/`
- [ ] No servidor: pull + `php scripts/migrate.php` (ou só abrir o painel)
