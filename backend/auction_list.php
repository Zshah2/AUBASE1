<?php
declare(strict_types=1);

/**
 * WHERE clause and bound params for auction listing.
 * $systemNow must match AuctionBase system time (CurrentTime table), same as item.php / dashboard.
 *
 * @return array{0: string, 1: string, 2: list<mixed>}
 */
function aubase_auction_filters(string $search, string $category, string $tab, string $systemNow): array
{
    $conditions = [];
    $types = '';
    $params = [];

    if ($search !== '') {
        $conditions[] = 'i.name LIKE ?';
        $types .= 's';
        $params[] = '%' . $search . '%';
    }
    if ($category !== '') {
        $conditions[] = 'c.name = ?';
        $types .= 's';
        $params[] = $category;
    }

    if ($tab === 'open') {
        $conditions[] = 'a.end_time > ?';
        $types .= 's';
        $params[] = $systemNow;
    } elseif ($tab === 'closed') {
        $conditions[] = 'a.end_time <= ?';
        $types .= 's';
        $params[] = $systemNow;
    } elseif ($tab === 'buynow') {
        $conditions[] = 'a.buy_price IS NOT NULL';
    }

    $where_sql = 'WHERE 1=1';
    foreach ($conditions as $c) {
        $where_sql .= ' AND ' . $c;
    }

    return [$where_sql, $types, $params];
}
