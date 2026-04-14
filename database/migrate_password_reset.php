<?php
/**
 * One-time: adds password_reset_token, password_reset_expires on User if missing.
 *   php database/migrate_password_reset.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../backend/db.php';

function column_exists(mysqli $conn, string $name): bool
{
    $n = $conn->real_escape_string($name);
    $q = "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'User' AND COLUMN_NAME = '$n'";
    $r = $conn->query($q);
    $row = $r ? $r->fetch_assoc() : null;
    return $row && (int) $row['c'] > 0;
}

if (!column_exists($conn, 'password_reset_token')) {
    if (!$conn->query('ALTER TABLE User ADD COLUMN password_reset_token VARCHAR(64) DEFAULT NULL')) {
        fwrite(STDERR, $conn->error . "\n");
        exit(1);
    }
    echo "Added password_reset_token.\n";
}

if (!column_exists($conn, 'password_reset_expires')) {
    if (!$conn->query('ALTER TABLE User ADD COLUMN password_reset_expires DATETIME DEFAULT NULL')) {
        fwrite(STDERR, $conn->error . "\n");
        exit(1);
    }
    echo "Added password_reset_expires.\n";
}

echo "OK. Password reset columns are ready.\n";
exit(0);
