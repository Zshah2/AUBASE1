<?php
declare(strict_types=1);

/**
 * AuctionBase “system time” (spec requirement): stored in CurrentTime table.
 * The table must contain a single row with id=1.
 */

function aubase_now_ts(mysqli $conn): int
{
    $res = $conn->query("SELECT system_time FROM CurrentTime WHERE id = 1 LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row || !isset($row['system_time'])) {
        return time();
    }
    $ts = strtotime((string) $row['system_time']);
    return $ts !== false ? $ts : time();
}

