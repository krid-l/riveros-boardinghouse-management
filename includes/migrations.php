<?php
// includes/migrations.php
// Applies schema changes once, automatically, on the first request after deploy.
// Guarded by a Postgres advisory lock so concurrent requests can't run it twice.

require_once __DIR__ . '/billing.php';

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
        $pdo->query("SELECT pg_advisory_xact_lock(778201)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT)");
        $current = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn();
        if ($current === SCHEMA_VERSION) {
            $pdo->commit();
            return;
        }

        migrateToV2($pdo);

        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', ?)
                       ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value")
            ->execute([SCHEMA_VERSION]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Migration failed: " . $e->getMessage());
        die("Database update failed: " . htmlspecialchars($e->getMessage()));
    }
}

// v2: tenant status / move-in date, rent charges ledger, room transfer history,
// and linking "covered by roommate" payment rows to the payment that paid for them.
function migrateToV2(PDO $pdo): void {
    $pdo->exec("
        ALTER TABLE tenants
            ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'active',
            ADD COLUMN IF NOT EXISTS move_in_date DATE,
            ADD COLUMN IF NOT EXISTS deactivated_at DATE,
            ADD COLUMN IF NOT EXISTS last_billed_month VARCHAR(7)
    ");

    $pdo->exec("
        ALTER TABLE payments
            ADD COLUMN IF NOT EXISTS covered_by_payment_id INT REFERENCES payments(id) ON DELETE CASCADE
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS charges (
            id SERIAL PRIMARY KEY,
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
            id SERIAL PRIMARY KEY,
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

    // Move-in date used to be the account creation date.
    $pdo->exec("
        UPDATE tenants t SET move_in_date = u.created_at::date
        FROM users u
        WHERE u.id = t.user_id AND t.move_in_date IS NULL
          AND (t.room_id IS NOT NULL OR t.last_billed_month IS NOT NULL)
    ");

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
        ON CONFLICT DO NOTHING
    ");
    foreach ($rows as $r) {
        $opening = round((float)$r['balance'] + (float)$r['credited'], 2);
        if (abs($opening) >= 0.01) {
            $ins->execute([$r['id'], $r['room_id'], $currentMonth, $opening, billingDueDate($currentMonth)]);
        }
    }
}
