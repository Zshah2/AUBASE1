<?php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . rawurlencode('dashboard.php'));
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';

$name    = (string) ($_SESSION['username'] ?? 'Member');
$user_id = (string) $_SESSION['user_id'];
$uid_esc = $conn->real_escape_string($user_id);
$demo_ref_ts = defined('AUBASE_DEMO_NOW') ? strtotime(AUBASE_DEMO_NOW) : time();

// Withdraw bid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_bid_id'])) {
    if (!csrf_verify()) {
        header('Location: dashboard.php?msg=invalid_session');
        exit;
    }
    $bid_id = (int)$_POST['remove_bid_id'];
    $check  = $conn->prepare("SELECT auction_id FROM Bid WHERE bid_id = ? AND bidder_id = ?");
    $check->bind_param('is', $bid_id, $user_id);
    $check->execute();
    $bid_row = $check->get_result()->fetch_assoc();
    if ($bid_row) {
        $auction_id = (int)$bid_row['auction_id'];
        $del = $conn->prepare("DELETE FROM Bid WHERE bid_id = ? AND bidder_id = ?");
        $del->bind_param('is', $bid_id, $user_id);
        $del->execute();
        $recalc = $conn->prepare(
                "UPDATE Auction SET
                num_bids      = (SELECT COUNT(*) FROM Bid WHERE auction_id = ?),
                current_price = COALESCE((SELECT MAX(amount) FROM Bid WHERE auction_id = ?), starting_price)
             WHERE auction_id = ?"
        );
        $recalc->bind_param('iii', $auction_id, $auction_id, $auction_id);
        $recalc->execute();
    }
    header('Location: dashboard.php?msg=bid_removed');
    exit;
}

// Delete listing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_listing_id'])) {
    if (!csrf_verify()) {
        header('Location: dashboard.php?msg=invalid_session');
        exit;
    }
    $item_id = (int)$_POST['remove_listing_id'];
    $check = $conn->prepare("SELECT item_id FROM Item WHERE item_id = ? AND seller_id = ?");
    $check->bind_param('is', $item_id, $user_id);
    $check->execute();
    if ($check->get_result()->fetch_assoc()) {
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM Bid WHERE auction_id = (SELECT auction_id FROM Auction WHERE item_id = $item_id)");
            $conn->query("DELETE FROM Auction WHERE item_id = $item_id");
            $conn->query("DELETE FROM Item_Category WHERE item_id = $item_id");
            $conn->query("DELETE FROM Item WHERE item_id = $item_id AND seller_id = '$uid_esc'");
            $conn->commit();
        } catch (Exception $e) { $conn->rollback(); }
    }
    header('Location: dashboard.php?msg=listing_removed');
    exit;
}

$msg = $_GET['msg'] ?? '';

$total_bids = (int) $conn->query("SELECT COUNT(*) AS c FROM Bid WHERE bidder_id = '$uid_esc'")->fetch_assoc()['c'];
$total_items = (int) $conn->query("SELECT COUNT(*) AS c FROM Item WHERE seller_id = '$uid_esc'")->fetch_assoc()['c'];
$active_auctions = (int) $conn->query("SELECT COUNT(*) AS c FROM Auction a JOIN Item i ON a.item_id = i.item_id WHERE i.seller_id = '$uid_esc'")->fetch_assoc()['c'];

$recent_bids = $conn->query("
    SELECT b.bid_id, i.name, i.item_id, b.amount, b.bid_time, a.current_price, a.end_time
    FROM Bid b
    JOIN Auction a ON b.auction_id = a.auction_id
    JOIN Item i ON a.item_id = i.item_id
    WHERE b.bidder_id = '$uid_esc'
    ORDER BY b.bid_time DESC LIMIT 25
");

$my_listings = $conn->query("
    SELECT i.item_id, i.name, a.current_price, a.num_bids, a.end_time
    FROM Item i
    JOIN Auction a ON i.item_id = a.item_id
    WHERE i.seller_id = '$uid_esc'
    ORDER BY a.num_bids DESC LIMIT 25
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My AuBase — Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        :root{--ink:#0f172a;--ink-3:#334155;--mid:#64748b;--muted:#94a3b8;--border:#e2e8f0;--border-2:#cbd5e1;--bg:#f8fafc;--bg-2:#f1f5f9;--white:#ffffff;--gold:#f59e0b;--gold-dark:#d97706;--gold-light:#fffbeb;--navy:#0284c7;--navy-2:#0ea5e9;--navy-3:#38bdf8;--sky-wash:#e0f2fe;--sky-mist:#f0f9ff;--green:#10b981;--green-bg:#d1fae5;--red:#ef4444;--red-bg:#fee2e2;--radius:12px;--radius-sm:6px;--shadow-sm:0 1px 3px rgba(14,165,233,0.08);--shadow:0 4px 24px rgba(14,165,233,0.1);--page-max:1200px;--edge:clamp(16px,4vw,48px)}
        *{margin:0;padding:0;box-sizing:border-box}html{scroll-behavior:smooth}
        body{font-family:'DM Sans',system-ui,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;-webkit-font-smoothing:antialiased}
        a{text-decoration:none;color:inherit}
        .topbar{background:linear-gradient(90deg,var(--sky-wash) 0%,var(--sky-mist) 100%);padding:7px var(--edge);display:flex;justify-content:space-between;align-items:center;font-size:12px;border-bottom:1px solid var(--border)}
        .topbar a{color:var(--mid);transition:color 0.18s}.topbar a:hover{color:var(--navy)}
        .topbar .hi{color:var(--gold-dark);font-weight:700}
        .topbar-right{display:flex;gap:14px}
        header{background:var(--white);border-bottom:1px solid var(--border);padding:0 var(--edge);display:flex;justify-content:space-between;align-items:center;gap:16px;position:sticky;top:0;z-index:100;box-shadow:var(--shadow-sm);height:64px}
        .logo{font-family:'DM Serif Display',serif;font-size:28px;letter-spacing:-1.5px;line-height:1}
        .logo span:nth-child(1){color:var(--red)}.logo span:nth-child(2){color:var(--gold)}.logo span:nth-child(3){color:var(--navy)}.logo span:nth-child(4){color:var(--ink-3)}
        .nav-links{display:flex;gap:6px;align-items:center}
        .nav-links a{font-size:13px;font-weight:500;color:var(--mid);padding:7px 16px;border-radius:50px;transition:all 0.18s}
        .nav-links a:hover{background:var(--bg-2);color:var(--navy)}.nav-links a.active{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;box-shadow:0 2px 10px rgba(2,132,199,0.3)}
        .nav-links a.danger{color:var(--red)}.nav-links a.danger:hover{background:var(--red);color:#fff}
        .dash-hero{background:linear-gradient(145deg,var(--sky-wash) 0%,var(--sky-mist) 45%,#fff 100%);padding:clamp(32px,4vw,52px) var(--edge);position:relative;overflow:hidden;border-bottom:1px solid var(--border)}
        .dash-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 70% 90% at 90% 50%,rgba(14,165,233,0.12) 0%,transparent 70%);pointer-events:none}
        .dash-hero::after{content:'';position:absolute;right:-40px;top:-40px;width:340px;height:340px;border-radius:50%;border:1px solid rgba(56,189,248,0.25);pointer-events:none}
        .dash-hero-inner{position:relative;z-index:1;max-width:var(--page-max);margin:0 auto;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:24px}
        .dash-eyebrow{display:inline-flex;align-items:center;background:var(--gold-light);border:1px solid #fde68a;color:var(--gold-dark);font-size:11px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;padding:4px 12px;border-radius:50px;margin-bottom:12px}
        .dash-hero h1{font-family:'DM Serif Display',serif;font-size:clamp(24px,2.5vw+1rem,38px);font-weight:400;color:var(--ink);letter-spacing:-1px;line-height:1.1;margin-bottom:6px}
        .dash-hero h1 em{color:var(--navy);font-style:italic}
        .dash-hero p{color:var(--mid);font-size:14px;font-weight:400;max-width:42ch;line-height:1.55}
        .dash-hero-actions{display:flex;gap:10px;flex-wrap:wrap}
        .btn-gold{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;padding:10px 22px;border-radius:50px;font-size:13.5px;font-weight:600;transition:all 0.2s;box-shadow:0 4px 14px rgba(2,132,199,0.35)}
        .btn-gold:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(2,132,199,0.45)}
        .btn-ghost{display:inline-flex;align-items:center;gap:6px;background:var(--white);color:var(--ink-3);padding:10px 18px;border-radius:50px;font-size:13.5px;font-weight:500;border:1.5px solid var(--border-2);transition:all 0.2s;box-shadow:var(--shadow-sm)}
        .btn-ghost:hover{border-color:var(--navy-2);color:var(--navy);background:var(--sky-mist)}
        .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:clamp(10px,2vw,16px);max-width:var(--page-max);margin:clamp(20px,3vw,28px) auto 0;padding:0 var(--edge)}
        a.stat-card{text-decoration:none;color:inherit}
        .stat-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:18px 22px;display:flex;align-items:center;gap:14px;transition:box-shadow 0.2s,transform 0.2s}
        .stat-card:hover,.stat-card:focus-visible{box-shadow:var(--shadow);transform:translateY(-2px);outline:none}
        .stat-card:focus-visible{box-shadow:var(--shadow),0 0 0 3px rgba(14,165,233,0.35)}
        .stat-icon{width:42px;height:42px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
        .stat-icon.gold{background:var(--gold-light)}.stat-icon.navy{background:var(--sky-wash)}.stat-icon.green{background:var(--green-bg)}
        .stat-num{font-family:'DM Serif Display',serif;font-size:26px;font-weight:400;color:var(--navy);letter-spacing:-1px;line-height:1}
        .stat-label{font-size:11px;color:var(--muted);font-weight:500;letter-spacing:0.04em;text-transform:uppercase;margin-top:2px}
        .toast{max-width:var(--page-max);margin:16px auto 0;padding:0 var(--edge);transition:opacity .4s ease,transform .4s ease,margin .4s ease}
        .toast.toast-hiding{opacity:0;transform:translateY(-6px);margin-top:0;margin-bottom:0;pointer-events:none}
        .toast-inner{background:var(--green-bg);border:1px solid #a7f3d0;color:var(--green);border-radius:var(--radius-sm);padding:11px 16px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:8px}
        .toast-inner.warn{background:var(--red-bg);border-color:#fecaca;color:var(--red)}
        .dash-body{max-width:var(--page-max);margin:clamp(16px,2.5vw,28px) auto clamp(40px,5vw,64px);padding:0 var(--edge);display:grid;grid-template-columns:1fr 1fr;gap:clamp(14px,2vw,22px)}
        .panel{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;scroll-margin-top:88px}
        .panel-head{padding:15px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
        .panel-head h2{font-family:'DM Serif Display',serif;font-size:18px;font-weight:400;color:var(--ink);letter-spacing:-0.35px}
        .panel-head a{font-size:12px;color:var(--gold-dark);font-weight:600;transition:color 0.18s}.panel-head a:hover{color:var(--gold)}
        .row-item{display:flex;align-items:stretch;gap:0;border-bottom:1px solid var(--border);position:relative;min-height:64px;transition:background .12s ease}
        .row-item:last-child{border-bottom:none}
        .row-item:hover .row-actions{opacity:1;pointer-events:auto}
        .row-hit{flex:1;display:flex;align-items:center;gap:12px;min-width:0;padding:12px 16px 12px 20px;text-decoration:none;color:inherit;transition:background 0.15s;border-radius:0}
        .row-hit:hover,.row-hit:focus-visible{background:var(--bg);outline:none}
        .row-hit:focus-visible{box-shadow:inset 0 0 0 2px var(--navy-3)}
        .row-thumb{width:40px;height:40px;border-radius:8px;overflow:hidden;flex-shrink:0;background:var(--bg-2)}
        .row-thumb img{width:100%;height:100%;object-fit:cover}
        .row-info{flex:1;min-width:0}
        .row-name{font-size:13px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px;display:block}
        .row-hit:hover .row-name,.row-hit:focus-visible .row-name{color:var(--navy)}
        .row-meta{display:block;font-size:11px;color:var(--muted);line-height:1.45}
        .row-right{text-align:right;flex-shrink:0;flex-direction:column;display:flex;align-items:flex-end;justify-content:center;gap:2px;margin-right:8px}
        .row-price{font-size:13.5px;font-weight:700;color:var(--navy);font-family:'DM Serif Display',serif;letter-spacing:-0.3px}
        .row-sub{font-size:10px;color:var(--muted)}
        .row-go{font-size:10px;font-weight:600;color:var(--navy);letter-spacing:0.02em;margin-top:2px}
        .is-winning{color:var(--green)!important;font-weight:600}
        .is-outbid{color:var(--red)!important;font-weight:600}
        .row-actions{display:flex;align-items:center;padding:8px 14px 8px 8px;opacity:0;pointer-events:none;transition:opacity 0.18s;flex-shrink:0}
        .btn-remove{background:var(--red-bg);color:var(--red);border:1px solid #fecaca;border-radius:50px;padding:5px 12px;font-size:11.5px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all 0.18s;white-space:nowrap}
        .btn-remove:hover{background:var(--red);color:#fff}
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,0.35);z-index:1000;align-items:center;justify-content:center;backdrop-filter:blur(6px)}
        .modal-overlay.open{display:flex}
        .modal{background:var(--white);border-radius:18px;padding:28px;max-width:360px;width:90%;box-shadow:0 20px 60px rgba(13,15,20,0.2);text-align:center}
        .modal-icon{font-size:36px;margin-bottom:14px}
        .modal h3{font-family:'DM Serif Display',serif;font-size:20px;font-weight:400;color:var(--ink);margin-bottom:8px}
        .modal p{font-size:13.5px;color:var(--muted);line-height:1.6;margin-bottom:10px}
        .modal-body-text{margin-bottom:8px}
        .modal-item-name{font-weight:600;color:var(--ink);font-size:14px;margin-bottom:22px;line-height:1.4;word-break:break-word}
        .modal-actions{display:flex;gap:10px;justify-content:center}
        .modal-cancel{padding:10px 22px;border-radius:50px;border:1.5px solid var(--border-2);background:var(--white);color:var(--mid);font-size:13.5px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all 0.18s}
        .modal-cancel:hover{color:var(--navy);border-color:var(--navy)}
        .modal-confirm{padding:10px 22px;border-radius:50px;border:none;background:var(--red);color:#fff;font-size:13.5px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all 0.18s}
        .modal-confirm:hover{background:#c03030}
        .empty{text-align:center;padding:44px 24px 48px;color:var(--muted)}
        .empty-icon{font-size:32px;margin-bottom:10px;opacity:0.4}
        .empty h3{font-family:'DM Serif Display',serif;font-size:16px;font-weight:400;color:var(--ink-3);margin-bottom:5px}
        .empty p{font-size:13px;line-height:1.6}.empty a{color:var(--gold-dark);font-weight:600}
        .quick-links{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--border)}
        .ql-item{background:var(--white);padding:18px 22px;display:flex;align-items:center;gap:14px;transition:background 0.15s;border-radius:0}
        .ql-item:hover{background:var(--bg)}
        .ql-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
        .ql-icon.gold{background:var(--gold-light)}.ql-icon.navy{background:var(--sky-wash)}.ql-icon.green{background:var(--green-bg)}.ql-icon.red{background:var(--red-bg)}
        .ql-title{font-size:13px;font-weight:600;color:var(--ink);margin-bottom:1px}
        .ql-desc{font-size:11px;color:var(--muted)}
        footer{background:linear-gradient(180deg,var(--sky-mist) 0%,var(--bg-2) 100%);color:var(--muted);text-align:center;padding:18px var(--edge);font-size:12px;border-top:1px solid var(--border)}
        footer a{color:var(--mid);margin:0 10px;transition:color 0.18s}footer a:hover{color:var(--navy)}
        @media(max-width:900px){.quick-links{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:700px){.dash-body{grid-template-columns:1fr}.stats-row{grid-template-columns:1fr 1fr}.row-hit{padding-right:12px}.row-right{margin-right:0}.row-actions{opacity:1;pointer-events:auto;padding:8px 12px}}
        @media(max-width:480px){.quick-links{grid-template-columns:1fr}}
    </style>
</head>
<body>

<div class="topbar">
    <span class="hi">Hi, <?= htmlspecialchars($name) ?> 👋</span>
    <div class="topbar-right">
        <a href="index.php">Browse</a>
        <a href="account.php">Account</a>
        <a href="sell.php">Sell</a>
        <a href="logout.php">Sign out</a>
    </div>
</div>

<header>
    <a href="index.php" class="logo"><span>A</span><span>u</span><span>B</span><span>ase</span></a>
    <div class="nav-links">
        <a href="index.php">Auctions</a>
        <a href="dashboard.php" class="active">Dashboard</a>
        <a href="account.php">Account</a>
        <a href="sell.php">Sell</a>
        <a href="logout.php" class="danger">Sign out</a>
    </div>
</header>

<div class="dash-hero">
    <div class="dash-hero-inner">
        <div>
            <div class="dash-eyebrow">✦ My Account</div>
            <h1>Welcome back,<br><em><?= htmlspecialchars($name) ?></em></h1>
            <p>Manage your bids, listings and watchlist all in one place.</p>
        </div>
        <div class="dash-hero-actions">
            <a href="index.php" class="btn-gold">Browse auctions →</a>
            <a href="account.php" class="btn-ghost">Account settings</a>
            <a href="sell.php" class="btn-ghost">+ List an item</a>
        </div>
    </div>
</div>

<div class="stats-row">
    <a href="#panel-bids" class="stat-card"><div class="stat-icon gold">🏷️</div><div><div class="stat-num"><?= $total_bids ?></div><div class="stat-label">Bids placed</div></div></a>
    <a href="#panel-listings" class="stat-card"><div class="stat-icon navy">📦</div><div><div class="stat-num"><?= $total_items ?></div><div class="stat-label">Items listed</div></div></a>
    <a href="#panel-listings" class="stat-card"><div class="stat-icon green">⚡</div><div><div class="stat-num"><?= $active_auctions ?></div><div class="stat-label">Active auctions</div></div></a>
</div>

<?php if ($msg): ?>
    <div class="toast" id="dash-toast" role="status" aria-live="polite">
        <div class="toast-inner <?= in_array($msg, ['listing_removed', 'invalid_session'], true) ? 'warn' : '' ?>">
            <?php
            if ($msg === 'bid_removed') {
                echo 'Your bid has been removed. The auction price was updated. You cannot restore this bid.';
            } elseif ($msg === 'listing_removed') {
                echo 'Your listing has been permanently removed along with its bids. You cannot reclaim it or undo this.';
            } elseif ($msg === 'invalid_session') {
                echo 'That action could not be completed. Refresh the page and try again.';
            } else {
                echo htmlspecialchars($msg);
            }
            ?>
        </div>
    </div>
<?php endif; ?>

<div class="dash-body">

    <!-- My Bids -->
    <div class="panel" id="panel-bids">
        <div class="panel-head">
            <h2>My Bids</h2>
            <a href="index.php?tab=open">Browse more →</a>
        </div>
        <div class="panel-body">
            <?php if ($recent_bids && $recent_bids->num_rows > 0): ?>
                <?php while ($bid = $recent_bids->fetch_assoc()):
                    $seed      = abs((int)$bid['item_id']) % 1000;
                    $my_bid    = '$' . number_format((float)$bid['amount'], 2);
                    $current   = '$' . number_format((float)$bid['current_price'], 2);
                    $is_closed = strtotime($bid['end_time']) <= $demo_ref_ts;
                    $winning   = (float)$bid['amount'] >= (float)$bid['current_price'];
                    $date      = date('M j', strtotime($bid['bid_time']));
                    ?>
                    <div class="row-item">
                        <a class="row-hit" href="item.php?id=<?= (int) $bid['item_id'] ?>">
                            <span class="row-thumb"><img src="https://picsum.photos/seed/<?= $seed ?>/80/80" alt=""></span>
                            <span class="row-info">
                                <span class="row-name"><?= htmlspecialchars($bid['name']) ?></span>
                                <span class="row-meta">
                                    Your bid: <strong><?= $my_bid ?></strong> · <?= $date ?> ·
                                    <?php if ($is_closed): ?>
                                        <span style="color:var(--muted)">Closed</span>
                                    <?php elseif ($winning): ?>
                                        <span class="is-winning">● Winning</span>
                                    <?php else: ?>
                                        <span class="is-outbid">● Outbid</span>
                                    <?php endif; ?>
                                </span>
                            </span>
                            <span class="row-right">
                                <span class="row-price"><?= $current ?></span>
                                <span class="row-sub">Current bid</span>
                                <span class="row-go">View listing →</span>
                            </span>
                        </a>
                        <?php if (!$is_closed): ?>
                            <div class="row-actions">
                                <button type="button" class="btn-remove"
                                        onclick="openModal('bid', <?= (int) $bid['bid_id'] ?>, '<?= htmlspecialchars(addslashes($bid['name'])) ?>')">
                                    Withdraw
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-icon">🏷️</div>
                    <h3>No bids yet</h3>
                    <p>Find something you love and<br><a href="index.php">place your first bid →</a></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- My Listings -->
    <div class="panel" id="panel-listings">
        <div class="panel-head">
            <h2>My Listings</h2>
            <a href="sell.php">+ New listing</a>
        </div>
        <div class="panel-body">
            <?php if ($my_listings && $my_listings->num_rows > 0): ?>
                <?php while ($listing = $my_listings->fetch_assoc()):
                    $seed      = abs((int)$listing['item_id']) % 1000;
                    $price     = '$' . number_format((float)$listing['current_price'], 2);
                    $is_closed = strtotime($listing['end_time']) <= $demo_ref_ts;
                    ?>
                    <div class="row-item">
                        <a class="row-hit" href="item.php?id=<?= (int) $listing['item_id'] ?>">
                            <span class="row-thumb"><img src="https://picsum.photos/seed/<?= $seed ?>/80/80" alt=""></span>
                            <span class="row-info">
                                <span class="row-name"><?= htmlspecialchars($listing['name']) ?></span>
                                <span class="row-meta">
                                    <?= (int) $listing['num_bids'] ?> bids ·
                                    <?= $is_closed ? '<span style="color:var(--muted)">Closed</span>' : '<span class="is-winning">● Live</span>' ?>
                                </span>
                            </span>
                            <span class="row-right">
                                <span class="row-price"><?= $price ?></span>
                                <span class="row-sub">Current bid</span>
                                <span class="row-go">View listing →</span>
                            </span>
                        </a>
                        <div class="row-actions">
                            <button type="button" class="btn-remove"
                                    onclick="openModal('listing', <?= (int) $listing['item_id'] ?>, '<?= htmlspecialchars(addslashes($listing['name'])) ?>')">
                                Remove
                            </button>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-icon">📦</div>
                    <h3>No listings yet</h3>
                    <p>Start selling today —<br><a href="sell.php">list your first item →</a></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="panel" style="grid-column:1/-1">
        <div class="panel-head"><h2>Quick Actions</h2></div>
        <div class="quick-links">
            <a href="account.php" class="ql-item"><div class="ql-icon navy">⚙️</div><div><div class="ql-title">Account settings</div><div class="ql-desc">Profile, email, password</div></div></a>
            <a href="#panel-bids" class="ql-item"><div class="ql-icon gold">🏷️</div><div><div class="ql-title">My bids</div><div class="ql-desc">Jump to auctions you’re on</div></div></a>
            <a href="#panel-listings" class="ql-item"><div class="ql-icon navy">📦</div><div><div class="ql-title">My listings</div><div class="ql-desc">What you’re selling</div></div></a>
            <a href="index.php?tab=open" class="ql-item"><div class="ql-icon gold">🔥</div><div><div class="ql-title">Open Auctions</div><div class="ql-desc">Browse live bidding</div></div></a>
            <a href="index.php?tab=buynow" class="ql-item"><div class="ql-icon green">⚡</div><div><div class="ql-title">Buy It Now</div><div class="ql-desc">Skip the bidding</div></div></a>
            <a href="sell.php" class="ql-item"><div class="ql-icon navy">📝</div><div><div class="ql-title">Sell an Item</div><div class="ql-desc">List something new</div></div></a>
            <a href="logout.php" class="ql-item"><div class="ql-icon red">🚪</div><div><div class="ql-title">Sign Out</div><div class="ql-desc">See you next time</div></div></a>
        </div>
    </div>

</div>

<!-- Confirm Modal -->
<div class="modal-overlay" id="modal">
    <div class="modal">
        <div class="modal-icon" id="modal-icon">⚠️</div>
        <h3 id="modal-title"></h3>
        <p id="modal-desc" class="modal-body-text"></p>
        <p id="modal-item-name" class="modal-item-name"></p>
        <div class="modal-actions">
            <button type="button" class="modal-cancel" onclick="closeModal()">Cancel</button>
            <form id="modal-form" method="POST" action="dashboard.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" id="modal-input" name="" value="">
                <button type="submit" class="modal-confirm" id="modal-btn">Confirm</button>
            </form>
        </div>
    </div>
</div>

<footer>
    <a href="index.php">Home</a>
    <a href="sell.php">Sell</a>
    <a href="logout.php">Sign out</a>
    <br><br>&copy; 2026 AuBase Inc.
</footer>

<script>
    function openModal(type, id, name) {
        const isBid = type === 'bid';
        document.getElementById('modal-icon').textContent  = isBid ? '🏷️' : '🗑️';
        document.getElementById('modal-title').textContent = isBid ? 'Withdraw this bid?' : 'Remove this listing?';
        document.getElementById('modal-desc').textContent = isBid
            ? 'If you continue, your bid will be deleted and the current auction price will be recalculated. You cannot put this exact bid back.'
            : 'If you continue, this listing and all bids on it will be permanently deleted. You will not be able to recover it.';
        document.getElementById('modal-item-name').textContent = name;
        document.getElementById('modal-input').name  = isBid ? 'remove_bid_id' : 'remove_listing_id';
        document.getElementById('modal-input').value = String(id);
        document.getElementById('modal-btn').textContent = isBid ? 'Yes, withdraw bid' : 'Yes, remove listing';
        document.getElementById('modal').classList.add('open');
    }
    function closeModal() {
        document.getElementById('modal').classList.remove('open');
    }
    document.getElementById('modal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    (function () {
        var toast = document.getElementById('dash-toast');
        if (!toast) return;
        var ms = <?= in_array($msg ?? '', ['listing_removed', 'bid_removed', 'invalid_session'], true) ? 10000 : 8000 ?>;
        setTimeout(function () {
            toast.classList.add('toast-hiding');
            setTimeout(function () { toast.remove(); }, 450);
        }, ms);
    })();
</script>
</body>
</html>