<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../backend/mail_verify.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

if (!csrf_verify()) {
    header('Location: login.php?resent=0');
    exit;
}

$email = trim((string) ($_POST['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: login.php?resent=0');
    exit;
}

$from = getenv('AUBASE_MAIL_FROM') ?: '';
if ($from === '') {
    header('Location: login.php?resent=0');
    exit;
}

$stmt = $conn->prepare(
    'SELECT user_id, first_name FROM User WHERE email = ? AND password_hash IS NOT NULL AND email_verified = 0 LIMIT 1'
);
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($user) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
    $upd = $conn->prepare('UPDATE User SET verify_token = ?, verify_expires = ? WHERE user_id = ?');
    $upd->bind_param('sss', $token, $expires, $user['user_id']);
    $upd->execute();
    $name = (string) ($user['first_name'] ?? '');
    aubase_send_verification_email($email, $name, $token);
}

// Same message whether or not the email exists (privacy).
header('Location: login.php?resent=1');
exit;
