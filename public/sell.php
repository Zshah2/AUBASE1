<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../backend/db.php';

$logged_in        = isset($_SESSION['user_id'], $_SESSION['username']);
$session_username = $logged_in ? (string) $_SESSION['username'] : '';
$session_user_id  = $logged_in ? (string) $_SESSION['user_id'] : '';

if (!$logged_in) {
    header('Location: login.php?redirect=sell.php');
    exit;
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim((string)($_POST['name']        ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $location    = trim((string)($_POST['location']    ?? ''));
    $country     = trim((string)($_POST['country']     ?? ''));
    $category    = trim((string)($_POST['category']    ?? ''));
    $start_price = trim((string)($_POST['start_price'] ?? ''));
    $buy_price   = trim((string)($_POST['buy_price']   ?? ''));
    $duration    = (int)($_POST['duration'] ?? 7);

    if (!$name)                           $errors[] = 'Item name is required.';
    if (!$description)                    $errors[] = 'Description is required.';
    if (!$location)                       $errors[] = 'Location is required.';
    if (!$country)                        $errors[] = 'Country is required.';
    if (!is_numeric($start_price) || (float)$start_price <= 0)
        $errors[] = 'Starting price must be a positive number.';
    if ($buy_price !== '' && (!is_numeric($buy_price) || (float)$buy_price <= (float)$start_price))
        $errors[] = 'Buy It Now price must be greater than starting price.';
    if (!in_array($duration, [1,3,5,7,10,14], true))
        $errors[] = 'Invalid auction duration.';

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // Insert Item
            $stmt = $conn->prepare("INSERT INTO Item (name, description, location, country, seller_id) VALUES (?,?,?,?,?)");
            $stmt->bind_param('sssss', $name, $description, $location, $country, $session_user_id);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $item_id = (int)$conn->insert_id;

            // Category
            if ($category) {
                $stmt2 = $conn->prepare("SELECT category_id FROM Category WHERE name = ?");
                $stmt2->bind_param('s', $category);
                $stmt2->execute();
                $cat_row = $stmt2->get_result()->fetch_assoc();
                if ($cat_row) {
                    $stmt3 = $conn->prepare("INSERT INTO Item_Category (item_id, category_id) VALUES (?,?)");
                    $stmt3->bind_param('ii', $item_id, $cat_row['category_id']);
                    $stmt3->execute();
                }
            }

            // Insert Auction
            $start_time = date('Y-m-d H:i:s');
            $end_time   = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
            $sp         = (float)$start_price;
            $bp         = ($buy_price !== '' && is_numeric($buy_price)) ? (float)$buy_price : null;

            if ($bp !== null) {
                $stmt4 = $conn->prepare("INSERT INTO Auction (item_id, current_price, starting_price, buy_price, num_bids, start_time, end_time) VALUES (?,?,?,?,0,?,?)");
                if (!$stmt4) throw new Exception($conn->error);
                $stmt4->bind_param('idddss', $item_id, $sp, $sp, $bp, $start_time, $end_time);
            } else {
                $stmt4 = $conn->prepare("INSERT INTO Auction (item_id, current_price, starting_price, num_bids, start_time, end_time) VALUES (?,?,?,0,?,?)");
                if (!$stmt4) throw new Exception($conn->error);
                $stmt4->bind_param('iddss', $item_id, $sp, $sp, $start_time, $end_time);
            }
            if (!$stmt4->execute()) throw new Exception($stmt4->error);

            $conn->commit();
            $success = true;
            $new_item_id = $item_id;
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch categories for dropdown
$cats = [];
$cr   = $conn->query("SELECT name FROM Category ORDER BY name");
if ($cr) while ($r = $cr->fetch_assoc()) $cats[] = $r['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sell on AuBase</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink:#0f172a; --ink-3:#334155;
            --mid:#64748b; --muted:#94a3b8;
            --border:#e2e8f0; --border-2:#cbd5e1;
            --bg:#f8fafc; --bg-2:#f1f5f9; --white:#ffffff;
            --gold:#f59e0b; --gold-dark:#d97706;
            --navy:#0284c7; --navy-2:#0ea5e9; --navy-3:#38bdf8;
            --sky-wash:#e0f2fe; --sky-mist:#f0f9ff;
            --green:#10b981; --green-bg:#d1fae5;
            --red:#ef4444; --red-bg:#fee2e2;
            --radius-sm:6px; --radius:12px; --radius-lg:18px;
            --shadow-sm:0 1px 3px rgba(14,165,233,0.08);
            --shadow:0 4px 24px rgba(14,165,233,0.1);
            --shadow-lg:0 20px 50px rgba(14,165,233,0.12);
            --edge:clamp(16px,4vw,56px);
        }
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'DM Sans',system-ui,sans-serif;background:var(--bg);color:var(--ink);font-size:14px;-webkit-font-smoothing:antialiased;min-height:100vh}
        a{text-decoration:none;color:inherit}

        /* TOP BAR */
        .top-bar{background:linear-gradient(90deg,var(--sky-wash) 0%,var(--sky-mist) 100%);color:var(--ink-3);padding:7px var(--edge);display:flex;justify-content:space-between;align-items:center;font-size:11.5px;border-bottom:1px solid var(--border)}
        .top-bar a{color:var(--mid);transition:color 0.18s}
        .top-bar a:hover{color:var(--navy)}
        .top-bar .highlight{color:var(--gold-dark);font-weight:700}
        .top-bar-left,.top-bar-right{display:flex;gap:14px;align-items:center}
        .top-bar-sep{opacity:0.2}

        /* NAVBAR */
        nav{background:var(--white);padding:0 var(--edge);display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border);min-height:64px;box-shadow:var(--shadow-sm);flex-wrap:wrap;padding-top:8px;padding-bottom:8px}
        .logo{font-family:'DM Serif Display',serif;font-size:28px;letter-spacing:-1.5px;min-width:110px;line-height:1;flex-shrink:0}
        .logo span:nth-child(1){color:var(--red)}.logo span:nth-child(2){color:var(--gold)}.logo span:nth-child(3){color:var(--navy)}.logo span:nth-child(4){color:var(--ink-3)}
        .nav-right{display:flex;align-items:center;gap:4px;margin-left:auto}
        .nav-link{color:var(--mid);font-size:13px;font-weight:500;padding:8px 14px;border-radius:var(--radius-sm);transition:all 0.18s}
        .nav-link:hover{color:var(--navy);background:var(--bg)}
        .nav-link.active{color:var(--navy);font-weight:600}

        /* PAGE LAYOUT */
        .page-wrap{max-width:860px;margin:0 auto;padding:clamp(28px,4vw,48px) var(--edge)}

        /* BREADCRUMB */
        .breadcrumb{display:flex;align-items:center;gap:6px;font-size:12.5px;color:var(--muted);margin-bottom:24px;flex-wrap:wrap}
        .breadcrumb a{color:var(--muted);transition:color 0.15s}
        .breadcrumb a:hover{color:var(--navy)}
        .breadcrumb span{opacity:0.4}

        /* PAGE HEADER */
        .page-header{margin-bottom:36px}
        .page-header h1{font-family:'DM Serif Display',serif;font-size:clamp(24px,2vw+1rem,32px);font-weight:400;letter-spacing:-0.5px;color:var(--ink);margin-bottom:8px;line-height:1.2}
        .page-header p{color:var(--muted);font-size:14.5px;line-height:1.6;max-width:48ch}

        /* PROGRESS STEPS */
        .steps{display:flex;align-items:center;gap:0;margin-bottom:36px;background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
        .step{flex:1;display:flex;align-items:center;gap:10px;padding:14px 18px;font-size:13px;font-weight:500;color:var(--muted);border-right:1px solid var(--border);position:relative}
        .step:last-child{border-right:none}
        .step.active{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff}
        .step.done{background:var(--green-bg);color:var(--green)}
        .step-num{width:24px;height:24px;border-radius:50%;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0}
        .step.active .step-num{background:rgba(255,255,255,0.2)}
        .step.done .step-num{background:var(--green);color:#fff}
        .step:not(.active) .step-num{background:var(--bg-2);color:var(--muted)}

        /* FORM CARD */
        .form-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-sm)}
        .form-section{padding:24px 28px;border-bottom:1px solid var(--border)}
        .form-section:last-child{border-bottom:none}
        .form-section-title{font-size:13px;font-weight:600;color:var(--navy);letter-spacing:0.04em;text-transform:uppercase;margin-bottom:18px;display:flex;align-items:center;gap:8px}
        .form-section-title::after{content:'';flex:1;height:1px;background:var(--border)}

        /* FORM ELEMENTS */
        .field{margin-bottom:18px}
        .field:last-child{margin-bottom:0}
        .field-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        label{display:block;font-size:12.5px;font-weight:600;color:var(--ink-3);margin-bottom:6px;letter-spacing:0.01em}
        label .req{color:var(--red);margin-left:2px}
        label .opt{color:var(--muted);font-weight:400;font-size:11px;margin-left:4px}
        input[type=text],input[type=number],select,textarea{width:100%;padding:10px 14px;border:1.5px solid var(--border-2);border-radius:var(--radius-sm);font-size:13.5px;font-family:'DM Sans',sans-serif;color:var(--ink);background:var(--bg);outline:none;transition:border-color 0.18s,box-shadow 0.18s,background 0.18s}
        input[type=text]:focus,input[type=number]:focus,select:focus,textarea:focus{border-color:var(--navy-2);box-shadow:0 0 0 3px rgba(14,165,233,0.2);background:var(--white)}
        input[type=text]::placeholder,input[type=number]::placeholder,textarea::placeholder{color:var(--muted)}
        textarea{resize:vertical;min-height:110px;line-height:1.6}
        select{cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238b93b0' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center}
        .input-prefix{position:relative}
        .input-prefix span{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;font-weight:500;pointer-events:none}
        .input-prefix input{padding-left:26px}
        .field-hint{font-size:11.5px;color:var(--muted);margin-top:5px;line-height:1.5}

        /* DURATION PICKER */
        .duration-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:8px}
        .duration-opt{position:relative}
        .duration-opt input{position:absolute;opacity:0;width:0;height:0}
        .duration-opt label{display:flex;flex-direction:column;align-items:center;padding:10px 6px;border:1.5px solid var(--border-2);border-radius:var(--radius-sm);cursor:pointer;transition:all 0.18s;background:var(--bg);font-weight:500;color:var(--mid);font-size:13px;letter-spacing:0}
        .duration-opt label span{font-size:9.5px;color:var(--muted);margin-top:2px;font-weight:400}
        .duration-opt input:checked + label{border-color:var(--navy);background:var(--navy);color:#fff}
        .duration-opt input:checked + label span{color:rgba(255,255,255,0.6)}
        .duration-opt label:hover{border-color:var(--border-2);background:var(--bg-2)}

        /* ERRORS / SUCCESS */
        .alert{padding:14px 18px;border-radius:var(--radius-sm);font-size:13.5px;margin-bottom:24px;display:flex;align-items:flex-start;gap:10px}
        .alert-error{background:var(--red-bg);border:1px solid #fecaca;color:var(--red)}
        .alert-success{background:var(--green-bg);border:1px solid #a7f3d0;color:var(--green)}
        .alert ul{padding-left:16px;margin-top:4px}
        .alert ul li{margin-bottom:3px}

        /* SUBMIT */
        .form-footer{padding:20px 28px;background:var(--bg);border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px}
        .btn-submit{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;padding:12px 32px;border-radius:50px;font-size:14px;font-weight:600;font-family:'DM Sans',sans-serif;border:none;cursor:pointer;transition:all 0.2s;letter-spacing:0.01em;box-shadow:0 4px 16px rgba(2,132,199,0.35)}
        .btn-submit:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(2,132,199,0.45)}
        .btn-cancel{color:var(--mid);font-size:13px;font-weight:500;padding:12px 20px;border-radius:50px;transition:all 0.18s;border:1px solid var(--border);background:var(--white)}
        .btn-cancel:hover{color:var(--navy);border-color:var(--border-2)}

        /* SUCCESS STATE */
        .success-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius-lg);padding:48px 32px;text-align:center;box-shadow:var(--shadow-sm)}
        .success-icon{width:64px;height:64px;background:var(--green-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px}
        .success-card h2{font-family:'DM Serif Display',serif;font-size:24px;font-weight:400;color:var(--ink);margin-bottom:8px}
        .success-card p{color:var(--muted);font-size:14px;line-height:1.6;margin-bottom:24px}
        .success-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
        .btn-primary{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;padding:11px 24px;border-radius:50px;font-size:13.5px;font-weight:600;transition:all 0.2s;box-shadow:0 4px 14px rgba(2,132,199,0.35)}
        .btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(2,132,199,0.45)}
        .btn-ghost{display:inline-flex;align-items:center;gap:7px;background:transparent;color:var(--mid);padding:11px 20px;border-radius:50px;font-size:13.5px;font-weight:500;border:1px solid var(--border);transition:all 0.2s}
        .btn-ghost:hover{color:var(--navy);border-color:var(--border-2)}

        /* TIPS SIDEBAR */
        .layout{display:grid;grid-template-columns:1fr 280px;gap:24px;align-items:start}
        .tips-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;box-shadow:var(--shadow-sm);position:sticky;top:80px}
        .tips-card h3{font-size:13px;font-weight:600;color:var(--navy);margin-bottom:14px;display:flex;align-items:center;gap:6px}
        .tip{display:flex;gap:10px;margin-bottom:14px;font-size:12.5px;color:var(--mid);line-height:1.55}
        .tip:last-child{margin-bottom:0}
        .tip-icon{font-size:16px;flex-shrink:0;margin-top:1px}

        @media(max-width:768px){
            .layout{grid-template-columns:1fr}
            .tips-card{display:none}
            .field-row{grid-template-columns:1fr}
            .duration-grid{grid-template-columns:repeat(3,1fr)}
            .steps{display:none}
        }
    </style>
</head>
<body>

<!-- Top Bar -->
<div class="top-bar">
    <div class="top-bar-left">
        <span class="highlight">Hi, <?= htmlspecialchars($session_username) ?></span>
        <span class="top-bar-sep">|</span>
        <a href="logout.php">Sign out</a>
    </div>
    <div class="top-bar-right">
        <a href="account.php">Account</a>
        <span class="top-bar-sep">|</span>
        <a href="dashboard.php">My AuBase</a>
        <span class="top-bar-sep">|</span>
        <a href="index.php">Browse</a>
    </div>
</div>

<!-- Navbar -->
<nav>
    <a href="index.php" class="logo">
        <span>A</span><span>u</span><span>B</span><span>ase</span>
    </a>
    <div class="nav-right">
        <a href="index.php" class="nav-link">Browse</a>
        <a href="dashboard.php" class="nav-link">Dashboard</a>
        <a href="account.php" class="nav-link">Account</a>
        <a href="sell.php" class="nav-link active">Sell</a>
    </div>
</nav>

<!-- Page -->
<div class="page-wrap">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="index.php">Home</a>
        <span>›</span>
        <a href="dashboard.php">My AuBase</a>
        <span>›</span>
        <span style="color:var(--ink)">List an item</span>
    </div>

    <?php if ($success): ?>
        <!-- Success State -->
        <div class="success-card">
            <div class="success-icon">🎉</div>
            <h2>Your item is live!</h2>
            <p>Your auction has been created successfully. Bidders can find it on the homepage right now.</p>
            <div class="success-actions">
                <a href="item.php?id=<?= $new_item_id ?>" class="btn-primary">View listing →</a>
                <a href="sell.php" class="btn-ghost">List another item</a>
                <a href="dashboard.php" class="btn-ghost">Go to dashboard</a>
            </div>
        </div>

    <?php else: ?>

        <div class="page-header">
            <h1>List an item for auction</h1>
            <p>Fill in the details below. Your item will go live immediately after submission.</p>
        </div>

        <!-- Steps -->
        <div class="steps">
            <div class="step active">
                <div class="step-num">1</div>
                Item Details
            </div>
            <div class="step">
                <div class="step-num">2</div>
                Pricing
            </div>
            <div class="step">
                <div class="step-num">3</div>
                Duration
            </div>
            <div class="step">
                <div class="step-num">4</div>
                Review &amp; List
            </div>
        </div>

        <!-- Errors -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <span>⚠</span>
                <div>
                    <strong>Please fix the following:</strong>
                    <ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul>
                </div>
            </div>
        <?php endif; ?>

        <div class="layout">
            <form method="POST" action="sell.php">

                <div class="form-card">

                    <!-- Item Info -->
                    <div class="form-section">
                        <div class="form-section-title">Item Information</div>

                        <div class="field">
                            <label>Item title <span class="req">*</span></label>
                            <input type="text" name="name" placeholder="e.g. Vintage Rolex Submariner 1965" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" maxlength="120">
                            <div class="field-hint">Be specific — good titles get more bids.</div>
                        </div>

                        <div class="field">
                            <label>Description <span class="req">*</span></label>
                            <textarea name="description" placeholder="Describe the item's condition, history, what's included…"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        </div>

                        <div class="field">
                            <label>Category <span class="opt">(optional)</span></label>
                            <select name="category">
                                <option value="">— Select a category —</option>
                                <?php foreach ($cats as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>" <?= (($_POST['category'] ?? '') === $c) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Location -->
                    <div class="form-section">
                        <div class="form-section-title">Location</div>
                        <div class="field-row">
                            <div class="field">
                                <label>City / Region <span class="req">*</span></label>
                                <input type="text" name="location" placeholder="e.g. New York, NY" value="<?= htmlspecialchars($_POST['location'] ?? '') ?>">
                            </div>
                            <div class="field">
                                <label>Country <span class="req">*</span></label>
                                <input type="text" name="country" placeholder="e.g. USA" value="<?= htmlspecialchars($_POST['country'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Pricing -->
                    <div class="form-section">
                        <div class="form-section-title">Pricing</div>
                        <div class="field-row">
                            <div class="field">
                                <label>Starting bid <span class="req">*</span></label>
                                <div class="input-prefix">
                                    <span>$</span>
                                    <input type="number" name="start_price" placeholder="0.00" min="0.01" step="0.01" value="<?= htmlspecialchars($_POST['start_price'] ?? '') ?>">
                                </div>
                                <div class="field-hint">Lower starting bids attract more bidders.</div>
                            </div>
                            <div class="field">
                                <label>Buy It Now price <span class="opt">(optional)</span></label>
                                <div class="input-prefix">
                                    <span>$</span>
                                    <input type="number" name="buy_price" placeholder="0.00" min="0.01" step="0.01" value="<?= htmlspecialchars($_POST['buy_price'] ?? '') ?>">
                                </div>
                                <div class="field-hint">Must be higher than starting bid.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Duration -->
                    <div class="form-section">
                        <div class="form-section-title">Auction Duration</div>
                        <div class="duration-grid">
                            <?php foreach ([1,3,5,7,10,14] as $d): ?>
                                <div class="duration-opt">
                                    <input type="radio" name="duration" id="d<?= $d ?>" value="<?= $d ?>"
                                            <?= (($_POST['duration'] ?? '7') == $d) ? 'checked' : '' ?>>
                                    <label for="d<?= $d ?>">
                                        <?= $d ?>
                                        <span><?= $d === 1 ? 'day' : 'days' ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="field-hint" style="margin-top:10px">7-day auctions typically receive the most bids.</div>
                    </div>

                    <!-- Footer -->
                    <div class="form-footer">
                        <a href="index.php" class="btn-cancel">Cancel</a>
                        <button type="submit" class="btn-submit">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            List item now
                        </button>
                    </div>

                </div>

            </form>

            <!-- Tips Sidebar -->
            <div class="tips-card">
                <h3>💡 Tips for more bids</h3>
                <div class="tip">
                    <span class="tip-icon">📝</span>
                    <div><strong>Clear titles win.</strong> Include brand, model, year, and condition.</div>
                </div>
                <div class="tip">
                    <span class="tip-icon">💰</span>
                    <div><strong>Start low.</strong> A $0.99 starting bid creates urgency and competition.</div>
                </div>
                <div class="tip">
                    <span class="tip-icon">⏰</span>
                    <div><strong>7 days is the sweet spot.</strong> Long enough to get exposure, short enough to feel urgent.</div>
                </div>
                <div class="tip">
                    <span class="tip-icon">⚡</span>
                    <div><strong>Add Buy It Now.</strong> Some buyers prefer certainty — you can capture both audiences.</div>
                </div>
                <div class="tip">
                    <span class="tip-icon">📍</span>
                    <div><strong>Be specific about location.</strong> Local buyers may prefer pickup over shipping.</div>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

</body>
</html>