<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auction_list.php';

$logged_in = isset($_SESSION['user_id'], $_SESSION['username']);
$session_username = $logged_in ? (string) $_SESSION['username'] : '';

$total_items = (int) $conn->query("SELECT COUNT(*) AS cnt FROM Item")->fetch_assoc()['cnt'];
$total_bids  = (int) $conn->query("SELECT COUNT(*) AS cnt FROM Bid")->fetch_assoc()['cnt'];
$live_count  = (int) $conn->query("SELECT COUNT(*) AS cnt FROM Auction")->fetch_assoc()['cnt'];

$search   = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$tab      = isset($_GET['tab']) ? (string) $_GET['tab'] : 'all';
if (!in_array($tab, ['all', 'open', 'closed', 'buynow'], true)) $tab = 'all';
$page     = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$per_page = 48;
$offset   = ($page - 1) * $per_page;

[$where_sql, $bind_types, $bind_params] = aubase_auction_filters($search, $category, $tab);
$demo_ref_ts = strtotime(AUBASE_DEMO_NOW);

$base_from = "
    FROM Item i
    JOIN Auction a ON i.item_id = a.item_id
    JOIN User u ON i.seller_id = u.user_id
    LEFT JOIN Item_Category ic ON i.item_id = ic.item_id
    LEFT JOIN Category c ON ic.category_id = c.category_id
";

$list_sql = "
    SELECT i.item_id, i.name, i.location, i.country, i.seller_id,
        u.rating AS seller_rating,
        a.auction_id, a.current_price, a.starting_price,
        a.buy_price, a.num_bids, a.end_time, a.start_time,
        GROUP_CONCAT(DISTINCT c.name SEPARATOR ',') AS categories
    $base_from $where_sql
    GROUP BY i.item_id
    ORDER BY a.num_bids DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($list_sql);
if ($stmt === false) die('Unable to load listings.');
$types_full  = $bind_types . 'ii';
$params_full = array_merge($bind_params, [$per_page, $offset]);
$stmt->bind_param($types_full, ...$params_full);
$stmt->execute();
$result = $stmt->get_result();

$count_sql  = "SELECT COUNT(DISTINCT i.item_id) AS total $base_from $where_sql";
$stmt_count = $conn->prepare($count_sql);
if ($stmt_count === false) die('Unable to count listings.');
if ($bind_types !== '') $stmt_count->bind_param($bind_types, ...$bind_params);
$stmt_count->execute();
$total_results = (int) ($stmt_count->get_result()->fetch_assoc()['total'] ?? 0);
$total_pages   = (int) ceil($total_results / $per_page);

$cat_dropdown_result = $conn->query("SELECT name FROM Category ORDER BY name LIMIT 50");
$cat_dropdown = [];
if ($cat_dropdown_result) {
    while ($r = $cat_dropdown_result->fetch_assoc()) $cat_dropdown[] = $r['name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AuBase — Bid, Win, Save</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink:#0f172a; --ink-2:#1e293b; --ink-3:#334155;
            --mid:#64748b; --muted:#94a3b8;
            --border:#e2e8f0; --border-2:#cbd5e1;
            --bg:#f8fafc; --bg-2:#f1f5f9; --white:#ffffff;
            --gold:#f59e0b; --gold-light:#fffbeb; --gold-dark:#d97706;
            --navy:#0284c7; --navy-2:#0ea5e9; --navy-3:#38bdf8;
            --sky-wash:#e0f2fe; --sky-mist:#f0f9ff;
            --green:#10b981; --green-bg:#d1fae5;
            --red:#ef4444; --red-bg:#fee2e2;
            --radius-sm:6px; --radius:12px; --radius-lg:18px;
            --shadow-sm:0 1px 3px rgba(14,165,233,0.08);
            --shadow:0 4px 24px rgba(14,165,233,0.1);
            --shadow-lg:0 20px 50px rgba(14,165,233,0.12);
            --page-max:1480px;
            --gutter:clamp(16px,4vw,56px);
            --edge:max(var(--gutter),calc((100vw - var(--page-max)) / 2));
        }
        *{margin:0;padding:0;box-sizing:border-box}
        html{-webkit-text-size-adjust:100%;scroll-behavior:smooth}
        body{font-family:'DM Sans',system-ui,sans-serif;background:var(--bg);color:var(--ink);font-size:14px;overflow-x:hidden;-webkit-font-smoothing:antialiased}
        a{text-decoration:none;color:inherit}

        /* ── TOP BAR ── */
        .top-bar{background:linear-gradient(90deg,var(--sky-wash) 0%,var(--sky-mist) 50%,#fff 100%);color:var(--ink-3);padding:7px var(--edge);display:flex;justify-content:space-between;align-items:center;font-size:11.5px;letter-spacing:0.01em;line-height:1;border-bottom:1px solid var(--border)}
        .top-bar-left,.top-bar-right{display:flex;gap:16px;align-items:center}
        .top-bar a{color:var(--mid);transition:color 0.18s}
        .top-bar a:hover{color:var(--navy)}
        .top-bar .highlight{color:var(--gold-dark);font-weight:700}
        .top-bar-sep{opacity:0.2}

        /* ── NAVBAR ── */
        nav{background:var(--white);padding:0 var(--edge);display:flex;align-items:center;gap:16px;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:300;box-shadow:var(--shadow-sm);height:64px}
        .logo{font-family:'DM Serif Display',serif;font-size:28px;letter-spacing:-1.5px;min-width:110px;line-height:1;flex-shrink:0}
        .logo span:nth-child(1){color:var(--red)}
        .logo span:nth-child(2){color:var(--gold)}
        .logo span:nth-child(3){color:var(--navy)}
        .logo span:nth-child(4){color:var(--ink-3)}

        /* search */
        .search-wrap{flex:1;max-width:780px;display:flex;align-items:center;border:1.5px solid var(--border-2);border-radius:50px;overflow:hidden;transition:border-color 0.2s,box-shadow 0.2s;background:var(--bg);min-width:0}
        .search-wrap:focus-within{border-color:var(--navy-2);box-shadow:0 0 0 3px rgba(14,165,233,0.2);background:var(--white)}
        .search-wrap input{flex:1;padding:11px 22px;border:none;background:transparent;font-size:14px;font-family:'DM Sans',sans-serif;outline:none;color:var(--ink);min-width:0}
        .search-wrap input::placeholder{color:var(--muted)}
        .search-divider{width:1px;align-self:stretch;background:var(--border-2);flex-shrink:0}
        .search-wrap select{border:none;background:transparent;font-size:13px;color:var(--mid);padding:0 12px;outline:none;cursor:pointer;font-family:'DM Sans',sans-serif}
        .search-btn{padding:10px 28px;background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;border:none;font-size:13.5px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:transform 0.2s,box-shadow 0.2s;border-radius:0 50px 50px 0;flex-shrink:0;height:100%;box-shadow:0 2px 10px rgba(2,132,199,0.35)}
        .search-btn:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(2,132,199,0.45)}

        /* nav icons */
        .nav-right{display:flex;align-items:center;gap:6px;margin-left:8px;flex-shrink:0}
        .nav-icon{color:var(--ink-3);font-size:11px;font-weight:500;display:flex;flex-direction:column;align-items:center;gap:4px;transition:color 0.18s;padding:8px 14px;border-radius:var(--radius-sm);cursor:pointer}
        .nav-icon:hover{color:var(--navy);background:var(--bg)}
        .nav-icon svg{width:22px;height:22px;stroke:currentColor;stroke-width:1.5;fill:none}
        .nav-icon .label{font-size:10.5px;color:var(--muted);letter-spacing:0.02em;white-space:nowrap}

        /* ── NAV TABS (replaces cat-bar) ── */
        .nav-tabs{background:var(--white);border-bottom:1px solid var(--border);padding:0 var(--edge);display:flex;align-items:center;justify-content:space-between;gap:0}
        .nav-tabs-left{display:flex;align-items:center;gap:0}
        .nav-tab{color:var(--mid);font-size:13px;font-weight:500;padding:12px 18px;border-bottom:2px solid transparent;transition:color 0.18s,border-color 0.18s;white-space:nowrap;cursor:pointer;letter-spacing:0.01em;display:flex;align-items:center;gap:6px}
        .nav-tab:hover{color:var(--ink)}
        .nav-tab.active{color:var(--navy);border-bottom-color:var(--gold);font-weight:600}
        .nav-tab .dot{width:6px;height:6px;border-radius:50%;background:var(--green);display:inline-block;flex-shrink:0}
        .nav-tab .dot-gold{background:var(--gold)}

        /* categories dropdown in nav-tabs */
        .nav-sep{width:1px;height:18px;background:var(--border-2);margin:0 4px;flex-shrink:0;align-self:center}
        .cat-pill-wrap{position:relative}
        .cat-pill{display:flex;align-items:center;gap:4px;color:var(--mid);font-size:13px;font-weight:500;cursor:pointer;padding:12px 14px;border-bottom:2px solid transparent;transition:all 0.18s;user-select:none;white-space:nowrap}
        .cat-pill:hover,.cat-pill.open,.cat-pill.active{color:var(--navy)}
        .cat-menu{position:absolute;top:calc(100% + 4px);left:0;width:220px;background:var(--white);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-lg);z-index:400;max-height:320px;overflow-y:auto;display:none;padding:6px 0}
        .cat-menu.open{display:block}
        .cat-menu a{display:block;padding:8px 16px;font-size:13px;color:var(--ink-3);transition:background 0.12s,color 0.12s;letter-spacing:0.01em}
        .cat-menu a:hover,.cat-menu a.is-active{background:var(--bg-2);color:var(--navy)}
        .cat-menu a.all-link{font-weight:600;border-bottom:1px solid var(--border);color:var(--navy);margin-bottom:4px}

        /* active category badge in nav-tabs */
        .active-cat-badge{display:flex;align-items:center;gap:8px;padding:12px 0;font-size:12.5px;color:var(--mid)}
        .active-cat-badge span{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;padding:3px 10px;border-radius:50px;font-size:11.5px;font-weight:600;display:flex;align-items:center;gap:5px;box-shadow:0 2px 8px rgba(2,132,199,0.25)}
        .active-cat-badge span a{color:rgba(255,255,255,0.75);font-size:12px;font-weight:400;margin-left:2px}
        .active-cat-badge span a:hover{color:#fff}

        /* ── HERO ── */
        .hero{position:relative;overflow:hidden;background:linear-gradient(160deg,var(--sky-wash) 0%,var(--sky-mist) 40%,#fff 100%)}
        .hero-slides-wrap{position:relative;min-height:clamp(260px,32vw,380px)}
        .hero-slide{position:absolute;inset:0;display:flex;align-items:center;padding:clamp(36px,5vw,56px) var(--edge);opacity:0;transition:opacity 0.8s ease;pointer-events:none;background-size:cover;background-position:center}
        .hero-slide::before{content:'';position:absolute;inset:0;background:linear-gradient(115deg,rgba(255,255,255,0.94) 0%,rgba(224,242,254,0.82) 42%,rgba(255,255,255,0.45) 100%)}
        .hero-slide.active{opacity:1;pointer-events:auto}
        .hero-content{max-width:min(520px,100%);position:relative;z-index:2}
        .hero-eyebrow{display:inline-flex;align-items:center;gap:5px;background:var(--gold-light);border:1px solid #fde68a;color:var(--gold-dark);font-size:11px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;padding:4px 12px;border-radius:50px;margin-bottom:16px}
        .hero-content h1{font-family:'DM Serif Display',serif;font-size:clamp(28px,3.5vw + 0.8rem,48px);font-weight:400;color:var(--ink);line-height:1.1;margin-bottom:12px;letter-spacing:-1px}
        .hero-content h1 em{color:var(--navy);font-style:italic}
        .hero-content p{font-size:clamp(13px,0.8vw + 0.65rem,15.5px);color:var(--mid);margin-bottom:clamp(20px,2.5vw,32px);line-height:1.75;font-weight:400}
        .hero-actions{display:flex;gap:10px;flex-wrap:wrap}
        .hero-btn-primary{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;padding:11px 24px;border-radius:50px;font-size:13.5px;font-weight:600;font-family:'DM Sans',sans-serif;transition:all 0.2s;letter-spacing:0.01em;box-shadow:0 4px 16px rgba(2,132,199,0.35)}
        .hero-btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(2,132,199,0.45)}
        .hero-btn-ghost{display:inline-flex;align-items:center;gap:7px;background:var(--white);color:var(--ink-3);padding:11px 20px;border-radius:50px;font-size:13.5px;font-weight:500;border:1.5px solid var(--border-2);transition:all 0.2s;box-shadow:var(--shadow-sm)}
        .hero-btn-ghost:hover{border-color:var(--navy-2);color:var(--navy);background:var(--sky-mist)}
        .hero-dots{position:absolute;bottom:16px;left:50%;transform:translateX(-50%);display:flex;gap:5px;z-index:10}
        .hero-dot{width:5px;height:5px;border-radius:50%;background:var(--border-2);cursor:pointer;transition:all 0.3s;border:none;outline:none}
        .hero-dot.active{background:var(--navy);width:18px;border-radius:3px}
        .hero-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:10;background:var(--white);border:1.5px solid var(--border-2);color:var(--navy);width:36px;height:36px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s;font-size:16px;box-shadow:var(--shadow-sm)}
        .hero-arrow:hover{background:var(--sky-wash);border-color:var(--navy-3)}
        .hero-arrow.prev{left:clamp(10px,1.5vw,24px)}
        .hero-arrow.next{right:clamp(10px,1.5vw,24px)}

        /* ── STATS BAR ── */
        .stats-bar{background:linear-gradient(180deg,#fff 0%,var(--sky-mist) 100%);border-bottom:1px solid var(--border);padding:0 var(--edge);display:flex;justify-content:center}
        .stat{text-align:center;padding:16px clamp(24px,4vw,52px);border-right:1px solid var(--border)}
        .stat:last-child{border-right:none}
        .stat h3{font-family:'DM Serif Display',serif;font-size:clamp(20px,1.8vw + 0.4rem,28px);font-weight:400;color:var(--navy);letter-spacing:-0.5px;line-height:1;margin-bottom:2px}
        .stat p{font-size:11px;color:var(--muted);font-weight:500;letter-spacing:0.05em;text-transform:uppercase}

        /* ── SECTION ── */
        .section{padding:clamp(24px,3vw,40px) var(--gutter);max-width:var(--page-max);margin:0 auto;width:100%}
        .section-header{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:20px;gap:12px}
        .section-header h2{font-family:'DM Serif Display',serif;font-size:clamp(18px,1.2vw + 0.8rem,24px);font-weight:400;letter-spacing:-0.5px;color:var(--ink)}
        .section-header a{color:var(--gold-dark);font-size:13px;font-weight:600;transition:color 0.18s}
        .section-header a:hover{color:var(--gold)}

        /* ── FILTER TABS (inside listings) ── */
        .filter-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;gap:12px;flex-wrap:wrap}
        .filter-tabs{display:flex;gap:4px;background:var(--bg-2);padding:4px;border-radius:50px;border:1px solid var(--border)}
        .tab{padding:6px 20px;border-radius:50px;border:none;background:transparent;color:var(--mid);cursor:pointer;font-size:13px;font-weight:500;font-family:'DM Sans',sans-serif;transition:all 0.18s}
        .tab:hover{color:var(--ink)}
        .tab.active{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;font-weight:600;box-shadow:0 2px 12px rgba(2,132,199,0.3)}
        .results-count{font-size:12.5px;color:var(--muted)}

        /* ── GRID ── */
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:clamp(10px,1.5vw,18px)}

        /* ── CARD ── */
        .card{background:var(--white);border-radius:var(--radius);overflow:hidden;border:1px solid var(--border);transition:box-shadow 0.22s,transform 0.22s,border-color 0.22s;cursor:pointer}
        .card:hover{box-shadow:var(--shadow-lg);transform:translateY(-3px);border-color:var(--border-2)}
        .card-img{background:var(--bg);position:relative;padding-top:72%;overflow:hidden}
        .card-img img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transition:transform 0.35s ease}
        .card:hover .card-img img{transform:scale(1.05)}
        .badge{position:absolute;top:9px;left:9px;font-size:9.5px;padding:3px 9px;border-radius:50px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;z-index:1}
        .badge-red{background:var(--red-bg);color:var(--red);border:1px solid #fecaca}
        .badge-green{background:var(--green-bg);color:var(--green);border:1px solid #a7f3d0}
        .badge-blue{background:var(--sky-wash);color:var(--navy);border:1px solid var(--navy-3)}
        .badge-gold{background:var(--gold-light);color:var(--gold-dark);border:1px solid #fcd34d}
        .card-body{padding:13px}
        .card-body h3{font-size:13px;font-weight:600;margin-bottom:5px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.4}
        .card:hover h3{color:var(--navy)}
        .cat-tags{display:flex;flex-wrap:wrap;gap:3px;margin-bottom:7px}
        .cat-tag{background:var(--bg-2);color:var(--mid);font-size:9.5px;font-weight:500;padding:2px 7px;border-radius:50px;border:1px solid var(--border)}
        .item-location{font-size:10.5px;color:var(--muted);margin-bottom:8px;display:flex;align-items:center;gap:3px}
        .price-box{background:linear-gradient(135deg,var(--sky-wash) 0%,#fff 100%);border:1px solid var(--border-2);border-radius:var(--radius-sm);padding:9px 11px;margin-bottom:8px;box-shadow:var(--shadow-sm)}
        .current-bid{font-size:18px;font-weight:700;color:var(--ink);letter-spacing:-0.5px;line-height:1;display:flex;align-items:baseline;gap:5px}
        .current-bid small{font-size:10px;color:var(--muted);font-weight:500;letter-spacing:0}
        .starting-price{font-size:10px;color:var(--mid);margin-top:3px}
        .buy-now-price{font-size:10.5px;color:var(--gold-dark);font-weight:600;margin-top:4px;display:flex;align-items:center;gap:4px}
        .card-meta{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
        .bids-count{font-size:10.5px;color:var(--mid);background:var(--bg-2);padding:2px 8px;border-radius:50px;border:1px solid var(--border);font-weight:500}
        .time-left{font-size:10.5px;color:var(--muted);font-weight:500}
        .time-left.ending{color:var(--red);font-weight:700;animation:pulse 1.2s infinite}
        .time-left.closed{color:var(--muted)}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:0.5}}
        .seller-info{font-size:10.5px;color:var(--muted);margin-bottom:9px}
        .seller-info a{color:var(--navy-3);font-weight:600}
        .bid-btn{display:block;text-align:center;background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;padding:8px;border-radius:50px;font-size:12px;font-weight:600;letter-spacing:0.02em;transition:all 0.2s;font-family:'DM Sans',sans-serif;box-shadow:0 2px 10px rgba(2,132,199,0.3)}
        .bid-btn:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(2,132,199,0.4)}
        .buy-btn{display:block;text-align:center;background:var(--green-bg);color:var(--green);padding:7px;border-radius:50px;font-size:11.5px;font-weight:600;margin-top:5px;border:1.5px solid var(--green);transition:all 0.2s}
        .buy-btn:hover{background:var(--green);color:#fff}

        /* ── NO ITEMS ── */
        .no-items{grid-column:1/-1;text-align:center;padding:72px 20px;color:var(--muted)}
        .no-items h3{font-family:'DM Serif Display',serif;font-size:21px;font-weight:400;margin-bottom:8px;color:var(--ink)}

        /* ── PAGINATION ── */
        .pagination{display:flex;justify-content:center;align-items:center;gap:5px;padding:32px 0;flex-wrap:wrap}
        .page-btn{padding:7px 14px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--white);color:var(--mid);font-size:13px;font-weight:500;font-family:'DM Sans',sans-serif;cursor:pointer;text-decoration:none;transition:all 0.18s}
        .page-btn:hover{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;border-color:var(--navy)}
        .page-btn.active{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-2) 100%);color:#fff;border-color:var(--navy);font-weight:700}
        .page-btn.disabled{opacity:0.3;pointer-events:none}

        /* ── FOOTER ── */
        footer{background:linear-gradient(180deg,var(--sky-mist) 0%,var(--bg-2) 55%,var(--bg) 100%);color:var(--mid);padding:clamp(32px,4vw,52px) var(--edge);margin-top:clamp(24px,3vw,44px);border-top:1px solid var(--border)}
        .footer-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:clamp(20px,2.5vw,40px);margin-bottom:clamp(28px,3vw,44px);max-width:var(--page-max);margin-left:auto;margin-right:auto}
        .footer-col h4{font-family:'DM Serif Display',serif;color:var(--ink);font-size:15px;font-weight:400;margin-bottom:14px}
        .footer-col a{display:block;color:var(--mid);font-size:12.5px;margin-bottom:10px;transition:color 0.18s}
        .footer-col a:hover{color:var(--navy)}
        .footer-bottom{border-top:1px solid var(--border);padding-top:24px;display:flex;justify-content:space-between;align-items:center;font-size:12px;max-width:var(--page-max);margin-left:auto;margin-right:auto;gap:12px;color:var(--muted)}
        .footer-logo{font-family:'DM Serif Display',serif;font-size:24px;letter-spacing:-1px}
        .footer-logo span:nth-child(1){color:var(--red)}.footer-logo span:nth-child(2){color:var(--gold)}.footer-logo span:nth-child(3){color:var(--navy)}.footer-logo span:nth-child(4){color:var(--ink-3)}

        /* ── RESPONSIVE ── */
        @media(max-width:1024px) and (min-width:769px){
            nav{flex-wrap:wrap;height:auto;padding:10px var(--gutter);row-gap:10px}
            .logo{order:1}.nav-right{order:2;margin-left:auto}.search-wrap{order:3;flex:1 1 100%}
        }
        @media(max-width:768px){
            nav{display:grid;grid-template-columns:1fr auto;grid-template-rows:auto auto;row-gap:8px;height:auto;padding:10px var(--gutter)}
            .logo{grid-column:1;grid-row:1;font-size:24px}
            .nav-right{grid-column:2;grid-row:1;margin-left:0;gap:2px}
            .search-wrap{grid-column:1/-1;grid-row:2}
            .nav-icon .label{display:none}
            .stats-bar{flex-wrap:wrap}
            .stat{border-right:none;border-bottom:1px solid var(--border);padding:12px 20px;flex:1 1 33%}
            .grid{grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:10px}
            .filter-row{flex-direction:column;align-items:flex-start;gap:10px}
            .filter-tabs{width:100%}
            .footer-bottom{flex-direction:column;text-align:center}
            .hero-arrow{display:none}
            .nav-tabs{overflow-x:auto;gap:0}.nav-tabs::-webkit-scrollbar{display:none}
        }
        @media(max-width:420px){
            .grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
            .card-body{padding:10px}
            .current-bid{font-size:16px}
        }
    </style>
</head>
<body>

<?php if (isset($_GET['account_closed'])): ?>
<div style="background:linear-gradient(90deg,#ecfdf5,#d1fae5);border-bottom:1px solid #a7f3d0;color:#065f46;text-align:center;padding:12px 18px;font-size:14px;font-weight:500;line-height:1.45">
    Your account has been closed. Thanks for using AuBase.
</div>
<?php endif; ?>

<!-- Top Bar -->
<div class="top-bar">
    <div class="top-bar-left">
        <?php if ($logged_in): ?>
            <span class="highlight">Hi, <?= htmlspecialchars($session_username) ?></span>
            <span class="top-bar-sep">|</span>
            <a href="logout.php">Sign out</a>
        <?php else: ?>
            <a href="login.php?redirect=dashboard.php" class="highlight">Sign in</a>
            <span class="top-bar-sep">or</span>
            <a href="register.php" class="highlight">Register</a>
        <?php endif; ?>
        <span class="top-bar-sep">|</span>
        <a href="index.php">Help & Contact</a>
    </div>
    <div class="top-bar-right">
        <a href="sell.php">Sell</a>
        <?php if ($logged_in): ?>
        <span class="top-bar-sep">|</span>
        <a href="account.php">Account</a>
        <?php endif; ?>
        <span class="top-bar-sep">|</span>
        <a href="dashboard.php">My AuBase</a>
    </div>
</div>

<!-- Navbar -->
<nav>
    <a href="index.php" class="logo">
        <span>A</span><span>u</span><span>B</span><span>ase</span>
    </a>

    <div class="search-wrap">
        <input type="text" id="search-input" placeholder="Search for anything…" value="<?= htmlspecialchars($search) ?>">
        <div class="search-divider"></div>
        <select id="cat-select">
            <option value="">All Categories</option>
            <?php foreach ($cat_dropdown as $cn): ?>
                <option value="<?= htmlspecialchars($cn) ?>" <?= $category === $cn ? 'selected' : '' ?>><?= htmlspecialchars($cn) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="search-btn" onclick="doSearch()">Search</button>
    </div>

    <div class="nav-right">
        <a href="<?= $logged_in ? 'account.php' : 'login.php?redirect=dashboard.php' ?>" class="nav-icon">
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

<!-- Nav Tabs -->
<div class="nav-tabs">
    <div class="nav-tabs-left">
        <a href="index.php" class="nav-tab <?= (!$category && $tab==='all') ? 'active' : '' ?>">All</a>
        <a href="index.php?tab=open" class="nav-tab <?= ($tab==='open' && !$category) ? 'active' : '' ?>"><span class="dot"></span> Open</a>
        <a href="index.php?tab=buynow" class="nav-tab <?= ($tab==='buynow' && !$category) ? 'active' : '' ?>"><span class="dot dot-gold"></span> Buy It Now</a>
        <a href="index.php?tab=closed" class="nav-tab <?= ($tab==='closed' && !$category) ? 'active' : '' ?>">Closed</a>
        <div class="nav-sep"></div>
        <?php
        $inline_cats = array_slice($cat_dropdown, 0, 6);
        foreach ($inline_cats as $cn):
            ?>
            <a href="index.php?category=<?= urlencode($cn) ?>" class="nav-tab <?= $category === $cn ? 'active' : '' ?>"><?= htmlspecialchars($cn) ?></a>
        <?php endforeach; ?>
        <div class="cat-pill-wrap">
            <div class="cat-pill <?= ($category && !in_array($category, $inline_cats)) ? 'active' : '' ?>" id="cat-toggle">
                <?= ($category && !in_array($category, $inline_cats)) ? htmlspecialchars($category) : 'More ▾' ?>
            </div>
            <div class="cat-menu" id="cat-menu">
                <a href="index.php" class="all-link">All Categories</a>
                <?php foreach ($cat_dropdown as $cn): ?>
                    <a href="index.php?category=<?= urlencode($cn) ?>" class="<?= $category === $cn ? 'is-active' : '' ?>"><?= htmlspecialchars($cn) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php if ($category): ?>
        <div class="active-cat-badge">
            <span><?= htmlspecialchars($category) ?> <a href="index.php" title="Clear">✕</a></span>
        </div>
    <?php endif; ?>
</div>

<!-- Hero Slideshow -->
<div class="hero">
    <div class="hero-slides-wrap" id="heroWrap">

        <div class="hero-slide active" style="background-image:url('https://picsum.photos/seed/auction10/1600/600')">
            <div class="hero-content">
                <div class="hero-eyebrow">✦ Live Auctions</div>
                <h1>From essentials<br>to <em>exclusives.</em></h1>
                <p>Discover thousands of unique items up for auction. Bid, win, and save big on AuBase.</p>
                <div class="hero-actions">
                    <a href="#listings" class="hero-btn-primary">Browse auctions →</a>
                    <?php if ($logged_in): ?>
                        <a href="dashboard.php" class="hero-btn-ghost">My Dashboard</a>
                    <?php else: ?>
                        <a href="register.php" class="hero-btn-ghost">Create account</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="hero-slide" style="background-image:url('https://picsum.photos/seed/auction22/1600/600')">
            <div class="hero-content">
                <div class="hero-eyebrow">✦ Hot Bids</div>
                <h1>Rare finds,<br><em>unbeatable</em> prices.</h1>
                <p>Over 19,000 live auctions across collectibles, antiques, fashion and more.</p>
                <div class="hero-actions">
                    <a href="index.php?tab=open" class="hero-btn-primary">View open auctions →</a>
                    <?php if ($logged_in): ?>
                        <a href="dashboard.php" class="hero-btn-ghost">My Dashboard</a>
                    <?php else: ?>
                        <a href="login.php?redirect=dashboard.php" class="hero-btn-ghost">Sign in to bid</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="hero-slide" style="background-image:url('https://picsum.photos/seed/auction35/1600/600')">
            <div class="hero-content">
                <div class="hero-eyebrow">✦ Buy It Now</div>
                <h1>Don't wait,<br><em>grab it</em> now.</h1>
                <p>Skip the bidding — snap up items instantly with Buy It Now pricing.</p>
                <div class="hero-actions">
                    <a href="index.php?tab=buynow" class="hero-btn-primary">Shop Buy It Now →</a>
                    <?php if ($logged_in): ?>
                        <a href="dashboard.php" class="hero-btn-ghost">My Dashboard</a>
                    <?php else: ?>
                        <a href="register.php" class="hero-btn-ghost">Join free</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="hero-slide" style="background-image:url('https://picsum.photos/seed/auction48/1600/600')">
            <div class="hero-content">
                <div class="hero-eyebrow">✦ Collectibles</div>
                <h1>History's hidden<br><em>treasures</em> await.</h1>
                <p>Real vintage auction data — browse genuine collectibles spanning every category.</p>
                <div class="hero-actions">
                    <a href="index.php?category=Collectibles" class="hero-btn-primary">Explore collectibles →</a>
                    <?php if ($logged_in): ?>
                        <a href="dashboard.php" class="hero-btn-ghost">My Dashboard</a>
                    <?php else: ?>
                        <a href="register.php" class="hero-btn-ghost">Get started</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <button class="hero-arrow prev" onclick="changeSlide(-1)">&#8592;</button>
    <button class="hero-arrow next" onclick="changeSlide(1)">&#8594;</button>
    <div class="hero-dots" id="heroDots">
        <button class="hero-dot active" onclick="goToSlide(0)"></button>
        <button class="hero-dot" onclick="goToSlide(1)"></button>
        <button class="hero-dot" onclick="goToSlide(2)"></button>
        <button class="hero-dot" onclick="goToSlide(3)"></button>
    </div>
</div>

<!-- Stats Bar -->
<div class="stats-bar">
    <div class="stat">
        <h3><?= number_format($live_count) ?></h3>
        <p>Auctions</p>
    </div>
    <div class="stat">
        <h3><?= number_format($total_bids) ?></h3>
        <p>Total Bids</p>
    </div>
    <div class="stat">
        <h3><?= number_format($total_items) ?></h3>
        <p>Items Listed</p>
    </div>
</div>

<!-- Listings -->
<div class="section" id="listings">
    <div class="section-header">
        <h2>
            <?php
            if ($search)       echo "Results for &ldquo;" . htmlspecialchars($search) . "&rdquo;";
            elseif ($category) echo htmlspecialchars($category);
            else               echo "All Listings";
            ?>
        </h2>
        <a href="index.php">View all →</a>
    </div>

    <div class="filter-row">
        <div class="filter-tabs">
            <button class="tab <?= $tab==='all'    ? 'active':'' ?>" onclick="setTab('all')">All</button>
            <button class="tab <?= $tab==='open'   ? 'active':'' ?>" onclick="setTab('open')">Open</button>
            <button class="tab <?= $tab==='closed' ? 'active':'' ?>" onclick="setTab('closed')">Closed</button>
            <button class="tab <?= $tab==='buynow' ? 'active':'' ?>" onclick="setTab('buynow')">Buy It Now</button>
        </div>
        <span class="results-count"><?= number_format($total_results) ?> results</span>
    </div>

    <div class="grid">
        <?php
        if ($result && $result->num_rows > 0) {
            while ($item = $result->fetch_assoc()) {
                $cats     = explode(',', $item['categories'] ?? '');
                $cat_tags = '';
                foreach (array_slice($cats, 0, 2) as $c) {
                    if (trim($c)) $cat_tags .= "<span class='cat-tag'>" . htmlspecialchars(trim($c)) . "</span>";
                }

                $is_closed = strtotime($item['end_time']) <= $demo_ref_ts;
                $has_buy   = !empty($item['buy_price']);
                $is_hot    = $item['num_bids'] > 10;

                if ($is_closed)   $badge = "<span class='badge badge-blue'>Closed</span>";
                elseif ($is_hot)  $badge = "<span class='badge badge-red'>🔥 Hot</span>";
                elseif ($has_buy) $badge = "<span class='badge badge-green'>Buy Now</span>";
                else              $badge = "<span class='badge badge-gold'>Live</span>";

                if ($is_closed) {
                    $time_str   = "Closed";
                    $time_class = "closed";
                } else {
                    $diff  = max(0, strtotime($item['end_time']) - $demo_ref_ts);
                    $days  = floor($diff / 86400);
                    $hours = floor(($diff % 86400) / 3600);
                    $time_str   = $days > 0 ? "{$days}d {$hours}h left" : "{$hours}h left";
                    $time_class = ($hours < 2 && $days === 0) ? "ending" : "";
                }

                $current  = '$' . number_format((float)$item['current_price'], 2);
                $starting = '$' . number_format((float)$item['starting_price'], 2);
                $buy      = $has_buy ? '$' . number_format((float)$item['buy_price'], 2) : '';
                $img_seed = abs($item['item_id']) % 1000;

                echo "
                <div class='card' onclick=\"window.location='item.php?id={$item['item_id']}'\">
                    <div class='card-img'>
                        <img src='https://picsum.photos/seed/{$img_seed}/400/300' alt='" . htmlspecialchars($item['name']) . "' loading='lazy'>
                        $badge
                    </div>
                    <div class='card-body'>
                        <h3 title='" . htmlspecialchars($item['name']) . "'>" . htmlspecialchars($item['name']) . "</h3>
                        <div class='cat-tags'>$cat_tags</div>
                        <p class='item-location'>📍 " . htmlspecialchars($item['location']) . "</p>
                        <div class='price-box'>
                            <div class='current-bid'>$current <small>current bid</small></div>
                            <div class='starting-price'>Starting: $starting</div>
                            " . ($has_buy ? "<div class='buy-now-price'>⚡ Buy Now: $buy</div>" : "") . "
                        </div>
                        <div class='card-meta'>
                            <span class='bids-count'>{$item['num_bids']} bids</span>
                            <span class='time-left $time_class'>⏱ $time_str</span>
                        </div>
                        <p class='seller-info'>Seller: <a href='#' onclick='event.stopPropagation()'>" . htmlspecialchars($item['seller_id']) . "</a> ⭐ {$item['seller_rating']}</p>
                        <a href='item.php?id={$item['item_id']}' class='bid-btn' onclick='event.stopPropagation()'>Place Bid</a>
                        " . ($has_buy && !$is_closed ? "<a href='item.php?id={$item['item_id']}' class='buy-btn' onclick='event.stopPropagation()'>Buy Now — $buy</a>" : "") . "
                    </div>
                </div>";
            }
        } else {
            echo "<div class='no-items'><h3>No items found</h3><p>Try a different search or category.</p></div>";
        }
        ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="page-btn">← Prev</a>
            <?php endif; ?>
            <?php
            $start = max(1, $page - 2);
            $end   = min($total_pages, $page + 2);
            for ($p = $start; $p <= $end; $p++):
                ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
                   class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="page-btn">Next →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Footer -->
<footer>
    <div class="footer-grid">
        <div class="footer-col">
            <h4>Buy</h4>
            <a href="register.php">Registration</a>
            <a href="index.php?tab=open">Live Auctions</a>
            <a href="index.php?tab=buynow">Buy It Now</a>
        </div>
        <div class="footer-col">
            <h4>Sell</h4>
            <a href="sell.php">Start selling</a>
            <a href="sell.php">List an item</a>
        </div>
        <div class="footer-col">
            <h4>Account</h4>
            <a href="dashboard.php">My AuBase</a>
            <?php if ($logged_in): ?>
                <a href="account.php">Account settings</a>
            <?php endif; ?>
            <a href="dashboard.php">Watchlist</a>
            <?php if ($logged_in): ?>
                <a href="logout.php">Sign out</a>
            <?php else: ?>
                <a href="login.php?redirect=dashboard.php">Sign in</a>
                <a href="register.php">Register</a>
            <?php endif; ?>
        </div>
        <div class="footer-col">
            <h4>Explore</h4>
            <a href="index.php?category=Collectibles">Collectibles</a>
            <a href="index.php?category=Clothing+%26+Accessories">Clothing</a>
            <a href="index.php?category=Sports">Sports</a>
        </div>
    </div>
    <div class="footer-bottom">
        <div class="footer-logo">
            <span>A</span><span>u</span><span>B</span><span>ase</span>
        </div>
        <p>© 2026 AuBase Inc. All rights reserved.</p>
    </div>
</footer>

<script>
    // ── Search ──
    function doSearch() {
        const q   = document.getElementById('search-input').value.trim();
        const cat = document.getElementById('cat-select').value;
        window.location = 'index.php?search=' + encodeURIComponent(q) + '&category=' + encodeURIComponent(cat);
    }
    document.getElementById('search-input').addEventListener('keypress', e => {
        if (e.key === 'Enter') doSearch();
    });

    // ── Tab filter ──
    function setTab(tab) {
        const p = new URLSearchParams(window.location.search);
        p.set('tab', tab);
        p.delete('page');
        window.location = 'index.php?' + p.toString();
    }

    // ── Category dropdown ──
    const catToggle = document.getElementById('cat-toggle');
    const catMenu   = document.getElementById('cat-menu');
    catToggle.addEventListener('click', e => {
        e.stopPropagation();
        catToggle.classList.toggle('open');
        catMenu.classList.toggle('open');
    });
    document.addEventListener('click', () => {
        catToggle.classList.remove('open');
        catMenu.classList.remove('open');
    });

    // ── Hero Slideshow ──
    const slides = document.querySelectorAll('.hero-slide');
    const dots   = document.querySelectorAll('.hero-dot');
    let current  = 0;
    let timer;

    function goToSlide(n) {
        slides[current].classList.remove('active');
        dots[current].classList.remove('active');
        current = (n + slides.length) % slides.length;
        slides[current].classList.add('active');
        dots[current].classList.add('active');
        syncHeight();
        resetTimer();
    }
    function changeSlide(dir) { goToSlide(current + dir); }
    function resetTimer() {
        clearInterval(timer);
        timer = setInterval(() => changeSlide(1), 5500);
    }
    function syncHeight() {
        document.getElementById('heroWrap').style.minHeight =
            slides[current].offsetHeight + 'px';
    }

    slides.forEach((s, i) => {
        if (i !== 0) {
            s.style.position = 'absolute';
            s.style.top = '0'; s.style.left = '0'; s.style.width = '100%';
        }
    });
    syncHeight();
    window.addEventListener('resize', syncHeight);
    resetTimer();
</script>
</body>
</html>