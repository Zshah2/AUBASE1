<?php
/**
 * One-time: adds ship-to address columns on `Order` for seller fulfillment.
 *   php database/migrate_order_ship_to.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

function order_column_exists(mysqli $conn, string $name): bool
{
    $n = $conn->real_escape_string($name);
    $q = "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Order' AND COLUMN_NAME = '$n'";
    $r = $conn->query($q);
    $row = $r ? $r->fetch_assoc() : null;
    return $row && (int) $row['c'] > 0;
}

$cols = [
    'ship_to_name' => "VARCHAR(150) DEFAULT NULL",
    'ship_to_line1' => "VARCHAR(255) DEFAULT NULL",
    'ship_to_line2' => "VARCHAR(255) DEFAULT NULL",
    'ship_to_city' => "VARCHAR(100) DEFAULT NULL",
    'ship_to_region' => "VARCHAR(100) DEFAULT NULL",
    'ship_to_postal' => "VARCHAR(32) DEFAULT NULL",
    'ship_to_country' => "VARCHAR(100) DEFAULT NULL",
];

foreach ($cols as $col => $def) {
    if (!order_column_exists($conn, $col)) {
        $sql = "ALTER TABLE `Order` ADD COLUMN `$col` $def";
        if (!$conn->query($sql)) {
            fwrite(STDERR, $conn->error . "\n");
            exit(1);
        }
        echo "Added Order.$col.\n";
    } else {
        echo "OK: Order.$col already exists.\n";
    }
}

echo "Done.\n";
exit(0);
