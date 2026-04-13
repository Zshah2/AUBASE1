    <?php
declare(strict_types=1);

require_once __DIR__ . '/backend/db.php';

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
if (strlen($token) !== 64 || !ctype_xdigit($token)) {
    header('Location: login.php?invalid_token=1');
    exit;
}

$stmt = $conn->prepare(
    'SELECT user_id FROM User WHERE verify_token = ? AND verify_expires > NOW() AND email_verified = 0 LIMIT 1'
);
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    header('Location: login.php?invalid_token=1');
    exit;
}

$uid = $row['user_id'];
$clear = $conn->prepare(
    'UPDATE User SET email_verified = 1, verify_token = NULL, verify_expires = NULL WHERE user_id = ?'
);
$clear->bind_param('s', $uid);
$clear->execute();

header('Location: login.php?verified=1');
exit;
