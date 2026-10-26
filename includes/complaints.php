<?php
// includes/complaints.php
//
// How a complaint is shown, the same on the admin's and the tenant's pages.
// The database keeps status 'open' or 'resolved'. An open complaint is Pending until the admin
// replies, then In Progress until it is marked resolved.

const COMPLAINT_PRIORITIES = [
    // value  => [label,   colour]
    'low'    => ['Low',    'success'],
    'normal' => ['Medium', 'warning'],
    'high'   => ['High',   'danger'],
];

/** What tenants can complain about, with an icon for each. */
const COMPLAINT_CATEGORIES = [
    'Maintenance & Repair' => 'fa-screwdriver-wrench',
    'Noise Complaint'      => 'fa-volume-high',
    'Cleanliness'          => 'fa-broom',
    'Security'             => 'fa-shield-halved',
    'Others'               => 'fa-ellipsis',
];

const COMPLAINT_PENDING_SQL = "c.status <> 'resolved' AND (c.admin_response IS NULL OR c.admin_response = '')";
const COMPLAINT_PROGRESS_SQL = "c.status <> 'resolved' AND c.admin_response IS NOT NULL AND c.admin_response <> ''";

/** ['key' => pending|in_progress|resolved, 'label', 'color', 'icon'] */
function complaintStatus(array $c): array {
    if (($c['status'] ?? '') === 'resolved') {
        return ['key' => 'resolved', 'label' => 'Resolved', 'color' => 'success', 'icon' => 'fa-circle-check'];
    }
    if (trim((string)($c['admin_response'] ?? '')) !== '') {
        return ['key' => 'in_progress', 'label' => 'In Progress', 'color' => 'primary', 'icon' => 'fa-spinner'];
    }
    return ['key' => 'pending', 'label' => 'Pending', 'color' => 'warning', 'icon' => 'fa-clock'];
}

/** "C-2026-007": the year it was filed and its number. */
function complaintCode(array $c): string {
    return 'C-' . date('Y', strtotime($c['created_at'] ?? 'now')) . '-' . str_pad((string)$c['id'], 3, '0', STR_PAD_LEFT);
}

function complaintPriority(?string $value): array {
    return COMPLAINT_PRIORITIES[$value ?? 'normal'] ?? COMPLAINT_PRIORITIES['normal'];
}

/** "3 hours", "1.5 days" */
function durationText(float $seconds): string {
    if ($seconds < 3600) return max(1, round($seconds / 60)) . ' min';
    if ($seconds < 86400) {
        $h = round($seconds / 3600, 1);
        return ($h == 1 ? '1 hour' : rtrim(rtrim(number_format($h, 1), '0'), '.') . ' hours');
    }
    $d = round($seconds / 86400, 1);
    return ($d == 1 ? '1 day' : rtrim(rtrim(number_format($d, 1), '0'), '.') . ' days');
}
