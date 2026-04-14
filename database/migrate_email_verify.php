<?php
/**
 * One-time: adds email_verified, verify_token, verify_expires on User if missing.
 *   php database/migrate_email_verify.php
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

if (!column_exists($conn, 'email_verified')) {
    if (!$conn->query('ALTER TABLE User ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1')) {
        fwrite(STDERR, $conn->error . "\n");
        exit(1);
    }
    echo "Added email_verified.\n";
}

if (!column_exists($conn, 'verify_token')) {
    if (!$conn->query('ALTER TABLE User ADD COLUMN verify_token VARCHAR(64) DEFAULT NULL')) {
        fwrite(STDERR, $conn->error . "\n");
        exit(1);
    }
    echo "Added verify_token.\n";
}

if (!column_exists($conn, 'verify_expires')) {
    if (!$conn->query('ALTER TABLE User ADD COLUMN verify_expires DATETIME DEFAULT NULL')) {
        fwrite(STDERR, $conn->error . "\n");
        exit(1);
    }
    echo "Added verify_expires.\n";
}

echo "OK. Email verification columns are ready.\n";
exit(0);
