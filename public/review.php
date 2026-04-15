<?php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . rawurlencode('review.php'));
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';

$userId = (string) $_SESSION['user_id'];
$orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
if ($orderId < 1) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

// Load order + auction + seller; require delivered
$stmt = $conn->prepare(
    "SELECT o.order_id, o.auction_id, o.delivery_confirmed,
            i.name AS item_name, i.seller_id
     FROM `Order` o
     JOIN Auction a ON o.auction_id = a.auction_id
     JOIN Item i ON a.item_id = i.item_id
     WHERE o.order_id = ? AND o.buyer_id = ?
     LIMIT 1"
);
$stmt->bind_param('is', $orderId, $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    http_response_code(404);
}

$existing = false;
if ($row) {
    $chk = $conn->prepare("SELECT 1 FROM Review WHERE reviewer_id = ? AND auction_id = ? LIMIT 1");
    $aid = (int) $row['auction_id'];
    $chk->bind_param('si', $userId, $aid);
    $chk->execute();
    $existing = $chk->get_result()->num_rows > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $row) {
    if (!csrf_verify()) {
        $error = 'Invalid session. Please refresh and try again.';
    } elseif ((int) ($row['delivery_confirmed'] ?? 0) !== 1) {
        $error = 'You can leave a review after confirming delivery.';
    } elseif ($existing) {
        $error = 'You already reviewed this seller for this auction.';
    } else {
        $rating = (int) ($_POST['rating'] ?? 0);
        $feedback = trim((string) ($_POST['feedback'] ?? ''));
        if ($rating < 1 || $rating > 5) {
            $error = 'Rating must be between 1 and 5.';
        } else {
            $sellerId = (string) $row['seller_id'];
            $aid = (int) $row['auction_id'];
            $ins = $conn->prepare("INSERT INTO Review (reviewer_id, seller_id, auction_id, rating, feedback) VALUES (?,?,?,?,?)");
            $ins->bind_param('sssis', $userId, $sellerId, $aid, $rating, $feedback);
            try {
                $conn->begin_transaction();
                $ins->execute();

                // Update seller rating as rounded average (stored as INT per schema)
                $upd = $conn->prepare(
                    "UPDATE User
                     SET rating = (
                        SELECT COALESCE(ROUND(AVG(rating)), 0) FROM Review WHERE seller_id = ?
                     )
                     WHERE user_id = ?"
                );
                $upd->bind_param('ss', $sellerId, $sellerId);
                $upd->execute();

                $conn->commit();
                header('Location: dashboard.php?msg=review_saved');
                exit;
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                $error = 'Could not save review.';
            }
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave a review — AuBase</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        :root{--ink:#0f172a;--mid:#64748b;--muted:#94a3b8;--border:#e2e8f0;--bg:#f8fafc;--white:#fff;--navy:#0284c7;--navy2:#0ea5e9;--red:#ef4444;--redbg:#fee2e2;--radius:12px;--edge:clamp(16px,4vw,56px)}
        *{box-sizing:border-box} body{margin:0;font-family:'DM Sans',system-ui,sans-serif;background:var(--bg);color:var(--ink)}
        header{background:var(--white);border-bottom:1px solid var(--border);padding:14px var(--edge);display:flex;justify-content:space-between;align-items:center}
        a{text-decoration:none;color:inherit}
        .wrap{max-width:760px;margin:0 auto;padding:22px var(--edge) 70px}
        .card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:18px}
        .err{background:var(--redbg);color:var(--red);border:1px solid #fecaca;border-radius:10px;padding:12px 14px;margin-bottom:14px}
        label{display:block;font-size:12.5px;font-weight:700;margin:12px 0 6px}
        select,textarea{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;font-family:inherit}
        textarea{min-height:120px;resize:vertical}
        .btn{display:inline-flex;align-items:center;justify-content:center;width:100%;margin-top:14px;padding:12px 14px;border:none;border-radius:999px;background:linear-gradient(135deg,var(--navy) 0%,var(--navy2) 100%);color:#fff;font-weight:800;cursor:pointer}
        .hint{color:var(--muted);font-size:13px;line-height:1.5}
    </style>
</head>
<body>
<header>
    <a href="dashboard.php" style="color:var(--mid)">← Back</a>
    <a href="index.php" style="font-family:'DM Serif Display',serif;font-size:24px;letter-spacing:-1px">
        <span style="color:#ef4444">A</span><span style="color:#f59e0b">u</span><span style="color:#0284c7">B</span><span style="color:#334155">ase</span>
    </a>
    <span></span>
</header>

<div class="wrap">
    <h1 style="font-family:'DM Serif Display',serif;font-weight:400;letter-spacing:-0.6px;margin:0 0 14px">Leave a review</h1>
    <?php if (!$row): ?>
        <div class="err">Order not found.</div>
    <?php else: ?>
        <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <div class="card">
            <p class="hint" style="margin-top:0">Item: <strong><?= htmlspecialchars((string) $row['item_name']) ?></strong></p>
            <p class="hint">Seller: <strong><?= htmlspecialchars((string) $row['seller_id']) ?></strong></p>
            <?php if ($existing): ?>
                <p class="hint">You already reviewed this auction.</p>
                <a class="btn" href="dashboard.php">Back to dashboard</a>
            <?php elseif ((int) ($row['delivery_confirmed'] ?? 0) !== 1): ?>
                <p class="hint">Confirm delivery in your dashboard before leaving a review.</p>
                <a class="btn" href="dashboard.php#panel-purchases">Go to purchases</a>
            <?php else: ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <label for="rating">Rating</label>
                    <select id="rating" name="rating" required>
                        <option value="">Select…</option>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <option value="<?= $i ?>"><?= $i ?> star<?= $i === 1 ? '' : 's' ?></option>
                        <?php endfor; ?>
                    </select>

                    <label for="feedback">Feedback (optional)</label>
                    <textarea id="feedback" name="feedback" maxlength="2000" placeholder="What went well? Was shipping fast? Condition as described?"></textarea>

                    <button class="btn" type="submit">Submit review</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>

