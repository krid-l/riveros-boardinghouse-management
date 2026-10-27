<?php
// includes/ui.php
//
// Pieces of page layout shared by the admin pages.

/**
 * A row of summary cards, the same size on every page (styles: .stat-card in custom.css).
 * Every card is clickable: it either opens a page ('href') or a modal ('modal' => element id).
 *
 * Each card: label, value, icon (Font Awesome class), color (Bootstrap colour name),
 * optional sub (small line under the value), subClass (its text colour class),
 * href or modal, aria (what clicking does, for screen readers), active (the filter in use).
 */
function statCards(array $cards): string {
    $html = '<div class="row g-2 mb-3 stat-row">';
    foreach ($cards as $c) {
        $color = $c['color'] ?? 'primary';
        $aria = htmlspecialchars($c['aria'] ?? ('Open ' . $c['label']));
        if (!empty($c['modal'])) {
            $link = '<button type="button" class="stretched-link stat-link" data-bs-toggle="modal" data-bs-target="#'
                  . htmlspecialchars($c['modal']) . '" aria-label="' . $aria . '"></button>';
        } else {
            $link = '<a class="stretched-link stat-link" href="' . htmlspecialchars($c['href'] ?? '#') . '" aria-label="' . $aria . '"></a>';
        }
        $html .= '<div class="col-6 col-xl-3">'
               . '<div class="card stat-card border-0 shadow-sm h-100 rounded-3' . (!empty($c['active']) ? ' active' : '') . '">'
               . '<div class="card-body d-flex align-items-center">'
               . '<div class="stat-icon bg-' . $color . ' bg-opacity-10 text-' . $color . '"><i class="' . htmlspecialchars($c['icon']) . '"></i></div>'
               . '<div class="stat-text">'
               . '<div class="stat-label">' . htmlspecialchars($c['label']) . '</div>'
               . '<div class="stat-value" title="' . htmlspecialchars((string)$c['value']) . '">' . htmlspecialchars((string)$c['value']) . '</div>'
               . (isset($c['sub']) && $c['sub'] !== '' ? '<div class="stat-sub ' . htmlspecialchars($c['subClass'] ?? 'text-muted') . '">' . htmlspecialchars($c['sub']) . '</div>' : '')
               . '</div>'
               . '<i class="fa-solid fa-chevron-right stat-chevron"></i>'
               . '</div>' . $link . '</div></div>';
    }
    return $html . '</div>';
}

/** ₱1,234.50 */
function peso(float $amount): string {
    return '₱' . number_format($amount, 2);
}

/**
 * Shares of a whole, as percentages that add up to exactly 100 once rounded.
 * Rounding each share on its own can give 99.9% or 100.1% (1, 3 and 3 of 7: 14.3 + 42.9 + 42.9).
 * Each share is rounded down first, and the leftover tenths go to the shares that lost the most
 * (ties: the earlier one). ['occupied' => 4, 'available' => 4, 'maintenance' => 1]
 * gives ['occupied' => 44.5, 'available' => 44.4, 'maintenance' => 11.1].
 */
function percentShares(array $counts, int $decimals = 1): array {
    $total = array_sum($counts);
    if ($total <= 0) {
        return array_map(fn() => 0.0, $counts);
    }
    $scale = 10 ** $decimals;
    $units = [];
    $remainders = [];
    foreach ($counts as $key => $n) {
        $exact = $n / $total * 100 * $scale;
        $units[$key] = (int)floor($exact + 1e-9);
        $remainders[$key] = $exact - $units[$key];
    }
    $left = 100 * $scale - array_sum($units);
    $order = array_keys($counts);
    usort($order, fn($a, $b) => $remainders[$b] <=> $remainders[$a]);   // stable: ties keep their order
    foreach (array_slice($order, 0, max(0, $left)) as $key) {
        $units[$key]++;
    }
    return array_map(fn($u) => $u / $scale, $units);
}
