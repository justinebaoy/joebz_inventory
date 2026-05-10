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
    } elseif ($export_type === 'items') {
        fputcsv($output, ['ITEM ID', 'ITEM NAME', 'CATEGORY', 'STOCK', 'PRICE (₱)', 'STATUS']);
        $result = $conn->query("SELECT i.item_id, i.item_name, i.stock, i.price, i.is_active, c.category_name FROM items i JOIN categories c ON i.category_id = c.category_id ORDER BY c.category_name, i.item_name");
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['item_id'], $row['item_name'], $row['category_name'], $row['stock'], number_format($row['price'], 2), $row['is_active'] ? 'Active' : 'Inactive']);
        }
    } elseif ($export_type === 'customers') {
        fputcsv($output, ['CUSTOMER ID', 'NAME', 'PHONE', 'EMAIL', 'TOTAL PURCHASES (₱)', 'LOYALTY POINTS', 'MEMBER SINCE']);
        $result = $conn->query("SELECT customer_id, name, phone, email, total_purchases, loyalty_points, created_at FROM customers ORDER BY total_purchases DESC");
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['customer_id'], $row['name'], $row['phone'] ?? 'N/A', $row['email'] ?? 'N/A', number_format($row['total_purchases'] ?? 0, 2), $row['loyalty_points'] ?? 0, date('Y-m-d', strtotime($row['created_at']))]);
        }
    } elseif ($export_type === 'lowstock') {
        fputcsv($output, ['ITEM ID', 'ITEM NAME', 'CATEGORY', 'CURRENT STOCK', 'STATUS', 'RECOMMENDED ACTION']);
        $result = $conn->query("SELECT i.item_id, i.item_name, i.stock, c.category_name FROM items i JOIN categories c ON i.category_id = c.category_id WHERE i.stock <= 5 AND i.is_active = 1 ORDER BY i.stock ASC");
        while ($row = $result->fetch_assoc()) {
            $status = $row['stock'] == 0 ? 'OUT OF STOCK' : ($row['stock'] <= 2 ? 'CRITICAL' : 'LOW');
            $action = $row['stock'] == 0 ? 'ORDER IMMEDIATELY' : ($row['stock'] <= 2 ? 'URGENT RESTOCK' : 'Plan restock');
            fputcsv($output, [$row['item_id'], $row['item_name'], $row['category_name'], $row['stock'], $status, $action]);
        }
    } elseif ($export_type === 'users') {
        fputcsv($output, ['USER ID', 'NAME', 'USERNAME', 'EMAIL', 'ROLE', 'REGISTERED DATE']);
        $result = $conn->query("SELECT user_id, first_name, last_name, username, email, role, created_at FROM users ORDER BY created_at DESC");
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['user_id'], $row['first_name'] . ' ' . $row['last_name'], $row['username'], $row['email'], ucfirst($row['role']), date('Y-m-d', strtotime($row['created_at']))]);
        }
    } elseif ($export_type === 'category') {
        fputcsv($output, ['CATEGORY', 'TOTAL TRANSACTIONS', 'ITEMS SOLD', 'TOTAL REVENUE (₱)']);
        $stmt = $conn->prepare("SELECT c.category_name, COUNT(DISTINCT s.sale_id) as transactions, SUM(si.quantity) as items_sold, SUM(si.quantity * si.price) as revenue FROM sale_items si JOIN items i ON si.item_id = i.item_id JOIN categories c ON i.category_id = c.category_id JOIN sales s ON si.sale_id = s.sale_id WHERE DATE(s.sale_date) BETWEEN ? AND ? GROUP BY c.category_id, c.category_name ORDER BY revenue DESC");
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['category_name'], $row['transactions'], $row['items_sold'], number_format($row['revenue'], 2)]);
        }
    } elseif ($export_type === 'discounts') {
        fputcsv($output, ['LOG ID', 'REQUEST DATE', 'CASHIER', 'DISCOUNT TYPE', 'DISCOUNT %', 'DISCOUNT AMOUNT (₱)', 'REASON', 'STATUS', 'APPROVER', 'APPROVAL DATE', 'REJECTION REASON']);
        $stmt = $conn->prepare("
            SELECT 
                l.log_id,
                l.request_time,
                l.discount_type,
                l.discount_percent,
                l.discount_amount,
                l.reason,
                l.status,
                l.approval_time,
                l.rejection_reason,
                cashier.first_name AS cashier_first_name,
                cashier.last_name AS cashier_last_name,
                approver.first_name AS approver_first_name,
                approver.last_name AS approver_last_name
            FROM discount_logs l
            JOIN users cashier ON l.cashier_id = cashier.user_id
            LEFT JOIN users approver ON l.approver_id = approver.user_id
            WHERE DATE(l.request_time) BETWEEN ? AND ?
            ORDER BY l.request_time DESC
        ");
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $approver_name = trim(($row['approver_first_name'] ?? '') . ' ' . ($row['approver_last_name'] ?? ''));
            fputcsv($output, [
                $row['log_id'],
                $row['request_time'] ? date('Y-m-d H:i:s', strtotime($row['request_time'])) : 'N/A',
                trim($row['cashier_first_name'] . ' ' . $row['cashier_last_name']),
                strtoupper($row['discount_type']),
                number_format((float)$row['discount_percent'], 2),
                number_format((float)$row['discount_amount'], 2),
                $row['reason'] ?? 'N/A',
                strtoupper($row['status']),
                $approver_name !== '' ? $approver_name : 'N/A',
                $row['approval_time'] ? date('Y-m-d H:i:s', strtotime($row['approval_time'])) : 'N/A',
                $row['rejection_reason'] ?? 'N/A'
            ]);
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
        header("Location: reports.php?start_date=" . urlencode($start_date) . "&end_date=" . urlencode($end_date) . "&success=" . urlencode("Transaction #{$sale_id} deleted and stock restored."));
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

// Supplier-level procurement widgets
$open_pos_result = $conn->query("SELECT s.supplier_name, COUNT(*) AS open_pos FROM purchase_orders po JOIN suppliers s ON s.supplier_id = po.supplier_id WHERE po.status IN ('submitted','partial_received') GROUP BY s.supplier_id ORDER BY open_pos DESC LIMIT 5");
$overdue_pos_result = $conn->query("SELECT s.supplier_name, COUNT(*) AS overdue_pos FROM purchase_orders po JOIN suppliers s ON s.supplier_id = po.supplier_id WHERE po.status IN ('submitted','partial_received') AND po.expected_at IS NOT NULL AND po.expected_at < NOW() GROUP BY s.supplier_id ORDER BY overdue_pos DESC LIMIT 5");
$fill_rate_result = $conn->query("SELECT s.supplier_name, ROUND((SUM(poi.received_qty)/NULLIF(SUM(poi.ordered_qty),0))*100,2) AS fill_rate FROM purchase_order_items poi JOIN purchase_orders po ON po.po_id = poi.po_id JOIN suppliers s ON s.supplier_id = po.supplier_id GROUP BY s.supplier_id ORDER BY fill_rate DESC LIMIT 5");
$recent_receipts_result = $conn->query("SELECT gr.receipt_id, s.supplier_name, gr.received_at FROM goods_receipts gr JOIN purchase_orders po ON po.po_id = gr.po_id JOIN suppliers s ON s.supplier_id = po.supplier_id ORDER BY gr.received_at DESC LIMIT 8");
$last_costs_result = $conn->query("SELECT s.supplier_name, i.item_name, h.cost, h.effective_at FROM supplier_item_cost_history h JOIN suppliers s ON s.supplier_id = h.supplier_id JOIN items i ON i.item_id = h.item_id ORDER BY h.effective_at DESC LIMIT 8");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — JOEBZ POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: #3b82f6;
            --accent-glow: rgba(59,130,246,0.18);
            --up: #10b981;
        }
        body { font-family: 'DM Sans', sans-serif; }
        h1, h2, .display { font-family: 'Syne', sans-serif; }
        .mono { font-family: 'DM Mono', monospace; }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .reveal { opacity: 0; animation: slideUp 0.45s ease forwards; }
        .reveal-1 { animation-delay: 0.05s; }
        .reveal-2 { animation-delay: 0.10s; }
        .reveal-3 { animation-delay: 0.15s; }
        .reveal-4 { animation-delay: 0.20s; }
        .reveal-5 { animation-delay: 0.25s; }
        .reveal-6 { animation-delay: 0.30s; }

        .stat-card { transition: transform 0.18s ease, box-shadow 0.18s ease; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(0,0,0,0.35); }

        @keyframes pulse-ring {
            0%   { transform: scale(0.8); opacity: 1; }
            100% { transform: scale(1.8); opacity: 0; }
        }
        .live-dot::after {
            content: ''; display: block; position: absolute; inset: 0;
            border-radius: 50%; background: var(--up);
            animation: pulse-ring 1.6s ease infinite;
        }
        .live-dot {
            position: relative; width: 8px; height: 8px;
            border-radius: 50%; background: var(--up); display: inline-block;
        }

        .input-field {
            width: 100%; background: rgba(30,41,59,0.8);
            border: 1px solid #334155; border-radius: 0.75rem;
            color: #f1f5f9; padding: 0.5rem 0.75rem;
            font-family: 'DM Sans', sans-serif;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            outline: none;
        }
        .input-field:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }

        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-panel { animation: modalIn 0.25s ease both; }

        .item-row { transition: background 0.15s ease; }
        .item-row:hover { background: rgba(255,255,255,0.03); }

        .export-btn {
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
            height: 40px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            white-space: nowrap !important;
            overflow: hidden;
        }
        .export-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.3); }

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 9999px; }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

    <section class="md:ml-64 max-w-7xl mx-auto px-4 md:px-6 pt-6">
        <h2 class="text-2xl font-bold mb-3">Supplier Procurement Widgets</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-4">
                <h3 class="font-semibold mb-2">Open POs</h3>
                <?php
                if ($open_pos_result && $open_pos_result->num_rows > 0) {
                    while ($r = $open_pos_result->fetch_assoc()) {
                        echo '<div class="text-sm text-slate-200">' . htmlspecialchars($r['supplier_name']) . ': ' . (int)$r['open_pos'] . '</div>';
                    }
                } else {
                    echo '<div class="text-sm text-slate-400">No open purchase orders.</div>';
                }
                ?>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-4">
                <h3 class="font-semibold mb-2">Overdue POs</h3>
                <?php
                if ($overdue_pos_result && $overdue_pos_result->num_rows > 0) {
                    while ($r = $overdue_pos_result->fetch_assoc()) {
                        echo '<div class="text-sm text-slate-200">' . htmlspecialchars($r['supplier_name']) . ': ' . (int)$r['overdue_pos'] . '</div>';
                    }
                } else {
                    echo '<div class="text-sm text-slate-400">No overdue purchase orders.</div>';
                }
                ?>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-4">
                <h3 class="font-semibold mb-2">Fill Rate (%)</h3>
                <?php
                if ($fill_rate_result && $fill_rate_result->num_rows > 0) {
                    while ($r = $fill_rate_result->fetch_assoc()) {
                        echo '<div class="text-sm text-slate-200">' . htmlspecialchars($r['supplier_name']) . ': ' . number_format((float)($r['fill_rate'] ?? 0), 2) . '%</div>';
                    }
                } else {
                    echo '<div class="text-sm text-slate-400">No fill-rate data yet.</div>';
                }
                ?>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-4">
                <h3 class="font-semibold mb-2">Recent Receipts</h3>
                <?php
                if ($recent_receipts_result && $recent_receipts_result->num_rows > 0) {
                    while ($r = $recent_receipts_result->fetch_assoc()) {
                        echo '<div class="text-sm text-slate-200">#' . (int)$r['receipt_id'] . ' ' . htmlspecialchars($r['supplier_name']) . ' @ ' . htmlspecialchars($r['received_at']) . '</div>';
                    }
                } else {
                    echo '<div class="text-sm text-slate-400">No receipts posted yet.</div>';
                }
                ?>
            </div>
            <div class="md:col-span-2 rounded-xl border border-slate-800 bg-slate-900/70 p-4">
                <h3 class="font-semibold mb-2">Last Costs</h3>
                <?php
                if ($last_costs_result && $last_costs_result->num_rows > 0) {
                    while ($r = $last_costs_result->fetch_assoc()) {
                        echo '<div class="text-sm text-slate-200">' . htmlspecialchars($r['supplier_name']) . ' / ' . htmlspecialchars($r['item_name']) . ' : ' . number_format((float)$r['cost'], 4) . ' (' . htmlspecialchars($r['effective_at']) . ')</div>';
                    }
                } else {
                    echo '<div class="text-sm text-slate-400">No supplier cost history yet.</div>';
                }
                ?>
            </div>
        </div>
    </section>


<!-- ═══════════ SIDEBAR ═══════════ -->
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
                <h1 class="text-3xl font-bold text-white tracking-tight">Reports & Analytics</h1>
                <p class="text-slate-400 mt-1 text-sm">
                    Welcome back, <span class="text-blue-300"><?= htmlspecialchars($_SESSION['first_name']) ?></span>
                    &nbsp;·&nbsp; <?= date('l, F j, Y') ?>
                </p>
            </div>
            <div class="flex items-center gap-2 text-xs text-slate-400 bg-slate-900 border border-slate-800 px-3 py-1.5 rounded-full">
                <span class="live-dot"></span>
                <span id="liveTime" class="mono"></span>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
        <div id="successMsg" class="mb-5 p-4 bg-emerald-900/30 border border-emerald-700/50 rounded-2xl text-emerald-200 flex items-center gap-3 reveal reveal-2">
            <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div id="errorMsg" class="mb-5 p-4 bg-red-900/30 border border-red-700/50 rounded-2xl text-red-200 flex items-center gap-3 reveal reveal-2">
            <svg class="w-5 h-5 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <!-- KPI Stat Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3 mb-6">

            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-2">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-emerald-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Total Sales</p>
                        <p class="text-2xl font-bold text-white mono">₱<?= number_format($sales_summary['total_sales'] ?? 0, 0) ?></p>
                        <p class="text-xs text-slate-500 mt-1"><?= $sales_summary['total_transactions'] ?? 0 ?> transactions</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/15 border border-emerald-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
            </div>

            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-2">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-blue-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Avg Transaction</p>
                        <p class="text-2xl font-bold text-white mono">₱<?= number_format($sales_summary['avg_transaction'] ?? 0, 0) ?></p>
                        <p class="text-xs text-slate-500 mt-1">Per transaction</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-blue-500/15 border border-blue-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                </div>
            </div>

            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-3">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-indigo-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Monthly Growth</p>
                        <p class="text-2xl font-bold mono <?= $monthly_growth >= 0 ? 'text-emerald-400' : 'text-red-400' ?>"><?= $monthly_growth >= 0 ? '+' : '' ?><?= number_format($monthly_growth, 1) ?>%</p>
                        <p class="text-xs text-slate-500 mt-1">vs last month</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-500/15 border border-indigo-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    </div>
                </div>
            </div>

            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-3">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-amber-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Total Items</p>
                        <p class="text-2xl font-bold text-white mono"><?= number_format($inventory_summary['total_items'] ?? 0) ?></p>
                        <p class="text-xs text-slate-500 mt-1"><?= $inventory_summary['out_of_stock_items'] ?? 0 ?> out of stock</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-500/15 border border-amber-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>
                    </div>
                </div>
            </div>

            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-3">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-red-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Low Stock</p>
                        <p class="text-2xl font-bold mono <?= ($inventory_summary['low_stock_items'] ?? 0) > 0 ? 'text-red-400' : 'text-white' ?>"><?= $inventory_summary['low_stock_items'] ?? 0 ?></p>
                        <p class="text-xs text-slate-500 mt-1">Need restocking</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-red-500/15 border border-red-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                </div>
            </div>

        </div>

        <!-- Date Range + Export -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6 reveal reveal-4">

            <!-- Date Filter -->
            <div class="lg:col-span-1 bg-slate-900 rounded-2xl border border-slate-800 p-5">
                <h2 class="text-sm font-bold text-white mb-4" style="font-family:'Syne',sans-serif">Date Range</h2>
                <form method="GET" class="space-y-3">
                    <div>
                        <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Start Date</label>
                        <input type="date" name="start_date" value="<?= $start_date ?>" class="input-field text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">End Date</label>
                        <input type="date" name="end_date" value="<?= $end_date ?>" class="input-field text-sm">
                    </div>
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 rounded-xl transition text-sm shadow-lg shadow-blue-900/30">
                        Generate Report
                    </button>
                </form>
            </div>

            <!-- Export Buttons -->
            <div class="lg:col-span-2 bg-slate-900 rounded-2xl border border-slate-800 p-5 self-start">
                <h2 class="text-sm font-bold text-white mb-4" style="font-family:'Syne',sans-serif">Export to CSV</h2>
                <!-- ═══ FIXED: grid-template-rows explicitly locks both rows to 40px;
                     each <a> gets height/min-height/max-height + box-sizing to prevent
                     content or browser defaults from stretching the first row taller. ═══ -->
                <style>
                    .export-grid { display: grid; grid-template-columns: repeat(3, 1fr); grid-template-rows: repeat(3, 40px); gap: 12px; align-items: stretch; }
                    .export-grid a { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; height: 100%; box-sizing: border-box; border-radius: 12px; font-size: 12px; font-weight: 500; white-space: nowrap; overflow: hidden; text-decoration: none; transition: transform 0.18s ease, box-shadow 0.18s ease; }
                    .export-grid a:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.3); }
                </style>
                <div class="export-grid">
                    <a href="?export=csv&export_type=sales&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" style="background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.2);color:#6ee7b7;">
                        <svg style="width:16px;height:16px;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10"/></svg>
                        Sales Report
                    </a>
                    <a href="?export=csv&export_type=items&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" style="background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.2);color:#93c5fd;">
                        <svg style="width:16px;height:16px;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>
                        Items Report
                    </a>
                    <a href="?export=csv&export_type=customers&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" style="background:rgba(139,92,246,0.1);border:1px solid rgba(139,92,246,0.2);color:#c4b5fd;">
                        <svg style="width:16px;height:16px;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Customers
                    </a>
                    <a href="?export=csv&export_type=lowstock&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.2);color:#fcd34d;">
                        <svg style="width:16px;height:16px;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        Low Stock
                    </a>
                    <a href="?export=csv&export_type=users&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" style="background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.2);color:#a5b4fc;">
                        <svg style="width:16px;height:16px;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        Users
                    </a>
                    <a href="?export=csv&export_type=category&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" style="background:rgba(244,63,94,0.1);border:1px solid rgba(244,63,94,0.2);color:#fda4af;">
                        <svg style="width:16px;height:16px;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                        By Category
                    </a>
                    <a href="?export=csv&export_type=discounts&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" style="background:rgba(14,165,233,0.1);border:1px solid rgba(14,165,233,0.2);color:#7dd3fc;">
                        <svg style="width:16px;height:16px;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5-1h.01M14 17h.01M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"/></svg>
                        Discounts
                    </a>
                </div>
                <p class="text-xs text-slate-600 mt-4">Compatible with Excel, Google Sheets, and LibreOffice.</p>
            </div>

        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4 reveal reveal-5">

            <!-- Daily Sales Chart -->
            <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-800">
                    <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">Daily Sales Trend</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Sales performance over selected period</p>
                </div>
                <div class="p-5">
                    <canvas id="salesChart" height="180"></canvas>
                </div>
            </div>

            <!-- Top Selling Items -->
            <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">Top Selling Items</h2>
                        <p class="text-xs text-slate-500 mt-0.5">Best performing products</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wider">
                                <th class="px-6 py-3 text-left">Item</th>
                                <th class="px-6 py-3 text-right">Sold</th>
                                <th class="px-6 py-3 text-right">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($item = $top_items_result->fetch_assoc()): ?>
                            <tr class="item-row border-b border-slate-800/60">
                                <td class="px-6 py-3">
                                    <p class="font-medium text-white text-sm"><?= htmlspecialchars($item['item_name']) ?></p>
                                    <span class="px-2 py-0.5 rounded text-xs bg-blue-500/10 text-blue-300 border border-blue-500/20"><?= htmlspecialchars($item['category_name']) ?></span>
                                </td>
                                <td class="px-6 py-3 text-right mono text-slate-300 text-sm"><?= $item['total_quantity'] ?></td>
                                <td class="px-6 py-3 text-right mono text-emerald-400 font-semibold text-sm">₱<?= number_format($item['total_revenue'], 2) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- Second Row: Category + Staff -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4 reveal reveal-5">

            <!-- Sales by Category -->
            <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-800">
                    <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">Sales by Category</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Revenue breakdown by product categories</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wider">
                                <th class="px-6 py-3 text-left">Category</th>
                                <th class="px-6 py-3 text-right">Items Sold</th>
                                <th class="px-6 py-3 text-right">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($category = $category_sales_result->fetch_assoc()): ?>
                            <tr class="item-row border-b border-slate-800/60">
                                <td class="px-6 py-3">
                                    <p class="font-medium text-white"><?= htmlspecialchars($category['category_name']) ?></p>
                                    <p class="text-xs text-slate-500"><?= $category['transactions'] ?> transactions</p>
                                </td>
                                <td class="px-6 py-3 text-right mono text-slate-300 text-sm"><?= $category['items_sold'] ?></td>
                                <td class="px-6 py-3 text-right mono text-emerald-400 font-semibold text-sm">₱<?= number_format($category['revenue'], 2) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Staff Performance -->
            <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-800">
                    <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">Staff Performance</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Sales performance by staff member</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wider">
                                <th class="px-6 py-3 text-left">Staff</th>
                                <th class="px-6 py-3 text-right">Transactions</th>
                                <th class="px-6 py-3 text-right">Total Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($user = $user_performance_result->fetch_assoc()): ?>
                            <tr class="item-row border-b border-slate-800/60">
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full bg-blue-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                                            <?= strtoupper(substr($user['first_name'], 0, 1)) ?>
                                        </div>
                                        <span class="font-medium text-white"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-3 text-right mono text-slate-300 text-sm"><?= $user['transactions'] ?></td>
                                <td class="px-6 py-3 text-right mono text-purple-400 font-semibold text-sm">₱<?= number_format($user['total_sales'], 2) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- Low Stock Alert -->
        <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden mb-4 reveal reveal-6">
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">Low Stock Alert</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Items requiring immediate attention</p>
                </div>
                <span class="mono text-xs text-amber-400 bg-amber-500/10 border border-amber-500/20 px-2.5 py-1 rounded-xl">
                    <?= $low_stock_result->num_rows ?> item<?= $low_stock_result->num_rows !== 1 ? 's' : '' ?>
                </span>
            </div>
            <div class="p-5">
                <?php if ($low_stock_result->num_rows === 0): ?>
                    <div class="py-10 text-center">
                        <div class="w-12 h-12 mx-auto bg-emerald-500/15 border border-emerald-500/20 rounded-2xl flex items-center justify-center mb-3">
                            <svg class="w-6 h-6 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <p class="text-slate-400 font-medium text-sm">All items are well stocked!</p>
                        <p class="text-xs text-slate-600 mt-1">No low stock items to display</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <?php $low_stock_result->data_seek(0); while ($item = $low_stock_result->fetch_assoc()): ?>
                        <div class="flex items-center justify-between bg-red-900/15 border border-red-800/30 rounded-xl px-4 py-3">
                            <div>
                                <p class="font-medium text-slate-100 text-sm"><?= htmlspecialchars($item['item_name']) ?></p>
                                <p class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($item['category_name']) ?></p>
                            </div>
                            <span class="mono text-xs font-bold px-2.5 py-1 rounded-lg <?= $item['stock'] == 0 ? 'bg-red-500/20 text-red-300 border border-red-500/30' : 'bg-amber-500/20 text-amber-300 border border-amber-500/30' ?>">
                                <?= $item['stock'] == 0 ? 'OUT' : $item['stock'] . ' left' ?>
                            </span>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden reveal reveal-6">
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">Recent Transactions</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Individual sales records for the selected period</p>
                </div>
                <span class="mono text-xs text-slate-400 bg-slate-800 border border-slate-700 px-2.5 py-1 rounded-xl">
                    <?= $transactions_result->num_rows ?> record<?= $transactions_result->num_rows !== 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if ($transactions_result->num_rows === 0): ?>
            <div class="py-16 text-center">
                <svg class="w-10 h-10 text-slate-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                <p class="text-slate-500 text-sm">No transactions found for the selected date range.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wider">
                            <th class="px-6 py-3 text-left">Sale ID</th>
                            <th class="px-6 py-3 text-left">Date & Time</th>
                            <th class="px-6 py-3 text-left">Cashier</th>
                            <th class="px-6 py-3 text-center">Items</th>
                            <th class="px-6 py-3 text-right">Total</th>
                            <th class="px-6 py-3 text-right">Cash</th>
                            <th class="px-6 py-3 text-right">Change</th>
                            <th class="px-6 py-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($t = $transactions_result->fetch_assoc()): ?>
                        <tr class="item-row border-b border-slate-800/60">
                            <td class="px-6 py-3.5">
                                <span class="mono text-xs text-blue-400 bg-blue-500/10 border border-blue-500/20 px-2 py-0.5 rounded-lg">#<?= $t['sale_id'] ?></span>
                            </td>
                            <td class="px-6 py-3.5 text-slate-300 text-xs"><?= date('M d, Y g:i A', strtotime($t['sale_date'])) ?></td>
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-2">
                                    <div class="w-6 h-6 rounded-full bg-blue-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                                        <?= strtoupper(substr($t['first_name'], 0, 1)) ?>
                                    </div>
                                    <span class="font-medium text-white text-xs"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-3.5 text-center">
                                <span class="mono text-xs bg-slate-800 border border-slate-700 px-2 py-0.5 rounded-lg text-slate-400"><?= $t['items_count'] ?></span>
                            </td>
                            <td class="px-6 py-3.5 text-right mono text-emerald-400 font-semibold text-sm">₱<?= number_format($t['total_amount'], 2) ?></td>
                            <td class="px-6 py-3.5 text-right mono text-slate-400 text-xs">₱<?= number_format($t['cash_received'], 2) ?></td>
                            <td class="px-6 py-3.5 text-right mono text-slate-400 text-xs">₱<?= number_format($t['change_amount'], 2) ?></td>
                            <td class="px-6 py-3.5 text-center">
                                <form method="POST" action="reports.php" id="delete-form-<?= $t['sale_id'] ?>" class="inline">
                                    <input type="hidden" name="action" value="delete_sale">
                                    <input type="hidden" name="sale_id" value="<?= $t['sale_id'] ?>">
                                    <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                                    <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                                    <button type="button"
                                        onclick="confirmDeleteSale(<?= $t['sale_id'] ?>, '₱<?= number_format($t['total_amount'], 2) ?>', '<?= date('M d, Y g:i A', strtotime($t['sale_date'])) ?>', '<?= htmlspecialchars(addslashes($t['first_name'] . ' ' . $t['last_name'])) ?>')"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-red-400 hover:text-red-300 hover:bg-red-500/20 border border-transparent hover:border-red-500/20 transition"
                                        title="Delete transaction">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- ═══════════ DELETE MODAL ═══════════ -->
<div id="deleteModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 items-center justify-center p-4">
    <div id="deleteModalPanel" class="modal-panel bg-slate-900 border border-red-900/40 rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <div class="flex items-start gap-4 mb-5">
            <div class="w-11 h-11 rounded-xl bg-red-900/30 border border-red-800/40 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </div>
            <div class="min-w-0">
                <h3 class="font-bold text-white text-base" style="font-family:'Syne',sans-serif">Delete Transaction</h3>
                <p class="text-xs text-slate-400 mt-1 mono" id="deleteModalSaleId"></p>
            </div>
        </div>

        <div class="bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 mb-5 space-y-2">
            <div class="flex justify-between text-sm">
                <span class="text-slate-500 text-xs uppercase tracking-wider">Amount</span>
                <span class="text-white font-semibold mono" id="deleteModalAmount"></span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-slate-500 text-xs uppercase tracking-wider">Date</span>
                <span class="text-slate-300 text-xs" id="deleteModalDate"></span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-slate-500 text-xs uppercase tracking-wider">Cashier</span>
                <span class="text-slate-300 text-xs" id="deleteModalCashier"></span>
            </div>
        </div>

        <p class="text-xs text-amber-400/80 flex items-center gap-1.5 mb-5">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            Stock will be restored. This cannot be undone.
        </p>

        <div class="flex gap-3">
            <button type="button" onclick="closeDeleteModal()"
                class="flex-1 border border-slate-700 hover:bg-slate-800 text-slate-300 py-2.5 rounded-xl text-sm font-medium transition">
                Cancel
            </button>
            <button type="button" id="deleteModalConfirmBtn"
                class="flex-1 bg-red-600 hover:bg-red-500 text-white py-2.5 rounded-xl text-sm font-semibold transition shadow-lg shadow-red-900/30">
                Delete
            </button>
        </div>
    </div>
</div>

<script>
// ── Live clock ─────────────────────────────────────────────────────────────
function updateClock() {
    var el = document.getElementById('liveTime');
    if (el) el.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
updateClock(); setInterval(updateClock, 1000);

// ── Sidebar toggle ─────────────────────────────────────────────────────────
var sidebar = document.getElementById('sidebar');
var openBtn = document.getElementById('open-sidebar');
if (openBtn) openBtn.addEventListener('click', function() { sidebar.classList.remove('-translate-x-full'); });
document.addEventListener('click', function(e) {
    if (window.innerWidth < 768 && sidebar && openBtn && !sidebar.contains(e.target) && !openBtn.contains(e.target))
        sidebar.classList.add('-translate-x-full');
});
window.addEventListener('resize', function() {
    if (!sidebar) return;
    if (window.innerWidth >= 768) sidebar.classList.remove('-translate-x-full');
    else sidebar.classList.add('-translate-x-full');
});

// ── Chart.js ───────────────────────────────────────────────────────────────
const ctx = document.getElementById('salesChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($daily_sales, 'date')) ?>,
        datasets: [{
            label: 'Daily Sales (₱)',
            data: <?= json_encode(array_column($daily_sales, 'amount')) ?>,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,0.08)',
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
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: (ctx) => '₱' + ctx.parsed.y.toLocaleString() } }
        },
        scales: {
            y: { beginAtZero: true, grid: { color: '#1e293b' }, ticks: { color: '#64748b', callback: (v) => '₱' + v.toLocaleString() } },
            x: { grid: { display: false }, ticks: { color: '#64748b' } }
        }
    }
});

// ── Delete modal ───────────────────────────────────────────────────────────
function confirmDeleteSale(id, amount, date, cashier) {
    document.getElementById('deleteModalSaleId').textContent  = 'Transaction #' + id;
    document.getElementById('deleteModalAmount').textContent  = amount;
    document.getElementById('deleteModalDate').textContent    = date;
    document.getElementById('deleteModalCashier').textContent = cashier;

    document.getElementById('deleteModalConfirmBtn').onclick = function() {
        closeDeleteModal();
        document.getElementById('delete-form-' + id).submit();
    };

    const panel = document.getElementById('deleteModalPanel');
    panel.style.animation = 'none'; panel.offsetHeight; panel.style.animation = '';

    const modal = document.getElementById('deleteModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDeleteModal();
});

// ── Auto-dismiss alerts ────────────────────────────────────────────────────
['successMsg','errorMsg'].forEach(function(id) {
    var el = document.getElementById(id);
    if (!el) return;
    setTimeout(function() {
        el.style.transition = 'opacity 0.5s ease';
        el.style.opacity = '0';
        setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 500);
    }, 5000);
});
</script>
</body>
</html>
