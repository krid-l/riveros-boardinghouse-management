<?php
// includes/auto_biller.php

if (!isset($pdo)) {
    return; // Safety check
}

try {
    $currentDay = (int)date('d');
    $currentMonth = date('Y-m'); // e.g., 2026-08

    // Find tenants who haven't been billed yet for THIS month, and have a room
    // The due date is the DAY of their move-in date (users.created_at)
    $stmt = $pdo->prepare("
        SELECT t.id, t.balance, r.price_per_month, EXTRACT(DAY FROM u.created_at) as move_in_day
        FROM tenants t 
        JOIN rooms r ON t.room_id = r.id 
        JOIN users u ON t.user_id = u.id
        WHERE t.last_billed_month != ? OR t.last_billed_month IS NULL
    ");
    $stmt->execute([$currentMonth]);
    $tenantsToBill = $stmt->fetchAll();

    if (count($tenantsToBill) > 0) {
        $pdo->beginTransaction();
        $updStmt = $pdo->prepare("UPDATE tenants SET balance = balance + ?, last_billed_month = ? WHERE id = ?");
        
        foreach ($tenantsToBill as $t) {
            $moveInDay = (int)$t['move_in_day'];
            // Auto bill only if today is past or on their specific move-in day
            if ($currentDay >= $moveInDay) {
                $monthlyRent = (float)$t['price_per_month'];
                $updStmt->execute([$monthlyRent, $currentMonth, $t['id']]);
            }
        }
        $pdo->commit();
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Auto Biller Error: " . $e->getMessage());
}
?>
