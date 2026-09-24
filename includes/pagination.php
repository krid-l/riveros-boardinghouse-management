<?php
// includes/pagination.php
//
// Server-side paging for the admin lists.
//
// The tables used to render every row and let JavaScript hide all but a handful, so the
// payments page sent 3 MB of HTML to show five rows. These helpers work out the LIMIT and
// OFFSET for the current page and draw the controls; the page's filters travel in the query
// string so they survive paging.

const DEFAULT_PER_PAGE = 10;

/**
 * Read one query-string value as text.
 * A hand-crafted URL can make any parameter an array (?q[]=x), which would make trim() and
 * the like throw, so anything that isn't a plain scalar is treated as "not supplied".
 */
function queryParam(string $key, string $default = ''): string {
    $value = $_GET[$key] ?? null;
    return is_scalar($value) ? trim((string)$value) : $default;
}

/**
 * Work out which slice of the rows to show.
 *
 * @param int $totalRows Total matching rows (a COUNT query, not the rows themselves).
 * @return array page, perPage, total, totalPages, offset, from, to
 */
function paginate(int $totalRows, int $perPage = DEFAULT_PER_PAGE, string $param = 'page', array $extra = []): array {
    $perPage = max(1, $perPage);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = (int)queryParam($param, '1');
    $page = max(1, min($totalPages, $page));   // a hand-typed page number can't run off either end
    $offset = ($page - 1) * $perPage;

    return [
        'page'       => $page,
        'perPage'    => $perPage,
        'total'      => $totalRows,
        'totalPages' => $totalPages,
        'offset'     => $offset,
        'from'       => $totalRows === 0 ? 0 : $offset + 1,
        'to'         => min($offset + $perPage, $totalRows),
        'param'      => $param,
        'extra'      => $extra,   // query parameters every page link must carry, e.g. the open tab
    ];
}

/** A link to the same page with some query parameters changed, keeping the active filters. */
function pageUrl(array $overrides): string {
    $query = array_merge(array_filter($_GET, 'is_scalar'), $overrides);
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null);
    return strtok($_SERVER['REQUEST_URI'], '?') . ($query ? '?' . http_build_query($query) : '');
}

/** "Showing 1 to 10 of 961 payments" */
function paginationSummary(array $p, string $noun): string {
    if ($p['total'] === 0) {
        return 'No ' . htmlspecialchars($noun) . ' found';
    }
    return 'Showing ' . number_format($p['from']) . ' to ' . number_format($p['to'])
         . ' of ' . number_format($p['total']) . ' ' . htmlspecialchars($noun);
}

/**
 * Prev / page numbers / Next.
 * Only a window of pages is listed, so 961 rows don't produce 97 links.
 */
function paginationControls(array $p): string {
    if ($p['totalPages'] <= 1) {
        return '';
    }
    $param = $p['param'];
    $page = $p['page'];

    $extra = $p['extra'] ?? [];
    $item = function (string $label, ?int $target, bool $active = false, bool $disabled = false) use ($param, $extra): string {
        $classes = 'page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '');
        $linkClass = 'page-link px-2 py-1 ' . ($active ? 'border-primary' : 'text-muted border-light');
        $href = $disabled || $target === null
            ? '#'
            : htmlspecialchars(pageUrl($extra + [$param => $target]));
        return "<li class=\"$classes\"><a class=\"$linkClass\" href=\"$href\">" . htmlspecialchars($label) . '</a></li>';
    };

    // A window of at most 5 numbered pages centred on the current one.
    $window = 5;
    $start = max(1, min($page - intdiv($window, 2), $p['totalPages'] - $window + 1));
    $end = min($p['totalPages'], $start + $window - 1);

    $html = $item('Prev', $page - 1, false, $page <= 1);
    if ($start > 1) {
        $html .= $item('1', 1);
        if ($start > 2) $html .= '<li class="page-item disabled"><span class="page-link px-2 py-1 text-muted border-light">…</span></li>';
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $item((string)$i, $i, $i === $page);
    }
    if ($end < $p['totalPages']) {
        if ($end < $p['totalPages'] - 1) $html .= '<li class="page-item disabled"><span class="page-link px-2 py-1 text-muted border-light">…</span></li>';
        $html .= $item((string)$p['totalPages'], $p['totalPages']);
    }
    $html .= $item('Next', $page + 1, false, $page >= $p['totalPages']);

    return $html;
}

/** A LIMIT clause built from the state. The numbers are integers, never user text. */
function paginationLimitSql(array $p): string {
    return ' LIMIT ' . (int)$p['perPage'] . ' OFFSET ' . (int)$p['offset'];
}
