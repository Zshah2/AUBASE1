<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/backend/db.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id < 1) { header('Location: index.php'); exit; }

$logged_in        = isset($_SESSION['user_id'], $_SESSION['username']);
$session_user_id  = $logged_in ? (string) $_SESSION['user_id'] : '';
$session_username = $logged_in ? (string) $_SESSION['username'] : '';

$demo_ref_ts = strtotime(AUBASE_DEMO_NOW);

// ── Fetch item ──
$stmt = $conn->prepare(
        'SELECT i.item_id, i.name, i.description, i.location, i.country, i.seller_id,
            u.rating AS seller_rating,
            a.auction_id, a.starting_price, a.current_price, a.buy_price,
            a.num_bids, a.start_time, a.end_time
     FROM Item i
     JOIN Auction a ON i.item_id = a.item_id
     JOIN User u ON i.seller_id = u.user_id
     WHERE i.item_id = ?'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();

if (!$item) { http_response_code(404); }

$errors  = [];
$success = '';

if ($item) {
    $is_closed  = strtotime($item['end_time']) <= $demo_ref_ts;
    $has_buy    = $item['buy_price'] !== null && (float)$item['buy_price'] > 0;
    $img_seed   = abs($item['item_id']) % 1000;
    $min_bid    = (float)$item['current_price'] + 1.00;
    $is_seller  = $logged_in && $session_user_id === $item['seller_id'];

    // fetch categories
    $cat_stmt = $conn->prepare(
            'SELECT c.name FROM Item_Category ic
         JOIN Category c ON ic.category_id = c.category_id
         WHERE ic.item_id = ? ORDER BY c.name'
    );
    $cat_stmt->bind_param('i', $id);
    $cat_stmt->execute();
    $cat_rows = $cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // fetch last 5 bids
    $bid_stmt = $conn->prepare(
            'SELECT b.amount, b.bid_time, b.bidder_id FROM Bid b
         WHERE b.auction_id = ?
         ORDER BY b.amount DESC LIMIT 5'
    );
    $bid_stmt->bind_param('i', $item['auction_id']);
    $bid_stmt->execute();
    $bid_history = $bid_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // ── Handle POST ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_closed) {
        if (!$logged_in) {
            header('Location: login.php?redirect=item.php?id=' . $id);
            exit;
        }
        if ($is_seller) {
            $errors[] = 'You cannot bid on your own item.';
        } else {
            $action = $_POST['action'] ?? '';

            // PLACE BID
            if ($action === 'bid') {
                $bid_amount = (float)($_POST['bid_amount'] ?? 0);
                if ($bid_amount < $min_bid) {
                    $errors[] = 'Your bid must be at least $' . number_format($min_bid, 2) . '.';
                } else {
                    $conn->begin_transaction();
                    try {
                        // lock the row
                        $lock = $conn->prepare('SELECT current_price, num_bids FROM Auction WHERE auction_id = ? FOR UPDATE');
                        $lock->bind_param('i', $item['auction_id']);
                        $lock->execute();
                        $locked = $lock->get_result()->fetch_assoc();

                        if ($bid_amount <= (float)$locked['current_price']) {
                            throw new Exception('Someone just outbid you! Current price is $' . number_format((float)$locked['current_price'], 2) . '.');
                        }

                        $now = date('Y-m-d H:i:s');
                        $ins = $conn->prepare('INSERT INTO Bid (auction_id, bidder_id, amount, bid_time) VALUES (?,?,?,?)');
                        $ins->bind_param('isds', $item['auction_id'], $session_user_id, $bid_amount, $now);
                        if (!$ins->execute()) throw new Exception($ins->error);

                        $new_bids = (int)$locked['num_bids'] + 1;
                        $upd = $conn->prepare('UPDATE Auction SET current_price = ?, num_bids = ? WHERE auction_id = ?');
                        $upd->bind_param('dii', $bid_amount, $new_bids, $item['auction_id']);
                        if (!$upd->execute()) throw new Exception($upd->error);

                        $conn->commit();
                        $success = 'bid';
                        // refresh item
                        $item['current_price'] = $bid_amount;
                        $item['num_bids']      = $new_bids;
                        $min_bid               = $bid_amount + 1.00;
                    } catch (Exception $e) {
                        $conn->rollback();
                        $errors[] = $e->getMessage();
                    }
                }
            }

            // BUY IT NOW
            if ($action === 'buynow' && $has_buy) {
                $buy_price = (float)$item['buy_price'];
                $conn->begin_transaction();
                try {
                    $now = date('Y-m-d H:i:s');
                    $ins = $conn->prepare('INSERT INTO Bid (auction_id, bidder_id, amount, bid_time) VALUES (?,?,?,?)');
                    $ins->bind_param('isds', $item['auction_id'], $session_user_id, $buy_price, $now);
                    if (!$ins->execute()) throw new Exception($ins->error);

                    $new_bids = (int)$item['num_bids'] + 1;
                    $upd = $conn->prepare('UPDATE Auction SET current_price = ?, num_bids = ?, end_time = ? WHERE auction_id = ?');
                    $upd->bind_param('disi', $buy_price, $new_bids, $now, $item['auction_id']);
                    if (!$upd->execute()) throw new Exception($upd->error);

                    $conn->commit();
                    $success    = 'buynow';
                    $is_closed  = true;
                    $item['current_price'] = $buy_price;
                    $item['num_bids']      = $new_bids;
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = $e->getMessage();
                }
            }
        }
    }

    // time remaining
    $diff  = max(0, strtotime($item['end_time']) - $demo_ref_ts);
    $days  = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);
    $mins  = floor(($diff % 3600) / 60);
    if ($days > 0)       $time_str = "{$days}d {$hours}h remaining";
    elseif ($hours > 0)  $time_str = "{$hours}h {$mins}m remaining";
    elseif ($diff > 0)   $time_str = "{$mins}m remaining";
    else                 $time_str = "Auction ended";
    $is_ending = !$is_closed && $days === 0 && $hours < 2;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $item ? htmlspecialchars($item['name']) . ' — AuBase' : 'Not found — AuBase' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        :root{
            --ink:#0f172a;--ink-3:#334155;
            --mid:#64748b;--muted:#94a3b8;
            --border:#e2e8f0;--border-2:#cbd5e1;
            --bg:#f8fafc;--bg-2:#f1f5f9;--white:#ffffff;
            --gold:#f59e0b;--gold-dark:#d97706;--gold-light:#fffbeb;
            --navy:#0284c7;--navy-2:#0ea5e9;--navy-3:#38bdf8;
            --sky-wash:#e0f2fe;--sky-mist:#f0f9ff;
            --green:#10b981;--green-bg:#d1fae5;
            --red:#ef4444;--red-bg:#fee2e2;
            --radius-sm:6px;--radius:12px;--radius-lg:18px;
            --shadow-sm:0 1px 3px rgba(14,165,233,0.08);
            --shadow:0 4px 24px rgba(14,165,233,0.1);
            --shadow-lg:0 20px 50px rgba(14,165,233,0.12);
            --edge:clamp(16px,4vw,56px);
        }
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'DM Sans',system-ui,sans-serif;background:var(--bg);color:var(--ink);font-size:14px;-webkit-font-smoothing:antialiased}
        a{text-decoration:none;color:inherit}

        /* TOP BAR */
        .top-bar{background:linear-gradient(90deg,var(--sky-wash) 0%,var(--sky-mist) 100%);color:var(--ink-3);padding:7px var(--edge);display:flex;justify-content:space-between;align-items:center;font-size:11.5px;border-bottom:1px solid var(--border)}
        .top-bar a{color:var(--mid);transition:color 0.18s}.top-bar a:hover{color:var(--navy)}
        .top-bar .highlight{color:var(--gold-dark);font-weight:700}
        .top-bar-left,.top-bar-right{display:flex;gap:14px;align-items:center}
        .sep{opacity:0.2}

        /* NAVBAR */
        nav{background:var(--white);padding:0 var(--edge);display:flex;align-items:center;gap:16px;border-bottom:1px solid var(--border);height:64px;box-shadow:var(--shadow-sm);position:sticky;top:0;z-index:300}
        .logo{font-family:'DM Serif Display',serif;font-size:28px;letter-spacing:-1.5px;min-width:110px;line-height:1;flex-shrink:0}
        .logo span:nth-child(1){color:var(--red)}.logo span:nth-child(2){color:var(--gold)}.logo span:nth-child(3){color:var(--navy)}.logo span:nth-child(4){color:var(--ink-3)}
        .search-wrap{flex:1;max-width:540px;display:flex;align-items:center;border:1.5px solid var(--border-2);border-radius:50px;overflow:hidden;background:var(--bg)}
        .search-wrap:focus-within{border-color:var(--navy-2);box-shadow:0 0 0 3px rgba(14,165,233,0.2);background:var(--white)}
        .search-wrap input{flex:1;padding:10px 20px;border:none;background:transparent;font-size:14px;font-family:'DM Sans',sans-serif;outline:none;color:var(--ink)}
        .search-wrap input::placeholder{color:var(--muted)}
        .search-btn{padding:10px 22px;background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;border:none;font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;border-radius:0 50px 50px 0;box-shadow:0 2px 10px rgba(2,132,199,0.35)}
        .search-btn:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(2,132,199,0.45)}
        .nav-right{display:flex;align-items:center;gap:4px;margin-left:auto}
        .nav-icon{color:var(--ink-3);font-size:11px;font-weight:500;display:flex;flex-direction:column;align-items:center;gap:3px;padding:8px 14px;border-radius:var(--radius-sm);transition:all 0.18s;cursor:pointer}
        .nav-icon:hover{color:var(--navy);background:var(--bg)}
        .nav-icon svg{width:22px;height:22px;stroke:currentColor;stroke-width:1.5;fill:none}
        .nav-icon .label{font-size:10.5px;color:var(--muted);white-space:nowrap}

        /* PAGE */
        .wrap{max-width:1100px;margin:0 auto;padding:clamp(20px,3vw,36px) var(--edge) 60px}

        /* BREADCRUMB */
        .breadcrumb{display:flex;align-items:center;gap:6px;font-size:12.5px;color:var(--muted);margin-bottom:24px;flex-wrap:wrap}
        .breadcrumb a{color:var(--muted);transition:color 0.15s}.breadcrumb a:hover{color:var(--navy)}
        .breadcrumb .sep{opacity:0.4}

        /* MAIN GRID */
        .main-grid{display:grid;grid-template-columns:1fr 380px;gap:28px;align-items:start}

        /* IMAGE */
        .img-box{background:var(--bg-2);border-radius:var(--radius-lg);border:1px solid var(--border);overflow:hidden;aspect-ratio:4/3;position:relative}
        .img-box img{width:100%;height:100%;object-fit:cover;display:block}
        .img-badge{position:absolute;top:12px;left:12px;font-size:10px;padding:4px 11px;border-radius:50px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase}
        .badge-red{background:var(--red-bg);color:var(--red);border:1px solid #fecaca}
        .badge-green{background:var(--green-bg);color:var(--green);border:1px solid #a7f3d0}
        .badge-navy{background:var(--sky-wash);color:var(--navy);border:1px solid var(--navy-3)}
        .badge-gold{background:var(--gold-light);color:var(--gold-dark);border:1px solid #fcd34d}

        /* ITEM INFO (left col below image) */
        .item-info{margin-top:20px}
        .item-info h2{font-family:'DM Serif Display',serif;font-size:18px;font-weight:400;color:var(--ink);margin-bottom:10px}
        .desc-text{font-size:13.5px;line-height:1.7;color:var(--mid);white-space:pre-wrap}
        .cat-tags{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:14px}
        .cat-tag{background:var(--bg-2);color:var(--mid);font-size:11px;font-weight:500;padding:3px 10px;border-radius:50px;border:1px solid var(--border)}

        /* BID HISTORY */
        .bid-history{margin-top:24px;background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
        .bid-history-header{padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px;font-weight:600;color:var(--navy);display:flex;align-items:center;gap:7px}
        .bid-row{display:flex;justify-content:space-between;align-items:center;padding:11px 18px;border-bottom:1px solid var(--border);font-size:13px}
        .bid-row:last-child{border-bottom:none}
        .bid-row:first-of-type .bid-amount{color:var(--green);font-weight:700}
        .bid-user{color:var(--mid);font-size:12px}
        .bid-amount{font-weight:600;color:var(--ink)}
        .bid-time{color:var(--muted);font-size:11.5px}
        .no-bids{padding:20px 18px;text-align:center;color:var(--muted);font-size:13px}

        /* RIGHT PANEL */
        .panel{background:var(--white);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-sm);position:sticky;top:80px}
        .panel-head{padding:20px 22px;border-bottom:1px solid var(--border)}
        .item-title{font-family:'DM Serif Display',serif;font-size:20px;font-weight:400;color:var(--ink);line-height:1.3;margin-bottom:6px}
        .item-location{font-size:12.5px;color:var(--muted);display:flex;align-items:center;gap:4px;margin-bottom:12px}
        .seller-row{font-size:12.5px;color:var(--muted);display:flex;align-items:center;gap:5px}
        .seller-row strong{color:var(--navy)}

        /* PRICE BOX */
        .price-section{padding:18px 22px;border-bottom:1px solid var(--border);background:linear-gradient(135deg,var(--sky-wash) 0%,#fff 100%)}
        .current-label{font-size:11px;color:var(--muted);font-weight:600;letter-spacing:0.05em;text-transform:uppercase;margin-bottom:4px}
        .current-price{font-size:32px;font-weight:700;color:var(--ink);letter-spacing:-1px;line-height:1}
        .price-meta{display:flex;gap:14px;margin-top:8px;font-size:12px;color:var(--mid)}
        .time-badge{display:inline-flex;align-items:center;gap:5px;margin-top:12px;background:var(--bg-2);border:1px solid var(--border-2);border-radius:50px;padding:5px 12px;font-size:12px;color:var(--ink-3);font-weight:500}
        .time-badge.ending{background:var(--red-bg);border-color:#fecaca;color:var(--red);animation:pulse 1.2s infinite}
        .time-badge.closed-badge{background:var(--bg-2);color:var(--muted)}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:0.6}}

        /* BUY IT NOW STRIP */
        .buynow-strip{padding:12px 22px;border-bottom:1px solid var(--border);background:var(--green-bg);display:flex;align-items:center;justify-content:space-between}
        .buynow-strip .buy-label{font-size:12.5px;color:var(--green);font-weight:600}
        .buynow-strip .buy-price{font-size:18px;font-weight:700;color:var(--green)}

        /* BID FORM */
        .bid-section{padding:20px 22px}
        .alert{padding:12px 16px;border-radius:var(--radius-sm);font-size:13px;margin-bottom:16px;display:flex;align-items:flex-start;gap:8px}
        .alert-error{background:var(--red-bg);border:1px solid rgba(217,64,64,0.2);color:var(--red)}
        .alert-success{background:var(--green-bg);border:1px solid rgba(26,155,108,0.25);color:var(--green)}
        .alert ul{padding-left:14px;margin-top:4px}
        .bid-label{font-size:12px;font-weight:600;color:var(--ink-3);margin-bottom:6px;letter-spacing:0.01em}
        .bid-input-wrap{display:flex;border:1.5px solid var(--border-2);border-radius:var(--radius-sm);overflow:hidden;margin-bottom:10px;transition:border-color 0.18s,box-shadow 0.18s}
        .bid-input-wrap:focus-within{border-color:var(--navy-2);box-shadow:0 0 0 3px rgba(14,165,233,0.2)}
        .bid-prefix{padding:11px 13px;background:var(--bg-2);color:var(--mid);font-size:14px;font-weight:600;border-right:1px solid var(--border-2)}
        .bid-input{flex:1;padding:11px 14px;border:none;font-size:16px;font-weight:600;font-family:'DM Sans',sans-serif;outline:none;color:var(--ink);background:var(--white)}
        .bid-hint{font-size:11.5px;color:var(--muted);margin-bottom:16px}
        .btn-bid{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;padding:13px;border-radius:50px;font-size:14px;font-weight:600;font-family:'DM Sans',sans-serif;border:none;cursor:pointer;transition:all 0.2s;letter-spacing:0.01em;margin-bottom:8px;box-shadow:0 4px 16px rgba(2,132,199,0.35)}
        .btn-bid:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(2,132,199,0.45)}
        .btn-bid:disabled{opacity:0.45;cursor:not-allowed;transform:none;box-shadow:none}
        .btn-buynow{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;background:var(--green);color:#fff;padding:13px;border-radius:50px;font-size:14px;font-weight:600;font-family:'DM Sans',sans-serif;border:none;cursor:pointer;transition:all 0.2s;margin-bottom:8px}
        .btn-buynow:hover{background:#158a5c;transform:translateY(-1px)}
        .signin-prompt{text-align:center;padding:20px;font-size:13.5px;color:var(--mid)}
        .signin-prompt a{color:var(--navy);font-weight:600}
        .closed-msg{text-align:center;padding:24px 20px}
        .closed-msg h3{font-family:'DM Serif Display',serif;font-size:18px;font-weight:400;color:var(--ink);margin-bottom:6px}
        .closed-msg p{font-size:13px;color:var(--muted);line-height:1.6}
        .success-banner{background:var(--green-bg);border:1px solid rgba(26,155,108,0.25);border-radius:var(--radius-sm);padding:14px 16px;margin-bottom:16px;text-align:center}
        .success-banner h3{color:var(--green);font-size:15px;font-weight:700;margin-bottom:4px}
        .success-banner p{color:var(--green);font-size:13px;opacity:0.85}
        .guarantee{display:flex;align-items:center;gap:8px;font-size:11.5px;color:var(--muted);justify-content:center;margin-top:12px}
        .guarantee svg{width:14px;height:14px;stroke:var(--muted);fill:none;flex-shrink:0}

        @media(max-width:860px){
            .main-grid{grid-template-columns:1fr}
            .panel{position:static}
        }
    </style>
</head>
<body>

<!-- Top Bar -->
<div class="top-bar">
    <div class="top-bar-left">
        <?php if ($logged_in): ?>
            <span class="highlight">Hi, <?= htmlspecialchars($session_username) ?></span>
            <span class="sep">|</span>
            <a href="logout.php">Sign out</a>
        <?php else: ?>
            <a href="login.php?redirect=<?= rawurlencode('item.php?id=' . $id) ?>" class="highlight">Sign in</a>
            <span class="sep">or</span>
            <a href="register.php" class="highlight">Register</a>
        <?php endif; ?>
    </div>
    <div class="top-bar-right">
        <?php if ($logged_in): ?>
        <a href="account.php">Account</a>
        <span class="sep">|</span>
        <?php endif; ?>
        <a href="dashboard.php">My AuBase</a>
        <span class="sep">|</span>
        <a href="sell.php">Sell</a>
    </div>
</div>

<!-- Navbar -->
<nav>
    <a href="index.php" class="logo"><span>A</span><span>u</span><span>B</span><span>ase</span></a>
    <div class="search-wrap">
        <input type="text" placeholder="Search for anything…" onkeypress="if(event.key==='Enter')window.location='index.php?search='+encodeURIComponent(this.value)">
        <button class="search-btn" onclick="window.location='index.php'">Search</button>
    </div>
    <div class="nav-right">
        <a href="<?= $logged_in ? 'account.php' : ('login.php?redirect=' . rawurlencode('item.php?id=' . $id)) ?>" class="nav-icon">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            <span class="label"><?= $logged_in ? 'Account' : 'Sign in' ?></span>
        </a>
        <a href="dashboard.php" class="nav-icon">
            <svg viewBox="0 0 24 24"><path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
            <span class="label">Watchlist</span>
        </a>
        <a href="sell.php" class="nav-icon">
            <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
            <span class="label">Sell</span>
        </a>
    </div>
</nav>

<div class="wrap">

    <?php if (!$item): ?>
        <div style="text-align:center;padding:80px 20px;color:var(--muted)">
            <h1 style="font-family:'DM Serif Display',serif;font-size:28px;font-weight:400;margin-bottom:10px">Item not found</h1>
            <p>This listing may have been removed.</p>
            <a href="index.php" style="display:inline-block;margin-top:20px;color:var(--navy);font-weight:600">← Back to listings</a>
        </div>
    <?php else: ?>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="index.php">Home</a>
            <span class="sep">›</span>
            <?php if (!empty($cat_rows)): ?>
                <a href="index.php?category=<?= urlencode($cat_rows[0]['name']) ?>"><?= htmlspecialchars($cat_rows[0]['name']) ?></a>
                <span class="sep">›</span>
            <?php endif; ?>
            <span style="color:var(--ink)"><?= htmlspecialchars(mb_strimwidth($item['name'], 0, 50, '…')) ?></span>
        </div>

        <div class="main-grid">

            <!-- LEFT: image + description + bid history -->
            <div>
                <div class="img-box">
                    <img src="https://picsum.photos/seed/<?= $img_seed ?>/800/600" alt="<?= htmlspecialchars($item['name']) ?>">
                    <?php
                    if ($is_closed)          echo "<span class='img-badge badge-navy'>Closed</span>";
                    elseif ($item['num_bids'] > 10) echo "<span class='img-badge badge-red'>🔥 Hot</span>";
                    elseif ($has_buy)        echo "<span class='img-badge badge-green'>Buy Now</span>";
                    else                     echo "<span class='img-badge badge-gold'>Live</span>";
                    ?>
                </div>

                <div class="item-info">
                    <?php if (!empty($cat_rows)): ?>
                        <div class="cat-tags">
                            <?php foreach ($cat_rows as $cr): ?>
                                <span class="cat-tag"><?= htmlspecialchars($cr['name']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($item['description'])): ?>
                        <h2>About this item</h2>
                        <div class="desc-text" style="margin-top:10px"><?= nl2br(htmlspecialchars($item['description'])) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Bid History -->
                <div class="bid-history">
                    <div class="bid-history-header">
                        📋 Bid history
                        <span style="color:var(--muted);font-weight:400;font-size:12px"><?= (int)$item['num_bids'] ?> total bids</span>
                    </div>
                    <?php if (empty($bid_history)): ?>
                        <div class="no-bids">No bids yet — be the first!</div>
                    <?php else: ?>
                        <?php foreach ($bid_history as $i => $b): ?>
                            <div class="bid-row">
                                <div>
                                    <div class="bid-user"><?= htmlspecialchars(substr($b['bidder_id'], 0, 3) . str_repeat('*', max(0, strlen($b['bidder_id']) - 3))) ?></div>
                                </div>
                                <div class="bid-amount">$<?= number_format((float)$b['amount'], 2) ?></div>
                                <div class="bid-time"><?= date('M j, g:ia', strtotime($b['bid_time'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT: bid panel -->
            <div class="panel">

                <!-- Header -->
                <div class="panel-head">
                    <div class="item-title"><?= htmlspecialchars($item['name']) ?></div>
                    <div class="item-location">📍 <?= htmlspecialchars($item['location']) ?><?= $item['country'] ? ', ' . htmlspecialchars($item['country']) : '' ?></div>
                    <div class="seller-row">
                        Sold by <strong><?= htmlspecialchars($item['seller_id']) ?></strong>
                        &nbsp;⭐ <?= (int)$item['seller_rating'] ?>
                    </div>
                </div>

                <!-- Price -->
                <div class="price-section">
                    <div class="current-label">Current bid</div>
                    <div class="current-price">$<?= number_format((float)$item['current_price'], 2) ?></div>
                    <div class="price-meta">
                        <span>Starting: $<?= number_format((float)$item['starting_price'], 2) ?></span>
                        <span>·</span>
                        <span><?= (int)$item['num_bids'] ?> bids</span>
                    </div>
                    <div class="time-badge <?= $is_closed ? 'closed-badge' : ($is_ending ? 'ending' : '') ?>">
                        ⏱ <?= $time_str ?>
                    </div>
                </div>

                <!-- Buy It Now strip -->
                <?php if ($has_buy && !$is_closed): ?>
                    <div class="buynow-strip">
                        <span class="buy-label">⚡ Buy It Now available</span>
                        <span class="buy-price">$<?= number_format((float)$item['buy_price'], 2) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Bid Section -->
                <div class="bid-section">

                    <?php if ($success === 'bid'): ?>
                        <div class="success-banner">
                            <h3>🎉 Bid placed!</h3>
                            <p>You're the highest bidder at $<?= number_format((float)$item['current_price'], 2) ?></p>
                        </div>
                    <?php elseif ($success === 'buynow'): ?>
                        <div class="success-banner">
                            <h3>🎉 Item purchased!</h3>
                            <p>You bought this item for $<?= number_format((float)$item['current_price'], 2) ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-error">
                            <span>⚠</span>
                            <div>
                                <?php if (count($errors) === 1): ?>
                                    <?= htmlspecialchars($errors[0]) ?>
                                <?php else: ?>
                                    <ul><?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($is_closed): ?>
                        <div class="closed-msg">
                            <h3>Auction ended</h3>
                            <p>This auction is now closed. Browse other listings to find similar items.</p>
                            <a href="index.php" style="display:inline-block;margin-top:16px;background:var(--navy);color:#fff;padding:10px 24px;border-radius:50px;font-size:13.5px;font-weight:600">Browse listings</a>
                        </div>

                    <?php elseif ($is_seller): ?>
                        <div style="text-align:center;padding:16px 0;font-size:13.5px;color:var(--muted)">
                            This is your listing. You cannot bid on your own item.
                            <br><a href="dashboard.php" style="color:var(--navy);font-weight:600;margin-top:8px;display:inline-block">View in dashboard →</a>
                        </div>

                    <?php elseif (!$logged_in): ?>
                        <div class="signin-prompt">
                            <p style="margin-bottom:12px">Sign in to place a bid or buy this item.</p>
                            <a href="login.php?redirect=item.php?id=<?= $id ?>" style="display:flex;align-items:center;justify-content:center;gap:7px;background:var(--navy);color:#fff;padding:12px;border-radius:50px;font-size:14px;font-weight:600;margin-bottom:8px">Sign in to bid</a>
                            <a href="register.php" style="display:flex;align-items:center;justify-content:center;gap:7px;background:transparent;color:var(--mid);padding:12px;border-radius:50px;font-size:13.5px;font-weight:500;border:1.5px solid var(--border)">Create account</a>
                        </div>

                    <?php else: ?>
                        <!-- Place Bid Form -->
                        <form method="POST" action="item.php?id=<?= $id ?>">
                            <input type="hidden" name="action" value="bid">
                            <div class="bid-label">Your bid</div>
                            <div class="bid-input-wrap">
                                <span class="bid-prefix">$</span>
                                <input class="bid-input" type="number" name="bid_amount"
                                       min="<?= $min_bid ?>" step="0.01"
                                       placeholder="<?= number_format($min_bid, 2) ?>"
                                       value="<?= htmlspecialchars($_POST['bid_amount'] ?? '') ?>">
                            </div>
                            <div class="bid-hint">Minimum bid: <strong>$<?= number_format($min_bid, 2) ?></strong></div>
                            <button type="submit" class="btn-bid">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                                Place bid
                            </button>
                        </form>

                        <?php if ($has_buy): ?>
                            <form method="POST" action="item.php?id=<?= $id ?>">
                                <input type="hidden" name="action" value="buynow">
                                <button type="submit" class="btn-buynow">
                                    ⚡ Buy It Now — $<?= number_format((float)$item['buy_price'], 2) ?>
                                </button>
                            </form>
                        <?php endif; ?>

                        <div class="guarantee">
                            <svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            AuBase buyer protection on every bid
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

</body>
</html>
