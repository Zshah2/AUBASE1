<?php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . rawurlencode('checkout.php'));
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../backend/time.php';

$userId = (string) $_SESSION['user_id'];
$nowTs  = aubase_now_ts($conn);
$nowSql = date('Y-m-d H:i:s', $nowTs);

$itemId = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;
if ($itemId < 1) {
    header('Location: index.php');
    exit;
}

// Load auction + item + seller
$stmt = $conn->prepare(
    "SELECT a.auction_id, a.item_id, a.current_price, a.end_time,
            i.name AS item_name, i.seller_id
     FROM Auction a
     JOIN Item i ON a.item_id = i.item_id
     WHERE a.item_id = ?"
);
$stmt->bind_param('i', $itemId);
$stmt->execute();
$auction = $stmt->get_result()->fetch_assoc();
if (!$auction) {
    http_response_code(404);
}

$error = '';
$ok = '';
$orderId = null;
$winnerId = '';
$isClosed = false;
$shippingOptions = [];
$cards = [];
$shipForm = [
    'ship_to_name' => '',
    'ship_to_line1' => '',
    'ship_to_line2' => '',
    'ship_to_city' => '',
    'ship_to_region' => '',
    'ship_to_postal' => '',
    'ship_to_country' => '',
];

if ($auction) {
    $isClosed = strtotime((string) $auction['end_time']) <= $nowTs;
    if (!$isClosed) {
        $error = 'This auction is still open. Checkout is available after it closes.';
    } else {
        // Winner is highest bid
        $w = $conn->prepare("SELECT bidder_id, amount FROM Bid WHERE auction_id = ? ORDER BY amount DESC, bid_time ASC LIMIT 1");
        $aid = (int) $auction['auction_id'];
        $w->bind_param('i', $aid);
        $w->execute();
        $wr = $w->get_result()->fetch_assoc();
        $winnerId = $wr ? (string) $wr['bidder_id'] : '';
        $winningBid = $wr ? (float) $wr['amount'] : 0.0;

        if ($winnerId === '') {
            $error = 'No bids were placed on this auction, so there is no checkout.';
        } elseif ($winnerId !== $userId) {
            $error = 'Only the winning bidder can check out this auction.';
        } else {
            $uprof = $conn->prepare('SELECT first_name, last_name, address FROM User WHERE user_id = ? LIMIT 1');
            $uprof->bind_param('s', $userId);
            $uprof->execute();
            $ur = $uprof->get_result()->fetch_assoc();
            if ($ur) {
                $fn = trim((string) ($ur['first_name'] ?? ''));
                $ln = trim((string) ($ur['last_name'] ?? ''));
                $shipForm['ship_to_name'] = trim($fn . ' ' . $ln);
                $shipForm['ship_to_line1'] = trim((string) ($ur['address'] ?? ''));
            }

            // Must have a valid card
            $cardStmt = $conn->prepare("SELECT card_id, card_number, expiration_date FROM CreditCard WHERE user_id = ? AND expiration_date >= CURDATE() ORDER BY card_id DESC LIMIT 5");
            $cardStmt->bind_param('s', $userId);
            $cardStmt->execute();
            $cards = $cardStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            if (!$cards) {
                $error = 'Add a valid credit card in Account settings before checkout.';
            }

            // Shipping options for this auction
            $shipStmt = $conn->prepare("SELECT shipping_option_id, method, price, estimated_days, is_pickup FROM ShippingOption WHERE auction_id = ? ORDER BY is_pickup DESC, price ASC");
            $shipStmt->bind_param('i', $aid);
            $shipStmt->execute();
            $shippingOptions = $shipStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            if (!$shippingOptions) {
                $error = 'This listing has no shipping options configured.';
            }

            // Existing order?
            $ordChk = $conn->prepare("SELECT order_id, payment_status, tracking_number, delivery_confirmed FROM `Order` WHERE auction_id = ? LIMIT 1");
            $ordChk->bind_param('i', $aid);
            $ordChk->execute();
            $ordRow = $ordChk->get_result()->fetch_assoc();
            if ($ordRow) {
                $orderId = (int) $ordRow['order_id'];
                $ok = 'Checkout already completed for this auction.';
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && $orderId === null) {
                if (!csrf_verify()) {
                    $error = 'Invalid session. Please refresh and try again.';
                } else {
                    $shipForm = [
                        'ship_to_name' => trim((string) ($_POST['ship_to_name'] ?? '')),
                        'ship_to_line1' => trim((string) ($_POST['ship_to_line1'] ?? '')),
                        'ship_to_line2' => trim((string) ($_POST['ship_to_line2'] ?? '')),
                        'ship_to_city' => trim((string) ($_POST['ship_to_city'] ?? '')),
                        'ship_to_region' => trim((string) ($_POST['ship_to_region'] ?? '')),
                        'ship_to_postal' => trim((string) ($_POST['ship_to_postal'] ?? '')),
                        'ship_to_country' => trim((string) ($_POST['ship_to_country'] ?? '')),
                    ];

                    $shipId = (int) ($_POST['shipping_option_id'] ?? 0);
                    $cardId = (int) ($_POST['card_id'] ?? 0);
                    if ($shipId < 1 || $cardId < 1) {
                        $error = 'Please choose a shipping option and a card.';
                    } else {
                        // Validate ownership / association
                        $s2 = $conn->prepare("SELECT price, is_pickup FROM ShippingOption WHERE shipping_option_id = ? AND auction_id = ? LIMIT 1");
                        $s2->bind_param('ii', $shipId, $aid);
                        $s2->execute();
                        $srow = $s2->get_result()->fetch_assoc();

                        $c2 = $conn->prepare("SELECT 1 FROM CreditCard WHERE card_id = ? AND user_id = ? AND expiration_date >= CURDATE() LIMIT 1");
                        $c2->bind_param('is', $cardId, $userId);
                        $c2->execute();
                        $cardOk = $c2->get_result()->num_rows > 0;

                        if (!$srow) {
                            $error = 'Invalid shipping option.';
                        } elseif (!$cardOk) {
                            $error = 'Invalid card selection.';
                        } else {
                            $isPickup = (int) ($srow['is_pickup'] ?? 0) === 1;
                            if (!$isPickup) {
                                if ($shipForm['ship_to_name'] === '') {
                                    $error = 'Enter the full name for the shipping label.';
                                } elseif ($shipForm['ship_to_line1'] === '') {
                                    $error = 'Enter a street address.';
                                } elseif ($shipForm['ship_to_city'] === '') {
                                    $error = 'Enter the city.';
                                } elseif ($shipForm['ship_to_postal'] === '') {
                                    $error = 'Enter the postal / ZIP code.';
                                } elseif ($shipForm['ship_to_country'] === '') {
                                    $error = 'Enter the country.';
                                } elseif (mb_strlen($shipForm['ship_to_name']) > 150
                                    || mb_strlen($shipForm['ship_to_line1']) > 255
                                    || mb_strlen($shipForm['ship_to_line2']) > 255
                                    || mb_strlen($shipForm['ship_to_city']) > 100
                                    || mb_strlen($shipForm['ship_to_region']) > 100
                                    || mb_strlen($shipForm['ship_to_postal']) > 32
                                    || mb_strlen($shipForm['ship_to_country']) > 100) {
                                    $error = 'One or more address fields are too long.';
                                }
                            }

                            $shipName = $isPickup ? null : $shipForm['ship_to_name'];
                            $shipL1 = $isPickup ? null : $shipForm['ship_to_line1'];
                            $shipL2 = $isPickup || $shipForm['ship_to_line2'] === '' ? null : $shipForm['ship_to_line2'];
                            $shipCity = $isPickup ? null : $shipForm['ship_to_city'];
                            $shipReg = $isPickup || $shipForm['ship_to_region'] === '' ? null : $shipForm['ship_to_region'];
                            $shipPostal = $isPickup ? null : $shipForm['ship_to_postal'];
                            $shipCountry = $isPickup ? null : $shipForm['ship_to_country'];

                            if ($error === '') {
                                $shippingCost = (float) $srow['price'];
                                $conn->begin_transaction();
                                try {
                                    $ins = $conn->prepare(
                                        "INSERT INTO `Order` (auction_id, buyer_id, card_id, shipping_option_id, bid_amount, shipping_cost, payment_status, payment_time,
                                         ship_to_name, ship_to_line1, ship_to_line2, ship_to_city, ship_to_region, ship_to_postal, ship_to_country)
                                         VALUES (?,?,?,?,?,?, 'paid', ?,?,?,?,?,?,?,?)"
                                    );
                                    $ins->bind_param(
                                        'isiiddssssssss',
                                        $aid,
                                        $userId,
                                        $cardId,
                                        $shipId,
                                        $winningBid,
                                        $shippingCost,
                                        $nowSql,
                                        $shipName,
                                        $shipL1,
                                        $shipL2,
                                        $shipCity,
                                        $shipReg,
                                        $shipPostal,
                                        $shipCountry
                                    );
                                    if (!$ins->execute()) {
                                        throw new Exception($ins->error);
                                    }
                                    $conn->commit();
                                    header('Location: dashboard.php?msg=order_paid');
                                    exit;
                                } catch (Exception $e) {
                                    $conn->rollback();
                                    $error = 'Could not complete checkout.';
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

function cc_last4_local(string $num): string {
    $d = preg_replace('/\D+/', '', $num) ?: '';
    return strlen($d) >= 4 ? substr($d, -4) : $d;
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout — AuBase</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        :root{--ink:#0f172a;--mid:#64748b;--muted:#94a3b8;--border:#e2e8f0;--bg:#f8fafc;--white:#fff;--navy:#0284c7;--navy2:#0ea5e9;--green:#10b981;--greenbg:#d1fae5;--red:#ef4444;--redbg:#fee2e2;--radius:12px;--edge:clamp(16px,4vw,56px)}
        *{box-sizing:border-box} body{margin:0;font-family:'DM Sans',system-ui,sans-serif;background:var(--bg);color:var(--ink)}
        a{text-decoration:none;color:inherit}
        header{background:var(--white);border-bottom:1px solid var(--border);padding:14px var(--edge);display:flex;align-items:center;justify-content:space-between}
        .logo{font-family:'DM Serif Display',serif;font-size:28px;letter-spacing:-1.2px}
        .wrap{max-width:900px;margin:0 auto;padding:22px var(--edge) 60px}
        .card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:18px 18px}
        .row{display:flex;gap:16px;flex-wrap:wrap}
        .row > .card{flex:1;min-width:280px}
        .h{font-size:18px;font-weight:700;margin:0 0 8px}
        .sub{color:var(--muted);margin:0 0 12px;line-height:1.5}
        .alert{padding:12px 14px;border-radius:10px;margin-bottom:14px}
        .err{background:var(--redbg);color:var(--red);border:1px solid #fecaca}
        .ok{background:var(--greenbg);color:var(--green);border:1px solid #a7f3d0}
        label{display:block;font-size:12.5px;font-weight:600;margin:12px 0 6px}
        select,input[type=text]{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;font-family:inherit;font-size:14px}
        input[type=text]:focus{outline:2px solid rgba(2,132,199,0.35);border-color:var(--navy2)}
        .ship-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}
        @media(max-width:560px){.ship-grid{grid-template-columns:1fr}}
        .ship-note{font-size:12.5px;color:var(--muted);margin:0 0 10px;line-height:1.45}
        .btn{display:inline-flex;align-items:center;justify-content:center;width:100%;margin-top:14px;padding:12px 14px;border:none;border-radius:999px;background:linear-gradient(135deg,var(--navy) 0%,var(--navy2) 100%);color:#fff;font-weight:700;cursor:pointer}
        .totals{display:flex;justify-content:space-between;margin:8px 0;color:var(--mid)}
        .totals strong{color:var(--ink)}
    </style>
</head>
<body>
<header>
    <a class="logo" href="index.php"><span style="color:#ef4444">A</span><span style="color:#f59e0b">u</span><span style="color:#0284c7">B</span><span style="color:#334155">ase</span></a>
    <div style="display:flex;gap:12px;color:var(--mid);font-size:13px">
        <a href="dashboard.php">Dashboard</a>
        <a href="account.php">Account</a>
    </div>
</header>

<div class="wrap">
    <h1 style="font-family:'DM Serif Display',serif;font-weight:400;letter-spacing:-0.6px;margin:0 0 14px">Checkout</h1>

    <?php if ($error !== ''): ?>
        <div class="alert err"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($ok !== ''): ?>
        <div class="alert ok"><?= htmlspecialchars($ok) ?></div>
    <?php endif; ?>

    <?php if ($auction): ?>
        <div class="row">
            <div class="card">
                <p class="sub" style="margin-top:0">Item</p>
                <p class="h"><?= htmlspecialchars((string) $auction['item_name']) ?></p>
                <div class="totals"><span>Winning bid</span><strong>$<?= number_format((float) $auction['current_price'], 2) ?></strong></div>
            </div>
            <div class="card">
                <p class="sub" style="margin-top:0">Payment</p>
                <?php if ($orderId !== null): ?>
                    <p class="h">Already paid</p>
                    <p class="sub">See your dashboard for shipping and delivery updates.</p>
                    <a class="btn" href="dashboard.php">Go to dashboard</a>
                <?php elseif ($error === ''): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <label for="shipping_option_id">Shipping option</label>
                        <select id="shipping_option_id" name="shipping_option_id" required>
                            <option value="">Select…</option>
                            <?php foreach ($shippingOptions as $s): ?>
                                <option value="<?= (int) $s['shipping_option_id'] ?>" data-pickup="<?= (int) $s['is_pickup'] === 1 ? '1' : '0' ?>">
                                    <?= htmlspecialchars((string) $s['method']) ?> — $<?= number_format((float) $s['price'], 2) ?>
                                    <?= ((int) $s['is_pickup'] === 1) ? '(Pickup)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <p class="ship-note" id="ship-hint">If the seller ships the item, enter the address where you want it delivered. For local pickup, these fields are optional.</p>
                        <div class="ship-grid" id="ship-fields">
                            <div style="grid-column:1/-1">
                                <label for="ship_to_name">Full name (shipping label)</label>
                                <input type="text" id="ship_to_name" name="ship_to_name" maxlength="150" value="<?= htmlspecialchars($shipForm['ship_to_name']) ?>" autocomplete="name">
                            </div>
                            <div style="grid-column:1/-1">
                                <label for="ship_to_line1">Street address</label>
                                <input type="text" id="ship_to_line1" name="ship_to_line1" maxlength="255" value="<?= htmlspecialchars($shipForm['ship_to_line1']) ?>" autocomplete="address-line1">
                            </div>
                            <div style="grid-column:1/-1">
                                <label for="ship_to_line2">Apt / suite <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
                                <input type="text" id="ship_to_line2" name="ship_to_line2" maxlength="255" value="<?= htmlspecialchars($shipForm['ship_to_line2']) ?>" autocomplete="address-line2">
                            </div>
                            <div>
                                <label for="ship_to_city">City</label>
                                <input type="text" id="ship_to_city" name="ship_to_city" maxlength="100" value="<?= htmlspecialchars($shipForm['ship_to_city']) ?>" autocomplete="address-level2">
                            </div>
                            <div>
                                <label for="ship_to_region">State / province <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
                                <input type="text" id="ship_to_region" name="ship_to_region" maxlength="100" value="<?= htmlspecialchars($shipForm['ship_to_region']) ?>" autocomplete="address-level1">
                            </div>
                            <div>
                                <label for="ship_to_postal">Postal / ZIP</label>
                                <input type="text" id="ship_to_postal" name="ship_to_postal" maxlength="32" value="<?= htmlspecialchars($shipForm['ship_to_postal']) ?>" autocomplete="postal-code">
                            </div>
                            <div>
                                <label for="ship_to_country">Country</label>
                                <input type="text" id="ship_to_country" name="ship_to_country" maxlength="100" value="<?= htmlspecialchars($shipForm['ship_to_country']) ?>" autocomplete="country-name">
                            </div>
                        </div>

                        <label for="card_id">Card</label>
                        <select id="card_id" name="card_id" required>
                            <option value="">Select…</option>
                            <?php foreach ($cards as $c): ?>
                                <option value="<?= (int) $c['card_id'] ?>">•••• <?= htmlspecialchars(cc_last4_local((string) $c['card_number'])) ?> — exp <?= htmlspecialchars(date('M Y', strtotime((string) $c['expiration_date']))) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button class="btn" type="submit">Pay now (simulated)</button>
                        <p class="sub" style="margin:12px 0 0">After payment, the seller must ship within two business days. You’ll confirm delivery in your dashboard.</p>
                    </form>
                    <script>
                    (function(){
                        var sel=document.getElementById('shipping_option_id');
                        var hint=document.getElementById('ship-hint');
                        var grid=document.getElementById('ship-fields');
                        if(!sel||!hint||!grid)return;
                        function upd(){
                            var opt=sel.options[sel.selectedIndex];
                            if(!opt||!opt.value){
                                hint.textContent='Choose a shipping option, then fill the address if the seller ships to you.';
                                grid.querySelectorAll('input').forEach(function(inp){ inp.removeAttribute('required'); });
                                return;
                            }
                            var pu=opt.getAttribute('data-pickup')==='1';
                            hint.textContent=pu
                                ? 'Local pickup: address fields are optional. Coordinate pickup with the seller if needed.'
                                : 'Enter the full delivery address. The seller uses this to ship your item.';
                            grid.querySelectorAll('input').forEach(function(inp){
                                if(pu){inp.removeAttribute('required');return;}
                                var id=inp.id;
                                if(id==='ship_to_line2'||id==='ship_to_region')return;
                                inp.setAttribute('required','required');
                            });
                        }
                        sel.addEventListener('change',upd);
                        upd();
                    })();
                    </script>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>

