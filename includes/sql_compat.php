<?php
// includes/sql_compat.php
//
// The app runs on PostgreSQL in production (Supabase) and on MySQL/MariaDB when it is
// tested locally with WAMP/XAMPP. The two speak slightly different SQL, so the handful
// of statements that differ are written through the helpers below instead of inline.
//
// includes/db.php sets $GLOBALS['db_driver'] before anything else uses these.

function dbDriver(): string {
    return $GLOBALS['db_driver'] ?? 'pgsql';
}

function isPgsql(): bool {
    return dbDriver() === 'pgsql';
}

function isMysql(): bool {
    return dbDriver() === 'mysql';
}

/**
 * Run an INSERT and return the new row's id.
 * Postgres needs a RETURNING clause; MySQL reads the id back from the connection.
 */
function insertReturningId(PDO $pdo, string $sql, array $params): int {
    if (isPgsql()) {
        $stmt = $pdo->prepare(rtrim($sql, "; \t\n\r") . " RETURNING id");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
    $pdo->prepare($sql)->execute($params);
    return (int)$pdo->lastInsertId();
}

/**
 * "Insert unless a row with these key columns already exists."
 * rowCount() is 1 when the row was inserted and 0 when it was skipped on both drivers,
 * which is what the billing code checks before it moves a balance.
 *
 * @param string[] $conflictColumns Columns of the unique key (Postgres needs them named).
 * @param string   $touchColumn     Any column of the table, for MySQL's no-op update.
 */
function sqlInsertIgnore(array $conflictColumns, string $touchColumn): string {
    if (isPgsql()) {
        return $conflictColumns
            ? 'ON CONFLICT (' . implode(', ', $conflictColumns) . ') DO NOTHING'
            : 'ON CONFLICT DO NOTHING';
    }
    return "ON DUPLICATE KEY UPDATE $touchColumn = $touchColumn";
}

/** "Insert, or overwrite these columns if the row already exists." */
function sqlUpsert(array $conflictColumns, array $updateColumns): string {
    if (isPgsql()) {
        $sets = array_map(fn($c) => "$c = EXCLUDED.$c", $updateColumns);
        return 'ON CONFLICT (' . implode(', ', $conflictColumns) . ') DO UPDATE SET ' . implode(', ', $sets);
    }
    $sets = array_map(fn($c) => "$c = VALUES($c)", $updateColumns);
    return 'ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
}

/** Cast a timestamp expression to a plain date. */
function sqlToDate(string $expr): string {
    return isPgsql() ? "($expr)::date" : "DATE($expr)";
}

/** Short month name of a date column, e.g. "Sep". */
function sqlMonthName(string $expr): string {
    return isPgsql() ? "TO_CHAR($expr, 'Mon')" : "DATE_FORMAT($expr, '%b')";
}

/**
 * Row lock for the named table alias.
 * MariaDB has no "FOR UPDATE OF <alias>", so it locks every table in the statement.
 */
function sqlForUpdateOf(string $alias): string {
    return isPgsql() ? "FOR UPDATE OF $alias" : 'FOR UPDATE';
}

/** Boolean literal in a bound parameter, for columns that are BOOLEAN on PG and TINYINT on MySQL. */
function dbBool(bool $value): string {
    return $value ? '1' : '0';
}

/** True when the table exists in the current database. */
function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
                           WHERE table_schema = " . currentSchemaSql() . " AND table_name = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

/** True when the column exists on the table. */
function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                           WHERE table_schema = " . currentSchemaSql() . " AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Add a column if it is missing.
 * MySQL 8 has no "ADD COLUMN IF NOT EXISTS", so the check is done in information_schema
 * rather than in the ALTER statement, which works the same way on both drivers.
 */
function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    if (!tableExists($pdo, $table) || columnExists($pdo, $table, $column)) {
        return;
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

function currentSchemaSql(): string {
    return isPgsql() ? 'CURRENT_SCHEMA()' : 'DATABASE()';
}

/** Auto-incrementing primary key declaration. */
function sqlSerialPk(): string {
    return isPgsql() ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY';
}

/** Type for a column that holds free-form text of unbounded length. */
function sqlTimestampDefaultNow(): string {
    return 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP';
}

/**
 * Take an exclusive lock for the duration of the current transaction, so two requests
 * can't run the migrations at the same time. MySQL has no transaction-scoped advisory
 * lock, so it uses a named lock released explicitly by releaseMigrationLock().
 */
function acquireMigrationLock(PDO $pdo): void {
    if (isPgsql()) {
        $pdo->query("SELECT pg_advisory_xact_lock(778201)");
    } else {
        $pdo->query("SELECT GET_LOCK('boardinghouse_migrations', 30)");
    }
}

function releaseMigrationLock(PDO $pdo): void {
    if (isMysql()) {
        $pdo->query("SELECT RELEASE_LOCK('boardinghouse_migrations')");
    }
}
