<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config.php';

$cli = php_sapi_name() === 'cli';
$keyOk = AUBASE_IMPORT_KEY !== ''
    && isset($_GET['key'])
    && hash_equals(AUBASE_IMPORT_KEY, (string) $_GET['key']);

if (!$cli && !$keyOk) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden.\n\nRun: php scripts/import.php\nOr set AUBASE_IMPORT_KEY in .env and open (docroot public/):\n  import.php?key=YOUR_KEY\n";
    exit;
}

require_once __DIR__ . '/../backend/db.php';

set_time_limit(600);
ini_set('memory_limit', '512M');

$conn->query("SET FOREIGN_KEY_CHECKS=0");
$conn->query("TRUNCATE TABLE Bid");
$conn->query("TRUNCATE TABLE Item_Category");
$conn->query("TRUNCATE TABLE Auction");
$conn->query("TRUNCATE TABLE Item");
$conn->query("TRUNCATE TABLE Category");
$conn->query("TRUNCATE TABLE User");
$conn->query("SET FOREIGN_KEY_CHECKS=1");

$errors = [];

function cleanMoney($str) {
    if (!$str) return 0;
    return floatval(str_replace(['$', ','], '', $str));
}

function cleanDate($str) {
    if (!$str) return null;
    // Format: Dec-03-01 18:10:40
    return date('Y-m-d H:i:s', strtotime($str));
}

for ($i = 0; $i <= 39; $i++) {
    $file = __DIR__ . '/../data/ebay_data/items-' . $i . '.json';

    if (!file_exists($file)) {
        $errors[] = "File not found: $file";
        continue;
    }

    $json  = file_get_contents($file);
    $data  = json_decode($json, true);

    if (!$data) {
        $errors[] = "Failed to parse: $file";
        continue;
    }

    // ── Data is wrapped in 'Items' key ──
    $items = isset($data['Items']) ? $data['Items'] : $data;

    foreach ($items as $item) {

        if (!isset($item['ItemID'])) continue;

        // ── Insert Seller ──
        $seller_id     = $conn->real_escape_string($item['Seller']['UserID'] ?? '');
        $seller_rating = intval($item['Seller']['Rating'] ?? 0);
        $location      = $conn->real_escape_string($item['Location'] ?? '');
        $country       = $conn->real_escape_string($item['Country'] ?? '');

        if ($seller_id) {
            $conn->query("
                INSERT IGNORE INTO User (user_id, rating)
                VALUES ('$seller_id', $seller_rating)
            ");
        }

        // ── Insert Item ──
        $item_id     = intval($item['ItemID']);
        $name        = $conn->real_escape_string(substr($item['Name'] ?? '', 0, 255));
        $description = $conn->real_escape_string(substr($item['Description'] ?? '', 0, 65535));

        $conn->query("
            INSERT IGNORE INTO Item (item_id, name, description, location, country, seller_id)
            VALUES ($item_id, '$name', '$description', '$location', '$country', '$seller_id')
        ");

        // ── Insert Categories ──
        $categories = $item['Category'] ?? [];
        if (!is_array($categories)) $categories = [$categories];

        foreach ($categories as $cat_name) {
            $cat_name = $conn->real_escape_string(trim($cat_name ?? ''));
            if (!$cat_name) continue;

            $conn->query("INSERT IGNORE INTO Category (name) VALUES ('$cat_name')");

            $cat_row = $conn->query("SELECT category_id FROM Category WHERE name='$cat_name'")->fetch_assoc();
            if ($cat_row) {
                $conn->query("
                    INSERT IGNORE INTO Item_Category (item_id, category_id)
                    VALUES ($item_id, {$cat_row['category_id']})
                ");
            }
        }

        // ── Insert Auction ──
        $currently  = cleanMoney($item['Currently'] ?? 0);
        $first_bid  = cleanMoney($item['First_Bid'] ?? 0);
        $buy_price  = isset($item['Buy_Price']) ? cleanMoney($item['Buy_Price']) : null;
        $num_bids   = intval($item['Number_of_Bids'] ?? 0);
        $start_time = cleanDate($item['Started'] ?? '');
        $end_time   = cleanDate($item['Ends'] ?? '');

        if (!$start_time || !$end_time) continue;
        if ($end_time <= $start_time) continue;

        $buy_sql = $buy_price !== null ? $buy_price : 'NULL';

        $conn->query("
            INSERT IGNORE INTO Auction (item_id, start_time, end_time, starting_price, current_price, buy_price, num_bids)
            VALUES ($item_id, '$start_time', '$end_time', $first_bid, $currently, $buy_sql, $num_bids)
        ");

        $auction_row = $conn->query("SELECT auction_id FROM Auction WHERE item_id=$item_id")->fetch_assoc();
        $auction_id  = $auction_row ? $auction_row['auction_id'] : null;

        // ── Insert Bids ──
        // Structure: Bids => [ [Bid => [Bidder=>, Time=>, Amount=>]], ... ]
        if ($auction_id && !empty($item['Bids']) && is_array($item['Bids'])) {
            foreach ($item['Bids'] as $bid_wrapper) {

                // Each element has a 'Bid' key
                $bid = isset($bid_wrapper['Bid']) ? $bid_wrapper['Bid'] : $bid_wrapper;

                if (!isset($bid['Bidder']) || !isset($bid['Amount'])) continue;

                $bidder_id     = $conn->real_escape_string($bid['Bidder']['UserID'] ?? '');
                $bidder_rating = intval($bid['Bidder']['Rating'] ?? 0);

                if (!$bidder_id) continue;

                // Insert bidder as user
                $conn->query("
                    INSERT IGNORE INTO User (user_id, rating)
                    VALUES ('$bidder_id', $bidder_rating)
                ");

                $amount   = cleanMoney($bid['Amount']);
                $bid_time = cleanDate($bid['Time'] ?? '');

                if (!$bid_time || $amount <= 0) continue;

                $conn->query("
                    INSERT IGNORE INTO Bid (auction_id, bidder_id, amount, bid_time)
                    VALUES ($auction_id, '$bidder_id', $amount, '$bid_time')
                ");
            }
        }
    }

    echo "✅ Processed file $i<br>";
    flush();
}

// ── Summary ──
$user_count = $conn->query("SELECT COUNT(*) as cnt FROM User")->fetch_assoc()['cnt'];
$cat_count  = $conn->query("SELECT COUNT(*) as cnt FROM Category")->fetch_assoc()['cnt'];
$bid_count  = $conn->query("SELECT COUNT(*) as cnt FROM Bid")->fetch_assoc()['cnt'];
$item_count = $conn->query("SELECT COUNT(*) as cnt FROM Item")->fetch_assoc()['cnt'];
$auc_count  = $conn->query("SELECT COUNT(*) as cnt FROM Auction")->fetch_assoc()['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AuBase — Import</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f8fafc; padding: 40px; color: #1e293b; }
        h1   { font-size: 24px; margin-bottom: 24px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 24px; margin-bottom: 16px; }
        .stat { display: inline-block; margin-right: 32px; text-align: center; }
        .stat h3 { font-size: 28px; color: #3b6ea5; margin: 0; }
        .stat p  { font-size: 13px; color: #64748b; margin: 4px 0 0; }
        .error { color: #dc2626; font-size: 13px; margin-top: 4px; }
        a { color: #3b6ea5; font-weight: 600; }
    </style>
</head>
<body>
<h1>✅ eBay Data Import Complete</h1>
<div class="card">
    <div class="stat"><h3><?php echo number_format((int) $item_count); ?></h3><p>Items</p></div>
    <div class="stat"><h3><?php echo number_format((int) $auc_count); ?></h3><p>Auctions</p></div>
    <div class="stat"><h3><?php echo number_format((int) $user_count); ?></h3><p>Users</p></div>
    <div class="stat"><h3><?php echo number_format((int) $cat_count); ?></h3><p>Categories</p></div>
    <div class="stat"><h3><?php echo number_format((int) $bid_count); ?></h3><p>Bids</p></div>
</div>
<?php if (!empty($errors)): ?>
    <div class="card">
        <h3>Errors</h3>
        <?php foreach ($errors as $e): ?>
            <p class="error">⚠️ <?php echo $e; ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<p><a href="index.php">← Go to AuBase</a></p>
</body>
</html>