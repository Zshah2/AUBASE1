<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$conn = new mysqli(AUBASE_DB_HOST, AUBASE_DB_USER, AUBASE_DB_PASS, AUBASE_DB_NAME, AUBASE_DB_PORT);

if ($conn->connect_error) {
    die('Database connection failed.');
}

$conn->set_charset('utf8mb4');
