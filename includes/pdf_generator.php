<?php
// includes/pdf_generator.php

if (!class_exists('FPDF')) {
    require_once __DIR__ . '/fpdf/fpdf.php';
}

function generateReceipt($paymentId, $tenantName, $amount, $date, $reference, $paymentMethod = 'GCash') {
    global $pdo;
    
    // Attempt to fetch current balance, pay_for_room status, and move-in date
    $remaining = 0;
    $payForRoom = false;
    $moveInDate = $date;
    
    if (isset($pdo)) {
        $stmt = $pdo->prepare("SELECT p.pay_for_room, t.balance, u.created_at AS move_in_date 
                               FROM payments p 
                               JOIN tenants t ON p.tenant_id = t.id 
                               JOIN users u ON t.user_id = u.id 
                               WHERE p.id = ?");
        $stmt->execute([$paymentId]);
        $data = $stmt->fetch();
        if ($data) {
            $remaining = (float)$data['balance'];
            $payForRoom = (bool)$data['pay_for_room'];
            $moveInDate = $data['move_in_date'];
        }
    }
    
    // Calculate Billing Cycle based on payment date and move-in day
    $paymentDateTs = strtotime($date);
    $moveInDay = (int)date('d', strtotime($moveInDate));
    $currentDay = (int)date('d', $paymentDateTs);
    $currentMonth = (int)date('m', $paymentDateTs);
    $currentYear = (int)date('Y', $paymentDateTs);

    if ($currentDay >= $moveInDay) {
        $startCycle = date('m/d/Y', strtotime("$currentYear-$currentMonth-$moveInDay"));
        $nextMonth = $currentMonth + 1; $nextYear = $currentYear; if($nextMonth>12){$nextMonth=1;$nextYear++;}
        $endCycle = date('m/d/Y', strtotime("$nextYear-$nextMonth-$moveInDay"));
    } else {
        $prevMonth = $currentMonth - 1; $prevYear = $currentYear; if($prevMonth<1){$prevMonth=12;$prevYear--;}
        $startCycle = date('m/d/Y', strtotime("$prevYear-$prevMonth-$moveInDay"));
        $endCycle = date('m/d/Y', strtotime("$currentYear-$currentMonth-$moveInDay"));
    }
    $cycleStr = "$startCycle to $endCycle";
    
    // Type string
    $typeStr = 'Individual Rent';
    if ($payForRoom) {
        $typeStr = 'Entire Room';
    } elseif (stripos($paymentMethod, 'Covered by') !== false) {
        $typeStr = 'Covered by Roommate';
    }
    
    $filename = 'receipt_' . $paymentId . '_' . time() . '.pdf';
    $dir = __DIR__ . '/../uploads/receipts/';
    
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    
    $path = $dir . $filename;
    
    // Custom size for thermal receipt (80mm width, dynamic height roughly 150mm)
    $pdf = new FPDF('P', 'mm', array(80, 160));
    $pdf->SetMargins(5, 10, 5);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    
    // Header
    $pdf->SetFont('Courier', 'B', 14);
    $pdf->Cell(0, 5, 'RIVEROS', 0, 1, 'C');
    $pdf->Cell(0, 5, 'BOARDING HOUSE', 0, 1, 'C');
    $pdf->Ln(2);
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 4, 'OFFICIAL RECEIPT', 0, 1, 'C');
    $pdf->Cell(0, 4, 'RCP-' . str_pad($paymentId, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
    
    $pdf->Ln(5);
    
    // Tenant Name
    $encodedName = $tenantName;
    if (function_exists('mb_convert_encoding')) {
        $encodedName = mb_convert_encoding($tenantName, 'ISO-8859-1', 'UTF-8');
    }
    if(strlen($encodedName) > 18) {
        $encodedName = substr($encodedName, 0, 15) . '...';
    }
    
    $pdf->SetFont('Courier', 'B', 15);
    $pdf->Cell(0, 6, strtoupper($encodedName), 0, 1, 'C');
    
    $pdf->Ln(3);
    
    // Remaining Balance Box
    $pdf->SetFont('Courier', 'B', 9);
    $boxText = 'P' . number_format(max(0, $remaining), 2) . ' REMAINING';
    $boxWidth = $pdf->GetStringWidth($boxText) + 8;
    $startX = (80 - $boxWidth) / 2;
    
    $pdf->SetX($startX);
    $pdf->Cell($boxWidth, 7, $boxText, 1, 1, 'C');
    
    $pdf->Ln(5);
    
    // Divider
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 3, str_repeat('-', 32), 0, 1, 'C');
    $pdf->Ln(2);
    
    // Amounts
    $pdf->Cell(35, 5, 'AMOUNT PAID', 0, 0, 'L');
    $pdf->SetFont('Courier', 'B', 10);
    $pdf->Cell(35, 5, 'P' . number_format($amount, 2), 0, 1, 'R');
    
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(35, 5, 'STILL OWED', 0, 0, 'L');
    $pdf->SetFont('Courier', 'B', 10);
    $pdf->Cell(35, 5, 'P' . number_format(max(0, $remaining), 2), 0, 1, 'R');
    
    $pdf->Ln(2);
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 3, str_repeat('-', 32), 0, 1, 'C');
    $pdf->Ln(2);
    
    // Details
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(0, 5, 'PAYMENT DETAILS', 0, 1, 'L');
    $pdf->SetFont('Courier', '', 8);
    
    $pdf->Cell(25, 4, 'Ref No:', 0, 0, 'L');
    $pdf->Cell(45, 4, $reference, 0, 1, 'R');
    
    $pdf->Cell(25, 4, 'Method:', 0, 0, 'L');
    $dispMethod = strlen($paymentMethod) > 20 ? substr($paymentMethod,0,20) : $paymentMethod;
    $pdf->Cell(45, 4, $dispMethod, 0, 1, 'R');
    
    $pdf->Cell(25, 4, 'Date:', 0, 0, 'L');
    $pdf->Cell(45, 4, date('M d, Y', strtotime($date)), 0, 1, 'R');
    
    $pdf->Cell(25, 4, 'Type:', 0, 0, 'L');
    $pdf->Cell(45, 4, $typeStr, 0, 1, 'R');
    
    // Added Billing Period!
    $pdf->Cell(25, 4, 'Covering:', 0, 0, 'L');
    $pdf->Cell(45, 4, $cycleStr, 0, 1, 'R');
    
    $pdf->Ln(2);
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 3, str_repeat('-', 32), 0, 1, 'C');
    
    // Mock Barcode
    $pdf->Ln(2);
    $pdf->SetFont('Courier', 'B', 10);
    $pdf->Cell(0, 5, '[ PAYMENT VERIFIED ]', 0, 1, 'C');
    $pdf->SetFont('Courier', '', 6);
    $pdf->Cell(0, 4, '|||||| | || ||| || ||| ||', 0, 1, 'C');
    
    $pdf->Ln(3);
    $pdf->SetFont('Courier', '', 7);
    $pdf->Cell(0, 4, 'PRINTED ON ' . date('M d, Y h:i A'), 0, 1, 'C');
    $pdf->Cell(0, 4, 'SYSTEM GENERATED RECEIPT', 0, 1, 'C');
    
    $pdf->Output('F', $path);
    
    return 'uploads/receipts/' . $filename;
}
?>
