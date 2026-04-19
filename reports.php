<?php
session_start();
require_once 'config/db.php';

// Protect page - must be logged in and must be admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$success = '';
$error = '';

// ── GET REPORT DATA ──────────────────────────────────

// Date range for reports
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// Sales Summary
$sales_query = $conn->prepare("
    SELECT
        COUNT(*) as total_transactions,
        COALESCE(SUM(total_amount), 0) as total_sales,
        COALESCE(SUM(cash_received), 0) as total_cash_received,
        COALESCE(SUM(change_amount), 0) as total_change_given,
        COALESCE(AVG(total_amount), 0) as avg_transaction,
        MIN(total_amount) as min_sale,
        MAX(total_amount) as max_sale
    FROM sales
    WHERE DATE(sale_date) BETWEEN ? AND ?
");
$sales_query->bind_param("ss", $start_date, $end_date);
$sales_query->execute();
$sales_summary = $sales_query->get_result()->fetch_assoc();
$sales_query->close();

// Daily Sales Chart Data
$daily_sales = [];
$current_date = strtotime($start_date);
$end_timestamp = strtotime($end_date);

while ($current_date <= $end_timestamp) {
    $date = date('Y-m-d', $current_date);
    $day_query = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as daily_total
        FROM sales
        WHERE DATE(sale_date) = ?
    ");
    $day_query->bind_param("s", $date);
    $day_query->execute();
    $daily_total = $day_query->get_result()->fetch_assoc()['daily_total'];
    $day_query->close();

    $daily_sales[] = [
        'date' => date('M d', $current_date),
        'amount' => (float)$daily_total
    ];

    $current_date = strtotime('+1 day', $current_date);
}

// Top Selling Items
$top_items_query = $conn->prepare("
    SELECT
        i.item_name,
        c.category_name,
        SUM(si.quantity) as total_quantity,
        SUM(si.quantity * si.price) as total_revenue
    FROM sale_items si
    JOIN items i ON si.item_id = i.item_id
    JOIN categories c ON i.category_id = c.category_id
    JOIN sales s ON si.sale_id = s.sale_id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY i.item_id, i.item_name, c.category_name
    ORDER BY total_quantity DESC
    LIMIT 10
");
$top_items_query->bind_param("ss", $start_date, $end_date);
$top_items_query->execute();
$top_items_result = $top_items_query->get_result();
$top_items_query->close();

// Sales by Category
$category_sales_query = $conn->prepare("
    SELECT
        c.category_name,
        COUNT(DISTINCT s.sale_id) as transactions,
        SUM(si.quantity) as items_sold,
        SUM(si.quantity * si.price) as revenue
    FROM sale_items si
    JOIN items i ON si.item_id = i.item_id
    JOIN categories c ON i.category_id = c.category_id
    JOIN sales s ON si.sale_id = s.sale_id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY c.category_id, c.category_name
    ORDER BY revenue DESC
");
$category_sales_query->bind_param("ss", $start_date, $end_date);
$category_sales_query->execute();
$category_sales_result = $category_sales_query->get_result();
$category_sales_query->close();

// Inventory Status
$inventory_query = $conn->query("
    SELECT
        COUNT(*) as total_items,
        SUM(stock) as total_stock_value,
        COUNT(CASE WHEN stock <= 5 THEN 1 END) as low_stock_items,
        COUNT(CASE WHEN stock = 0 THEN 1 END) as out_of_stock_items
    FROM items
");
$inventory_summary = $inventory_query->fetch_assoc();

// Low Stock Items
$low_stock_result = $conn->query("
    SELECT i.item_name, i.stock, c.category_name
    FROM items i
    JOIN categories c ON i.category_id = c.category_id
    WHERE i.stock <= 5
    ORDER BY i.stock ASC
    LIMIT 10
");

// User Performance
$user_performance_query = $conn->prepare("
    SELECT
        u.first_name,
        u.last_name,
        COUNT(s.sale_id) as transactions,
        COALESCE(SUM(s.total_amount), 0) as total_sales
    FROM users u
    LEFT JOIN sales s ON u.user_id = s.user_id AND DATE(s.sale_date) BETWEEN ? AND ?
    GROUP BY u.user_id, u.first_name, u.last_name
    ORDER BY total_sales DESC
    LIMIT 10
");
$user_performance_query->bind_param("ss", $start_date, $end_date);
$user_performance_query->execute();
$user_performance_result = $user_performance_query->get_result();
$user_performance_query->close();

// Monthly Comparison (current vs previous month)
$current_month = date('Y-m');
$prev_month = date('Y-m', strtotime('-1 month'));

$current_month_sales = $conn->query("
    SELECT COALESCE(SUM(total_amount), 0) as total
    FROM sales
    WHERE DATE_FORMAT(sale_date, '%Y-%m') = '$current_month'
")->fetch_assoc()['total'];

$prev_month_sales = $conn->query("
    SELECT COALESCE(SUM(total_amount), 0) as total
    FROM sales
    WHERE DATE_FORMAT(sale_date, '%Y-%m') = '$prev_month'
")->fetch_assoc()['total'];

$monthly_growth = $prev_month_sales > 0 ? (($current_month_sales - $prev_month_sales) / $prev_month_sales) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reports — JOEBZ Inventory</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

<!-- ── SIDEBAR ── -->
<div class="flex min-h-screen">
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 transform bg-slate-950 border-r border-slate-800 flex flex-col transition-transform duration-200 ease-out -translate-x-full md:translate-x-0">

    <!-- Logo -->
    <div class="flex items-center gap-3 px-6 py-5 border-b border-slate-800">
      <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center">
        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-bold text-slate-100">JOEBZ</p>
        <p class="text-xs text-slate-400">POINT-OF-SALE SYSTEM</p>
      </div>
    </div>

    <!-- Nav -->
    <nav class="flex-1 px-4 py-4 space-y-1 overflow-y-auto">

      <!-- Dashboard -->
      <a href="dashboard.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
        </svg>
        Dashboard
      </a>

      <!-- Sales -->
      <a href="sales.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
        </svg>
        Sales / POS
      </a>

      <!-- Items -->
      <a href="items.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>
        </svg>
        Items
      </a>

      <!-- Categories -->
      <a href="categories.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
        </svg>
        Categories
      </a>

      <!-- Reports - Admin only -->
      <a href="reports.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-blue-900 text-white font-medium text-sm shadow-sm shadow-blue-900/20">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
        </svg>
        Reports
      </a>

      <!-- Users - Admin only -->
      <a href="users.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
        </svg>
        Users
      </a>

    </nav>

    <!-- User Info + Logout -->
    <div class="px-4 py-4 border-t border-slate-800">
      <div class="flex items-center gap-3 px-3 py-2 rounded-xl bg-slate-900 mb-2">
        <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold">
          <?= strtoupper(substr($_SESSION['first_name'], 0, 1)) ?>
        </div>
        <div>
          <p class="text-sm font-medium text-slate-100">
            <?= htmlspecialchars($_SESSION['first_name']) ?>
          </p>
          <p class="text-xs text-slate-400 capitalize"><?= $_SESSION['role'] ?></p>
        </div>
      </div>
      <a href="logout.php"
         class="flex items-center gap-3 px-3 py-2 rounded-xl text-red-300 hover:bg-red-800 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
        </svg>
        Logout
      </a>
    </div>
  </aside>

  <!-- ── MAIN CONTENT ── -->
  <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-black/60 md:hidden"></div>

  <!-- ── MAIN CONTENT ── -->
  <main class="flex-1 w-full overflow-y-auto p-4 sm:p-6 md:ml-64 bg-slate-950/40">

    <div class="mb-4 flex items-center justify-between gap-3 md:hidden">
      <button type="button" id="open-sidebar" class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium text-slate-200">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
        Menu
      </button>
      <a href="logout.php" class="inline-flex items-center gap-2 rounded-xl border border-red-700/50 bg-red-900/20 px-3 py-2 text-sm text-red-200">Logout</a>
    </div>

    <!-- Header -->
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-slate-100">Reports & Analytics</h1>
      <p class="text-sm text-slate-400 mt-1">
        Comprehensive business insights and performance metrics
      </p>
    </div>

    <!-- Date Range Filter -->
    <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20 p-6 mb-6">
      <form method="GET" class="flex flex-wrap gap-4 items-end">
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-2">Start Date</label>
          <input type="date" name="start_date" value="<?= $start_date ?>"
                 class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-2">End Date</label>
          <input type="date" name="end_date" value="<?= $end_date ?>"
                 class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <button type="submit"
                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded-xl text-white font-medium transition">
          Generate Report
        </button>
      </form>
    </div>

    <!-- ── SALES SUMMARY CARDS ── -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4 mb-6">

      <!-- Total Sales -->
      <div class="bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl shadow-black/20">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm text-slate-400 font-medium">Total Sales</p>
          <div class="w-9 h-9 bg-green-600 rounded-xl flex items-center justify-center text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>
        </div>
        <p class="text-2xl font-bold text-slate-100">
          ₱<?= number_format($sales_summary['total_sales'], 2) ?>
        </p>
        <p class="text-xs text-slate-400 mt-1">
          <?= $sales_summary['total_transactions'] ?> transactions
        </p>
      </div>

      <!-- Average Transaction -->
      <div class="bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl shadow-black/20">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm text-slate-400 font-medium">Avg Transaction</p>
          <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
          </div>
        </div>
        <p class="text-2xl font-bold text-slate-100">
          ₱<?= number_format($sales_summary['avg_transaction'], 2) ?>
        </p>
        <p class="text-xs text-slate-400 mt-1">
          Per transaction
        </p>
      </div>

      <!-- Monthly Growth -->
      <div class="bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl shadow-black/20">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm text-slate-400 font-medium">Monthly Growth</p>
          <div class="w-9 h-9 bg-indigo-600 rounded-xl flex items-center justify-center text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
            </svg>
          </div>
        </div>
        <p class="text-2xl font-bold text-slate-100
                   <?= $monthly_growth >= 0 ? 'text-green-400' : 'text-red-400' ?>">
          <?= $monthly_growth >= 0 ? '+' : '' ?><?= number_format($monthly_growth, 1) ?>%
        </p>
        <p class="text-xs text-slate-400 mt-1">
          vs last month
        </p>
      </div>

      <!-- Low Stock Items -->
      <div class="bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl shadow-black/20">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm text-slate-400 font-medium">Low Stock Alert</p>
          <div class="w-9 h-9 bg-red-600 rounded-xl flex items-center justify-center text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
          </div>
        </div>
        <p class="text-2xl font-bold text-slate-100">
          <?= $inventory_summary['low_stock_items'] ?>
        </p>
        <p class="text-xs text-slate-400 mt-1">
          Items need restocking
        </p>
      </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">

      <!-- ── SALES CHART ── -->
      <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20">
        <div class="p-6 border-b border-slate-800">
          <h2 class="text-lg font-bold text-slate-100">Daily Sales Trend</h2>
          <p class="text-sm text-slate-400 mt-1">Sales performance over selected period</p>
        </div>
        <div class="p-6">
          <canvas id="salesChart" width="400" height="200"></canvas>
        </div>
      </div>

      <!-- ── TOP SELLING ITEMS ── -->
      <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20">
        <div class="p-6 border-b border-slate-800">
          <h2 class="text-lg font-bold text-slate-100">Top Selling Items</h2>
          <p class="text-sm text-slate-400 mt-1">Best performing products</p>
        </div>
        <div class="p-6">
          <div class="space-y-4">
            <?php while ($item = $top_items_result->fetch_assoc()): ?>
            <div class="flex items-center justify-between py-2 border-b border-slate-800">
              <div class="flex-1">
                <p class="text-sm font-medium text-slate-100">
                  <?= htmlspecialchars($item['item_name']) ?>
                </p>
                <p class="text-xs text-slate-400">
                  <?= htmlspecialchars($item['category_name']) ?>
                </p>
              </div>
              <div class="text-right">
                <p class="text-sm font-bold text-blue-400">
                  ₱<?= number_format($item['total_revenue'], 2) ?>
                </p>
                <p class="text-xs text-slate-400">
                  <?= $item['total_quantity'] ?> sold
                </p>
              </div>
            </div>
            <?php endwhile; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

      <!-- ── SALES BY CATEGORY ── -->
      <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20">
        <div class="p-6 border-b border-slate-800">
          <h2 class="text-lg font-bold text-slate-100">Sales by Category</h2>
          <p class="text-sm text-slate-400 mt-1">Revenue breakdown by product categories</p>
        </div>
        <div class="p-6">
          <div class="space-y-4">
            <?php while ($category = $category_sales_result->fetch_assoc()): ?>
            <div class="flex items-center justify-between py-3 border-b border-slate-800">
              <div class="flex-1">
                <p class="text-sm font-medium text-slate-100">
                  <?= htmlspecialchars($category['category_name']) ?>
                </p>
                <p class="text-xs text-slate-400">
                  <?= $category['transactions'] ?> transactions
                </p>
              </div>
              <div class="text-right">
                <p class="text-sm font-bold text-green-400">
                  ₱<?= number_format($category['revenue'], 2) ?>
                </p>
                <p class="text-xs text-slate-400">
                  <?= $category['items_sold'] ?> items
                </p>
              </div>
            </div>
            <?php endwhile; ?>
          </div>
        </div>
      </div>

      <!-- ── USER PERFORMANCE ── -->
      <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20">
        <div class="p-6 border-b border-slate-800">
          <h2 class="text-lg font-bold text-slate-100">User Performance</h2>
          <p class="text-sm text-slate-400 mt-1">Sales performance by staff member</p>
        </div>
        <div class="p-6">
          <div class="space-y-4">
            <?php while ($user = $user_performance_result->fetch_assoc()): ?>
            <div class="flex items-center justify-between py-3 border-b border-slate-800">
              <div class="flex-1">
                <p class="text-sm font-medium text-slate-100">
                  <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
                </p>
                <p class="text-xs text-slate-400">
                  <?= $user['transactions'] ?> transactions
                </p>
              </div>
              <div class="text-right">
                <p class="text-sm font-bold text-blue-400">
                  ₱<?= number_format($user['total_sales'], 2) ?>
                </p>
              </div>
            </div>
            <?php endwhile; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ── LOW STOCK ITEMS ── -->
    <div class="mt-6 bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20">
      <div class="p-6 border-b border-slate-800">
        <h2 class="text-lg font-bold text-slate-100">Low Stock Items</h2>
        <p class="text-sm text-slate-400 mt-1">Items that need immediate restocking</p>
      </div>
      <div class="p-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <?php while ($item = $low_stock_result->fetch_assoc()): ?>
          <div class="bg-red-900/20 border border-red-700 rounded-xl p-4">
            <h3 class="font-medium text-slate-100 mb-1">
              <?= htmlspecialchars($item['item_name']) ?>
            </h3>
            <p class="text-xs text-slate-400 mb-2">
              <?= htmlspecialchars($item['category_name']) ?>
            </p>
            <p class="text-sm font-bold text-red-400">
              Only <?= $item['stock'] ?> left in stock
            </p>
          </div>
          <?php endwhile; ?>
        </div>
      </div>
    </div>

  </main>
</div>

<script>
const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebar-overlay');
const openSidebarBtn = document.getElementById('open-sidebar');

function openSidebar() {
  if (!sidebar || window.innerWidth >= 768) return;
  sidebar.classList.remove('-translate-x-full');
  sidebarOverlay.classList.remove('hidden');
}

function closeSidebar() {
  if (!sidebar || window.innerWidth >= 768) return;
  sidebar.classList.add('-translate-x-full');
  sidebarOverlay.classList.add('hidden');
}

if (openSidebarBtn) {
  openSidebarBtn.addEventListener('click', openSidebar);
}
if (sidebarOverlay) {
  sidebarOverlay.addEventListener('click', closeSidebar);
}
window.addEventListener('resize', () => {
  if (!sidebar || !sidebarOverlay) return;
  if (window.innerWidth >= 768) {
    sidebar.classList.remove('-translate-x-full');
    sidebarOverlay.classList.add('hidden');
  } else {
    sidebar.classList.add('-translate-x-full');
  }
});

// Sales Chart
const ctx = document.getElementById('salesChart').getContext('2d');
const salesChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($daily_sales, 'date')) ?>,
        datasets: [{
            label: 'Daily Sales (₱)',
            data: <?= json_encode(array_column($daily_sales, 'amount')) ?>,
            borderColor: 'rgb(59, 130, 246)',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '₱' + value.toLocaleString();
                    },
                    color: '#94a3b8'
                },
                grid: {
                    color: '#374151'
                }
            },
            x: {
                ticks: {
                    color: '#94a3b8'
                },
                grid: {
                    color: '#374151'
                }
            }
        },
        elements: {
            point: {
                radius: 4,
                hoverRadius: 6
            }
        }
    }
});
</script>

</body>
</html>