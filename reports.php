<?php
session_start();
require_once 'config/db.php';

// Protect page - must be logged in and must be admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// Date range for reports
$start_date = isset($_REQUEST['start_date']) ? $_REQUEST['start_date'] : date('Y-m-01');
$end_date = isset($_REQUEST['end_date']) ? $_REQUEST['end_date'] : date('Y-m-t');

$success = isset($_GET['success']) ? $_GET['success'] : '';
$error   = isset($_GET['error'])   ? $_GET['error']   : '';

// ── EXPORT TO CSV ───────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_type = $_GET['export_type'] ?? 'sales';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="joebz_' . $export_type . '_report_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    
    if ($export_type === 'sales') {
        fputcsv($output, ['SALE ID', 'DATE', 'TIME', 'TOTAL (₱)', 'CASH (₱)', 'CHANGE (₱)', 'CASHIER', 'CUSTOMER']);
        $stmt = $conn->prepare("SELECT s.sale_id, s.sale_date, s.total_amount, s.cash_received, s.change_amount, u.first_name, u.last_name, c.name as customer_name FROM sales s JOIN users u ON s.user_id = u.user_id LEFT JOIN customers c ON s.customer_id = c.customer_id WHERE DATE(s.sale_date) BETWEEN ? AND ? ORDER BY s.sale_date DESC");
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['sale_id'], date('Y-m-d', strtotime($row['sale_date'])), date('H:i:s', strtotime($row['sale_date'])), number_format($row['total_amount'], 2), number_format($row['cash_received'], 2), number_format($row['change_amount'], 2), $row['first_name'] . ' ' . $row['last_name'], $row['customer_name'] ?? 'Walk-in Customer']);
        }
    }
    elseif ($export_type === 'items') {
        fputcsv($output, ['ITEM ID', 'ITEM NAME', 'CATEGORY', 'STOCK', 'PRICE (₱)', 'STATUS']);
        $result = $conn->query("SELECT i.item_id, i.item_name, i.stock, i.price, i.is_active, c.category_name FROM items i JOIN categories c ON i.category_id = c.category_id ORDER BY c.category_name, i.item_name");
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['item_id'], $row['item_name'], $row['category_name'], $row['stock'], number_format($row['price'], 2), $row['is_active'] ? 'Active' : 'Inactive']);
        }
    }
    elseif ($export_type === 'customers') {
        fputcsv($output, ['CUSTOMER ID', 'NAME', 'PHONE', 'EMAIL', 'TOTAL PURCHASES (₱)', 'LOYALTY POINTS', 'MEMBER SINCE']);
        $result = $conn->query("SELECT customer_id, name, phone, email, total_purchases, loyalty_points, created_at FROM customers ORDER BY total_purchases DESC");
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['customer_id'], $row['name'], $row['phone'] ?? 'N/A', $row['email'] ?? 'N/A', number_format($row['total_purchases'] ?? 0, 2), $row['loyalty_points'] ?? 0, date('Y-m-d', strtotime($row['created_at']))]);
        }
    }
    elseif ($export_type === 'lowstock') {
        fputcsv($output, ['ITEM ID', 'ITEM NAME', 'CATEGORY', 'CURRENT STOCK', 'STATUS', 'RECOMMENDED ACTION']);
        $result = $conn->query("SELECT i.item_id, i.item_name, i.stock, c.category_name FROM items i JOIN categories c ON i.category_id = c.category_id WHERE i.stock <= 5 AND i.is_active = 1 ORDER BY i.stock ASC");
        while ($row = $result->fetch_assoc()) {
            $status = $row['stock'] == 0 ? 'OUT OF STOCK' : ($row['stock'] <= 2 ? 'CRITICAL' : 'LOW');
            $action = $row['stock'] == 0 ? 'ORDER IMMEDIATELY' : ($row['stock'] <= 2 ? 'URGENT RESTOCK' : 'Plan restock');
            fputcsv($output, [$row['item_id'], $row['item_name'], $row['category_name'], $row['stock'], $status, $action]);
        }
    }
    elseif ($export_type === 'users') {
        fputcsv($output, ['USER ID', 'NAME', 'USERNAME', 'EMAIL', 'ROLE', 'REGISTERED DATE']);
        $result = $conn->query("SELECT user_id, first_name, last_name, username, email, role, created_at FROM users ORDER BY created_at DESC");
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['user_id'], $row['first_name'] . ' ' . $row['last_name'], $row['username'], $row['email'], ucfirst($row['role']), date('Y-m-d', strtotime($row['created_at']))]);
        }
    }
    elseif ($export_type === 'category') {
        fputcsv($output, ['CATEGORY', 'TOTAL TRANSACTIONS', 'ITEMS SOLD', 'TOTAL REVENUE (₱)']);
        $stmt = $conn->prepare("SELECT c.category_name, COUNT(DISTINCT s.sale_id) as transactions, SUM(si.quantity) as items_sold, SUM(si.quantity * si.price) as revenue FROM sale_items si JOIN items i ON si.item_id = i.item_id JOIN categories c ON i.category_id = c.category_id JOIN sales s ON si.sale_id = s.sale_id WHERE DATE(s.sale_date) BETWEEN ? AND ? GROUP BY c.category_id, c.category_name ORDER BY revenue DESC");
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['category_name'], $row['transactions'], $row['items_sold'], number_format($row['revenue'], 2)]);
        }
    }
    fclose($output);
    exit;
}

// ── DELETE TRANSACTION ───────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete_sale') {
    $sale_id = (int)$_POST['sale_id'];
    $restore_stmt = $conn->prepare("UPDATE items i JOIN sale_items si ON i.item_id = si.item_id SET i.stock = i.stock + si.quantity WHERE si.sale_id = ?");
    $restore_stmt->bind_param("i", $sale_id);
    $restore_stmt->execute();
    $restore_stmt->close();
    $del_items = $conn->prepare("DELETE FROM sale_items WHERE sale_id = ?");
    $del_items->bind_param("i", $sale_id);
    $del_items->execute();
    $del_items->close();
    $stmt = $conn->prepare("DELETE FROM sales WHERE sale_id = ?");
    $stmt->bind_param("i", $sale_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        header("Location: reports.php?start_date=" . urlencode($start_date) . "&end_date=" . urlencode($end_date) . "&success=" . urlencode("Transaction #{$sale_id} deleted successfully and stock restored."));
        exit;
    } else {
        header("Location: reports.php?start_date=" . urlencode($start_date) . "&end_date=" . urlencode($end_date) . "&error=" . urlencode("Failed to delete transaction #{$sale_id}."));
        exit;
    }
    $stmt->close();
}

// ── GET REPORT DATA ──────────────────────────────────
$sales_query = $conn->prepare("SELECT COUNT(*) as total_transactions, COALESCE(SUM(total_amount), 0) as total_sales, COALESCE(SUM(cash_received), 0) as total_cash_received, COALESCE(SUM(change_amount), 0) as total_change_given, COALESCE(AVG(total_amount), 0) as avg_transaction, MIN(total_amount) as min_sale, MAX(total_amount) as max_sale FROM sales WHERE DATE(sale_date) BETWEEN ? AND ?");
$sales_query->bind_param("ss", $start_date, $end_date);
$sales_query->execute();
$sales_summary = $sales_query->get_result()->fetch_assoc();
$sales_query->close();

$daily_sales = [];
$current_date = strtotime($start_date);
$end_timestamp = strtotime($end_date);
while ($current_date <= $end_timestamp) {
    $date = date('Y-m-d', $current_date);
    $day_query = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as daily_total FROM sales WHERE DATE(sale_date) = ?");
    $day_query->bind_param("s", $date);
    $day_query->execute();
    $daily_total = $day_query->get_result()->fetch_assoc()['daily_total'];
    $day_query->close();
    $daily_sales[] = ['date' => date('M d', $current_date), 'amount' => (float)$daily_total];
    $current_date = strtotime('+1 day', $current_date);
}

$top_items_query = $conn->prepare("SELECT i.item_name, c.category_name, SUM(si.quantity) as total_quantity, SUM(si.quantity * si.price) as total_revenue FROM sale_items si JOIN items i ON si.item_id = i.item_id JOIN categories c ON i.category_id = c.category_id JOIN sales s ON si.sale_id = s.sale_id WHERE DATE(s.sale_date) BETWEEN ? AND ? GROUP BY i.item_id, i.item_name, c.category_name ORDER BY total_quantity DESC LIMIT 10");
$top_items_query->bind_param("ss", $start_date, $end_date);
$top_items_query->execute();
$top_items_result = $top_items_query->get_result();
$top_items_query->close();

$category_sales_query = $conn->prepare("SELECT c.category_name, COUNT(DISTINCT s.sale_id) as transactions, SUM(si.quantity) as items_sold, SUM(si.quantity * si.price) as revenue FROM sale_items si JOIN items i ON si.item_id = i.item_id JOIN categories c ON i.category_id = c.category_id JOIN sales s ON si.sale_id = s.sale_id WHERE DATE(s.sale_date) BETWEEN ? AND ? GROUP BY c.category_id, c.category_name ORDER BY revenue DESC");
$category_sales_query->bind_param("ss", $start_date, $end_date);
$category_sales_query->execute();
$category_sales_result = $category_sales_query->get_result();
$category_sales_query->close();

$inventory_query = $conn->query("SELECT COUNT(*) as total_items, COALESCE(SUM(stock), 0) as total_stock_value, COUNT(CASE WHEN stock <= 5 THEN 1 END) as low_stock_items, COUNT(CASE WHEN stock = 0 THEN 1 END) as out_of_stock_items FROM items WHERE is_active = 1");
$inventory_summary = $inventory_query ? $inventory_query->fetch_assoc() : ['total_items' => 0, 'total_stock_value' => 0, 'low_stock_items' => 0, 'out_of_stock_items' => 0];

$low_stock_result = $conn->query("SELECT i.item_name, i.stock, c.category_name FROM items i JOIN categories c ON i.category_id = c.category_id WHERE i.stock <= 5 AND i.is_active = 1 ORDER BY i.stock ASC LIMIT 10");

$user_performance_query = $conn->prepare("SELECT u.first_name, u.last_name, COUNT(s.sale_id) as transactions, COALESCE(SUM(s.total_amount), 0) as total_sales FROM users u LEFT JOIN sales s ON u.user_id = s.user_id AND DATE(s.sale_date) BETWEEN ? AND ? GROUP BY u.user_id, u.first_name, u.last_name ORDER BY total_sales DESC LIMIT 10");
$user_performance_query->bind_param("ss", $start_date, $end_date);
$user_performance_query->execute();
$user_performance_result = $user_performance_query->get_result();
$user_performance_query->close();

$transactions_query = $conn->prepare("SELECT s.sale_id, s.sale_date, s.total_amount, s.cash_received, s.change_amount, u.first_name, u.last_name, COUNT(si.sale_item_id) as items_count FROM sales s JOIN users u ON s.user_id = u.user_id LEFT JOIN sale_items si ON s.sale_id = si.sale_id WHERE DATE(s.sale_date) BETWEEN ? AND ? GROUP BY s.sale_id, s.sale_date, s.total_amount, s.cash_received, s.change_amount, u.first_name, u.last_name ORDER BY s.sale_date DESC LIMIT 50");
$transactions_query->bind_param("ss", $start_date, $end_date);
$transactions_query->execute();
$transactions_result = $transactions_query->get_result();
$transactions_query->close();

$current_month = date('Y-m');
$prev_month = date('Y-m', strtotime('-1 month'));
$current_month_stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM sales WHERE DATE_FORMAT(sale_date, '%Y-%m') = ?");
$current_month_stmt->bind_param("s", $current_month);
$current_month_stmt->execute();
$current_month_sales = (float)($current_month_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$current_month_stmt->close();
$prev_month_stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM sales WHERE DATE_FORMAT(sale_date, '%Y-%m') = ?");
$prev_month_stmt->bind_param("s", $prev_month);
$prev_month_stmt->execute();
$prev_month_sales = (float)($prev_month_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$prev_month_stmt->close();
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
    <style>
        @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        .alert-anim { animation: slideDown 0.25s ease; }
        .stat-card { transition: all 0.2s ease; }
        .stat-card:hover { transform: translateY(-2px); }
        .export-btn { transition: all 0.2s ease; }
        .export-btn:hover { transform: translateY(-2px); filter: brightness(1.05); }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

<div class="flex min-h-screen">
    <!-- SIDEBAR - MATCHES OTHER PAGES EXACTLY -->
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 transform bg-slate-950 border-r border-slate-800 flex flex-col transition-transform duration-200 ease-out -translate-x-full md:translate-x-0">
        <div class="flex items-center gap-3 px-6 py-5 border-b border-slate-800">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center overflow-hidden flex-shrink-0">
                <img src="assets/logo.png" alt="JOEBZ Logo" class="w-full h-full object-cover rounded-xl">
            </div>
            <span class="text-lg font-bold text-slate-100 tracking-tight">JOEBZ</span>
        </div>
        <nav class="flex-1 px-4 py-4 space-y-1 overflow-y-auto">
            <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Dashboard
            </a>
            <a href="sales.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                Sales / POS
            </a>
            <a href="items.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>
                Items
            </a>
            <a href="categories.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                Categories
            </a>
            <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-blue-600/20 text-blue-200 font-medium text-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                Reports
            </a>
            <?php if ($_SESSION['role'] === 'admin'): ?>
            <a href="users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                Users
            </a>
            <?php endif; ?>
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

    <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-black/60 md:hidden"></div>

    <!-- MAIN CONTENT -->
    <main class="flex-1 w-full min-w-0 overflow-y-auto p-4 sm:p-6 lg:p-8 md:ml-64">
        
        <div class="mb-4 flex items-center justify-between gap-3 md:hidden">
            <button type="button" id="open-sidebar" class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium text-slate-200">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>Menu
            </button>
            <a href="logout.php" class="inline-flex items-center gap-2 rounded-xl border border-red-700/50 bg-red-900/20 px-3 py-2 text-sm text-red-200">Logout</a>
        </div>

        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-100">Reports & Analytics</h1>
            <p class="text-sm text-slate-400 mt-1">Comprehensive business insights and performance metrics</p>
        </div>

        <!-- Date Range Filter -->
        <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20 p-6 mb-6">
            <form method="GET" class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Start Date</label>
                    <input type="date" name="start_date" value="<?= $start_date ?>" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">End Date</label>
                    <input type="date" name="end_date" value="<?= $end_date ?>" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded-xl text-white font-medium transition">Generate Report</button>
            </form>
        </div>

        <!-- Export Buttons -->
        <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20 p-6 mb-6">
            <div class="flex items-center gap-2 mb-4">
                <div class="w-1 h-6 bg-blue-600 rounded-full"></div>
                <h3 class="text-sm font-semibold text-slate-200 uppercase tracking-wider">Export Reports to CSV</h3>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <a href="?export=csv&export_type=sales&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="export-btn flex items-center justify-center gap-2 px-3 py-2.5 bg-emerald-600/20 hover:bg-emerald-600/30 border border-emerald-500/30 rounded-xl text-emerald-300 text-sm font-medium transition">📊 Sales</a>
                <a href="?export=csv&export_type=items&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="export-btn flex items-center justify-center gap-2 px-3 py-2.5 bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/30 rounded-xl text-blue-300 text-sm font-medium transition">📦 Items</a>
                <a href="?export=csv&export_type=customers&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="export-btn flex items-center justify-center gap-2 px-3 py-2.5 bg-purple-600/20 hover:bg-purple-600/30 border border-purple-500/30 rounded-xl text-purple-300 text-sm font-medium transition">👥 Customers</a>
                <a href="?export=csv&export_type=lowstock&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="export-btn flex items-center justify-center gap-2 px-3 py-2.5 bg-amber-600/20 hover:bg-amber-600/30 border border-amber-500/30 rounded-xl text-amber-300 text-sm font-medium transition">⚠️ Low Stock</a>
                <a href="?export=csv&export_type=users&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="export-btn flex items-center justify-center gap-2 px-3 py-2.5 bg-indigo-600/20 hover:bg-indigo-600/30 border border-indigo-500/30 rounded-xl text-indigo-300 text-sm font-medium transition">👤 Users</a>
                <a href="?export=csv&export_type=category&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="export-btn flex items-center justify-center gap-2 px-3 py-2.5 bg-rose-600/20 hover:bg-rose-600/30 border border-rose-500/30 rounded-xl text-rose-300 text-sm font-medium transition">📁 By Category</a>
            </div>
            <p class="text-xs text-slate-500 mt-4">✓ CSV files open directly in Excel without security warnings. Compatible with Excel, Google Sheets, and LibreOffice.</p>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert-anim mb-6 flex items-center gap-3 bg-emerald-900/40 border border-emerald-700 text-emerald-200 rounded-xl px-4 py-3 text-sm">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-anim mb-6 flex items-center gap-3 bg-red-900/40 border border-red-700 text-red-200 rounded-xl px-4 py-3 text-sm">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4 mb-6">
            <div class="stat-card bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm text-slate-400 font-medium">Total Sales</p>
                    <div class="w-9 h-9 bg-emerald-600 rounded-xl flex items-center justify-center text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
                <p class="text-2xl font-bold text-slate-100">₱<?= number_format($sales_summary['total_sales'] ?? 0, 2) ?></p>
                <p class="text-xs text-slate-400 mt-1"><?= $sales_summary['total_transactions'] ?? 0 ?> transactions</p>
            </div>
            <div class="stat-card bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm text-slate-400 font-medium">Avg Transaction</p>
                    <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                </div>
                <p class="text-2xl font-bold text-slate-100">₱<?= number_format($sales_summary['avg_transaction'] ?? 0, 2) ?></p>
                <p class="text-xs text-slate-400 mt-1">Per transaction</p>
            </div>
            <div class="stat-card bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm text-slate-400 font-medium">Monthly Growth</p>
                    <div class="w-9 h-9 bg-indigo-600 rounded-xl flex items-center justify-center text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    </div>
                </div>
                <p class="text-2xl font-bold <?= $monthly_growth >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= $monthly_growth >= 0 ? '+' : '' ?><?= number_format($monthly_growth, 1) ?>%</p>
                <p class="text-xs text-slate-400 mt-1">vs last month</p>
            </div>
            <a href="items.php" class="stat-card bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl hover:border-blue-500/50 transition group cursor-pointer">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm text-slate-400 font-medium group-hover:text-blue-300 transition">Total Items</p>
                    <div class="w-9 h-9 bg-amber-600 rounded-xl flex items-center justify-center text-white group-hover:scale-110 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>
                    </div>
                </div>
                <p class="text-2xl font-bold text-slate-100 group-hover:text-blue-300 transition"><?= number_format($inventory_summary['total_items'] ?? 0) ?></p>
                <p class="text-xs text-slate-400 mt-1 group-hover:text-slate-300 transition"><?= $inventory_summary['out_of_stock_items'] ?? 0 ?> out of stock</p>
            </a>
            <a href="items.php?show_inactive=1" class="stat-card bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl hover:border-red-500/50 transition group cursor-pointer">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm text-slate-400 font-medium group-hover:text-red-300 transition">Low Stock Alert</p>
                    <div class="w-9 h-9 bg-red-600 rounded-xl flex items-center justify-center text-white group-hover:scale-110 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                </div>
                <p class="text-2xl font-bold text-slate-100 group-hover:text-red-300 transition"><?= $inventory_summary['low_stock_items'] ?? 0 ?></p>
                <p class="text-xs text-slate-400 mt-1 group-hover:text-slate-300 transition">Items need restocking</p>
            </a>
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
            <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl">
                <div class="p-6 border-b border-slate-800">
                    <h2 class="text-lg font-bold text-slate-100">Daily Sales Trend</h2>
                    <p class="text-sm text-slate-400 mt-1">Sales performance over selected period</p>
                </div>
                <div class="p-6">
                    <canvas id="salesChart" width="400" height="200"></canvas>
                </div>
            </div>
            <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl">
                <div class="p-6 border-b border-slate-800">
                    <h2 class="text-lg font-bold text-slate-100">Top Selling Items</h2>
                    <p class="text-sm text-slate-400 mt-1">Best performing products</p>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <?php while ($item = $top_items_result->fetch_assoc()): ?>
                        <div class="flex items-center justify-between py-2 border-b border-slate-800">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-slate-100"><?= htmlspecialchars($item['item_name']) ?></p>
                                <p class="text-xs text-slate-400"><?= htmlspecialchars($item['category_name']) ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-blue-400">₱<?= number_format($item['total_revenue'], 2) ?></p>
                                <p class="text-xs text-slate-400"><?= $item['total_quantity'] ?> sold</p>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Second Row -->
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
            <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl">
                <div class="p-6 border-b border-slate-800">
                    <h2 class="text-lg font-bold text-slate-100">Sales by Category</h2>
                    <p class="text-sm text-slate-400 mt-1">Revenue breakdown by product categories</p>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <?php while ($category = $category_sales_result->fetch_assoc()): ?>
                        <div class="flex items-center justify-between py-2 border-b border-slate-800">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-slate-100"><?= htmlspecialchars($category['category_name']) ?></p>
                                <p class="text-xs text-slate-400"><?= $category['transactions'] ?> transactions</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-green-400">₱<?= number_format($category['revenue'], 2) ?></p>
                                <p class="text-xs text-slate-400"><?= $category['items_sold'] ?> items</p>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
            <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl">
                <div class="p-6 border-b border-slate-800">
                    <h2 class="text-lg font-bold text-slate-100">Staff Performance</h2>
                    <p class="text-sm text-slate-400 mt-1">Sales performance by staff member</p>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <?php while ($user = $user_performance_result->fetch_assoc()): ?>
                        <div class="flex items-center justify-between py-2 border-b border-slate-800">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-slate-100"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></p>
                                <p class="text-xs text-slate-400"><?= $user['transactions'] ?> transactions</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-purple-400">₱<?= number_format($user['total_sales'], 2) ?></p>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Stock Section -->
        <div class="mb-6 bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl">
            <div class="p-6 border-b border-slate-800">
                <div class="flex items-center gap-2">
                    <div class="w-1 h-5 bg-amber-500 rounded-full"></div>
                    <h2 class="text-lg font-bold text-slate-100">Low Stock Alert</h2>
                </div>
                <p class="text-sm text-slate-400 mt-1">Items requiring immediate attention</p>
            </div>
            <div class="p-6">
                <?php if ($low_stock_result->num_rows === 0): ?>
                    <div class="text-center py-8">
                        <div class="w-16 h-16 mx-auto bg-emerald-500/20 rounded-full flex items-center justify-center mb-3">
                            <svg class="w-8 h-8 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <p class="text-slate-400 font-medium">All items are well stocked!</p>
                        <p class="text-sm text-slate-500 mt-1">No low stock items to display</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php 
                        $low_stock_result->data_seek(0);
                        while ($item = $low_stock_result->fetch_assoc()): 
                        ?>
                        <div class="bg-red-900/20 border border-red-700 rounded-xl p-4">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h3 class="font-medium text-slate-100"><?= htmlspecialchars($item['item_name']) ?></h3>
                                    <p class="text-xs text-slate-400 mt-0.5"><?= htmlspecialchars($item['category_name']) ?></p>
                                </div>
                                <span class="px-2 py-1 rounded-lg text-xs font-bold <?= $item['stock'] == 0 ? 'bg-red-500/30 text-red-300' : 'bg-amber-500/30 text-amber-300' ?>">
                                    <?= $item['stock'] == 0 ? 'OUT OF STOCK' : $item['stock'] . ' left' ?>
                                </span>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl">
            <div class="p-6 border-b border-slate-800 flex items-center justify-between flex-wrap gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <div class="w-1 h-5 bg-blue-600 rounded-full"></div>
                        <h2 class="text-lg font-bold text-slate-100">Recent Transactions</h2>
                    </div>
                    <p class="text-sm text-slate-400 mt-1">Individual sales records for the selected period</p>
                </div>
                <span class="px-3 py-1 bg-blue-600/20 text-blue-300 rounded-lg text-xs font-medium"><?= $transactions_result->num_rows ?> records</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-400 uppercase tracking-wide border-b border-slate-800">
                            <th class="px-5 py-3 text-left">Sale ID</th>
                            <th class="px-5 py-3 text-left">Date & Time</th>
                            <th class="px-5 py-3 text-left">Cashier</th>
                            <th class="px-5 py-3 text-center">Items</th>
                            <th class="px-5 py-3 text-right">Total</th>
                            <th class="px-5 py-3 text-right">Cash</th>
                            <th class="px-5 py-3 text-right">Change</th>
                            <th class="px-5 py-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php if ($transactions_result->num_rows === 0): ?>
                            <tr><td colspan="8" class="px-5 py-8 text-center text-slate-400">No transactions found for the selected date range.</td></tr>
                        <?php else: ?>
                            <?php while ($t = $transactions_result->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-800/50 transition">
                                    <td class="px-5 py-3"><span class="font-mono text-xs text-blue-400">#<?= $t['sale_id'] ?></span></td>
                                    <td class="px-5 py-3 text-slate-300"><?= date('M d, Y g:i A', strtotime($t['sale_date'])) ?></td>
                                    <td class="px-5 py-3"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></td>
                                    <td class="px-5 py-3 text-center"><span class="px-2 py-0.5 bg-slate-800 rounded-lg text-xs text-slate-400"><?= $t['items_count'] ?> items</span></td>
                                    <td class="px-5 py-3 text-right font-bold text-green-400">₱<?= number_format($t['total_amount'], 2) ?></td>
                                    <td class="px-5 py-3 text-right text-slate-400">₱<?= number_format($t['cash_received'], 2) ?></td>
                                    <td class="px-5 py-3 text-right text-slate-400">₱<?= number_format($t['change_amount'], 2) ?></td>
                                    <td class="px-5 py-3 text-center">
                                        <form method="POST" action="reports.php" id="delete-form-<?= $t['sale_id'] ?>" class="inline">
                                            <input type="hidden" name="action" value="delete_sale">
                                            <input type="hidden" name="sale_id" value="<?= $t['sale_id'] ?>">
                                            <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                                            <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                                            <button type="button" onclick="confirmDeleteSale(<?= $t['sale_id'] ?>, '₱<?= number_format($t['total_amount'], 2) ?> — <?= date('M d, Y g:i A', strtotime($t['sale_date'])) ?>')" class="p-1.5 rounded-lg text-red-400 hover:text-red-300 hover:bg-red-500/20 transition">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebar-overlay');
const openSidebarBtn = document.getElementById('open-sidebar');

function openSidebar() { if (!sidebar || window.innerWidth >= 768) return; sidebar.classList.remove('-translate-x-full'); sidebarOverlay.classList.remove('hidden'); }
function closeSidebar() { if (!sidebar || window.innerWidth >= 768) return; sidebar.classList.add('-translate-x-full'); sidebarOverlay.classList.add('hidden'); }

if (openSidebarBtn) openSidebarBtn.addEventListener('click', openSidebar);
if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);
window.addEventListener('resize', () => {
    if (window.innerWidth >= 768) { sidebar.classList.remove('-translate-x-full'); sidebarOverlay.classList.add('hidden'); }
    else { sidebar.classList.add('-translate-x-full'); }
});

const ctx = document.getElementById('salesChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($daily_sales, 'date')) ?>,
        datasets: [{
            label: 'Daily Sales (₱)',
            data: <?= json_encode(array_column($daily_sales, 'amount')) ?>,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#3b82f6',
            pointBorderColor: '#60a5fa',
            pointRadius: 4,
            pointHoverRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => '₱' + ctx.parsed.y.toLocaleString() } } },
        scales: { y: { beginAtZero: true, grid: { color: '#334155' }, ticks: { color: '#94a3b8', callback: (v) => '₱' + v.toLocaleString() } }, x: { grid: { display: false }, ticks: { color: '#94a3b8' } } }
    }
});

function confirmDeleteSale(id, info) {
    if (confirm('Delete transaction #' + id + '?\n' + info + '\n\n⚠️ This will restore the stock and cannot be undone.')) {
        document.getElementById('delete-form-' + id).submit();
    }
}

document.querySelectorAll('.alert-anim').forEach(el => {
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 500); }, 5000);
});
</script>

</body>
</html>