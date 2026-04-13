<?php
declare(strict_types=1);

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/csrf.php';

/** Same-origin relative redirect only; default dashboard after sign-in. */
function aubase_safe_redirect(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return 'dashboard.php';
    }
    if (strpbrk($raw, "\r\n") !== false) {
        return 'dashboard.php';
    }
    if (str_contains($raw, '..') || str_starts_with($raw, '/')) {
        return 'dashboard.php';
    }
    if (!preg_match('#^[a-zA-Z0-9][a-zA-Z0-9_./-]*\.php(\?[a-zA-Z0-9_&.=+%-]*)?$#', $raw)) {
        return 'dashboard.php';
    }

    return $raw;
}

$error = '';
$redirectField = (string) ($_POST['redirect'] ?? $_GET['redirect'] ?? '');
$verification_enabled = (getenv('AUBASE_MAIL_FROM') ?: '') !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid session. Please refresh the page and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Please enter your email or username and password.';
        } else {
            $stmt = $conn->prepare(
                'SELECT user_id, username, password_hash, email_verified FROM User
                 WHERE (email = ? OR username = ?) AND password_hash IS NOT NULL
                   AND (deleted_at IS NULL)
                 LIMIT 1'
            );
            $stmt->bind_param('ss', $username, $username);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            if ($row && password_verify($password, $row['password_hash'])) {
                if (!(int) ($row['email_verified'] ?? 1)) {
                    $error = 'Please verify your email before signing in. Check your inbox for the link we sent.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $row['user_id'];
                    $_SESSION['username'] = (string) ($row['username'] ?: $row['user_id']);
                    header('Location: ' . aubase_safe_redirect((string) ($_POST['redirect'] ?? '')));
                    exit;
                }
            } else {
                $error = 'The email, username, or password is not correct.';
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
    <title>AuBase - Sign In</title>
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --blue:  #0ea5e9;
            --blue-h:#0284c7;
            --red:   #ef4444;
            --yel:   #f59e0b;
            --grn:   #10b981;
            --gray:  #64748b;
            --light: #f0f9ff;
            --border:#e2e8f0;
            --auth-max: 440px;
            --gutter: clamp(14px, 4vw, 40px);
        }

        html { -webkit-text-size-adjust: 100%; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(165deg, #e0f2fe 0%, #f8fafc 35%, #ffffff 100%);
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── TOPBAR ── */
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px var(--gutter);
            border-bottom: 1px solid var(--border);
            background: rgba(255,255,255,0.75);
            backdrop-filter: blur(8px);
        }
        .logo {
            font-size: clamp(24px, 2vw + 1.1rem, 30px);
            font-weight: 800;
            letter-spacing: -1px;
            text-decoration: none;
        }
        .logo .l1 { color: var(--red);  }
        .logo .l2 { color: var(--blue); }
        .logo .l3 { color: var(--yel);  }
        .logo .l4 { color: var(--grn);  }
        .topbar-link { font-size: 13px; color: var(--gray); text-decoration: none; }
        .topbar-link:hover { text-decoration: underline; }

        /* ── PAGE CENTER ── */
        .page {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: clamp(36px, 8vw, 64px) var(--gutter) clamp(48px, 10vw, 96px);
            width: 100%;
            max-width: min(100%, calc(var(--auth-max) + var(--gutter) * 2));
            margin-left: auto;
            margin-right: auto;
        }

        h1 {
            font-size: clamp(22px, 2.5vw + 1rem, 28px);
            font-weight: 700;
            margin-bottom: 10px;
            text-align: center;
        }
        .signin-sub {
            font-size: 14px;
            color: var(--gray);
            text-align: center;
            margin-bottom: 22px;
            line-height: 1.45;
            max-width: 360px;
        }
        .signin-sub a { color: var(--blue-h); font-weight: 600; }

        /* ── NEW USER BANNER ── */
        .new-user-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--light);
            border-radius: 30px;
            padding: 8px 8px 8px 20px;
            width: 100%;
            max-width: 400px;
            margin-bottom: 30px;
            gap: 12px;
        }
        .new-user-row span { font-size: 14px; color: var(--gray); }
        .btn-outline {
            background: #fff;
            border: 1.5px solid var(--blue-h);
            border-radius: 25px;
            padding: 8px 20px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            color: var(--blue-h);
            text-decoration: none;
            white-space: nowrap;
            transition: background .15s, box-shadow .15s;
        }
        .btn-outline:hover { background: var(--light); box-shadow: 0 2px 8px rgba(2, 132, 199, 0.12); }

        /* ── FORM ── */
        .form-wrap { width: 100%; max-width: min(400px, 100%); }

        .alert {
            background: #fff3f3;
            border: 1px solid #fac3c3;
            color: #b03030;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 13px;
            margin-bottom: 14px;
        }
        .alert-success {
            background: #ecfdf5;
            border: 1px solid #6ee7b7;
            color: #065f46;
        }
        .alert-muted {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 13px;
        }
        .resend-box {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
            width: 100%;
            max-width: 400px;
        }
        .resend-box h2 { font-size: 14px; font-weight: 600; color: #0f172a; margin-bottom: 10px; }
        .resend-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .resend-row input[type="email"] {
            flex: 1;
            min-width: 180px;
            padding: 10px 12px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
        }
        .resend-row button {
            padding: 10px 16px;
            background: #fff;
            border: 1.5px solid var(--blue-h);
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 13px;
            color: var(--blue-h);
        }
        .resend-row button:hover { background: var(--light); }

        .form-group { margin-bottom: 12px; }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 15px;
            background: var(--light);
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        input:focus {
            border-color: var(--blue);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(14,165,233,.22);
        }
        input[readonly] { color: var(--gray); background: #f1f5f9; cursor: default; }

        .pw-wrap { position: relative; }
        .pw-wrap input { padding-right: 48px; }
        .pw-toggle {
            position: absolute; right: 14px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            cursor: pointer; font-size: 18px; color: var(--gray);
        }
        .pw-toggle:hover { color: #0f172a; }

        .btn-primary {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--blue-h) 0%, var(--blue) 100%);
            color: #fff;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: transform .2s, box-shadow .2s;
            margin-top: 4px;
            box-shadow: 0 4px 16px rgba(2, 132, 199, 0.35);
        }
        .btn-primary:hover  { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(2, 132, 199, 0.45); }
        .btn-primary:active { transform: scale(.98); }

        /* ── FOOTER ── */
        footer {
            text-align: center;
            padding: 20px;
            font-size: 12px;
            color: var(--gray);
            border-top: 1px solid var(--border);
            background: rgba(255,255,255,0.6);
        }
        footer a { color: var(--gray); text-decoration: none; margin: 0 6px; }
        footer a:hover { text-decoration: underline; }

        @media (min-width: 768px) {
            .topbar { padding: 14px clamp(24px, 5vw, 48px); }
        }

        @media (min-width: 1024px) {
            :root { --auth-max: 480px; }
            .new-user-row { max-width: min(440px, 100%); }
        }

        @media (min-width: 1280px) {
            .page { padding: 72px var(--gutter) 100px; }
        }

        @media (max-width: 640px) {
            .topbar {
                flex-wrap: wrap;
                gap: 10px;
                padding: 10px var(--gutter);
                justify-content: center;
                text-align: center;
            }
            .logo { font-size: 24px; }
            .page { padding: 32px var(--gutter) 48px; }
            h1 { font-size: 22px; margin-bottom: 20px; }
            .new-user-row {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
                padding: 12px 14px;
                border-radius: 16px;
            }
            .new-user-row .btn-outline { width: 100%; text-align: center; }
            input[type="text"],
            input[type="email"],
            input[type="password"] { font-size: 16px; }
            footer { padding: 16px 12px; line-height: 1.6; }
            footer a { display: inline-block; margin: 4px 8px; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <a href="index.php" class="logo">
        <span class="l1">A</span><span class="l2">u</span><span class="l3">B</span><span class="l4">ase</span>
    </a>
    <a href="#" class="topbar-link">Tell us what you think</a>
</div>

<div class="page">
    <h1>Sign in to your account</h1>
    <p class="signin-sub">You’ll open your <a href="dashboard.php">dashboard</a> to jump to auctions you’re bidding on or selling.</p>

    <div class="new-user-row">
        <span>New to AuBase?</span>
        <a href="register.php" class="btn-outline">Create account</a>
    </div>

    <div class="form-wrap">
        <?php if ($error): ?>
            <div class="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['registered'])): ?>
            <div class="alert alert-success">Account created. Sign in with your email and password.</div>
        <?php endif; ?>
        <?php if (isset($_GET['check_email'])): ?>
            <div class="alert alert-success">Check your email for a verification link. You must verify before signing in.</div>
        <?php endif; ?>
        <?php if (isset($_GET['verified'])): ?>
            <div class="alert alert-success">Email verified. You can sign in now.</div>
        <?php endif; ?>
        <?php if (isset($_GET['invalid_token'])): ?>
            <div class="alert">That verification link is invalid or has expired. Sign in below or request a new link.</div>
        <?php endif; ?>
        <?php if (isset($_GET['resent'])): ?>
            <?php if ($_GET['resent'] === '1'): ?>
            <div class="alert alert-success">If an account needs verification, we sent a new email.</div>
            <?php else: ?>
            <div class="alert alert-muted">Could not resend (invalid email or verification mail is not configured).</div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (isset($_GET['reset'])): ?>
            <div class="alert alert-success">Your password was updated. Sign in with your new password.</div>
        <?php endif; ?>

        <form method="POST" id="login-form">
            <?= csrf_field() ?>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectField) ?>">
            <div class="form-group">
                <input type="text" name="username" id="username-input"
                       placeholder="Email or username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username">
            </div>

            <div class="form-group pw-wrap" id="step-password" style="display:none;">
                <input type="password" name="password" id="password-input"
                       placeholder="Password"
                       autocomplete="current-password">
                <button type="button" class="pw-toggle" onclick="togglePw(this)">&#128065;</button>
            </div>

            <button class="btn-primary" type="submit" id="main-btn">Continue</button>
        </form>

        <p style="text-align:center;margin-top:16px;font-size:14px;">
            <a href="forgot_password.php" style="color:var(--blue-h);font-weight:600;">Forgot password?</a>
        </p>

        <?php if ($verification_enabled): ?>
        <div class="resend-box">
            <h2>Didn’t get the verification email?</h2>
            <form method="POST" action="resend_verification.php" class="resend-row">
                <?= csrf_field() ?>
                <input type="email" name="email" placeholder="Your email address" required autocomplete="email"
                       value=""
                       aria-label="Email for resend">
                <button type="submit">Resend link</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<footer>
    <a href="#">Copyright &copy; <?= date('Y') ?> AuBase</a>
    <a href="#">Privacy Policy</a>
    <a href="#">Terms of Use</a>
    <a href="#">Help</a>
</footer>

<script>
    const form      = document.getElementById('login-form');
    const userInput = document.getElementById('username-input');
    const pwGroup   = document.getElementById('step-password');
    const pwInput   = document.getElementById('password-input');
    const mainBtn   = document.getElementById('main-btn');
    let step = 1;

    <?php if (!empty($_POST['username'])): ?>
    revealPassword();  // server bounce back — jump straight to step 2
    <?php endif; ?>

    form.addEventListener('submit', function(e) {
        if (step === 1) {
            e.preventDefault();
            if (!userInput.value.trim()) { userInput.focus(); return; }
            revealPassword();
        }
        // step 2 — normal submit
    });

    function revealPassword() {
        step = 2;
        userInput.readOnly = true;
        pwGroup.style.display = 'block';
        pwInput.focus();
        mainBtn.textContent = 'Sign In';
    }

    function togglePw(btn) {
        const show = pwInput.type === 'password';
        pwInput.type = show ? 'text' : 'password';
        btn.innerHTML = show ? '&#128683;' : '&#128065;';
    }
</script>
</body>
</html>