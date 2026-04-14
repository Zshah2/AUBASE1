<?php
/**
 * One-time: adds User.password_hash if missing. Run from project root:
 *   php database/migrate_password_hash.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

$res = $conn->query(
    "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'User' AND COLUMN_NAME = 'password_hash'"
);
$row = $res ? $res->fetch_assoc() : null;
if ($row && (int) $row['n'] > 0) {
    echo "Column User.password_hash already exists. Nothing to do.\n";
    exit(0);
}

if (!$conn->query('ALTER TABLE User ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER phone')) {
    fwrite(STDERR, 'Migration failed: ' . $conn->error . "\n");
    exit(1);
}

echo "OK: Added column User.password_hash. You can sign up now.\n";
exit(0);
