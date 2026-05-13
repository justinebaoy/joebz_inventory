<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Staff cannot access dashboard
if ($_SESSION['role'] !== 'admin') {
    header("Location: sales.php");
    exit;
}

// ── Core stats ────────────────────────────────────────────────────────────
$total_products         = $conn->query("SELECT COUNT(*) as total FROM items WHERE is_active = 1")->fetch_assoc()['total'];
$total_categories       = $conn->query("SELECT COUNT(*) as total FROM categories")->fetch_assoc()['total'];
$total_users            = $conn->query("SELECT COUNT(*) as total FROM users")->fetch_assoc()['total'];
$low_stock              = $conn->query("SELECT COUNT(*) as total FROM items WHERE stock <= 5 AND is_active = 1")->fetch_assoc()['total'];

// FIX: Use net revenue (total_amount - discount_amount) for today's sales
$today_row = $conn->query("
    SELECT
        COALESCE(SUM(total_amount - discount_amount), 0) AS net_revenue,
        COALESCE(SUM(total_amount), 0)                   AS gross_revenue,
        COUNT(*) AS tx_count
    FROM sales
    WHERE DATE(sale_date) = CURDATE()
")->fetch_assoc();
$total_sales_today        = $today_row['net_revenue'];
$total_transactions_today = $today_row['tx_count'];

// Yesterday for comparison
$yesterday_row = $conn->query("
    SELECT COALESCE(SUM(total_amount - discount_amount), 0) AS net_revenue
    FROM sales
    WHERE DATE(sale_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
")->fetch_assoc();
$yesterday_sales = $yesterday_row['net_revenue'];

// FIX: avg sale uses net amount
$avg_sale = $conn->query("SELECT COALESCE(AVG(total_amount - discount_amount), 0) as avg FROM sales")->fetch_assoc()['avg'];

// ── 7-day sparkline data ──────────────────────────────────────────────────
$sparkline_result = $conn->query("
    SELECT
        DATE(sale_date) AS day,
        COALESCE(SUM(total_amount - discount_amount), 0) AS revenue
    FROM sales
    WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(sale_date)
    ORDER BY day ASC
");
$sparkline_map = [];
while ($r = $sparkline_result->fetch_assoc()) {
    $sparkline_map[$r['day']] = (float)$r['revenue'];
}
// Fill all 7 days including zeros
$sparkline_labels = [];
$sparkline_values = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $sparkline_labels[] = date('D', strtotime($d));
    $sparkline_values[] = $sparkline_map[$d] ?? 0;
}

// ── Recent transactions — FIX: include customer_name ─────────────────────
$recent_sales = $conn->query("
    SELECT s.sale_id, s.total_amount, s.discount_amount,
           s.customer_name, s.sale_date, u.first_name AS cashier
    FROM sales s
    JOIN users u ON s.user_id = u.user_id
    ORDER BY s.sale_date DESC
    LIMIT 8
");

// ── Low stock items ───────────────────────────────────────────────────────
$low_stock_items = $conn->query("
    SELECT item_name, stock
    FROM items
    WHERE stock <= 5 AND is_active = 1
    ORDER BY stock ASC
    LIMIT 6
");

// ── Sales trend direction ─────────────────────────────────────────────────
$trend_pct = 0;
$trend_dir = 'flat';
if ($yesterday_sales > 0) {
    $trend_pct = (($total_sales_today - $yesterday_sales) / $yesterday_sales) * 100;
    $trend_dir = $trend_pct >= 0 ? 'up' : 'down';
} elseif ($total_sales_today > 0) {
    $trend_dir = 'up';
    $trend_pct = 100;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — JOEBZ POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: #3b82f6;
            --accent-glow: rgba(59,130,246,0.18);
            --up: #10b981;
            --down: #f43f5e;
            --warn: #f59e0b;
        }
        body { font-family: 'DM Sans', sans-serif; }
        h1, h2, .display { font-family: 'Syne', sans-serif; }
        .mono { font-family: 'DM Mono', monospace; }

        /* Staggered card reveal */
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .reveal { opacity: 0; animation: slideUp 0.45s ease forwards; }
        .reveal-1  { animation-delay: 0.05s; }
        .reveal-2  { animation-delay: 0.10s; }
        .reveal-3  { animation-delay: 0.15s; }
        .reveal-4  { animation-delay: 0.20s; }
        .reveal-5  { animation-delay: 0.25s; }
        .reveal-6  { animation-delay: 0.30s; }
        .reveal-7  { animation-delay: 0.38s; }
        .reveal-8  { animation-delay: 0.46s; }

        .stat-card {
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.35);
        }

        /* Accent bar on cards */
        .card-accent::before {
            content: '';
            display: block;
            width: 3px;
            height: 100%;
            border-radius: 9999px;
            position: absolute;
            left: 0; top: 0;
            background: var(--accent);
        }

        /* Pulse dot for live indicator */
        @keyframes pulse-ring {
            0%   { transform: scale(0.8); opacity: 1; }
            100% { transform: scale(1.8); opacity: 0; }
        }
        .live-dot::after {
            content: '';
            display: block;
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: var(--up);
            animation: pulse-ring 1.6s ease infinite;
        }
        .live-dot {
            position: relative;
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--up);
            display: inline-block;
        }

        /* Stock bar */
        .stock-bar-bg { background: rgba(255,255,255,0.06); border-radius: 9999px; height: 4px; }
        .stock-bar-fill { height: 4px; border-radius: 9999px; transition: width 0.6s ease; }

        /* Scrollbar styling */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 9999px; }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

<!-- ═══════════ SIDEBAR ═══════════ -->
<!-- FIX: added id="sidebar" so JS mobile toggle works -->
<aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 transform bg-slate-950 border-r border-slate-800 flex flex-col transition-transform duration-200 ease-out -translate-x-full md:translate-x-0">
    <div class="flex items-center gap-3 px-6 py-5 border-b border-slate-800">
        <img src="assets/logo.png" alt="JOEBZ Logo" class="w-10 h-10 rounded-xl object-cover">
        <span class="text-lg font-bold text-slate-100 tracking-tight" style="font-family:'Syne',sans-serif">JOEBZ</span>
    </div>
    <nav class="flex-1 px-4 py-4 space-y-1 overflow-y-auto">
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= basename($_SERVER['PHP_SELF'])=='dashboard.php'?'bg-blue-600/20 text-blue-200 font-medium':'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Dashboard
            </a>
            <a href="items.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= basename($_SERVER['PHP_SELF'])=='items.php'?'bg-blue-600/20 text-blue-200 font-medium':'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>
                Items
            </a>
            <a href="categories.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= basename($_SERVER['PHP_SELF'])=='categories.php'?'bg-blue-600/20 text-blue-200 font-medium':'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                Categories
            </a>
            <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= basename($_SERVER['PHP_SELF'])=='reports.php'?'bg-blue-600/20 text-blue-200 font-medium':'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                Reports
            </a>
            <a href="purchase_order.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= basename($_SERVER['PHP_SELF'])=='purchase_order.php'?'bg-blue-600/20 text-blue-200 font-medium':'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                Purchase Orders
            </a>
            <a href="users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= basename($_SERVER['PHP_SELF'])=='users.php'?'bg-blue-600/20 text-blue-200 font-medium':'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                Users
            </a>
        <?php endif; ?>
        <a href="sales.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= basename($_SERVER['PHP_SELF'])=='sales.php'?'bg-blue-600/20 text-blue-200 font-medium':'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            Point of Sale
        </a>
    </nav>
    <div class="px-4 py-4 border-t border-slate-800">
        <div class="flex items-center gap-3 px-3 py-2 rounded-xl bg-slate-900 mb-2">
            <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold">
                <?= strtoupper(substr($_SESSION['first_name'], 0, 1)) ?>
            </div>
            <div>
                <p class="text-sm font-medium text-slate-100"><?= htmlspecialchars($_SESSION['first_name']) ?></p>
                <p class="text-xs text-slate-400 capitalize"><?= $_SESSION['role'] ?></p>
            </div>
        </div>
        <a href="logout.php" class="flex items-center gap-3 px-3 py-2 rounded-xl text-red-300 hover:bg-red-900/40 text-sm transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </div>
</aside>

<!-- ═══════════ MAIN ═══════════ -->
<div class="flex-1 md:ml-64 min-h-screen">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6">

        <!-- Mobile top bar -->
        <div class="mb-5 flex items-center justify-between md:hidden">
            <button id="open-sidebar" class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium text-slate-200">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>Menu
            </button>
            <a href="logout.php" class="text-sm text-red-300 border border-red-800/40 bg-red-900/20 px-3 py-2 rounded-xl">Logout</a>
        </div>

        <!-- Page header -->
        <div class="mb-8 flex items-start justify-between reveal reveal-1">
            <div>
                <h1 class="text-3xl font-bold text-white tracking-tight">Dashboard</h1>
                <p class="text-slate-400 mt-1 text-sm">
                    Welcome back, <span class="text-blue-300"><?= htmlspecialchars($_SESSION['first_name']) ?></span>
                    &nbsp;·&nbsp; <?= date('l, F j, Y') ?>
                </p>
            </div>
            <!-- Live indicator -->
            <div class="flex items-center gap-2 text-xs text-slate-400 bg-slate-900 border border-slate-800 px-3 py-1.5 rounded-full">
                <span class="live-dot"></span>
                <span id="liveTime" class="mono"></span>
            </div>
        </div>

        <!-- ══ STAT CARDS ══ -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">

            <!-- Today's Revenue (net) -->
            <div class="stat-card col-span-2 sm:col-span-2 lg:col-span-2 relative overflow-hidden bg-slate-900 rounded-2xl border border-slate-800 p-5 reveal reveal-2">
                <div class="absolute inset-0 bg-gradient-to-br from-blue-600/10 to-transparent pointer-events-none"></div>
                <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Revenue Today</p>
                <p class="text-3xl font-bold text-white mono" id="liveSalesToday">₱<?= number_format($total_sales_today, 2) ?></p>
                <div class="flex items-center gap-2 mt-2">
                    <span class="text-xs mono <?= $trend_dir === 'up' ? 'text-emerald-400' : ($trend_dir === 'down' ? 'text-red-400' : 'text-slate-500') ?>">
                        <?= $trend_dir === 'up' ? '↑' : ($trend_dir === 'down' ? '↓' : '—') ?>
                        <?= number_format(abs($trend_pct), 1) ?>% vs yesterday
                    </span>
                </div>
                <p class="text-xs text-slate-500 mt-1" id="liveTxCount"><?= $total_transactions_today ?> transactions</p>
            </div>

            <!-- Transactions today -->
            <div class="stat-card bg-slate-900 rounded-2xl border border-slate-800 p-5 reveal reveal-3">
                <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Transactions</p>
                <p class="text-2xl font-bold text-white mono" id="liveTx"><?= $total_transactions_today ?></p>
                <p class="text-xs text-slate-500 mt-2">today</p>
            </div>

            <!-- Avg Sale (net) -->
            <div class="stat-card bg-slate-900 rounded-2xl border border-slate-800 p-5 reveal reveal-3">
                <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Avg Sale</p>
                <p class="text-2xl font-bold text-white mono">₱<?= number_format($avg_sale, 2) ?></p>
                <p class="text-xs text-slate-500 mt-2">net avg</p>
            </div>

            <!-- Products -->
            <div class="stat-card bg-slate-900 rounded-2xl border border-slate-800 p-5 reveal reveal-4">
                <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Products</p>
                <p class="text-2xl font-bold text-white mono"><?= $total_products ?></p>
                <p class="text-xs text-slate-500 mt-2"><?= $total_categories ?> categories</p>
            </div>

            <!-- Low Stock -->
            <div class="stat-card bg-slate-900 rounded-2xl border border-slate-800 p-5 reveal reveal-4 <?= $low_stock > 0 ? 'border-amber-700/50' : '' ?>">
                <p class="text-xs <?= $low_stock > 0 ? 'text-amber-400' : 'text-slate-400' ?> uppercase tracking-widest mb-1">Low Stock</p>
                <p class="text-2xl font-bold mono <?= $low_stock > 0 ? 'text-amber-400' : 'text-white' ?>"><?= $low_stock ?></p>
                <p class="text-xs text-slate-500 mt-2"><?= $low_stock > 0 ? 'need restocking' : 'all stocked' ?></p>
            </div>

        </div>

        <!-- ══ CHART + LOW STOCK ROW ══ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">

            <!-- 7-day sparkline chart -->
            <div class="lg:col-span-2 bg-slate-900 rounded-2xl border border-slate-800 p-5 reveal reveal-5">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">7-Day Revenue</h2>
                        <p class="text-xs text-slate-500">Net revenue after discounts</p>
                    </div>
                    <span class="text-xs text-slate-500 bg-slate-800 px-2 py-1 rounded-lg mono">last 7 days</span>
                </div>
                <div class="relative h-48">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <!-- Low stock list -->
            <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden reveal reveal-5">
                <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">Low Stock</h2>
                    <?php if ($low_stock > 0): ?>
                    <span class="text-xs bg-amber-500/15 text-amber-400 border border-amber-500/25 px-2 py-0.5 rounded-full"><?= $low_stock ?> items</span>
                    <?php endif; ?>
                </div>
                <div class="p-4 space-y-3">
                    <?php if ($low_stock_items->num_rows > 0):
                        while ($item = $low_stock_items->fetch_assoc()):
                            $pct = max(4, ($item['stock'] / 5) * 100);
                            $barColor = $item['stock'] <= 1 ? '#f43f5e' : ($item['stock'] <= 3 ? '#f59e0b' : '#10b981');
                    ?>
                    <div>
                        <div class="flex justify-between items-center mb-1">
                            <span class="text-sm text-slate-200 truncate max-w-[70%]"><?= htmlspecialchars($item['item_name']) ?></span>
                            <span class="mono text-xs font-bold <?= $item['stock'] <= 1 ? 'text-red-400' : ($item['stock'] <= 3 ? 'text-amber-400' : 'text-emerald-400') ?>"><?= $item['stock'] ?> left</span>
                        </div>
                        <div class="stock-bar-bg">
                            <div class="stock-bar-fill" style="width:<?= $pct ?>%; background:<?= $barColor ?>"></div>
                        </div>
                    </div>
                    <?php endwhile; else: ?>
                    <div class="text-center py-8 text-slate-500 text-sm">
                        <svg class="w-8 h-8 mx-auto mb-2 text-emerald-600/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        All items well stocked
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ══ RECENT TRANSACTIONS ══ -->
        <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden reveal reveal-7">
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">Recent Transactions</h2>
                <a href="reports.php" class="text-xs text-blue-400 hover:text-blue-300 transition">View all →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wider">
                            <th class="px-6 py-3 text-left">Sale #</th>
                            <th class="px-6 py-3 text-left">Customer</th>
                            <th class="px-6 py-3 text-left">Cashier</th>
                            <th class="px-6 py-3 text-left">Date & Time</th>
                            <th class="px-6 py-3 text-right">Net Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_sales->num_rows > 0):
                            $row_i = 0;
                            while ($sale = $recent_sales->fetch_assoc()):
                                $row_i++;
                                $net = $sale['total_amount'] - $sale['discount_amount'];
                                // FIX: show customer_name with Walk-in fallback
                                $customer = !empty($sale['customer_name']) ? $sale['customer_name'] : 'Walk-in Customer';
                                $is_walkin = empty($sale['customer_name']);
                        ?>
                        <tr class="border-b border-slate-800/60 hover:bg-slate-800/40 transition">
                            <td class="px-6 py-3.5">
                                <span class="mono text-blue-400 font-medium">#<?= str_pad($sale['sale_id'], 4, '0', STR_PAD_LEFT) ?></span>
                            </td>
                            <td class="px-6 py-3.5">
                                <span class="<?= $is_walkin ? 'text-slate-500 italic' : 'text-slate-200' ?>">
                                    <?= htmlspecialchars($customer) ?>
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-slate-400"><?= htmlspecialchars($sale['cashier']) ?></td>
                            <td class="px-6 py-3.5 text-slate-500 mono text-xs"><?= date('M d, Y · h:i A', strtotime($sale['sale_date'])) ?></td>
                            <td class="px-6 py-3.5 text-right">
                                <span class="text-white font-bold mono">₱<?= number_format($net, 2) ?></span>
                                <?php if ($sale['discount_amount'] > 0): ?>
                                <p class="text-xs text-emerald-400 mono">-₱<?= number_format($sale['discount_amount'], 2) ?> disc.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="5" class="px-6 py-10 text-center text-slate-500">No transactions yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /max-w -->
</div><!-- /main -->

<script>
// ── Sidebar toggle (FIX: id="sidebar" now exists) ──────────────────────────
const sidebar       = document.getElementById('sidebar');
const openSidebarBtn = document.getElementById('open-sidebar');

function openSidebar()  { sidebar.classList.remove('-translate-x-full'); }
function closeSidebar() { sidebar.classList.add('-translate-x-full'); }

if (openSidebarBtn) openSidebarBtn.addEventListener('click', openSidebar);
document.addEventListener('click', function (e) {
    if (window.innerWidth < 768 && sidebar &&
        !sidebar.contains(e.target) &&
        openSidebarBtn && !openSidebarBtn.contains(e.target)) {
        closeSidebar();
    }
});
window.addEventListener('resize', function () {
    if (window.innerWidth >= 768) sidebar.classList.remove('-translate-x-full');
    else sidebar.classList.add('-translate-x-full');
});

// ── Live clock ─────────────────────────────────────────────────────────────
function updateClock() {
    const now = new Date();
    document.getElementById('liveTime').textContent =
        now.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
updateClock();
setInterval(updateClock, 1000);

// ── Auto-refresh stats every 60 seconds ───────────────────────────────────
setInterval(function () {
    fetch('dashboard_stats.php')   // lightweight JSON endpoint (see note below)
        .then(r => r.json())
        .then(d => {
            if (d.net_revenue   !== undefined) document.getElementById('liveSalesToday').textContent = '₱' + d.net_revenue;
            if (d.tx_count      !== undefined) document.getElementById('liveTxCount').textContent    = d.tx_count + ' transactions';
            if (d.tx_count      !== undefined) document.getElementById('liveTx').textContent         = d.tx_count;
        })
        .catch(() => {}); // silent fail if endpoint not yet created
}, 60000);

// ── 7-day Revenue Chart ────────────────────────────────────────────────────
const labels = <?= json_encode($sparkline_labels) ?>;
const values = <?= json_encode($sparkline_values) ?>;
const maxVal = Math.max(...values, 1);

const ctx = document.getElementById('revenueChart').getContext('2d');

const gradient = ctx.createLinearGradient(0, 0, 0, 180);
gradient.addColorStop(0,   'rgba(59,130,246,0.35)');
gradient.addColorStop(1,   'rgba(59,130,246,0.00)');

new Chart(ctx, {
    type: 'line',
    data: {
        labels,
        datasets: [{
            data: values,
            borderColor: '#3b82f6',
            borderWidth: 2.5,
            backgroundColor: gradient,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#3b82f6',
            pointRadius: 4,
            pointHoverRadius: 6,
            pointBorderColor: '#0f172a',
            pointBorderWidth: 2,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1e293b',
                borderColor: '#334155',
                borderWidth: 1,
                titleColor: '#94a3b8',
                bodyColor: '#f1f5f9',
                callbacks: {
                    label: ctx => ' ₱' + Number(ctx.raw).toLocaleString('en-PH', { minimumFractionDigits: 2 })
                }
            }
        },
        scales: {
            x: {
                grid: { color: 'rgba(255,255,255,0.04)' },
                ticks: { color: '#64748b', font: { family: 'DM Mono', size: 11 } }
            },
            y: {
                grid: { color: 'rgba(255,255,255,0.04)' },
                ticks: {
                    color: '#64748b',
                    font: { family: 'DM Mono', size: 11 },
                    callback: v => '₱' + (v >= 1000 ? (v/1000).toFixed(1)+'k' : v)
                },
                beginAtZero: true,
            }
        }
    }
});
</script>
</body>
</html>