<?php
declare(strict_types=1);

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';

$error = '';
$tokenIn = trim((string) ($_GET['token'] ?? $_POST['reset_token'] ?? ''));
$tokenOk = strlen($tokenIn) === 64 && ctype_xdigit($tokenIn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenOk) {
    if (!csrf_verify()) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        $p1 = (string) ($_POST['password'] ?? '');
        $p2 = (string) ($_POST['confirm'] ?? '');
        if (strlen($p1) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($p1 !== $p2) {
            $error = 'Passwords do not match.';
        } else {
            $stmt = $conn->prepare(
                'SELECT user_id FROM User
                 WHERE password_reset_token = ? AND password_reset_expires > NOW() AND password_hash IS NOT NULL
                 LIMIT 1'
            );
            $stmt->bind_param('s', $tokenIn);
            try {
                $stmt->execute();
            } catch (mysqli_sql_exception $e) {
                $error = 'Database error. Run: php database/migrate_password_reset.php';
                $stmt = null;
            }
            $row = ($stmt !== null) ? $stmt->get_result()->fetch_assoc() : null;

            if (!$row) {
                $error = 'This reset link is invalid or has expired. Request a new one from the sign-in page.';
            } else {
                $hash = password_hash($p1, PASSWORD_DEFAULT);
                $uid = (string) $row['user_id'];
                $upd = $conn->prepare(
                    'UPDATE User SET
                        password_hash = ?,
                        password_reset_token = NULL,
                        password_reset_expires = NULL,
                        email_verified = 1
                     WHERE user_id = ?'
                );
                $upd->bind_param('ss', $hash, $uid);
                try {
                    $upd->execute();
                    header('Location: login.php?reset=1');
                    exit;
                } catch (mysqli_sql_exception $e) {
                    $error = 'Could not update password. Run: php database/migrate_password_reset.php';
                }
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
    <title>AuBase — New password</title>
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
        .form-group { margin-bottom: 12px; width: 100%; }
        input[type="password"] {
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
    <?php if (!$tokenOk): ?>
        <h1>Invalid link</h1>
        <p class="sub">This password reset link is missing or not valid.</p>
        <p class="back"><a href="forgot_password.php">Request a new link</a> · <a href="login.php">Sign in</a></p>
    <?php else: ?>
        <h1>Choose a new password</h1>
        <p class="sub">Use at least 6 characters.</p>
        <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST" style="width:100%">
            <?= csrf_field() ?>
            <input type="hidden" name="reset_token" value="<?= htmlspecialchars($tokenIn) ?>">
            <div class="form-group">
                <input type="password" name="password" required minlength="6" placeholder="New password" autocomplete="new-password">
            </div>
            <div class="form-group">
                <input type="password" name="confirm" required minlength="6" placeholder="Confirm password" autocomplete="new-password">
            </div>
            <button type="submit" class="btn-primary">Update password</button>
        </form>
        <p class="back"><a href="login.php">← Back to sign in</a></p>
    <?php endif; ?>
</div>

<footer>&copy; <?= date('Y') ?> AuBase</footer>
</body>
</html>
