<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . rawurlencode('account.php'));
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../backend/mail_verify.php';

$userId = (string) $_SESSION['user_id'];
$mailConfigured = (getenv('AUBASE_MAIL_FROM') ?: '') !== '';

$errors = [
    'profile'  => '',
    'username' => '',
    'email'    => '',
    'password' => '',
    'delete'   => '',
    'card'     => '',
    'bank'     => '',
];
$saved = $_GET['saved'] ?? '';

function aubase_cc_last4(string $num): string
{
    $d = preg_replace('/\D+/', '', $num) ?: '';
    return strlen($d) >= 4 ? substr($d, -4) : $d;
}

function aubase_load_user(mysqli $conn, string $userId): ?array
{
    $stmt = $conn->prepare(
        'SELECT user_id, username, email, first_name, last_name, address, phone, password_hash,
                rating, created_at, email_verified
         FROM User WHERE user_id = ? AND deleted_at IS NULL LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $userId);
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        return null;
    }
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function aubase_username_valid(string $u): bool
{
    $u = trim($u);
    return strlen($u) >= 2 && strlen($u) <= 80 && (bool) preg_match('/^[a-zA-Z0-9._-]+$/', $u);
}

$user = aubase_load_user($conn, $userId);
if (!$user) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php?redirect=' . rawurlencode('account.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'profile') {
        $fname   = trim((string) ($_POST['first_name'] ?? ''));
        $lname   = trim((string) ($_POST['last_name'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $phone   = trim((string) ($_POST['phone'] ?? ''));
        if ($fname === '') {
            $errors['profile'] = 'First name is required.';
        } else {
            $stmt = $conn->prepare(
                'UPDATE User SET first_name = ?, last_name = ?, address = ?, phone = ? WHERE user_id = ? AND deleted_at IS NULL'
            );
            $stmt->bind_param('sssss', $fname, $lname, $address, $phone, $userId);
            try {
                $stmt->execute();
                header('Location: account.php?saved=profile');
                exit;
            } catch (mysqli_sql_exception $e) {
                $errors['profile'] = 'Could not save profile.';
            }
        }
    }

    if ($action === 'username') {
        $newUser = trim((string) ($_POST['new_username'] ?? ''));
        if (!aubase_username_valid($newUser)) {
            $errors['username'] = 'Use 2–80 characters: letters, numbers, dots, underscores, or hyphens.';
        } elseif ($newUser === $user['username']) {
            header('Location: account.php?saved=username');
            exit;
        } else {
            $chk = $conn->prepare('SELECT 1 FROM User WHERE username = ? AND user_id != ? LIMIT 1');
            $chk->bind_param('ss', $newUser, $userId);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $errors['username'] = 'That username is already taken.';
            } else {
                $stmt = $conn->prepare('UPDATE User SET username = ? WHERE user_id = ? AND deleted_at IS NULL');
                $stmt->bind_param('ss', $newUser, $userId);
                try {
                    $stmt->execute();
                    $_SESSION['username'] = $newUser;
                    header('Location: account.php?saved=username');
                    exit;
                } catch (mysqli_sql_exception $e) {
                    $errors['username'] = 'Could not update username.';
                }
            }
        }
    }

    if ($action === 'email') {
        $newEmail = trim((string) ($_POST['new_email'] ?? ''));
        $curPw    = (string) ($_POST['current_password_email'] ?? '');
        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        } elseif ($user['password_hash'] === null || $user['password_hash'] === '' || !password_verify($curPw, $user['password_hash'])) {
            $errors['email'] = 'Current password is incorrect.';
        } elseif (strcasecmp($newEmail, (string) $user['email']) === 0) {
            header('Location: account.php?saved=email');
            exit;
        } else {
            $dup = $conn->prepare('SELECT 1 FROM User WHERE email = ? AND user_id != ? LIMIT 1');
            $dup->bind_param('ss', $newEmail, $userId);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $errors['email'] = 'That email is already used by another account.';
            } else {
                if ($mailConfigured) {
                    $verifyToken = bin2hex(random_bytes(32));
                    $verifyExpires = date('Y-m-d H:i:s', strtotime('+48 hours'));
                    $stmt = $conn->prepare(
                        'UPDATE User SET email = ?, email_verified = 0, verify_token = ?, verify_expires = ?,
                         password_reset_token = NULL, password_reset_expires = NULL
                         WHERE user_id = ? AND deleted_at IS NULL'
                    );
                    $stmt->bind_param('ssss', $newEmail, $verifyToken, $verifyExpires, $userId);
                    try {
                        $stmt->execute();
                        $fname = (string) ($user['first_name'] ?? '');
                        aubase_send_verification_email($newEmail, $fname, $verifyToken);
                        header('Location: account.php?saved=email_verify');
                        exit;
                    } catch (mysqli_sql_exception $e) {
                        $dupErr = $e->getCode() === 1062 || str_contains($e->getMessage(), 'Duplicate entry');
                        $errors['email'] = $dupErr
                            ? 'That email is already used by another account.'
                            : 'Could not update email.';
                    }
                } else {
                    $stmt = $conn->prepare(
                        'UPDATE User SET email = ?, email_verified = 1, verify_token = NULL, verify_expires = NULL
                         WHERE user_id = ? AND deleted_at IS NULL'
                    );
                    $stmt->bind_param('ss', $newEmail, $userId);
                    try {
                        $stmt->execute();
                        header('Location: account.php?saved=email');
                        exit;
                    } catch (mysqli_sql_exception $e) {
                        $dupErr = $e->getCode() === 1062 || str_contains($e->getMessage(), 'Duplicate entry');
                        $errors['email'] = $dupErr
                            ? 'That email is already used by another account.'
                            : 'Could not update email.';
                    }
                }
            }
        }
    }

    if ($action === 'password') {
        $curPw = (string) ($_POST['current_password'] ?? '');
        $newPw = (string) ($_POST['new_password'] ?? '');
        $conf  = (string) ($_POST['confirm_password'] ?? '');
        if ($user['password_hash'] === null || $user['password_hash'] === '') {
            $errors['password'] = 'This account has no password on file; use Forgot password after setting an email, or contact support.';
        } elseif (!password_verify($curPw, $user['password_hash'])) {
            $errors['password'] = 'Current password is incorrect.';
        } elseif (strlen($newPw) < 6) {
            $errors['password'] = 'New password must be at least 6 characters.';
        } elseif ($newPw !== $conf) {
            $errors['password'] = 'New passwords do not match.';
        } else {
            $hash = password_hash($newPw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                'UPDATE User SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL
                 WHERE user_id = ? AND deleted_at IS NULL'
            );
            $stmt->bind_param('ss', $hash, $userId);
            try {
                $stmt->execute();
                header('Location: account.php?saved=password');
                exit;
            } catch (mysqli_sql_exception $e) {
                $errors['password'] = 'Could not update password.';
            }
        }
    }

    if ($action === 'delete') {
        $curPw        = (string) ($_POST['delete_password'] ?? '');
        $confirmPhrase = trim((string) ($_POST['delete_confirm'] ?? ''));
        if ($user['password_hash'] === null || $user['password_hash'] === '') {
            $errors['delete'] = 'Accounts without a password cannot be closed here. Contact support.';
        } elseif (!password_verify($curPw, $user['password_hash'])) {
            $errors['delete'] = 'Password is incorrect.';
        } elseif ($confirmPhrase !== 'DELETE') {
            $errors['delete'] = 'Type DELETE in the confirmation box to close your account.';
        } else {
            $anonUser = 'deleted_' . $userId;
            if (strlen($anonUser) > 100) {
                $anonUser = substr($anonUser, 0, 100);
            }
            $stmt = $conn->prepare(
                'UPDATE User SET
                    deleted_at = NOW(),
                    password_hash = NULL,
                    verify_token = NULL,
                    verify_expires = NULL,
                    password_reset_token = NULL,
                    password_reset_expires = NULL,
                    email = NULL,
                    username = ?,
                    first_name = NULL,
                    last_name = NULL,
                    address = NULL,
                    phone = NULL,
                    email_verified = 0
                 WHERE user_id = ? AND deleted_at IS NULL'
            );
            $stmt->bind_param('ss', $anonUser, $userId);
            try {
                $stmt->execute();
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $p = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
                }
                session_destroy();
                header('Location: index.php?account_closed=1');
                exit;
            } catch (mysqli_sql_exception $e) {
                $errors['delete'] = 'Could not close account. Try again or contact support.';
            }
        }
    }

    if ($action === 'card_save') {
        $cardNumber = trim((string) ($_POST['card_number'] ?? ''));
        $expRaw     = trim((string) ($_POST['expiration_date'] ?? ''));
        $ccv        = trim((string) ($_POST['ccv'] ?? ''));
        $holder     = trim((string) ($_POST['cardholder_name'] ?? ''));
        $billing    = trim((string) ($_POST['billing_address'] ?? ''));

        $digits = preg_replace('/\D+/', '', $cardNumber) ?: '';
        if (strlen($digits) < 12 || strlen($digits) > 19) {
            $errors['card'] = 'Card number looks invalid.';
        } elseif (!preg_match('/^\d{3,4}$/', $ccv)) {
            $errors['card'] = 'CCV must be 3–4 digits.';
        } elseif ($holder === '' || strlen($holder) > 100) {
            $errors['card'] = 'Cardholder name is required.';
        } elseif ($billing === '' || strlen($billing) > 255) {
            $errors['card'] = 'Billing address is required.';
        } elseif (!preg_match('/^\d{4}-\d{2}$/', $expRaw)) {
            $errors['card'] = 'Expiration must be in YYYY-MM format.';
        } else {
            $expStart = strtotime($expRaw . '-01');
            if ($expStart === false) {
                $errors['card'] = 'Expiration date is invalid.';
            } else {
                $expEnd = date('Y-m-t', $expStart);
                if (strtotime($expEnd) < strtotime(date('Y-m-d'))) {
                    $errors['card'] = 'That card is expired.';
                }
            }
        }

        if ($errors['card'] === '') {
            $expEnd = date('Y-m-t', strtotime($expRaw . '-01'));

            // Keep exactly one card on file per user for the assignment constraint.
            $stmt = $conn->prepare('SELECT card_id FROM CreditCard WHERE user_id = ? ORDER BY card_id DESC LIMIT 1');
            $stmt->bind_param('s', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            if ($row) {
                $cardId = (int) $row['card_id'];
                $upd = $conn->prepare(
                    'UPDATE CreditCard
                        SET card_number = ?, expiration_date = ?, ccv = ?, cardholder_name = ?, billing_address = ?
                      WHERE card_id = ? AND user_id = ?'
                );
                $upd->bind_param('sssssis', $digits, $expEnd, $ccv, $holder, $billing, $cardId, $userId);
                try {
                    $upd->execute();
                    header('Location: account.php?saved=card');
                    exit;
                } catch (mysqli_sql_exception $e) {
                    $errors['card'] = 'Could not save card.';
                }
            } else {
                $ins = $conn->prepare(
                    'INSERT INTO CreditCard (user_id, card_number, expiration_date, ccv, cardholder_name, billing_address)
                     VALUES (?,?,?,?,?,?)'
                );
                $ins->bind_param('ssssss', $userId, $digits, $expEnd, $ccv, $holder, $billing);
                try {
                    $ins->execute();
                    header('Location: account.php?saved=card');
                    exit;
                } catch (mysqli_sql_exception $e) {
                    $errors['card'] = 'Could not save card.';
                }
            }
        }
    }

    if ($action === 'card_delete') {
        $del = $conn->prepare('DELETE FROM CreditCard WHERE user_id = ?');
        $del->bind_param('s', $userId);
        try {
            $del->execute();
            header('Location: account.php?saved=card_deleted');
            exit;
        } catch (mysqli_sql_exception $e) {
            $errors['card'] = 'Could not remove card.';
        }
    }

    if ($action === 'bank_save') {
        $bankName = trim((string) ($_POST['bank_name'] ?? ''));
        $routing  = trim((string) ($_POST['routing_number'] ?? ''));
        $account  = trim((string) ($_POST['account_number'] ?? ''));

        $routingDigits = preg_replace('/\D+/', '', $routing) ?: '';
        $acctDigits    = preg_replace('/\D+/', '', $account) ?: '';

        if ($bankName === '' || strlen($bankName) > 100) {
            $errors['bank'] = 'Bank name is required.';
        } elseif (strlen($routingDigits) < 5 || strlen($routingDigits) > 20) {
            $errors['bank'] = 'Routing number looks invalid.';
        } elseif (strlen($acctDigits) < 5 || strlen($acctDigits) > 20) {
            $errors['bank'] = 'Account number looks invalid.';
        } else {
            // Upsert: BankInfo.seller_id is UNIQUE
            $stmt = $conn->prepare('SELECT bank_id FROM BankInfo WHERE seller_id = ? LIMIT 1');
            $stmt->bind_param('s', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            if ($row) {
                $bankId = (int) $row['bank_id'];
                $upd = $conn->prepare('UPDATE BankInfo SET bank_name = ?, routing_number = ?, account_number = ? WHERE bank_id = ? AND seller_id = ?');
                $upd->bind_param('sssds', $bankName, $routingDigits, $acctDigits, $bankId, $userId);
                try {
                    $upd->execute();
                    header('Location: account.php?saved=bank');
                    exit;
                } catch (mysqli_sql_exception $e) {
                    $errors['bank'] = 'Could not save bank information.';
                }
            } else {
                $ins = $conn->prepare('INSERT INTO BankInfo (seller_id, bank_name, routing_number, account_number) VALUES (?,?,?,?)');
                $ins->bind_param('ssss', $userId, $bankName, $routingDigits, $acctDigits);
                try {
                    $ins->execute();
                    header('Location: account.php?saved=bank');
                    exit;
                } catch (mysqli_sql_exception $e) {
                    $errors['bank'] = 'Could not save bank information.';
                }
            }
        }
    }
}

$user = aubase_load_user($conn, $userId) ?? $user;
$name = (string) ($_SESSION['username'] ?? $user['username'] ?? 'Member');

$cardRow = null;
$stmtCard = $conn->prepare('SELECT card_id, card_number, expiration_date, cardholder_name, billing_address FROM CreditCard WHERE user_id = ? ORDER BY card_id DESC LIMIT 1');
$stmtCard->bind_param('s', $userId);
$stmtCard->execute();
$cardRow = $stmtCard->get_result()->fetch_assoc() ?: null;

$bankRow = null;
$stmtBank = $conn->prepare('SELECT bank_name, routing_number, account_number FROM BankInfo WHERE seller_id = ? LIMIT 1');
$stmtBank->bind_param('s', $userId);
$stmtBank->execute();
$bankRow = $stmtBank->get_result()->fetch_assoc() ?: null;

$memberSince = '';
if (!empty($user['created_at'])) {
    $memberSince = date('F j, Y', strtotime((string) $user['created_at']));
} else {
    $memberSince = 'Not available';
}

$notice = '';
if ($saved === 'profile') {
    $notice = 'Profile saved.';
} elseif ($saved === 'username') {
    $notice = 'Username updated.';
} elseif ($saved === 'email') {
    $notice = 'Email updated.';
} elseif ($saved === 'email_verify') {
    $notice = 'Email updated. We sent a verification link to your new address—use it before you sign in again.';
} elseif ($saved === 'password') {
    $notice = 'Password updated.';
} elseif ($saved === 'card') {
    $notice = 'Payment method saved.';
} elseif ($saved === 'card_deleted') {
    $notice = 'Payment method removed.';
} elseif ($saved === 'bank') {
    $notice = 'Bank information saved.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account settings — AuBase</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        :root{
            --ink:#0f172a;--ink-3:#334155;--mid:#64748b;--muted:#94a3b8;--border:#e2e8f0;--border-2:#cbd5e1;
            --bg:#f8fafc;--bg-2:#f1f5f9;--white:#fff;--gold:#f59e0b;--gold-dark:#d97706;--gold-light:#fffbeb;
            --navy:#0284c7;--navy-2:#0ea5e9;--sky-wash:#e0f2fe;--sky-mist:#f0f9ff;
            --green:#10b981;--green-bg:#d1fae5;--red:#ef4444;--red-bg:#fee2e2;
            --radius:14px;--radius-sm:8px;--shadow-sm:0 1px 3px rgba(14,165,233,0.08);
            --shadow:0 4px 24px rgba(14,165,233,0.08);--edge:clamp(16px,4vw,48px);--content-max:640px;
        }
        *{margin:0;padding:0;box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{font-family:'DM Sans',system-ui,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;-webkit-font-smoothing:antialiased;line-height:1.5}
        a{text-decoration:none;color:inherit}
        .topbar{background:linear-gradient(90deg,var(--sky-wash) 0%,var(--sky-mist) 100%);padding:8px var(--edge);display:flex;justify-content:space-between;align-items:center;font-size:12px;border-bottom:1px solid var(--border)}
        .topbar a{color:var(--mid);transition:color .15s}.topbar a:hover{color:var(--navy)}
        .topbar .hi{color:var(--gold-dark);font-weight:700}
        .topbar-right{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
        header{background:var(--white);border-bottom:1px solid var(--border);padding:0 var(--edge);display:flex;justify-content:space-between;align-items:center;gap:16px;position:sticky;top:0;z-index:60;box-shadow:var(--shadow-sm);min-height:64px;flex-wrap:wrap}
        .logo{font-family:'DM Serif Display',serif;font-size:26px;letter-spacing:-1px;line-height:1}
        .logo span:nth-child(1){color:var(--red)}.logo span:nth-child(2){color:var(--gold)}.logo span:nth-child(3){color:var(--navy)}.logo span:nth-child(4){color:var(--ink-3)}
        .nav-links{display:flex;gap:4px;align-items:center;flex-wrap:wrap}
        .nav-links a{font-size:13px;font-weight:500;color:var(--mid);padding:8px 14px;border-radius:999px;transition:background .15s,color .15s}
        .nav-links a:hover{background:var(--bg-2);color:var(--navy)}
        .nav-links a.active{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;box-shadow:0 2px 10px rgba(2,132,199,0.28)}
        .nav-links a.danger{color:var(--red)}.nav-links a.danger:hover{background:var(--red);color:#fff}
        .page-hero{background:linear-gradient(145deg,var(--sky-wash) 0%,var(--sky-mist) 42%,#fff 100%);border-bottom:1px solid var(--border);padding:clamp(28px,5vw,44px) var(--edge);position:relative;overflow:hidden}
        .page-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 60% 80% at 100% 0%,rgba(14,165,233,0.1) 0%,transparent 55%);pointer-events:none}
        .page-hero-inner{position:relative;max-width:min(var(--content-max),100%);margin:0 auto}
        .eyebrow{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--gold-dark);background:var(--gold-light);border:1px solid #fde68a;padding:4px 12px;border-radius:999px;margin-bottom:12px}
        .page-hero h1{font-family:'DM Serif Display',serif;font-size:clamp(26px,2.5vw+1rem,34px);font-weight:400;letter-spacing:-.5px;line-height:1.15;margin-bottom:8px;color:var(--ink)}
        .page-hero .lead{color:var(--mid);font-size:15px;max-width:36ch;line-height:1.55}
        .acct-shell{max-width:min(var(--content-max),100%);margin:0 auto;padding:0 var(--edge) 56px}
        .acct-tabs{position:sticky;top:64px;z-index:50;display:flex;gap:8px;flex-wrap:wrap;padding:16px 0 4px;margin:0 calc(-1 * var(--edge));padding-left:var(--edge);padding-right:var(--edge);background:linear-gradient(180deg,var(--bg) 88%,transparent);border-bottom:1px solid var(--border);margin-bottom:8px}
        .acct-tabs a{font-size:13px;font-weight:500;color:var(--mid);padding:8px 14px;border-radius:999px;background:var(--white);border:1px solid var(--border);transition:all .15s;white-space:nowrap}
        .acct-tabs a:hover{border-color:var(--navy-2);color:var(--navy);background:var(--sky-mist)}
        .notice{display:flex;align-items:flex-start;gap:10px;background:var(--green-bg);border:1px solid #a7f3d0;color:#047857;border-radius:var(--radius-sm);padding:14px 16px;font-size:14px;margin:20px 0 8px;line-height:1.45}
        .notice::before{content:'✓';font-weight:800;flex-shrink:0}
        .sec{scroll-margin-top:108px;margin-top:28px}
        .card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden}
        .card-h{padding:18px 22px 14px;border-bottom:1px solid var(--border);background:linear-gradient(180deg,#fff 0%,var(--bg) 100%)}
        .card-h h2{font-family:'DM Serif Display',serif;font-size:18px;font-weight:400;color:var(--ink);letter-spacing:-.3px;margin-bottom:4px}
        .card-h p{font-size:13px;color:var(--muted);line-height:1.5;max-width:52ch}
        .card-b{padding:22px}
        .card-danger{border-color:#fecaca;box-shadow:0 2px 12px rgba(239,68,68,0.06)}
        .card-danger .card-h{background:linear-gradient(180deg,#fff 0%,var(--red-bg) 100%);border-bottom-color:#fecaca}
        .card-danger .card-h h2{color:#b91c1c}
        .err{background:var(--red-bg);border:1px solid #fecaca;color:#991b1b;border-radius:var(--radius-sm);padding:12px 14px;font-size:13px;margin-bottom:16px;line-height:1.45}
        label{display:block;font-size:12.5px;font-weight:600;color:var(--ink-3);margin-bottom:7px;letter-spacing:.01em}
        .field{margin-bottom:18px}
        .field:last-of-type{margin-bottom:20px}
        .field-hint{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.45}
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        @media(max-width:540px){.grid-2{grid-template-columns:1fr}}
        input[type=text],input[type=email],input[type=password]{width:100%;padding:12px 14px;border:1.5px solid var(--border-2);border-radius:var(--radius-sm);font-size:15px;font-family:inherit;background:var(--bg-2);transition:border-color .15s,box-shadow .15s,background .15s}
        input:focus{outline:none;border-color:var(--navy-2);background:var(--white);box-shadow:0 0 0 3px rgba(14,165,233,0.18)}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;border:none;padding:11px 24px;border-radius:999px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(2,132,199,0.32);transition:transform .15s,box-shadow .15s}
        .btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(2,132,199,0.4)}
        .btn-danger{background:linear-gradient(135deg,#dc2626 0%,var(--red) 100%);box-shadow:0 4px 14px rgba(239,68,68,0.3)}
        .stats-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
        @media(min-width:540px){.stats-grid{grid-template-columns:repeat(4,1fr)}}
        @media(max-width:400px){.stats-grid{grid-template-columns:1fr}}
        .stat-box{background:var(--bg-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 16px}
        .stat-box dt{font-size:10.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:6px}
        .stat-box dd{font-size:14px;font-weight:600;color:var(--ink);line-height:1.35;word-break:break-word}
        .id-mono{font-size:11.5px;font-weight:500;color:var(--ink-3);font-family:ui-monospace,monospace}
        .badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:999px;margin-top:8px}
        .badge-ok{background:var(--green-bg);color:#047857;border:1px solid #a7f3d0}
        .badge-warn{background:var(--gold-light);color:var(--gold-dark);border:1px solid #fde68a}
        .identity-row{display:flex;flex-wrap:wrap;gap:20px 28px;padding-top:4px;border-top:1px solid var(--border);margin-top:18px;padding-top:18px}
        .identity-item{min-width:140px}
        .identity-item dt{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;font-weight:600;margin-bottom:4px}
        .identity-item dd{font-size:14px;font-weight:600;color:var(--ink);word-break:break-word}
        .empty-hint{font-size:13px;color:var(--muted);line-height:1.55}
    </style>
</head>
<body>

<div class="topbar">
    <span class="hi">Hi, <?= htmlspecialchars($name) ?></span>
    <div class="topbar-right">
        <a href="index.php">Browse</a>
        <a href="dashboard.php">Dashboard</a>
        <a href="logout.php">Sign out</a>
    </div>
</div>

<header>
    <a href="index.php" class="logo"><span>A</span><span>u</span><span>B</span><span>ase</span></a>
    <nav class="nav-links">
        <a href="index.php">Auctions</a>
        <a href="dashboard.php">Dashboard</a>
        <a href="account.php" class="active">Account</a>
        <a href="sell.php">Sell</a>
        <a href="logout.php" class="danger">Sign out</a>
    </nav>
</header>

<div class="page-hero">
    <div class="page-hero-inner">
        <span class="eyebrow">Your account</span>
        <h1>Settings</h1>
        <p class="lead">Keep your profile and sign-in details up to date—same idea as eBay’s Account Settings.</p>
    </div>
</div>

<div class="acct-shell">
    <nav class="acct-tabs" aria-label="Jump to section">
        <a href="#overview">Overview</a>
        <a href="#profile">Profile</a>
        <a href="#username">Username</a>
        <a href="#email">Email</a>
        <a href="#password">Password</a>
        <a href="#payment">Payment</a>
        <a href="#payout">Payout</a>
        <a href="#close-account">Close account</a>
    </nav>

    <?php if ($notice !== ''): ?>
        <div class="notice"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>

    <section class="sec" id="overview">
        <div class="card">
            <div class="card-h">
                <h2>Overview</h2>
                <p>Snapshot of your membership. Ratings reflect feedback from other members.</p>
            </div>
            <div class="card-b">
                <dl class="stats-grid">
                    <div class="stat-box">
                        <dt>Member since</dt>
                        <dd><?= htmlspecialchars($memberSince) ?></dd>
                    </div>
                    <div class="stat-box">
                        <dt>Rating</dt>
                        <dd><?= (int) ($user['rating'] ?? 0) ?></dd>
                    </div>
                    <div class="stat-box">
                        <dt>Member ID</dt>
                        <dd class="id-mono"><?= htmlspecialchars($user['user_id']) ?></dd>
                    </div>
                    <div class="stat-box">
                        <dt>Verification</dt>
                        <dd>
                            <?php if ((int) ($user['email_verified'] ?? 1)): ?>
                                <span class="badge badge-ok">Email verified</span>
                            <?php else: ?>
                                <span class="badge badge-warn">Email pending</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>
                <dl class="identity-row">
                    <div class="identity-item">
                        <dt>Username</dt>
                        <dd><?= htmlspecialchars((string) ($user['username'] ?? '')) ?></dd>
                    </div>
                    <div class="identity-item">
                        <dt>Email</dt>
                        <dd><?= htmlspecialchars((string) ($user['email'] ?? '—')) ?></dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    <section class="sec" id="profile">
        <div class="card">
            <div class="card-h">
                <h2>Profile</h2>
                <p>Name and contact info used for shipping and account notices.</p>
            </div>
            <div class="card-b">
                <?php if ($errors['profile'] !== ''): ?><div class="err"><?= htmlspecialchars($errors['profile']) ?></div><?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">
                    <div class="grid-2">
                        <div class="field">
                            <label for="first_name">First name</label>
                            <input type="text" id="first_name" name="first_name" required maxlength="50" autocomplete="given-name"
                                   value="<?= htmlspecialchars((string) ($_POST['first_name'] ?? $user['first_name'] ?? '')) ?>">
                        </div>
                        <div class="field">
                            <label for="last_name">Last name</label>
                            <input type="text" id="last_name" name="last_name" maxlength="50" autocomplete="family-name"
                                   value="<?= htmlspecialchars((string) ($_POST['last_name'] ?? $user['last_name'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="field">
                        <label for="address">Street address</label>
                        <input type="text" id="address" name="address" maxlength="255" autocomplete="street-address"
                               value="<?= htmlspecialchars((string) ($_POST['address'] ?? $user['address'] ?? '')) ?>">
                    </div>
                    <div class="field">
                        <label for="phone">Phone <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
                        <input type="text" id="phone" name="phone" maxlength="20" autocomplete="tel"
                               value="<?= htmlspecialchars((string) ($_POST['phone'] ?? $user['phone'] ?? '')) ?>">
                    </div>
                    <button type="submit" class="btn">Save profile</button>
                </form>
            </div>
        </div>
    </section>

    <section class="sec" id="username">
        <div class="card">
            <div class="card-h">
                <h2>Username</h2>
                <p>This is the name shown across AuBase. Letters, numbers, dots, underscores, and hyphens only.</p>
            </div>
            <div class="card-b">
                <?php if ($errors['username'] !== ''): ?><div class="err"><?= htmlspecialchars($errors['username']) ?></div><?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="username">
                    <div class="field">
                        <label for="new_username">Username</label>
                        <input type="text" id="new_username" name="new_username" required maxlength="80" autocomplete="username"
                               value="<?= htmlspecialchars((string) ($_POST['new_username'] ?? $user['username'] ?? '')) ?>"
                               pattern="[a-zA-Z0-9._\-]{2,80}"
                               title="2–80 characters: letters, numbers, . _ -">
                        <p class="field-hint">2–80 characters. You’ll see this name on bids, listings, and your dashboard.</p>
                    </div>
                    <button type="submit" class="btn">Save username</button>
                </form>
            </div>
        </div>
    </section>

    <section class="sec" id="email">
        <div class="card">
            <div class="card-h">
                <h2>Email address</h2>
                <p>We’ll use this for sign-in and important updates. Enter your current password to confirm it’s you.</p>
            </div>
            <div class="card-b">
                <?php if ($errors['email'] !== ''): ?><div class="err"><?= htmlspecialchars($errors['email']) ?></div><?php endif; ?>
                <?php if ($mailConfigured): ?>
                    <p class="field-hint" style="margin-bottom:16px">After a change, we email a verification link to the <strong>new</strong> address. You may need to verify before signing in again.</p>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="email">
                    <div class="field">
                        <label for="new_email">Email</label>
                        <input type="email" id="new_email" name="new_email" required maxlength="100" autocomplete="email"
                               value="<?= htmlspecialchars((string) ($_POST['new_email'] ?? $user['email'] ?? '')) ?>">
                    </div>
                    <div class="field">
                        <label for="current_password_email">Current password</label>
                        <input type="password" id="current_password_email" name="current_password_email" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn">Update email</button>
                </form>
            </div>
        </div>
    </section>

    <section class="sec" id="password">
        <div class="card">
            <div class="card-h">
                <h2>Password</h2>
                <p>Choose a strong password you don’t use on other sites.</p>
            </div>
            <div class="card-b">
                <?php if ($errors['password'] !== ''): ?><div class="err"><?= htmlspecialchars($errors['password']) ?></div><?php endif; ?>
                <?php if ($user['password_hash'] === null || $user['password_hash'] === ''): ?>
                    <p class="empty-hint">This account doesn’t have a password (for example, a legacy imported seller). Use <a href="forgot_password.php" style="color:var(--navy);font-weight:600">Forgot password</a> if you’ve added an email, or contact support.</p>
                <?php else: ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">
                    <div class="field">
                        <label for="current_password">Current password</label>
                        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="field">
                        <label for="new_password">New password</label>
                        <input type="password" id="new_password" name="new_password" required minlength="6" autocomplete="new-password">
                        <p class="field-hint">At least 6 characters.</p>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm new password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn">Update password</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="sec" id="payment">
        <div class="card">
            <div class="card-h">
                <h2>Payment</h2>
                <p>To place bids, AuctionBase requires a valid credit card on file.</p>
            </div>
            <div class="card-b">
                <?php if ($errors['card'] !== ''): ?><div class="err"><?= htmlspecialchars($errors['card']) ?></div><?php endif; ?>

                <?php if ($cardRow): ?>
                    <p class="field-hint" style="margin-bottom:14px">
                        Card on file: <strong>•••• <?= htmlspecialchars(aubase_cc_last4((string) $cardRow['card_number'])) ?></strong>
                        · Expires <strong><?= htmlspecialchars(date('M Y', strtotime((string) $cardRow['expiration_date']))) ?></strong>
                    </p>
                <?php else: ?>
                    <p class="field-hint" style="margin-bottom:14px">No credit card on file yet.</p>
                <?php endif; ?>

                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="card_save">
                    <div class="grid-2">
                        <div class="field">
                            <label for="card_number">Card number</label>
                            <input type="text" id="card_number" name="card_number" required maxlength="23" autocomplete="cc-number"
                                   value="<?= htmlspecialchars((string) ($_POST['card_number'] ?? ($cardRow['card_number'] ?? ''))) ?>">
                            <p class="field-hint">Digits only; spaces are OK.</p>
                        </div>
                        <div class="field">
                            <label for="expiration_date">Expiration (YYYY-MM)</label>
                            <input type="text" id="expiration_date" name="expiration_date" required maxlength="7" autocomplete="cc-exp"
                                   placeholder="2028-07"
                                   value="<?= htmlspecialchars((string) ($_POST['expiration_date'] ?? ($cardRow ? date('Y-m', strtotime((string) $cardRow['expiration_date'])) : ''))) ?>">
                        </div>
                    </div>
                    <div class="grid-2">
                        <div class="field">
                            <label for="ccv">CCV</label>
                            <input type="text" id="ccv" name="ccv" required maxlength="4" autocomplete="cc-csc"
                                   value="<?= htmlspecialchars((string) ($_POST['ccv'] ?? '')) ?>">
                        </div>
                        <div class="field">
                            <label for="cardholder_name">Cardholder name</label>
                            <input type="text" id="cardholder_name" name="cardholder_name" required maxlength="100" autocomplete="cc-name"
                                   value="<?= htmlspecialchars((string) ($_POST['cardholder_name'] ?? ($cardRow['cardholder_name'] ?? ''))) ?>">
                        </div>
                    </div>
                    <div class="field">
                        <label for="billing_address">Billing address</label>
                        <input type="text" id="billing_address" name="billing_address" required maxlength="255" autocomplete="billing street-address"
                               value="<?= htmlspecialchars((string) ($_POST['billing_address'] ?? ($cardRow['billing_address'] ?? ''))) ?>">
                    </div>
                    <button type="submit" class="btn">Save card</button>
                </form>

                <?php if ($cardRow): ?>
                    <form method="post" style="margin-top:12px" onsubmit="return confirm('Remove this card? You will not be able to bid until you add another one.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="card_delete">
                        <button type="submit" class="btn btn-danger">Remove card</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="sec" id="payout">
        <div class="card">
            <div class="card-h">
                <h2>Payout (seller bank info)</h2>
                <p>When you sell an item, AuctionBase records bank info for releasing payment after delivery.</p>
            </div>
            <div class="card-b">
                <?php if ($errors['bank'] !== ''): ?><div class="err"><?= htmlspecialchars($errors['bank']) ?></div><?php endif; ?>
                <?php if ($bankRow): ?>
                    <p class="field-hint" style="margin-bottom:14px">
                        Bank on file: <strong><?= htmlspecialchars((string) $bankRow['bank_name']) ?></strong>
                        · Routing <strong>•••• <?= htmlspecialchars(substr((string) $bankRow['routing_number'], -4)) ?></strong>
                        · Account <strong>•••• <?= htmlspecialchars(substr((string) $bankRow['account_number'], -4)) ?></strong>
                    </p>
                <?php else: ?>
                    <p class="field-hint" style="margin-bottom:14px">No bank info on file yet.</p>
                <?php endif; ?>

                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="bank_save">
                    <div class="grid-2">
                        <div class="field">
                            <label for="bank_name">Bank name</label>
                            <input type="text" id="bank_name" name="bank_name" required maxlength="100"
                                   value="<?= htmlspecialchars((string) ($_POST['bank_name'] ?? ($bankRow['bank_name'] ?? ''))) ?>">
                        </div>
                        <div class="field">
                            <label for="routing_number">Routing number</label>
                            <input type="text" id="routing_number" name="routing_number" required maxlength="20"
                                   value="<?= htmlspecialchars((string) ($_POST['routing_number'] ?? ($bankRow['routing_number'] ?? ''))) ?>">
                        </div>
                    </div>
                    <div class="field">
                        <label for="account_number">Account number</label>
                        <input type="text" id="account_number" name="account_number" required maxlength="20"
                               value="<?= htmlspecialchars((string) ($_POST['account_number'] ?? ($bankRow['account_number'] ?? ''))) ?>">
                    </div>
                    <button type="submit" class="btn">Save bank info</button>
                </form>
            </div>
        </div>
    </section>

    <section class="sec" id="close-account">
        <div class="card card-danger">
            <div class="card-h">
                <h2>Close account</h2>
                <p>Permanently close this account. You’ll be signed out and we’ll remove personal details from your profile.</p>
            </div>
            <div class="card-b">
                <?php if ($errors['delete'] !== ''): ?><div class="err"><?= htmlspecialchars($errors['delete']) ?></div><?php endif; ?>
                <p class="field-hint" style="margin-bottom:18px">Auction history may still show an anonymized seller or bidder ID so past listings stay consistent.</p>
                <?php if ($user['password_hash'] === null || $user['password_hash'] === ''): ?>
                    <p class="empty-hint">Closing the account requires a password. Set one via Forgot password if you can, or contact support.</p>
                <?php else: ?>
                <form method="post" onsubmit="return confirm('Close your account? You won’t be able to sign in again with this profile.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <div class="field">
                        <label for="delete_confirm">Confirmation</label>
                        <input type="text" id="delete_confirm" name="delete_confirm" required autocomplete="off" placeholder="Type DELETE in capitals">
                        <p class="field-hint">Type <strong>DELETE</strong> to confirm you understand this can’t be undone.</p>
                    </div>
                    <div class="field">
                        <label for="delete_password">Current password</label>
                        <input type="password" id="delete_password" name="delete_password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-danger">Close my account</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

</body>
</html>
