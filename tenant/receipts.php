<?php
require_once 'header.php';

// Fetch ALL payments (both verified and pending to match mockup)
$stmt = $pdo->prepare("SELECT * FROM payments WHERE tenant_id = ? ORDER BY payment_date DESC, id DESC");
$stmt->execute([$currentTenant['id']]);
$payments = $stmt->fetchAll();

$totalReceipts = 0;
$totalPaid = 0;
$pendingReceipts = 0;

foreach ($payments as $p) {
    if ($p['status'] === 'verified') {
        $totalReceipts++;
        $totalPaid += $p['amount'];
    } elseif ($p['status'] === 'pending') {
        $pendingReceipts++;
    }
}
?>

<style>
/* Custom Styles for Digital Receipts Screen */
.receipts-container { max-width: 900px; margin: 0 auto; padding-bottom: 3rem; }

.btn-how-works { border: 1px solid #e2e8f0; color: #3b82f6; font-weight: 600; font-size: 0.8rem; border-radius: 8px; padding: 0.5rem 1rem; background: #ffffff; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
.btn-how-works:hover { background: #f8fafc; }

.stats-card { background: #ffffff; border-radius: 16px; border: 1px solid #f1f5f9; box-shadow: 0 4px 12px rgba(0,0,0,0.03); display: flex; overflow: hidden; margin-bottom: 2rem; }
.stat-col { flex: 1; padding: 1.5rem; display: flex; align-items: center; gap: 1rem; border-right: 1px solid #f1f5f9; }
.stat-col:last-child { border-right: none; }
.stat-icon { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; }
.icon-blue { background: #eff6ff; color: #3b82f6; }
.icon-green { background: #f0fdf4; color: #22c55e; }
.icon-yellow { background: #fefce8; color: #eab308; }
.stat-label { font-size: 0.7rem; color: #64748b; font-weight: 600; margin-bottom: 0.2rem; }
.stat-value { font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1; }
.stat-sub { font-size: 0.65rem; color: #94a3b8; margin-top: 0.2rem; }

.custom-tabs { display: flex; gap: 2rem; border-bottom: 1px solid #e2e8f0; margin-bottom: 1.5rem; }
.custom-tab { padding: 0.75rem 0; font-size: 0.85rem; font-weight: 700; color: #64748b; cursor: pointer; border-bottom: 2px solid transparent; transition: all 0.2s; }
.custom-tab.active { color: #2563eb; border-bottom-color: #2563eb; }
.custom-tab i { margin-right: 6px; }

.filter-row { display: flex; gap: 1rem; margin-bottom: 1.5rem; }
.filter-input { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.6rem 1rem; font-size: 0.8rem; font-weight: 500; color: #475569; display: flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); flex: 1; }
.filter-input select, .filter-input input { border: none; background: transparent; outline: none; width: 100%; font-weight: 500; color: #475569; }

.receipt-card { min-width: 650px; background: #ffffff; border-radius: 16px; border: 1px solid #f1f5f9; box-shadow: 0 4px 12px rgba(0,0,0,0.03); padding: 1.5rem; margin-bottom: 1rem; transition: transform 0.2s; position: relative; }
.receipt-card:hover { transform: translateY(-2px); box-shadow: 0 8px 16px rgba(0,0,0,0.06); }
.rc-left { display: flex; flex-direction: column; align-items: center; text-align: center; width: 100px; border-right: 1px solid #f1f5f9; padding-right: 1.5rem; }
.rc-circle { width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 0.75rem; color: white; }
.circle-verified { background: #22c55e; }
.circle-pending { background: #eab308; }
.rc-badge { font-size: 0.65rem; font-weight: 700; padding: 0.25rem 0.6rem; border-radius: 6px; margin-bottom: 0.75rem; }
.badge-v { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.badge-p { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
.rc-date { font-size: 0.75rem; font-weight: 700; color: #1e293b; line-height: 1.2; mb-1 }
.rc-time { font-size: 0.65rem; color: #64748b; }

.rc-middle { flex: 2; padding: 0 1.5rem; }
.rc-right { flex: 1.5; padding-left: 1.5rem; display: flex; flex-direction: column; }
.rc-label { font-size: 0.65rem; color: #64748b; margin-bottom: 0.25rem; }
.rc-val { font-size: 0.85rem; font-weight: 700; color: #1e293b; margin-bottom: 1rem; }

.btn-view-receipt { border: 1px solid #e2e8f0; color: #2563eb; font-weight: 600; font-size: 0.75rem; border-radius: 8px; padding: 0.5rem 1rem; background: #ffffff; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }
.btn-view-receipt:hover { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
.btn-download { border: 1px solid #e2e8f0; color: #2563eb; font-size: 0.85rem; border-radius: 8px; padding: 0.5rem 0.75rem; background: #ffffff; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; }
.btn-download:hover { background: #eff6ff; border-color: #bfdbfe; }

.pending-alert-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 0.75rem; display: flex; gap: 10px; align-items: flex-start; }
.pa-title { font-size: 0.75rem; font-weight: 700; color: #b45309; }
.pa-desc { font-size: 0.65rem; color: #78350f; margin-top: 2px; }

.info-footer-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 1.25rem; display: flex; gap: 1rem; align-items: flex-start; margin-top: 2rem; }
.if-text { font-size: 0.8rem; color: #1e3a8a; line-height: 1.5; font-weight: 500; }
.chevron-right { position: absolute; right: 1.5rem; top: 1.5rem; color: #94a3b8; font-size: 1rem; }

/* Mobile Adjustments */
@media (max-width: 768px) {
    .stats-card { flex-direction: column; }
    .stat-col { border-right: none; border-bottom: 1px solid #f1f5f9; }
    .stat-col:last-child { border-bottom: none; }
    .filter-row { flex-direction: column; }
    
    
    
    
    
    
}
</style>

<div class="receipts-container">
    
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h3 class="fw-bolder mb-1 text-dark">My Digital Receipts</h3>
            <p class="text-muted" style="font-size: 0.85rem;">View and download your verified payment receipts.</p>
        </div>
        <button class="btn-how-works d-none d-md-flex align-items-center gap-2">
            <i class="fa-regular fa-circle-question"></i> How Receipts Work
        </button>
    </div>

    <!-- Stats Card -->
    <div class="stats-card">
        <div class="stat-col">
            <div class="stat-icon icon-blue"><i class="fa-solid fa-file-invoice"></i></div>
            <div>
                <div class="stat-label">Total Receipts</div>
                <div class="stat-value"><?= $totalReceipts ?></div>
                <div class="stat-sub">All time</div>
            </div>
        </div>
        <div class="stat-col">
            <div class="stat-icon icon-green"><i class="fa-regular fa-circle-check"></i></div>
            <div>
                <div class="stat-label">Total Paid</div>
                <div class="stat-value text-success">PHP <?= number_format($totalPaid, 2) ?></div>
                <div class="stat-sub">All time</div>
            </div>
        </div>
        <div class="stat-col">
            <div class="stat-icon icon-yellow"><i class="fa-regular fa-clock"></i></div>
            <div>
                <div class="stat-label">Pending Receipts</div>
                <div class="stat-value"><?= $pendingReceipts ?></div>
                <div class="stat-sub">Waiting for verification</div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="custom-tabs">
        <div class="custom-tab active" onclick="filterReceipts('all', this)">All Receipts</div>
        <div class="custom-tab" onclick="filterReceipts('verified', this)"><i class="fa-solid fa-circle-check text-success"></i> Verified</div>
        <div class="custom-tab" onclick="filterReceipts('pending', this)"><i class="fa-solid fa-clock text-warning"></i> Pending</div>
    </div>

    <!-- Filters -->
    <div class="filter-row">
        <div class="filter-input" style="flex: 0.7;">
            <i class="fa-regular fa-calendar text-muted"></i>
            <select>
                <option>All Dates</option>
                <option>This Month</option>
                <option>Last 6 Months</option>
                <option>This Year</option>
            </select>
        </div>
        <div class="filter-input" style="flex: 0.7;">
            <i class="fa-solid fa-list-ul text-muted"></i>
            <select id="statusFilter" onchange="applyFilters()">
                <option value="all">All Status</option>
                <option value="verified">Verified</option>
                <option value="pending">Pending</option>
            </select>
        </div>
        <div class="filter-input" style="flex: 1.6;">
            <i class="fa-solid fa-magnifying-glass text-muted"></i>
            <input type="text" id="searchInput" placeholder="Search receipt or ref #" onkeyup="applyFilters()">
        </div>
    </div>

    <!-- Receipt List -->
    <div id="receiptList">
        <?php if (empty($payments)): ?>
            <div class="text-center py-5 text-muted">No receipts or payments found.</div>
        <?php endif; ?>

        <?php foreach ($payments as $p): 
            $isVerified = ($p['status'] === 'verified');
            // Generate visual receipt number
            $rcpNum = 'RCP-' . date('mdY', strtotime($p['payment_date'])) . '-' . str_pad($p['id'], 3, '0', STR_PAD_LEFT);
            $payFor = date('F Y', strtotime($p['payment_date'])) . ' Rent';
        ?>
        <div class="receipt-card d-flex rcp-item" data-status="<?= $p['status'] ?>" data-search="<?= strtolower($rcpNum . ' ' . $p['reference_number']) ?>">
            <i class="fa-solid fa-chevron-right chevron-right d-none d-md-block"></i>
            
            <div class="rc-left">
                <?php if ($isVerified): ?>
                    <div class="rc-circle circle-verified"><i class="fa-solid fa-check"></i></div>
                    <div class="rc-badge badge-v">Verified</div>
                <?php else: ?>
                    <div class="rc-circle circle-pending"><i class="fa-solid fa-clock"></i></div>
                    <div class="rc-badge badge-p">Pending</div>
                <?php endif; ?>
                
                <div>
                    <div class="rc-date"><?= date('M d, Y', strtotime($p['payment_date'])) ?></div>
                    <div class="rc-time"><?= date('h:i A', strtotime($p['created_at'] ?? $p['payment_date'])) ?></div>
                </div>
            </div>

            <div class="rc-middle">
                <div class="rc-label">Receipt #</div>
                <div class="rc-val font-monospace"><?= htmlspecialchars($rcpNum) ?></div>
                
                <div class="rc-label">Reference No.</div>
                <div class="rc-val font-monospace"><?= htmlspecialchars($p['reference_number']) ?></div>
                
                <div class="rc-label">Payment Method</div>
                <div class="rc-val d-flex align-items-center gap-2">
                    <?php if (($p['payment_method'] ?? 'gcash') === 'cash'): ?>
                        <div class="bg-success bg-opacity-10 rounded d-flex align-items-center justify-content-center" style="width:20px; height:20px;">
                            <i class="fa-solid fa-money-bill-wave text-success" style="font-size:0.6rem;"></i>
                        </div> 
                        Cash
                    <?php else: ?>
                        <div class="bg-primary bg-opacity-10 rounded d-flex align-items-center justify-content-center" style="width:20px; height:20px;">
                            <i class="fa-solid fa-g text-primary" style="font-size:0.6rem;"></i>
                        </div> 
                        GCash
                    <?php endif; ?>
                </div>
            </div>

            <div class="rc-right">
                <div class="rc-label">Amount</div>
                <div class="rc-val" style="font-size: 1rem;">PHP <?= number_format($p['amount'], 2) ?></div>
                
                <div class="rc-label">Payment For</div>
                <div class="rc-val"><?= $payFor ?></div>
                
                <div class="mt-auto pt-2">
                    <?php if ($isVerified && !empty($p['receipt_path'])): ?>
                        <div class="d-flex gap-2">
                            <a href="../<?= htmlspecialchars($p['receipt_path']) ?>" target="_blank" class="btn-view-receipt flex-grow-1 justify-content-center">
                                <i class="fa-regular fa-file-lines text-primary"></i> View Receipt
                            </a>
                            <a href="../<?= htmlspecialchars($p['receipt_path']) ?>" download class="btn-download">
                                <i class="fa-solid fa-download"></i>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="pending-alert-box">
                            <i class="fa-regular fa-clock text-warning mt-1"></i>
                            <div>
                                <div class="pa-title">Pending Verification</div>
                                <div class="pa-desc">Your payment is being reviewed.</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Footer Info Box -->
    <div class="info-footer-box">
        <i class="fa-solid fa-circle-info text-primary fa-lg mt-1"></i>
        <div class="if-text">
            Once your payment is verified, your digital receipt will be available here.<br>
            You can view, download, and keep your receipts for your records.
        </div>
    </div>
</div>

<script>
let currentTabStatus = 'all';

function filterReceipts(status, element) {
    // Update active tab styling
    document.querySelectorAll('.custom-tab').forEach(el => el.classList.remove('active'));
    element.classList.add('active');
    
    // Sync dropdown
    document.getElementById('statusFilter').value = status;
    currentTabStatus = status;
    
    applyFilters();
}

function applyFilters() {
    const searchVal = document.getElementById('searchInput').value.toLowerCase();
    const dropdownStatus = document.getElementById('statusFilter').value;
    
    // Use dropdown status if it differs from tab (sync mechanism)
    if (dropdownStatus !== currentTabStatus) {
        currentTabStatus = dropdownStatus;
        // Update tab visuals to match dropdown
        document.querySelectorAll('.custom-tab').forEach(el => {
            if (el.innerText.toLowerCase().includes(dropdownStatus) || (dropdownStatus === 'all' && el.innerText.includes('All'))) {
                el.classList.add('active');
            } else {
                el.classList.remove('active');
            }
        });
    }

    const items = document.querySelectorAll('.rcp-item');
    let visibleCount = 0;

    items.forEach(item => {
        const itemStatus = item.getAttribute('data-status');
        const itemSearch = item.getAttribute('data-search');
        
        let statusMatch = (currentTabStatus === 'all' || itemStatus === currentTabStatus);
        let searchMatch = (searchVal === '' || itemSearch.includes(searchVal));

        if (statusMatch && searchMatch) {
            item.style.display = 'flex'; // It's a flex container on desktop
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
}
</script>

<?php require_once 'footer.php'; ?>
