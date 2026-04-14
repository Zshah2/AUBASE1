<?php
/**
 * One-time: adds User.created_at, User.deleted_at if missing.
 *   php database/migrate_account_settings.php
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

if (!column_exists($conn, 'created_at')) {
    if (!$conn->query('ALTER TABLE User ADD COLUMN created_at DATETIME NULL')) {
        fwrite(STDERR, $conn->error . "\n");
        exit(1);
    }
    echo "Added created_at.\n";
}

if (!column_exists($conn, 'deleted_at')) {
    if (!$conn->query('ALTER TABLE User ADD COLUMN deleted_at DATETIME NULL')) {
        fwrite(STDERR, $conn->error . "\n");
        exit(1);
    }
    echo "Added deleted_at.\n";
}

echo "OK. Account settings columns are ready.\n";
exit(0);
