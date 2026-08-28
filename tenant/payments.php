<?php
require_once 'header.php';

// Fetch settings (for GCash)
$settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$gcashNumber = $settings['gcash_number'] ?? '0917 123 4567';
$gcashName = $settings['gcash_name'] ?? 'Boarding House';

$myBalance = (float)($currentTenant['balance'] ?? 0);

// Calculate total room balance
$roomTotalBalance = $myBalance;
if (!empty($currentTenant['room_id'])) {
    $stmt = $pdo->prepare("SELECT SUM(balance) FROM tenants WHERE room_id = ?");
    $stmt->execute([$currentTenant['room_id']]);
    $roomTotalBalance = (float)$stmt->fetchColumn();
}

// Get user's move-in date
$uStmt = $pdo->prepare("SELECT created_at FROM users WHERE id = ?");
$uStmt->execute([$_SESSION['user_id']]);
$userCreatedAt = $uStmt->fetchColumn();
$moveInDay = (int)date('d', strtotime($userCreatedAt));

$currentDay = (int)date('d');
$currentMonth = (int)date('m');
$currentYear = (int)date('Y');

// Fetch base rent for advance payment fallback
$roomStmt = $pdo->prepare("SELECT price_per_month FROM rooms WHERE id = ?");
$roomStmt->execute([$currentTenant['room_id']]);
$baseRent = (float)$roomStmt->fetchColumn();

$isAdvance = false;

if ($myBalance <= 0) {
    $isAdvance = true;
    // Calculate Next Cycle
    if ($currentDay >= $moveInDay) {
        $nextMonth = $currentMonth + 1; $nextYear = $currentYear; if($nextMonth>12){$nextMonth=1;$nextYear++;}
        $startCycle = date('m/d', strtotime("$nextYear-$nextMonth-$moveInDay"));
        $nextNextMonth = $nextMonth + 1; $nextNextYear = $nextYear; if($nextNextMonth>12){$nextNextMonth=1;$nextNextYear++;}
        $endCycle = date('m/d', strtotime("$nextNextYear-$nextNextMonth-$moveInDay"));
    } else {
        $startCycle = date('m/d', strtotime("$currentYear-$currentMonth-$moveInDay"));
        $nextMonth = $currentMonth + 1; $nextYear = $currentYear; if($nextMonth>12){$nextMonth=1;$nextYear++;}
        $endCycle = date('m/d', strtotime("$nextYear-$nextMonth-$moveInDay"));
    }
    $billingPeriodStr = "$startCycle - $endCycle (Advance Payment)";
    $myExpected = $baseRent;
    $roomExpected = $baseRent; // Assume room advance is just 1 room base rent
} else {
    // Current Cycle
    if ($currentDay >= $moveInDay) {
        $startCycle = date('m/d', strtotime("$currentYear-$currentMonth-$moveInDay"));
        $nextMonth = $currentMonth + 1; $nextYear = $currentYear; if($nextMonth>12){$nextMonth=1;$nextYear++;}
        $endCycle = date('m/d', strtotime("$nextYear-$nextMonth-$moveInDay"));
    } else {
        $prevMonth = $currentMonth - 1; $prevYear = $currentYear; if($prevMonth<1){$prevMonth=12;$prevYear--;}
        $startCycle = date('m/d', strtotime("$prevYear-$prevMonth-$moveInDay"));
        $endCycle = date('m/d', strtotime("$currentYear-$currentMonth-$moveInDay"));
    }
    $billingPeriodStr = "$startCycle - $endCycle";
    $myExpected = $myBalance;
    $roomExpected = $roomTotalBalance;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($myBalance <= 0) {
        $error = "Advance payments are disabled. You have no outstanding balance.";
    } else {
        $amount = (float)$_POST['amount'];
        $payment_date = $_POST['payment_date'];
    $payment_method = $_POST['payment_method'] ?? 'gcash';
    $reference_number = trim($_POST['reference_number']);
    $pay_for_room = isset($_POST['pay_for_room']) ? 'true' : 'false';
    
    // Determine expected exact amount
    $expectedAmount = isset($_POST['pay_for_room']) ? $roomExpected : $myExpected;
    
    if (empty($reference_number) && $payment_method === 'cash') {
        $reference_number = 'CASH-' . time();
    }
    
    $destPath = null;
    
    if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] !== UPLOAD_ERR_NO_FILE) {
        $fileTmpPath = $_FILES['screenshot']['tmp_name'];
        // Remove spaces and special characters from filename for better compatibility
        $cleanFileName = preg_replace('/[^A-Za-z0-9.\-_]/', '_', basename($_FILES['screenshot']['name']));
        $fileName = time() . '_' . $cleanFileName;
        
        // Use env variable if available, otherwise fallback to the known project URL
        $supabaseUrl = getenv('SUPABASE_URL') ?: 'https://edswwvalfxehdklaackx.supabase.co';
        $supabaseKey = getenv('SUPABASE_SERVICE_KEY');
        
        if ($supabaseUrl && $supabaseKey) {
            // Upload to Supabase Storage
            $bucketName = 'payments';
            $fileData = file_get_contents($fileTmpPath);
            $mimeType = mime_content_type($fileTmpPath);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "$supabaseUrl/storage/v1/object/$bucketName/$fileName");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $supabaseKey",
                "Content-Type: $mimeType"
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode == 200) {
                // If the bucket is public, the URL will be accessible directly
                $destPath = "$supabaseUrl/storage/v1/object/public/$bucketName/$fileName";
            } else {
                $error = "Failed to upload image to Supabase. Please try again.";
            }
        } else {
            // Fallback to local storage if Supabase is not configured
            $uploadDir = __DIR__ . '/../uploads/payments/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            if (move_uploaded_file($fileTmpPath, $uploadDir . $fileName)) {
                $destPath = 'uploads/payments/' . $fileName;
            }
        }
    }
    
    // Validation
    if (isset($error)) {
        // Keep the upload error
    } elseif (abs($amount - $expectedAmount) > 0.01) {
        $error = "Partial or incorrect payments are not allowed. You must pay the exact amount of PHP " . number_format($expectedAmount, 2);
    } elseif ($payment_method === 'gcash' && !$destPath) {
        $error = "Please upload a GCash screenshot.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO payments (tenant_id, amount, payment_date, reference_number, screenshot_path, payment_method, pay_for_room) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$currentTenant['id'], $amount, $payment_date, $reference_number, $destPath, $payment_method, $pay_for_room]);
        $success = "Payment submitted successfully. Awaiting Admin verification.";
    }
    }
}

// Fetch payment history
$stmt = $pdo->prepare("SELECT * FROM payments WHERE tenant_id = ? ORDER BY payment_date DESC, id DESC LIMIT 5");
$stmt->execute([$currentTenant['id']]);
$payments = $stmt->fetchAll();
?>

<style>
/* Custom Styles for Payment Screen */
.payment-container { max-width: 550px; margin: 0 auto; padding-bottom: 3rem; }
.gcash-card { background: #f0f7ff; border-radius: 16px; padding: 1.25rem; display: flex; justify-content: space-between; align-items: stretch; margin-bottom: 0.5rem; }
.qr-box { background: #fff; padding: 6px; border-radius: 8px; border: 1px solid #e2e8f0; display:flex; align-items:center; justify-content:center; }
.info-text { color: #475569; font-size: 0.75rem; text-align: center; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: center; gap: 8px; }

.section-card { background: #ffffff; border-radius: 16px; padding: 1.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.03); margin-bottom: 1.5rem; border: 1px solid #f8fafc; }
.step-circle { width: 24px; height: 24px; background: #0d6efd; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: bold; margin-right: 10px; }
.form-label-custom { font-size: 0.75rem; font-weight: 700; color: #1e293b; margin-bottom: 0.4rem; }
.required-ast { color: #ef4444; }
.form-control-custom { border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.85rem; padding: 0.6rem 0.75rem; box-shadow: none; }
.form-control-custom:focus { border-color: #3b82f6; box-shadow: 0 0 0 0.2rem rgba(59,130,246,0.1); }

/* File Upload Custom Styling */
.file-upload-wrapper { position: relative; width: 100%; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 1.5rem; text-align: center; cursor: pointer; background: #f8fafc; transition: all 0.2s; }
.file-upload-wrapper:hover { background: #f1f5f9; border-color: #94a3b8; }
.file-upload-input { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }

/* File Upload Preview State */
.file-preview-box { border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem; display: none; align-items: center; justify-content: space-between; background: #ffffff; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
.file-preview-icon { width: 45px; height: 55px; background: #f0f7ff; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #3b82f6; object-fit: cover; }
.btn-choose-another { border: 1px solid #3b82f6; color: #3b82f6; font-weight: 600; font-size: 0.85rem; border-radius: 8px; padding: 0.6rem; width: 100%; background: transparent; display: none; margin-top: 1rem; }
.btn-choose-another:hover { background: #eff6ff; color: #2563eb; }

.btn-submit { background: #0d6efd; color: white; font-weight: 600; font-size: 0.85rem; border-radius: 8px; padding: 0.7rem; width: 100%; border: none; margin-top: 1rem; transition: background 0.2s; }
.btn-submit:hover { background: #0b5ed7; color: white; }
.footer-text { font-size: 0.7rem; color: #64748b; text-align: center; margin-top: 1rem; display: flex; justify-content: center; align-items: center; gap: 6px; }

/* History List */
.history-item { border-bottom: 1px solid #f1f5f9; padding: 1rem 0; display: flex; justify-content: space-between; align-items: center; }
.history-item:last-child { border-bottom: none; padding-bottom: 0; }
.badge-pending { background: #fef3c7; color: #d97706; padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.65rem; font-weight: 700; border: 1px solid #fde68a; }
.badge-verified { background: #dcfce7; color: #166534; padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.65rem; font-weight: 700; border: 1px solid #bbf7d0; }
</style>

<div class="payment-container">
    <!-- Header -->
    <div class="mb-4">
        <h4 class="fw-bolder mb-0 text-dark" style="font-size: 1.25rem;">Upload GCash Payment</h4>
    </div>

    <!-- Alerts -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success d-flex align-items-center border-0 shadow-sm rounded-3 py-2 mb-3" style="font-size: 0.85rem;"><i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger d-flex align-items-center border-0 shadow-sm rounded-3 py-2 mb-3" style="font-size: 0.85rem;"><i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Form Section -->
    <div class="section-card pb-3">
        <div class="d-flex align-items-center mb-4">
            <div class="step-circle">1</div>
            <h6 class="fw-bold text-dark mb-0">Payment Details</h6>
        </div>

        <form method="POST" enctype="multipart/form-data" id="paymentForm">
            
            <div class="mb-4">
                <label class="form-label-custom">Payment Method <span class="required-ast">*</span></label>
                <div class="d-flex gap-3">
                    <label class="btn btn-outline-primary flex-grow-1 text-start" style="border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                        <input type="radio" name="payment_method" value="gcash" class="me-2" onchange="togglePaymentMethod()" checked>
                        <i class="fa-solid fa-mobile-screen-button me-1"></i> GCash
                    </label>
                    <label class="btn btn-outline-success flex-grow-1 text-start" style="border-radius: 12px; font-size: 0.85rem; font-weight: 600;">
                        <input type="radio" name="payment_method" value="cash" class="me-2" onchange="togglePaymentMethod()">
                        <i class="fa-solid fa-money-bill-wave me-1"></i> Cash
                    </label>
                </div>
            </div>

            <!-- GCash Details Block (Hidden for Cash) -->
            <div id="gcashDetailsBlock">
                <div class="gcash-card mb-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 28px; height: 28px;">
                                <i class="fa-solid fa-g text-white" style="font-size: 0.75rem;"></i>
                            </div>
                            <div>
                                <div class="fw-bold text-dark" style="font-size: 0.85rem; line-height: 1;">GCash Payment Details</div>
                                <div class="text-muted" style="font-size: 0.65rem;">Send your payment to the number below.</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="fa-solid fa-phone text-dark"></i>
                            <h4 class="fw-bold text-dark mb-0 me-1"><?= htmlspecialchars($gcashNumber) ?></h4>
                            <i class="fa-regular fa-copy text-primary" style="cursor:pointer;" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($gcashNumber) ?>'); alert('Number Copied!');"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 0.85rem; line-height: 1.2;"><?= htmlspecialchars($gcashName) ?></div>
                            <div class="text-muted" style="font-size: 0.7rem;">Account Name</div>
                        </div>
                    </div>
                    <div class="text-center d-none d-sm-block">
                        <div class="fw-bold text-dark mb-1" style="font-size: 0.7rem;">Scan to Pay</div>
                        <div class="qr-box shadow-sm">
                            <div class="bg-dark rounded d-flex align-items-center justify-content-center" style="width: 70px; height: 70px;">
                                <i class="fa-solid fa-qrcode text-white fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="info-text mb-4">
                    <i class="fa-solid fa-circle-info text-primary"></i> After sending payment, fill in the details and upload your proof.
                </div>
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label-custom mb-0">Amount (PHP) <span class="required-ast">*</span></label>
                    
                    <?php if (!empty($currentTenant['room_id']) && $roomTotalBalance > $myBalance): ?>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="payForRoom" name="pay_for_room" value="1" onchange="updateAmount()">
                        <label class="form-check-label text-muted" for="payForRoom" style="font-size: 0.75rem; font-weight: 600;">Pay for entire room</label>
                    </div>
                    <?php endif; ?>
                </div>
                
                <input type="number" step="0.01" name="amount" id="amountInput" class="form-control form-control-custom fw-semibold bg-light" value="<?= htmlspecialchars($myExpected) ?>" readonly required>
                <div class="text-muted mt-1" style="font-size: 0.65rem;"><i class="fa-solid fa-circle-info me-1 text-primary"></i> Partial payments are disabled. You must pay the exact balance.</div>
                
                <div class="alert alert-info border-0 p-2 mt-2 mb-0 d-flex align-items-center" style="font-size: 0.7rem; background-color:#e0f2fe; color:#0284c7;">
                    <i class="fa-regular fa-calendar-check me-2 fs-6"></i>
                    <div>You are paying your rent for the billing cycle: <strong class="ms-1"><?= $billingPeriodStr ?></strong></div>
                </div>
            </div>
            
            <script>
            function updateAmount() {
                const payForRoom = document.getElementById('payForRoom');
                const amountInput = document.getElementById('amountInput');
                const myBal = <?= $myExpected ?>;
                const roomBal = <?= $roomExpected ?>;
                
                if (payForRoom && payForRoom.checked) {
                    amountInput.value = roomBal.toFixed(2);
                } else {
                    amountInput.value = myBal.toFixed(2);
                }
            }
            </script>
            
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label-custom">Payment Date <span class="required-ast">*</span></label>
                    <input type="date" name="payment_date" class="form-control form-control-custom text-muted fw-semibold" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label-custom" id="labelRefNum">GCash Reference Number <span class="required-ast" id="astRefNum">*</span></label>
                    <input type="text" name="reference_number" id="inpRefNum" class="form-control form-control-custom fw-semibold" placeholder="8926 2500 01" required>
                    <div class="text-muted mt-1" style="font-size: 0.6rem;" id="hintRefNum">Example: 8926 2500 01</div>
                </div>
            </div>

            <div class="mb-1">
                <label class="form-label-custom" id="labelScreenshot">Upload Screenshot (Receipt) <span class="required-ast" id="astScreenshot">*</span></label>
                
                <!-- Empty State Upload Area -->
                <div class="file-upload-wrapper" id="uploadWrapper">
                    <i class="fa-solid fa-cloud-arrow-up text-primary fa-2x mb-2"></i>
                    <div class="fw-bold text-dark" style="font-size: 0.85rem;">Tap to choose file</div>
                    <div class="text-muted" style="font-size: 0.7rem;">PNG, JPG up to 5MB</div>
                    <input type="file" name="screenshot" id="screenshotInput" class="file-upload-input" accept="image/*" required>
                </div>
                
                <!-- Filled State Preview Area -->
                <div class="file-preview-box" id="previewBox">
                    <div class="d-flex align-items-center gap-3">
                        <img src="" id="previewImg" class="file-preview-icon shadow-sm">
                        <div>
                            <div class="fw-bold text-dark text-truncate" id="previewName" style="font-size: 0.75rem; max-width: 150px;">receipt.jpg</div>
                            <div class="text-muted mb-1" id="previewSize" style="font-size: 0.65rem;">245 KB</div>
                            <div class="text-success fw-semibold" style="font-size: 0.7rem;"><i class="fa-solid fa-circle-check me-1"></i> File uploaded successfully</div>
                        </div>
                    </div>
                    <i class="fa-solid fa-xmark text-muted" style="cursor:pointer;" onclick="resetUpload()"></i>
                </div>
                
                <button type="button" class="btn-choose-another" id="btnChooseAnother" onclick="document.getElementById('screenshotInput').click()">
                    <i class="fa-regular fa-image me-2"></i> Choose Another File
                </button>
            </div>

            <?php if ($myBalance <= 0): ?>
                <button type="button" class="btn-submit bg-secondary border-0 text-white opacity-75" style="cursor: not-allowed;">
                    <i class="fa-solid fa-check me-2"></i> Fully Paid
                </button>
                <div class="text-center mt-2 text-muted" style="font-size:0.75rem;">You have no outstanding balance to pay.</div>
            <?php else: ?>
                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-cloud-arrow-up me-2"></i> Submit for Verification
                </button>
            <?php endif; ?>
            
            <div class="footer-text">
                <i class="fa-solid fa-shield-halved"></i> You will be notified once your payment is verified.
            </div>
        </form>
    </div>

    <!-- History Section -->
    <div class="section-card pt-4 pb-2">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h6 class="fw-bold text-dark mb-0"><i class="fa-regular fa-clock text-primary me-2"></i> Payment History</h6>
            <a href="receipts.php" class="text-primary fw-bold text-decoration-none" style="font-size: 0.75rem;">View All</a>
        </div>

        <div>
            <?php if (empty($payments)): ?>
                <div class="text-center py-4 text-muted" style="font-size: 0.8rem;">No payment history found.</div>
            <?php endif; ?>
            
            <?php foreach ($payments as $p): 
                $statusClass = $p['status'] === 'verified' ? 'badge-verified' : ($p['status'] === 'pending' ? 'badge-pending' : 'badge-pending border-danger text-danger');
                $dotColor = $p['status'] === 'verified' ? '#22c55e' : ($p['status'] === 'pending' ? '#eab308' : '#ef4444');
            ?>
            <div class="history-item">
                <!-- Date column -->
                <div class="d-flex gap-2">
                    <div class="mt-1"><span style="width:6px; height:6px; background:<?= $dotColor ?>; border-radius:50%; display:block;"></span></div>
                    <div>
                        <div class="fw-bold text-dark" style="font-size: 0.8rem;"><?= date('M d, Y', strtotime($p['payment_date'])) ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;"><?= date('h:i A', strtotime($p['created_at'] ?? $p['payment_date'])) ?></div>
                    </div>
                </div>
                
                <!-- Ref column -->
                <div>
                    <div class="fw-bold text-dark" style="font-size: 0.8rem;">Ref: <?= htmlspecialchars($p['reference_number']) ?></div>
                    <div class="text-muted" style="font-size: 0.7rem;">GCash</div>
                </div>
                
                <!-- Amount & Status column -->
                <div class="text-end d-flex align-items-center gap-3">
                    <div>
                        <div class="fw-bold text-dark mb-1" style="font-size: 0.8rem;">PHP <?= number_format($p['amount'], 2) ?></div>
                        <span class="<?= $statusClass ?>"><?= ucfirst($p['status']) ?></span>
                    </div>
                    <i class="fa-solid fa-chevron-right text-muted" style="font-size: 0.8rem;"></i>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="footer-text mt-4">
            <i class="fa-solid fa-shield-halved"></i> Verified payments will be reflected in your balance.
        </div>
    </div>
</div>

<script>
// Payment Method Toggle Logic
function togglePaymentMethod() {
    const method = document.querySelector('input[name="payment_method"]:checked').value;
    
    const gcashDetails = document.getElementById('gcashDetailsBlock');
    const labelRefNum = document.getElementById('labelRefNum');
    const inpRefNum = document.getElementById('inpRefNum');
    const hintRefNum = document.getElementById('hintRefNum');
    const astRefNum = document.getElementById('astRefNum');
    
    const labelScreenshot = document.getElementById('labelScreenshot');
    const fileInput = document.getElementById('screenshotInput');
    const astScreenshot = document.getElementById('astScreenshot');
    
    if (method === 'cash') {
        // Hide GCash Card
        gcashDetails.style.display = 'none';
        
        // Update Reference Number field
        labelRefNum.innerHTML = 'Reference Number <span class="text-muted fw-normal">(Optional for Cash)</span>';
        inpRefNum.placeholder = "Leave blank to auto-generate";
        inpRefNum.required = false;
        hintRefNum.style.display = 'none';
        
        // Update Upload field
        labelScreenshot.innerHTML = 'Upload Screenshot <span class="text-muted fw-normal">(Optional for Cash)</span>';
        fileInput.required = false;
        
    } else {
        // Show GCash Card
        gcashDetails.style.display = 'block';
        
        // Update Reference Number field
        labelRefNum.innerHTML = 'GCash Reference Number <span class="required-ast" id="astRefNum">*</span>';
        inpRefNum.placeholder = "8926 2500 01";
        inpRefNum.required = true;
        hintRefNum.style.display = 'block';
        
        // Update Upload field
        labelScreenshot.innerHTML = 'Upload Screenshot (Receipt) <span class="required-ast" id="astScreenshot">*</span>';
        fileInput.required = true;
    }
}

// File Upload Preview Logic
const fileInput = document.getElementById('screenshotInput');
const uploadWrapper = document.getElementById('uploadWrapper');
const previewBox = document.getElementById('previewBox');
const previewImg = document.getElementById('previewImg');
const previewName = document.getElementById('previewName');
const previewSize = document.getElementById('previewSize');
const btnChooseAnother = document.getElementById('btnChooseAnother');

fileInput.addEventListener('change', function() {
    if (this.files && this.files[0]) {
        const file = this.files[0];
        previewName.textContent = file.name;
        previewSize.textContent = (file.size / 1024).toFixed(0) + ' KB';
        
        // Show Image Preview
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
        }
        reader.readAsDataURL(file);

        uploadWrapper.style.display = 'none';
        previewBox.style.display = 'flex';
        btnChooseAnother.style.display = 'block';
    }
});

function resetUpload() {
    fileInput.value = '';
    uploadWrapper.style.display = 'block';
    previewBox.style.display = 'none';
    btnChooseAnother.style.display = 'none';
}

// Initialize on page load
togglePaymentMethod();
</script>

<?php require_once 'footer.php'; ?>
