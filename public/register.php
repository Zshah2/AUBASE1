<?php
declare(strict_types=1);

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../backend/mail_verify.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid session. Please refresh the page and try again.';
    } else {
        $fname   = trim($_POST['fname']    ?? '');
        $lname   = trim($_POST['lname']    ?? '');
        $email   = trim($_POST['email']    ?? '');
        $phone   = trim($_POST['phone']    ?? '');
        $address = trim($_POST['address']  ?? '');
        $pw      = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        if ($fname === '' || $email === '' || $pw === '') {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($pw) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($pw !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $dupChk = $conn->prepare('SELECT 1 FROM User WHERE email = ? LIMIT 1');
            $dupChk->bind_param('s', $email);
            $dupChk->execute();
            if ($dupChk->get_result()->num_rows > 0) {
                $error = 'That email is already registered. Try signing in or use Forgot password on the sign-in page.';
            } else {
                $hash = password_hash($pw, PASSWORD_DEFAULT);
                $baseUser = substr(preg_replace('/[^a-zA-Z0-9._-]/', '', strstr($email, '@', true) ?: 'user'), 0, 80);
                if (strlen($baseUser) < 2) {
                    $baseUser = 'user';
                }
                $username = $baseUser;
                for ($attempt = 0; $attempt < 20; $attempt++) {
                    $chk = $conn->prepare('SELECT 1 FROM User WHERE username = ? LIMIT 1');
                    $chk->bind_param('s', $username);
                    $chk->execute();
                    if ($chk->get_result()->num_rows === 0) {
                        break;
                    }
                    $username = substr($baseUser, 0, 60) . '_' . bin2hex(random_bytes(3));
                }

                $user_id = 'u' . bin2hex(random_bytes(8));

                // With AUBASE_MAIL_FROM set: require email verification before login.
                // Without it (local dev): account is active immediately — no mail server needed.
                $mailConfigured = (getenv('AUBASE_MAIL_FROM') ?: '') !== '';

                $createdAt = date('Y-m-d H:i:s');
                if ($mailConfigured) {
                    $verifyToken = bin2hex(random_bytes(32));
                    $verifyExpires = date('Y-m-d H:i:s', strtotime('+48 hours'));
                    $stmt = $conn->prepare(
                        'INSERT INTO User (user_id, username, email, first_name, last_name, address, phone, password_hash, rating, email_verified, verify_token, verify_expires, created_at)
                         VALUES (?,?,?,?,?,?,?,?,0,0,?,?,?)'
                    );
                    $stmt->bind_param(
                        'sssssssssss',
                        $user_id,
                        $username,
                        $email,
                        $fname,
                        $lname,
                        $address,
                        $phone,
                        $hash,
                        $verifyToken,
                        $verifyExpires,
                        $createdAt
                    );
                } else {
                    $stmt = $conn->prepare(
                        'INSERT INTO User (user_id, username, email, first_name, last_name, address, phone, password_hash, rating, email_verified, created_at)
                         VALUES (?,?,?,?,?,?,?,?,0,1,?)'
                    );
                    $stmt->bind_param(
                        'sssssssss',
                        $user_id,
                        $username,
                        $email,
                        $fname,
                        $lname,
                        $address,
                        $phone,
                        $hash,
                        $createdAt
                    );
                }

                try {
                    if (!$stmt->execute()) {
                        $error = 'Could not create account. Please try again.';
                    }
                } catch (mysqli_sql_exception $e) {
                    $dup = $e->getCode() === 1062 || str_contains($e->getMessage(), 'Duplicate entry');
                    $error = $dup
                        ? 'That email is already registered. Try signing in or use Forgot password on the sign-in page.'
                        : 'Could not create account. Please try again.';
                    $stmt = null;
                }

                if ($error === '' && isset($stmt)) {
                    if ($mailConfigured) {
                        aubase_send_verification_email($email, $fname, $verifyToken);
                        header('Location: login.php?check_email=1');
                        exit;
                    }

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $username;
                    header('Location: index.php?welcome=1');
                    exit;
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
    <title>AuBase - Create Account</title>
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
            --gutter: clamp(14px, 4vw, 40px);
            --form-panel-max: 520px;
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
            position: sticky;
            top: 0;
            z-index: 10;
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

        .topbar-right { font-size: 14px; color: var(--gray); }
        .topbar-right a { color: var(--blue); font-weight: 700; text-decoration: none; margin-left: 4px; }
        .topbar-right a:hover { text-decoration: underline; }

        /* ── SPLIT LAYOUT ── */
        .split {
            flex: 1;
            display: flex;
            min-height: calc(100vh - 57px);
        }

        /* ── LEFT HERO ── */
        .hero {
            flex: 1;
            background: linear-gradient(155deg, #bae6fd 0%, #7dd3fc 45%, #38bdf8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        .hero-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center top;
        }
        /* Fallback gradient pattern if no image */
        .hero-fallback {
            width: 100%;
            height: 100%;
            background:
                    radial-gradient(circle at 30% 40%, rgba(59,130,246,.35) 0%, transparent 50%),
                    radial-gradient(circle at 70% 70%, rgba(16,185,129,.25) 0%, transparent 50%),
                    linear-gradient(135deg, #dbeafe 0%, #e0f2fe 50%, #d1fae5 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
            color: #1e40af;
        }
        .hero-fallback .big-icon { font-size: clamp(56px, 10vw, 96px); }
        .hero-fallback h2 { font-size: clamp(18px, 2vw + 0.9rem, 26px); font-weight: 700; text-align: center; padding: 0 var(--gutter); }
        .hero-fallback p  { font-size: clamp(14px, 1.2vw + 0.75rem, 17px); text-align: center; padding: 0 var(--gutter); color: #2563eb; }

        /* ── RIGHT FORM PANEL ── */
        .form-panel {
            width: 100%;
            max-width: var(--form-panel-max);
            padding: clamp(32px, 5vw, 52px) clamp(24px, 4vw, 56px);
            display: flex;
            flex-direction: column;
            justify-content: center;
            overflow-y: auto;
            background: #fff;
        }

        @media (min-width: 721px) {
            .form-panel { box-shadow: -12px 0 40px rgba(14, 165, 233, 0.06); }
        }

        @media (max-width: 720px) {
            .hero { display: none; }
            .split { min-height: auto; }
            .form-panel { max-width: 100%; padding: 32px var(--gutter); }
        }

        @media (min-width: 721px) and (max-width: 1024px) {
            .form-panel { padding: 36px clamp(20px, 3vw, 44px); }
        }

        @media (min-width: 1024px) {
            :root { --form-panel-max: 540px; }
        }

        @media (min-width: 1280px) {
            :root { --form-panel-max: 560px; }
            .form-panel { padding: 52px 60px; }
        }

        @media (min-width: 1536px) {
            :root { --form-panel-max: 580px; }
        }

        h1 { font-size: clamp(22px, 2.2vw + 1rem, 28px); font-weight: 700; margin-bottom: 22px; }

        /* ── ACCOUNT TYPE TOGGLE ── */
        .type-toggle {
            display: flex;
            background: var(--light);
            border: 1.5px solid var(--border);
            border-radius: 30px;
            padding: 4px;
            margin-bottom: 24px;
            gap: 4px;
        }
        .type-btn {
            flex: 1;
            padding: 9px 0;
            border: none;
            border-radius: 26px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            background: transparent;
            color: var(--gray);
            transition: background .2s, color .2s;
        }
        .type-btn.active {
            background: linear-gradient(135deg, var(--blue-h) 0%, var(--blue) 100%);
            color: #fff;
            box-shadow: 0 2px 10px rgba(2, 132, 199, 0.3);
        }

        /* ── FORM FIELDS ── */
        .alert {
            background: #fff3f3;
            border: 1px solid #fac3c3;
            color: #b03030;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .form-row { display: flex; gap: 12px; }
        .form-row .form-group { flex: 1; }
        .form-group { margin-bottom: 14px; }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="tel"] {
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
        input.valid   { border-color: #16a34a; }
        input.invalid { border-color: var(--red); }

        .pw-wrap { position: relative; }
        .pw-wrap input { padding-right: 48px; }
        .pw-toggle {
            position: absolute; right: 14px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            cursor: pointer; font-size: 18px; color: var(--gray);
        }
        .pw-toggle:hover { color: #0f172a; }

        .strength-bar {
            height: 4px;
            background: #e5e5e5;
            border-radius: 2px;
            margin-top: 6px;
            overflow: hidden;
        }
        .strength-fill {
            height: 100%;
            width: 0;
            border-radius: 2px;
            transition: width .3s, background .3s;
        }
        .strength-text {
            font-size: 11px;
            color: var(--gray);
            margin-top: 3px;
            min-height: 14px;
        }

        /* ── TERMS ── */
        .terms {
            font-size: 12px;
            color: var(--gray);
            margin: 6px 0 18px;
            line-height: 1.6;
        }
        .terms a { color: var(--blue); text-decoration: none; }
        .terms a:hover { text-decoration: underline; }

        /* ── SUBMIT ── */
        .btn-primary {
            width: 100%;
            padding: 15px;
            background: #cbd5e1;
            color: #64748b;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: 700;
            cursor: not-allowed;
            transition: transform .2s, box-shadow .2s, background .2s, color .2s;
        }
        .btn-primary.ready {
            background: linear-gradient(135deg, var(--blue-h) 0%, var(--blue) 100%);
            color: #fff;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(2, 132, 199, 0.35);
        }
        .btn-primary.ready:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(2, 132, 199, 0.45); }
        .btn-primary.ready:active { transform: scale(.98); }

        /* ── FOOTER ── */
        footer {
            text-align: center;
            padding: 18px;
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

        @media (max-width: 640px) {
            .topbar {
                flex-wrap: wrap;
                gap: 10px;
                padding: 10px var(--gutter);
                justify-content: center;
                text-align: center;
            }
            .topbar-right { font-size: 13px; line-height: 1.4; }
            .logo { font-size: 24px; }
            .form-panel { padding: 24px 16px; }
            h1 { font-size: 22px; }
            .form-row { flex-direction: column; gap: 0; }
            input[type="text"],
            input[type="email"],
            input[type="password"],
            input[type="tel"] { font-size: 16px; }
            .type-toggle { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .type-btn { flex: 1; min-width: 0; white-space: nowrap; }
        }
    </style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
    <a href="index.php" class="logo">
        <span class="l1">A</span><span class="l2">u</span><span class="l3">B</span><span class="l4">ase</span>
    </a>
    <div class="topbar-right">
        Already have an account? <a href="login.php?redirect=dashboard.php">Sign in</a>
    </div>
</div>

<!-- Split Layout -->
<div class="split">

    <!-- Left: Hero -->
    <div class="hero">
        <div class="hero-fallback">
            <div class="big-icon">🛍️</div>
            <h2>Buy, sell, and bid on anything</h2>
            <p>Join millions of buyers and sellers on AuBase — the auction marketplace built for everyone.</p>
        </div>
    </div>

    <!-- Right: Form -->
    <div class="form-panel">
        <h1>Create an account</h1>

        <!-- Account Type Toggle -->
        <div class="type-toggle">
            <button type="button" class="type-btn active" id="btn-personal"
                    onclick="setType('personal')">Personal</button>
            <button type="button" class="type-btn" id="btn-business"
                    onclick="setType('business')">Business</button>
        </div>

        <?php if ($error): ?>
            <div class="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="reg-form" onsubmit="return beforeSubmit()">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="account_type" id="account_type" value="personal">

            <div class="form-row">
                <div class="form-group">
                    <input type="text" name="fname" id="fname"
                           placeholder="First name"
                           value="<?= htmlspecialchars($_POST['fname'] ?? '') ?>"
                           autocomplete="given-name" required
                           oninput="validateField(this, v => v.length >= 2)">
                </div>
                <div class="form-group">
                    <input type="text" name="lname" id="lname"
                           placeholder="Last name"
                           value="<?= htmlspecialchars($_POST['lname'] ?? '') ?>"
                           autocomplete="family-name">
                </div>
            </div>

            <div class="form-group">
                <input type="email" name="email" id="email"
                       placeholder="Email"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       autocomplete="email" required
                       oninput="validateField(this, v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v))">
            </div>

            <div class="form-group">
                <input type="tel" name="phone" id="phone"
                       placeholder="Phone number (optional)"
                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                       autocomplete="tel">
            </div>

            <div class="form-group">
                <input type="text" name="address" id="address"
                       placeholder="Address (optional)"
                       value="<?= htmlspecialchars($_POST['address'] ?? '') ?>"
                       autocomplete="street-address">
            </div>

            <div class="form-group pw-wrap">
                <input type="password" name="password" id="pw"
                       placeholder="Password"
                       autocomplete="new-password" required
                       oninput="checkStrength(this.value); checkReady();">
                <button type="button" class="pw-toggle" onclick="togglePw('pw',this)">&#128065;</button>
            </div>

            <div class="form-group">
                <input type="password" name="confirm" id="pw2"
                       placeholder="Confirm password"
                       autocomplete="new-password" required
                       oninput="validateField(this, v => v === document.getElementById('pw').value); checkReady();">
            </div>

            <div class="strength-bar"><div class="strength-fill" id="sfill"></div></div>
            <div class="strength-text" id="slabel"></div>

            <div class="terms">
                By selecting <strong>Create personal account</strong>, you agree to our
                <a href="#">User Agreement</a> and acknowledge reading our
                <a href="#">User Privacy Notice</a>.
            </div>

            <button class="btn-primary" type="submit" id="submit-btn">
                Create personal account
            </button>
        </form>
    </div>

</div>

<footer>
    <a href="#">Copyright &copy; <?= date('Y') ?> AuBase</a>
    <a href="#">Privacy Policy</a>
    <a href="#">Terms of Use</a>
    <a href="#">Help</a>
</footer>

<script>
    let accountType = 'personal';

    function setType(type) {
        accountType = type;
        document.getElementById('account_type').value = type;
        document.getElementById('btn-personal').classList.toggle('active', type === 'personal');
        document.getElementById('btn-business').classList.toggle('active', type === 'business');
        document.getElementById('submit-btn').textContent =
            type === 'personal' ? 'Create personal account' : 'Create business account';
    }

    function validateField(inp, testFn) {
        if (inp.value.trim() === '') { inp.className = ''; return; }
        inp.classList.toggle('valid',   testFn(inp.value.trim()));
        inp.classList.toggle('invalid', !testFn(inp.value.trim()));
        checkReady();
    }

    function checkStrength(pw) {
        const fill  = document.getElementById('sfill');
        const label = document.getElementById('slabel');
        let s = 0;
        if (pw.length >= 6)          s++;
        if (pw.length >= 10)         s++;
        if (/[A-Z]/.test(pw))        s++;
        if (/[0-9]/.test(pw))        s++;
        if (/[^A-Za-z0-9]/.test(pw)) s++;

        const lvls = [
            { w:'0%',   c:'transparent', t:'' },
            { w:'25%',  c:'#ef4444',     t:'Weak' },
            { w:'50%',  c:'#f97316',     t:'Fair' },
            { w:'75%',  c:'#22c55e',     t:'Good' },
            { w:'100%', c:'#16a34a',     t:'Strong' },
        ];
        const l = lvls[Math.min(s, 4)];
        fill.style.width      = l.w;
        fill.style.background = l.c;
        label.textContent     = l.t;
        label.style.color     = l.c;
    }

    function checkReady() {
        const fname = document.getElementById('fname').value.trim();
        const email = document.getElementById('email').value.trim();
        const pw    = document.getElementById('pw').value;
        const pw2   = document.getElementById('pw2').value;
        const btn   = document.getElementById('submit-btn');
        const ready = fname.length >= 2 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
            && pw.length >= 6 && pw === pw2;
        btn.classList.toggle('ready', ready);
    }

    function togglePw(id, btn) {
        const inp = document.getElementById(id);
        const show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        btn.innerHTML = show ? '&#128683;' : '&#128065;';
    }

    function beforeSubmit() {
        const pw = document.getElementById('pw').value;
        const pw2 = document.getElementById('pw2').value;
        if (pw !== pw2) {
            alert('Passwords do not match.');
            return false;
        }
        const btn = document.getElementById('submit-btn');
        btn.textContent = 'Creating account…';
        btn.disabled = true;
        return true;
    }
</script>
</body>
</html>