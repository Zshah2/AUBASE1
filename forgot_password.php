<?php
declare(strict_types=1);

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/csrf.php';
require_once __DIR__ . '/backend/mail_verify.php';

$error = '';
$mailConfigured = (getenv('AUBASE_MAIL_FROM') ?: '') !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid session. Please refresh the page and try again.';
    } elseif (!$mailConfigured) {
        $error = 'Password reset by email is not enabled (AUBASE_MAIL_FROM is not set). Ask the site administrator or configure mail in .env.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $stmt = $conn->prepare(
                'SELECT user_id, first_name FROM User WHERE email = ? AND password_hash IS NOT NULL LIMIT 1'
            );
            $stmt->bind_param('s', $email);
            try {
                $stmt->execute();
            } catch (mysqli_sql_exception $e) {
                $error = 'Could not process request. If this persists, run: php migrate_password_reset.php';
                $stmt = null;
            }
            $row = ($stmt !== null) ? $stmt->get_result()->fetch_assoc() : null;

            if ($row) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $uid = (string) $row['user_id'];
                $upd = $conn->prepare(
                    'UPDATE User SET password_reset_token = ?, password_reset_expires = ? WHERE user_id = ?'
                );
                $upd->bind_param('sss', $token, $expires, $uid);
                try {
                    $upd->execute();
                } catch (mysqli_sql_exception $e) {
                    $error = 'Database is missing password reset columns. Run: php migrate_password_reset.php';
                    $upd = null;
                }
                if ($upd !== null) {
                    $name = (string) ($row['first_name'] ?? '');
                    if (!aubase_send_password_reset_email($email, $name, $token)) {
                        $error = 'Could not send email. Check AUBASE_MAIL_FROM and your server mail settings, then try again.';
                    } else {
                        header('Location: forgot_password.php?sent=1');
                        exit;
                    }
                }
            } else {
                header('Location: forgot_password.php?sent=1');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AuBase — Forgot password</title>
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --blue: #0ea5e9; --blue-h: #0284c7; --red: #ef4444; --gray: #64748b;
            --light: #f0f9ff; --border: #e2e8f0; --gutter: clamp(14px, 4vw, 40px);
        }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(165deg, #e0f2fe 0%, #f8fafc 35%, #fff 100%);
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .topbar {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px var(--gutter); border-bottom: 1px solid var(--border);
            background: rgba(255,255,255,0.75); backdrop-filter: blur(8px);
        }
        .logo { font-size: clamp(24px, 2vw + 1.1rem, 30px); font-weight: 800; letter-spacing: -1px; text-decoration: none; }
        .logo .l1 { color: var(--red); } .logo .l2 { color: var(--blue); } .logo .l3 { color: #f59e0b; } .logo .l4 { color: #10b981; }
        .page {
            flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: clamp(36px, 8vw, 64px) var(--gutter);
            max-width: 440px; margin: 0 auto; width: 100%;
        }
        h1 { font-size: clamp(22px, 2.5vw + 1rem, 28px); font-weight: 700; margin-bottom: 10px; text-align: center; }
        .sub { font-size: 14px; color: var(--gray); text-align: center; margin-bottom: 22px; line-height: 1.45; }
        .alert {
            background: #fff3f3; border: 1px solid #fecaca; color: #b91c1c;
            border-radius: 8px; padding: 12px 14px; font-size: 13px; margin-bottom: 14px; width: 100%;
        }
        .alert-success {
            background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46;
        }
        .alert-muted {
            background: #f8fafc; border: 1px solid var(--border); color: var(--gray); font-size: 13px;
        }
        .form-group { margin-bottom: 12px; width: 100%; }
        input[type="email"] {
            width: 100%; padding: 14px 16px; border: 1.5px solid var(--border); border-radius: 8px;
            font-size: 15px; background: var(--light); outline: none;
        }
        input:focus {
            border-color: var(--blue); background: #fff;
            box-shadow: 0 0 0 3px rgba(14,165,233,.22);
        }
        .btn-primary {
            width: 100%; padding: 15px; margin-top: 6px;
            background: linear-gradient(135deg, var(--blue-h) 0%, var(--blue) 100%);
            color: #fff; border: none; border-radius: 25px; font-size: 16px; font-weight: 700;
            cursor: pointer; box-shadow: 0 4px 16px rgba(2, 132, 199, 0.35);
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(2, 132, 199, 0.45); }
        .back { text-align: center; margin-top: 20px; font-size: 14px; }
        .back a { color: var(--blue-h); font-weight: 600; }
        footer { text-align: center; padding: 20px; font-size: 12px; color: var(--gray); border-top: 1px solid var(--border); }
    </style>
</head>
<body>

<div class="topbar">
    <a href="index.php" class="logo"><span class="l1">A</span><span class="l2">u</span><span class="l3">B</span><span class="l4">ase</span></a>
    <a href="login.php" style="font-size:14px;color:var(--gray);">Sign in</a>
</div>

<div class="page">
    <h1>Forgot password</h1>
    <p class="sub">Enter the email on your account. If it exists, we’ll send a reset link (expires in one hour).</p>

    <?php if (!$mailConfigured && !isset($_GET['sent'])): ?>
        <div class="alert alert-muted" style="margin-bottom:18px">
            Outbound email is not configured (<code>AUBASE_MAIL_FROM</code>). Password reset cannot send mail until that is set in <code>.env</code>, along with <code>AUBASE_BASE_URL</code>.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['sent'])): ?>
        <div class="alert alert-success" style="width:100%">
            If an account exists for that email, we sent a reset link. Check your inbox and spam folder.
        </div>
        <p class="back"><a href="login.php">← Back to sign in</a></p>
    <?php else: ?>
        <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST" style="width:100%">
            <?= csrf_field() ?>
            <div class="form-group">
                <input type="email" name="email" required placeholder="Email address" autocomplete="email"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <button type="submit" class="btn-primary" <?= $mailConfigured ? '' : 'disabled style="opacity:0.5;cursor:not-allowed"' ?>>Send reset link</button>
        </form>
        <p class="back"><a href="login.php">← Back to sign in</a></p>
    <?php endif; ?>
</div>

<footer>&copy; <?= date('Y') ?> AuBase</footer>
</body>
</html>
