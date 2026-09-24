<?php
// includes/migrations.php
// Applies schema changes once, automatically, on the first request after deploy.
// Guarded by a lock so concurrent requests can't run it twice.
// Everything here has to work on PostgreSQL (Supabase) and on MySQL (local WAMP testing),
// so DDL goes through the helpers in sql_compat.php rather than driver-specific syntax.

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/sql_compat.php';

const SCHEMA_VERSION = '2';

function runMigrations(PDO $pdo): void {
    try {
        $current = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn();
        if ($current === SCHEMA_VERSION) {
            return;
        }
    } catch (PDOException $e) {
        // settings table missing: fall through and create it
    }

    $pdo->beginTransaction();
    try {
        acquireMigrationLock($pdo);

        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT)");
        $current = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn();
        if ($current === SCHEMA_VERSION) {
            if ($pdo->inTransaction()) $pdo->commit();
            releaseMigrationLock($pdo);
            return;
        }

        migrateBaseSchema($pdo);
        migrateToV2($pdo);

        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', ?)
                       " . sqlUpsert(['setting_key'], ['setting_value']))
            ->execute([SCHEMA_VERSION]);
        if ($pdo->inTransaction()) $pdo->commit();
        releaseMigrationLock($pdo);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        releaseMigrationLock($pdo);
        error_log("Migration failed: " . $e->getMessage());
        die("Database update failed: " . htmlspecialchars($e->getMessage()));
    }
}

// Columns the app grew after the first release, on top of database/schema.sql.
// They were added by the one-off migrate_*.php scripts before; doing it here means a fresh
// local database ends up with exactly the same shape as the deployed one.
function migrateBaseSchema(PDO $pdo): void {
    ensureColumn($pdo, 'users', 'temp_password', 'VARCHAR(255)');

    ensureColumn($pdo, 'tenants', 'occupation', 'VARCHAR(100)');
    ensureColumn($pdo, 'tenants', 'emergency_contact', 'VARCHAR(100)');
    ensureColumn($pdo, 'tenants', 'address', 'TEXT');
    ensureColumn($pdo, 'tenants', 'date_of_birth', 'DATE');
    ensureColumn($pdo, 'tenants', 'profile_picture', 'VARCHAR(255)');

    ensureColumn($pdo, 'payments', 'payment_method', "VARCHAR(50) DEFAULT 'gcash'");
    ensureColumn($pdo, 'payments', 'pay_for_room', 'BOOLEAN DEFAULT FALSE');

    ensureColumn($pdo, 'complaints', 'category', "VARCHAR(50) DEFAULT 'Others'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id " . sqlSerialPk() . ",
        title VARCHAR(150) NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

// v2: tenant status / move-in date, rent charges ledger, room transfer history,
// and linking "covered by roommate" payment rows to the payment that paid for them.
function migrateToV2(PDO $pdo): void {
    ensureColumn($pdo, 'tenants', 'status', "VARCHAR(20) NOT NULL DEFAULT 'active'");
    ensureColumn($pdo, 'tenants', 'move_in_date', 'DATE');
    ensureColumn($pdo, 'tenants', 'deactivated_at', 'DATE');
    ensureColumn($pdo, 'tenants', 'last_billed_month', 'VARCHAR(7)');

    ensureColumn($pdo, 'payments', 'covered_by_payment_id', 'INT REFERENCES payments(id) ON DELETE CASCADE');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS charges (
            id " . sqlSerialPk() . ",
            tenant_id INT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            room_id INT REFERENCES rooms(id) ON DELETE SET NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'rent',
            billing_month VARCHAR(7) NOT NULL,
            description VARCHAR(255),
            amount DECIMAL(10,2) NOT NULL,
            due_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (tenant_id, billing_month, kind)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS room_transfers (
            id " . sqlSerialPk() . ",
            tenant_id INT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
            from_room_id INT REFERENCES rooms(id) ON DELETE SET NULL,
            to_room_id INT REFERENCES rooms(id) ON DELETE SET NULL,
            transferred_at DATE NOT NULL,
            note VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Old "covered by roommate" rows were stand-alone payments, so revenue totals
    // counted the roommate's share twice. Link them to the room payment they came from.
    if (isPgsql()) {
        $pdo->exec("
            UPDATE payments c
            SET covered_by_payment_id = (
                SELECT p.id FROM payments p
                WHERE p.pay_for_room = true
                  AND p.status = 'verified'
                  AND p.reference_number = c.reference_number
                  AND p.id <> c.id
                ORDER BY p.id LIMIT 1
            )
            WHERE c.covered_by_payment_id IS NULL
              AND c.payment_method LIKE 'Covered by %'
        ");
    } else {
        // MySQL can't read the table it updates in a sub-select, but it can join a derived
        // table, which is materialised first.
        $pdo->exec("
            UPDATE payments c
            JOIN (
                SELECT reference_number, MIN(id) AS room_payment_id
                FROM payments
                WHERE pay_for_room = 1 AND status = 'verified'
                GROUP BY reference_number
            ) src ON src.reference_number = c.reference_number AND src.room_payment_id <> c.id
            SET c.covered_by_payment_id = src.room_payment_id
            WHERE c.covered_by_payment_id IS NULL
              AND c.payment_method LIKE 'Covered by %'
        ");
    }

    // Move-in date used to be the account creation date.
    if (isPgsql()) {
        $pdo->exec("
            UPDATE tenants t SET move_in_date = u.created_at::date
            FROM users u
            WHERE u.id = t.user_id AND t.move_in_date IS NULL
              AND (t.room_id IS NOT NULL OR t.last_billed_month IS NOT NULL)
        ");
    } else {
        $pdo->exec("
            UPDATE tenants t
            JOIN users u ON u.id = t.user_id
            SET t.move_in_date = DATE(u.created_at)
            WHERE t.move_in_date IS NULL
              AND (t.room_id IS NOT NULL OR t.last_billed_month IS NOT NULL)
        ");
    }

    // Opening entry so each existing tenant's ledger adds up to their current balance:
    // opening = balance + everything already credited to them.
    // It's due on this month's due date, so nobody turns overdue the moment this deploys.
    $currentMonth = date('Y-m');
    $rows = $pdo->query("
        SELECT t.id, t.room_id, t.balance, " . tenantCreditedSql('t') . " AS credited
        FROM tenants t
        WHERE NOT EXISTS (SELECT 1 FROM charges c WHERE c.tenant_id = t.id)
    ")->fetchAll();

    $ins = $pdo->prepare("
        INSERT INTO charges (tenant_id, room_id, kind, billing_month, description, amount, due_date)
        VALUES (?, ?, 'opening', ?, 'Charges before billing update', ?, ?)
        " . sqlInsertIgnore(['tenant_id', 'billing_month', 'kind'], 'tenant_id') . "
    ");
    foreach ($rows as $r) {
        $opening = round((float)$r['balance'] + (float)$r['credited'], 2);
        if (abs($opening) >= 0.01) {
            $ins->execute([$r['id'], $r['room_id'], $currentMonth, $opening, billingDueDate($currentMonth)]);
        }
    }
}
