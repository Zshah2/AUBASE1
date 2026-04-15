<?php
/**
 * One-time: brings schema in line with AuctionBase final requirements:
 * - Item.item_id AUTO_INCREMENT (so sell.php insert works)
 * - User.username UNIQUE
 * - CurrentTime single-row seed is idempotent
 *
 * Run from project root:
 *   php database/migrate_schema_v2.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

function column_is_auto_increment(mysqli $conn, string $table, string $column): bool
{
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $q = "SELECT EXTRA FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND COLUMN_NAME = '$c' LIMIT 1";
    $r = $conn->query($q);
    $row = $r ? $r->fetch_assoc() : null;
    return $row && str_contains((string) ($row['EXTRA'] ?? ''), 'auto_increment');
}

function index_exists(mysqli $conn, string $table, string $index): bool
{
    $t = $conn->real_escape_string($table);
    $i = $conn->real_escape_string($index);
    $q = "SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND INDEX_NAME = '$i' LIMIT 1";
    $r = $conn->query($q);
    return $r && $r->num_rows > 0;
}

// 1) Item.item_id AUTO_INCREMENT
if (!$conn->query("SHOW TABLES LIKE 'Item'")) {
    fwrite(STDERR, "Database error.\n");
    exit(1);
}
$hasItem = $conn->query("SHOW TABLES LIKE 'Item'")->num_rows > 0;
if ($hasItem) {
    if (!column_is_auto_increment($conn, 'Item', 'item_id')) {
        // Must keep it PRIMARY KEY; MySQL allows MODIFY to add AUTO_INCREMENT.
        if (!$conn->query("ALTER TABLE Item MODIFY item_id BIGINT NOT NULL AUTO_INCREMENT")) {
            fwrite(STDERR, "Failed to set Item.item_id AUTO_INCREMENT: " . $conn->error . "\n");
            exit(1);
        }
        echo "OK: Item.item_id is now AUTO_INCREMENT.\n";
    } else {
        echo "OK: Item.item_id already AUTO_INCREMENT.\n";
    }
}

// 2) User.username UNIQUE
$hasUser = $conn->query("SHOW TABLES LIKE 'User'")->num_rows > 0;
if ($hasUser) {
    if (!index_exists($conn, 'User', 'username')) {
        // Before adding UNIQUE, ensure no duplicates (pick deterministic suffix).
        $dup = $conn->query("SELECT username, COUNT(*) c FROM User WHERE username IS NOT NULL AND username != '' GROUP BY username HAVING c > 1");
        if ($dup && $dup->num_rows > 0) {
            while ($row = $dup->fetch_assoc()) {
                $u = (string) $row['username'];
                // Add suffix to all but one row
                $stmt = $conn->prepare("SELECT user_id FROM User WHERE username = ? ORDER BY user_id ASC");
                $stmt->bind_param('s', $u);
                $stmt->execute();
                $ids = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                for ($i = 1; $i < count($ids); $i++) {
                    $uid = (string) $ids[$i]['user_id'];
                    $new = substr($u, 0, 80) . '_' . substr($uid, 0, 8);
                    $upd = $conn->prepare("UPDATE User SET username = ? WHERE user_id = ? LIMIT 1");
                    $upd->bind_param('ss', $new, $uid);
                    $upd->execute();
                }
            }
            echo "Adjusted duplicate usernames to allow UNIQUE index.\n";
        }

        if (!$conn->query("ALTER TABLE User ADD UNIQUE KEY username (username)")) {
            fwrite(STDERR, "Failed to add UNIQUE(User.username): " . $conn->error . "\n");
            exit(1);
        }
        echo "OK: Added UNIQUE constraint on User.username.\n";
    } else {
        echo "OK: User.username already indexed.\n";
    }
}

// 3) Ensure CurrentTime single row exists (idempotent)
$hasCT = $conn->query("SHOW TABLES LIKE 'CurrentTime'")->num_rows > 0;
if ($hasCT) {
    if (!$conn->query("INSERT INTO CurrentTime (id, system_time) VALUES (1, NOW()) ON DUPLICATE KEY UPDATE system_time = system_time")) {
        fwrite(STDERR, "Failed to seed CurrentTime: " . $conn->error . "\n");
        exit(1);
    }
    echo "OK: CurrentTime row ensured.\n";
}

echo "Done.\n";
exit(0);

