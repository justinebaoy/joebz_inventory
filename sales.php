<?php
date_default_timezone_set('Asia/Manila');
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

function redirect($url) {
    header("Location: $url");
    exit;
}

$isManager = in_array($_SESSION['role'], ['admin', 'manager']);

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

function getActiveDiscounts($conn) {
    $discounts = [];
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        "SELECT * FROM discount_rules
         WHERE is_active = 1
           AND start_date <= ?
           AND (end_date IS NULL OR end_date >= ?)"
    );
    $stmt->bind_param("ss", $now, $now);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $discounts[] = $row;
    }
    $stmt->close();
    return $discounts;
}

function loadItemCategories($conn) {
    $map = [];
    $result = $conn->query("SELECT item_id, category_id FROM items");
    while ($row = $result->fetch_assoc()) {
        $map[(int)$row['item_id']] = (int)$row['category_id'];
    }
    return $map;
}

function applyDiscountsToCart(&$cart, array $activeDiscounts, array $itemCategoryMap) {
    if (empty($cart)) return;
    foreach ($cart as &$item) {
        $bestDiscount = 0;
        $itemId       = (int)$item['item_id'];
        $categoryId   = $itemCategoryMap[$itemId] ?? null;
        foreach ($activeDiscounts as $discount) {
            if ($discount['discount_type'] === 'item' && (int)$discount['target_id'] === $itemId) {
                $bestDiscount = max($bestDiscount, (float)$discount['discount_percent']);
            } elseif ($discount['discount_type'] === 'category' && $categoryId !== null && (int)$discount['target_id'] === $categoryId) {
                $bestDiscount = max($bestDiscount, (float)$discount['discount_percent']);
            }
        }
        $item['discount_percent'] = $bestDiscount;
        $item['discounted_price'] = $item['price'] * (1 - $bestDiscount / 100);
        $item['discounted_total'] = $item['discounted_price'] * $item['quantity'];
    }
    unset($item);
}

$activeDiscounts = getActiveDiscounts($conn);
$itemCategoryMap = loadItemCategories($conn);
applyDiscountsToCart($_SESSION['cart'], $activeDiscounts, $itemCategoryMap);

$cart_subtotal     = 0;
$cart_discount_amt = 0;
$cart_total        = 0;

foreach ($_SESSION['cart'] as $ci) {
    $original          = $ci['price'] * $ci['quantity'];
    $discounted        = $ci['discounted_total'];
    $cart_subtotal    += $original;
    $cart_discount_amt += ($original - $discounted);
    $cart_total       += $discounted;
}
$cart_count = count($_SESSION['cart']);

$items = $conn->query(
    "SELECT i.*, c.category_name
     FROM items i
     JOIN categories c ON i.category_id = c.category_id
     WHERE i.stock > 0 AND i.is_active = 1
     ORDER BY i.item_name"
);

$productDiscountMap = [];
foreach ($activeDiscounts as $d) {
    if ($d['discount_type'] === 'item') {
        $id = (int)$d['target_id'];
        $productDiscountMap[$id] = max($productDiscountMap[$id] ?? 0, (float)$d['discount_percent']);
    } elseif ($d['discount_type'] === 'category') {
        $catId = (int)$d['target_id'];
        foreach ($itemCategoryMap as $itemId => $itemCatId) {
            if ($itemCatId === $catId) {
                $productDiscountMap[$itemId] = max($productDiscountMap[$itemId] ?? 0, (float)$d['discount_percent']);
            }
        }
    }
}

$success = $_GET['success'] ?? '';
$error   = $_GET['error']   ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_to_cart') {
        $id  = (int)($_POST['item_id']  ?? 0);
        $qty = max(1, (int)($_POST['quantity'] ?? 1));
        $stmt = $conn->prepare("SELECT item_name, price, stock FROM items WHERE item_id = ? AND is_active = 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && $row['stock'] >= $qty) {
            $found = false;
            foreach ($_SESSION['cart'] as &$ci) {
                if ($ci['item_id'] == $id) { $ci['quantity'] += $qty; $found = true; break; }
            }
            unset($ci);
            if (!$found) {
                $_SESSION['cart'][] = [
                    'item_id'          => $id,
                    'item_name'        => $row['item_name'],
                    'price'            => (float)$row['price'],
                    'quantity'         => $qty,
                    'discount_percent' => 0,
                    'discounted_price' => (float)$row['price'],
                    'discounted_total' => (float)$row['price'] * $qty,
                ];
            }
            applyDiscountsToCart($_SESSION['cart'], $activeDiscounts, $itemCategoryMap);
        }
        redirect('sales.php');
    }

    if ($action === 'remove_from_cart') {
        $id = (int)($_POST['item_id'] ?? 0);
        $_SESSION['cart'] = array_values(array_filter($_SESSION['cart'], fn($i) => $i['item_id'] != $id));
        redirect('sales.php');
    }

    if ($action === 'clear_cart') {
        $_SESSION['cart'] = [];
        redirect('sales.php');
    }

    if ($action === 'create_discount' && $isManager) {
        $dtype = $_POST['discount_type'] ?? 'item';
        $tid   = ($dtype === 'item') ? (int)($_POST['item_id'] ?? 0) : (int)($_POST['category_id'] ?? 0);
        $pct   = (float)($_POST['discount_percent'] ?? 0);
        $start = $_POST['start_date'] ?? '';
        $end   = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $uid   = $_SESSION['user_id'];
        $pct   = max(1, min(100, $pct));
        $start = date('Y-m-d H:i:s', strtotime($start));
        if ($end) $end = date('Y-m-d H:i:s', strtotime($end));
        if (!$tid || !$start) {
            $missing = !$tid ? ($dtype === 'item' ? 'Please select an item.' : 'Please select a category.') : 'Please set a start date.';
            redirect('sales.php?error=' . urlencode($missing));
        }
        $stmt = $conn->prepare("INSERT INTO discount_rules (discount_type, target_id, discount_percent, start_date, end_date, is_active, created_by) VALUES (?, ?, ?, ?, ?, 1, ?)");
        $stmt->bind_param("sidssi", $dtype, $tid, $pct, $start, $end, $uid);
        if ($stmt->execute()) {
            redirect('sales.php?success=' . urlencode('Discount created successfully.'));
        } else {
            redirect('sales.php?error=' . urlencode('Failed: ' . $stmt->error));
        }
    }

    if ($action === 'update_discount' && $isManager) {
        $did       = (int)$_POST['discount_id'];
        $pct       = (float)$_POST['discount_percent'];
        $start     = $_POST['start_date'];
        $end       = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $is_active = (int)$_POST['is_active'];
        $pct   = max(1, min(100, $pct));
        $start = date('Y-m-d H:i:s', strtotime($start));
        if ($end) $end = date('Y-m-d H:i:s', strtotime($end));
        $stmt = $conn->prepare("UPDATE discount_rules SET discount_percent = ?, start_date = ?, end_date = ?, is_active = ? WHERE discount_id = ?");
        $stmt->bind_param("dssii", $pct, $start, $end, $is_active, $did);
        if ($stmt->execute()) {
            redirect('sales.php?success=' . urlencode('Discount updated successfully.'));
        } else {
            redirect('sales.php?error=' . urlencode('Failed to update discount.'));
        }
    }

    if ($action === 'delete_discount' && $isManager) {
        $did  = (int)($_POST['discount_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM discount_rules WHERE discount_id = ?");
        $stmt->bind_param("i", $did);
        $stmt->execute();
        $stmt->close();
        redirect('sales.php?success=' . urlencode('Discount removed.'));
    }

    if ($action === 'process_sale') {
        if (empty($_SESSION['cart'])) redirect('sales.php?error=' . urlencode('Cart is empty.'));

        $cash           = (float)($_POST['cash_received'] ?? 0);
        $customer_id    = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $customer_name  = trim($_POST['customer_name'] ?? '');
        $customer_email = trim($_POST['customer_email'] ?? '');

        $gross_total    = 0;
        $total_discount = 0;
        $sale_total     = 0;

        foreach ($_SESSION['cart'] as $ci) {
            $gross          = $ci['price'] * $ci['quantity'];
            $net            = $ci['discounted_total'];
            $gross_total   += $gross;
            $total_discount += ($gross - $net);
            $sale_total    += $net;
        }

        if ($cash <= 0) redirect('sales.php?error=' . urlencode('Enter cash amount.'));
        if ($cash < $sale_total) redirect('sales.php?error=' . urlencode('Insufficient cash.'));

        $change = $cash - $sale_total;

        if ($customer_id === null && $customer_name !== '') {
            $stmt = $conn->prepare("INSERT INTO customers (name, email) VALUES (?, ?)");
            $email_for_customer = $customer_email !== '' ? $customer_email : null;
            $stmt->bind_param("ss", $customer_name, $email_for_customer);
            $stmt->execute();
            $customer_id = $conn->insert_id;
            $stmt->close();
        }

        if ($customer_id !== null && $customer_name === '') {
            $stmt = $conn->prepare("SELECT name FROM customers WHERE customer_id = ?");
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $customer_name = $row['name'] ?? '';
            $stmt->close();
        }

        $display_name = $customer_name !== '' ? $customer_name : 'Walk-in Customer';

        $conn->begin_transaction();
        try {
            if ($customer_id === null) {
                $stmt = $conn->prepare("INSERT INTO sales (user_id, total_amount, discount_amount, cash_received, change_amount, customer_name, customer_email) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param("iddddss", $_SESSION['user_id'], $gross_total, $total_discount, $cash, $change, $display_name, $customer_email);
            } else {
                $stmt = $conn->prepare("INSERT INTO sales (user_id, customer_id, total_amount, discount_amount, cash_received, change_amount, customer_name, customer_email) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param("iiddddss", $_SESSION['user_id'], $customer_id, $gross_total, $total_discount, $cash, $change, $display_name, $customer_email);
            }
            $stmt->execute();
            $sale_id = $conn->insert_id;
            $stmt->close();

            $si_stmt  = $conn->prepare("INSERT INTO sale_items (sale_id, item_id, quantity, price, discount_percent) VALUES (?, ?, ?, ?, ?)");
            $upd_stmt = $conn->prepare("UPDATE items SET stock = stock - ? WHERE item_id = ?");
            foreach ($_SESSION['cart'] as $item) {
                $si_stmt->bind_param("iiddd", $sale_id, $item['item_id'], $item['quantity'], $item['discounted_price'], $item['discount_percent']);
                $si_stmt->execute();
                $upd_stmt->bind_param("ii", $item['quantity'], $item['item_id']);
                $upd_stmt->execute();
            }
            $si_stmt->close();
            $upd_stmt->close();
            $conn->commit();
            $_SESSION['cart']         = [];
            $_SESSION['last_sale_id'] = $sale_id;
            redirect('sales.php?success=' . urlencode('Sale #' . $sale_id . ' processed for ' . $display_name . '! Change: ₱' . number_format($change, 2)) . '&sale_id=' . $sale_id);
        } catch (Exception $e) {
            $conn->rollback();
            redirect('sales.php?error=' . urlencode('Sale failed: ' . $e->getMessage()));
        }
    }
}

// FIX: Only show Print Receipt button when sale_id is explicitly in the URL
// (not from session), so discount actions never show the button.
$last_sale_id = isset($_GET['sale_id']) ? (int)$_GET['sale_id'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale — JOEBZ POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
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

        .loading-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.7); display: none;
            align-items: center; justify-content: center; z-index: 1000;
        }
        .loading-spinner {
            border: 4px solid #334155; border-top: 4px solid #3b82f6;
            border-radius: 50%; width: 48px; height: 48px;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { 0%{transform:rotate(0deg)} 100%{transform:rotate(360deg)} }

        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-panel { animation: modalIn 0.25s ease both; }

        .product-card {
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
            cursor: pointer;
        }
        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.35);
            border-color: var(--accent);
        }

        .discount-badge {
            background: var(--warn); color: #000;
            font-size: 0.65rem; padding: 0.15rem 0.5rem;
            border-radius: 9999px; font-weight: 700;
            font-family: 'DM Mono', monospace;
        }

        .cart-item { transition: background 0.15s ease; }
        .cart-item:hover { background: rgba(255,255,255,0.03); }

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

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 9999px; }

        .input-field {
            width: 100%; background: rgba(30,41,59,0.8);
            border: 1px solid #334155; border-radius: 0.75rem;
            color: #f1f5f9; padding: 0.5rem 0.75rem;
            font-family: 'DM Sans', sans-serif;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            outline: none;
        }
        .input-field:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }
        .input-field::placeholder { color: #475569; }

        .promo-chip {
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0.375rem 0.75rem;
            background: rgba(30,41,59,0.5);
            border: 1px solid rgba(51,65,85,0.5);
            border-radius: 0.75rem;
            transition: border-color 0.15s ease;
        }
        .promo-chip:hover { border-color: #475569; }

        .btn-process {
            background: linear-gradient(135deg, #059669, #10b981);
            box-shadow: 0 4px 20px rgba(16,185,129,0.25);
            transition: all 0.18s ease;
        }
        .btn-process:hover:not(:disabled) {
            box-shadow: 0 6px 28px rgba(16,185,129,0.40);
            transform: translateY(-1px);
        }
        .btn-process:disabled {
            background: #1e293b; box-shadow: none;
            color: #475569; cursor: not-allowed; transform: none;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

<div class="loading-overlay" id="loading">
    <div class="loading-spinner"></div>
</div>

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
            <a href="purchase_orders.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= basename($_SERVER['PHP_SELF'])=='purchase_orders.php'?'bg-blue-600/20 text-blue-200 font-medium':'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5h6m-7 4h8m-9 4h10m-6 4h2M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"/></svg>
                Purchase Orders
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
                <h1 class="text-3xl font-bold text-white tracking-tight">Point of Sale</h1>
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

        <!-- ══ ALERTS ══ -->
        <?php if ($success): ?>
        <div id="successMsg" class="mb-5 p-4 bg-emerald-900/30 border border-emerald-700/50 rounded-2xl text-emerald-200 flex items-center justify-between reveal reveal-2">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
            <?php if ($last_sale_id): ?>
            <button onclick="openReceiptModal(<?= $last_sale_id ?>)" class="flex items-center gap-1.5 bg-blue-600/80 hover:bg-blue-500 px-3 py-1.5 rounded-lg text-xs font-medium transition shrink-0">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Print Receipt
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div id="errorMsg" class="mb-5 p-4 bg-red-900/30 border border-red-700/50 rounded-2xl text-red-200 flex items-center gap-3 reveal reveal-2">
            <svg class="w-5 h-5 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <!-- ══ ACTIVE PROMOTIONS STRIP ══ -->
        <?php if (!empty($activeDiscounts)): ?>
        <div class="mb-5 bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden reveal reveal-2">
            <div class="flex items-center justify-between px-5 py-3 border-b border-slate-800">
                <div class="flex items-center gap-2.5">
                    <div class="w-1.5 h-5 bg-emerald-500 rounded-full"></div>
                    <h2 class="text-sm font-bold text-white" style="font-family:'Syne',sans-serif">Active Promotions</h2>
                    <span class="mono text-xs text-slate-500 bg-slate-800 px-2 py-0.5 rounded-lg"><?= count($activeDiscounts) ?> active</span>
                </div>
                <?php if ($isManager): ?>
                <button onclick="openAddModal()" class="flex items-center gap-1.5 text-xs bg-emerald-600/80 hover:bg-emerald-500 text-white px-3 py-1.5 rounded-xl transition font-medium">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New Discount
                </button>
                <?php endif; ?>
            </div>
            <div class="p-4">
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($activeDiscounts as $d):
                        $targetName = 'Unknown';
                        if ($d['discount_type'] === 'item') {
                            $t = $conn->query("SELECT item_name FROM items WHERE item_id={$d['target_id']}")->fetch_assoc();
                            $targetName = $t['item_name'] ?? 'Unknown';
                        } else {
                            $t = $conn->query("SELECT category_name FROM categories WHERE category_id={$d['target_id']}")->fetch_assoc();
                            $targetName = $t['category_name'] ?? 'Unknown';
                        }
                        $isItem = $d['discount_type'] === 'item';
                        $badgeText = $isItem ? 'Item' : 'Category';
                    ?>
                    <div class="group promo-chip">
                        <span class="mono text-emerald-400 font-bold text-sm">-<?= $d['discount_percent'] ?>%</span>
                        <div class="w-px h-4 bg-slate-700"></div>
                        <span class="text-slate-200 text-sm"><?= htmlspecialchars($targetName) ?></span>
                        <span class="text-xs px-1.5 py-0.5 rounded <?= $isItem ? 'bg-blue-500/10 text-blue-400 border border-blue-500/20' : 'bg-purple-500/10 text-purple-400 border border-purple-500/20' ?>"><?= $badgeText ?></span>
                        <?php if ($isManager): ?>
                        <div class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button onclick='editDiscount(<?= json_encode($d) ?>, <?= json_encode($targetName) ?>)' class="p-1 text-slate-500 hover:text-amber-400 transition rounded" title="Edit">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <button onclick="confirmDeleteDiscount(<?= $d['discount_id'] ?>, <?= $d['discount_percent'] ?>, '<?= htmlspecialchars(addslashes($targetName)) ?>', '<?= $badgeText ?>')" class="p-1 text-slate-500 hover:text-red-400 transition rounded" title="Delete">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                            <form method="POST" id="delete-discount-form-<?= $d['discount_id'] ?>" class="hidden">
                                <input type="hidden" name="action" value="delete_discount">
                                <input type="hidden" name="discount_id" value="<?= $d['discount_id'] ?>">
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="mb-5 bg-slate-900/50 rounded-2xl border border-dashed border-slate-700/60 p-4 flex items-center justify-between reveal reveal-2">
            <p class="text-slate-500 text-sm">No active promotions at the moment</p>
            <?php if ($isManager): ?>
            <button onclick="openAddModal()" class="flex items-center gap-1.5 text-emerald-400 hover:text-emerald-300 text-xs transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Create a discount
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ══ MAIN GRID: Products + Cart ══ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

            <!-- ── PRODUCTS PANEL ── -->
            <div class="lg:col-span-2 reveal reveal-3">
                <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden">

                    <div class="px-5 py-4 border-b border-slate-800 flex items-center gap-3">
                        <div class="relative flex-1">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" id="searchProduct" placeholder="Search products…" class="input-field pl-9 text-sm">
                        </div>
                        <span class="text-xs text-slate-500 bg-slate-800 border border-slate-700 px-2.5 py-1.5 rounded-xl mono whitespace-nowrap">
                            <?= $items->num_rows ?> items
                        </span>
                    </div>

                    <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-[600px] overflow-y-auto">
                        <?php
                        $items->data_seek(0);
                        while ($item = $items->fetch_assoc()):
                            $discount        = $productDiscountMap[$item['item_id']] ?? 0;
                            $discountedPrice = $item['price'] * (1 - $discount / 100);
                            $stockLow        = $item['stock'] <= 5;
                        ?>
                        <div class="product-card bg-slate-800/50 rounded-xl border <?= $discount ? 'border-amber-500/40' : 'border-slate-700/60' ?> p-4 relative overflow-hidden"
                             data-name="<?= strtolower(htmlspecialchars($item['item_name'])) ?>">

                            <?php if ($discount): ?>
                            <div class="absolute top-3 right-3"><span class="discount-badge"><?= $discount ?>% OFF</span></div>
                            <?php endif; ?>

                            <?php if ($discount): ?>
                            <div class="absolute inset-0 bg-gradient-to-br from-amber-500/5 to-transparent pointer-events-none rounded-xl"></div>
                            <?php endif; ?>

                            <h3 class="font-semibold text-white text-base mb-0.5 pr-14 leading-tight"><?= htmlspecialchars($item['item_name']) ?></h3>
                            <p class="text-xs text-blue-400 mb-3"><?= htmlspecialchars($item['category_name']) ?></p>

                            <div class="flex items-end justify-between gap-2">
                                <div>
                                    <?php if ($discount): ?>
                                    <p class="text-xl font-bold text-emerald-400 mono">₱<?= number_format($discountedPrice, 2) ?></p>
                                    <p class="text-xs text-slate-500 line-through mono">₱<?= number_format($item['price'], 2) ?></p>
                                    <?php else: ?>
                                    <p class="text-xl font-bold text-emerald-400 mono">₱<?= number_format($item['price'], 2) ?></p>
                                    <?php endif; ?>
                                    <?php if ($stockLow): ?>
                                    <p class="text-xs text-amber-400 mt-0.5"><?= $item['stock'] ?> left</p>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" class="flex items-center gap-1.5">
                                    <input type="hidden" name="action"  value="add_to_cart">
                                    <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                                    <input type="number" name="quantity" value="1" min="1" max="<?= $item['stock'] ?>"
                                        class="w-14 px-2 py-1.5 bg-slate-700 border border-slate-600 rounded-lg text-white text-center text-sm mono focus:outline-none focus:border-blue-500">
                                    <button type="submit"
                                        class="px-3 py-1.5 bg-blue-600 hover:bg-blue-500 rounded-lg text-white text-sm font-medium transition flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                        Add
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>

            <!-- ── CART PANEL ── -->
            <div class="reveal reveal-4">
                <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden sticky top-6">

                    <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">Cart</h2>
                            <?php if ($cart_count > 0): ?>
                            <span class="mono text-xs font-bold bg-blue-600/20 text-blue-300 border border-blue-500/30 px-2 py-0.5 rounded-full"><?= $cart_count ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($cart_count > 0): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="clear_cart">
                            <button class="text-xs text-slate-500 hover:text-red-400 transition flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                Clear
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <div class="max-h-64 overflow-y-auto">
                        <?php if (empty($_SESSION['cart'])): ?>
                        <div class="flex flex-col items-center justify-center py-12 text-slate-600">
                            <svg class="w-10 h-10 mb-2 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            <p class="text-sm">Cart is empty</p>
                        </div>
                        <?php else: foreach ($_SESSION['cart'] as $ci): ?>
                        <div class="cart-item px-5 py-3 border-b border-slate-800/60 flex items-center justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-white font-medium truncate"><?= htmlspecialchars($ci['item_name']) ?></p>
                                <p class="text-xs text-slate-400 mono">₱<?= number_format($ci['discounted_price'], 2) ?> × <?= $ci['quantity'] ?></p>
                                <?php if ($ci['discount_percent'] > 0): ?>
                                <p class="text-xs text-emerald-400"><?= $ci['discount_percent'] ?>% off</p>
                                <?php endif; ?>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-sm font-bold text-white mono">₱<?= number_format($ci['discounted_total'], 2) ?></p>
                                <form method="POST">
                                    <input type="hidden" name="action"  value="remove_from_cart">
                                    <input type="hidden" name="item_id" value="<?= $ci['item_id'] ?>">
                                    <button class="text-xs text-slate-600 hover:text-red-400 transition mt-0.5">Remove</button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>

                    <!-- Totals -->
                    <div class="px-5 py-4 border-t border-slate-800 space-y-1.5">
                        <div class="flex justify-between text-sm text-slate-400">
                            <span>Subtotal</span>
                            <span class="mono">₱<?= number_format($cart_subtotal, 2) ?></span>
                        </div>
                        <?php if ($cart_discount_amt > 0): ?>
                        <div class="flex justify-between text-sm text-emerald-400">
                            <span>Discount</span>
                            <span class="mono">−₱<?= number_format($cart_discount_amt, 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="flex justify-between items-center pt-2 border-t border-slate-800">
                            <span class="text-base font-bold text-white">Total</span>
                            <span class="text-2xl font-bold text-white mono" id="cartTotal">₱<?= number_format($cart_total, 2) ?></span>
                        </div>
                    </div>

                    <!-- Customer -->
                    <div class="px-5 pb-4 border-t border-slate-800 pt-4 space-y-2.5">
                        <p class="text-xs text-slate-500 uppercase tracking-widest font-medium">Customer</p>

                        <div class="relative">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            <input type="text" id="customerSearch" placeholder="Type name or search…"
                                autocomplete="off"
                                class="input-field pl-9 text-sm">
                            <div id="customerResults" class="hidden absolute z-20 left-0 right-0 top-full mt-1 bg-slate-800 border border-slate-700 rounded-xl shadow-2xl max-h-48 overflow-y-auto"></div>
                        </div>

                        <div id="customerBadge" class="hidden px-2.5 py-1.5 rounded-xl border text-xs flex items-center gap-1.5"></div>

                        <input type="hidden" id="customerId">
                        <input type="hidden" id="customerName">

                        <input type="email" id="customerEmail" placeholder="Email for receipt (optional)"
                            class="input-field text-sm">
                    </div>

                    <!-- Cash -->
                    <div class="px-5 pb-4 space-y-2.5 border-t border-slate-800 pt-4">
                        <p class="text-xs text-slate-500 uppercase tracking-widest font-medium">Payment</p>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-bold mono pointer-events-none">₱</span>
                            <input type="number" id="cashReceived" step="0.01" min="0" placeholder="0.00"
                                class="input-field pl-7 text-lg mono font-bold">
                        </div>

                        <div id="changeDisplay" class="hidden bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3">
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-400">Change</span>
                                <span id="changeAmount" class="text-xl font-bold text-emerald-400 mono">₱0.00</span>
                            </div>
                        </div>
                    </div>

                    <!-- Process button -->
                    <div class="px-5 pb-5">
                        <button id="processSaleBtn" disabled
                            class="btn-process w-full py-3 rounded-xl font-bold text-white text-base flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Process Sale
                        </button>
                    </div>

                </div>
            </div><!-- /cart -->
        </div><!-- /grid -->
    </div><!-- /max-w -->
</div><!-- /main -->


<!-- ═══════════ RECEIPT MODAL ═══════════ -->
<div id="receiptModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="modal-panel bg-white rounded-2xl shadow-2xl w-full max-w-md flex flex-col" style="max-height:90vh">
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-200 shrink-0">
            <span class="font-bold text-gray-800 text-sm" style="font-family:'Syne',sans-serif">Receipt Preview</span>
            <div class="flex items-center gap-2">
                <button onclick="printReceiptFrame()"
                    class="flex items-center gap-1.5 bg-blue-600 hover:bg-blue-500 text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    Print
                </button>
                <button onclick="closeReceiptModal()"
                    class="w-7 h-7 flex items-center justify-center rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-500 hover:text-gray-800 text-lg leading-none transition font-medium">&times;</button>
            </div>
        </div>
        <iframe id="receiptFrame" src="" class="flex-1 rounded-b-2xl" style="min-height:500px; border:none;"></iframe>
    </div>
</div>


<!-- ═══════════ MODALS ═══════════ -->
<?php if ($isManager): ?>

<!-- DELETE DISCOUNT MODAL -->
<div id="deleteDiscountModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div id="deleteDiscountPanel" class="modal-panel bg-slate-900 border border-red-900/40 rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <div class="flex items-start gap-4 mb-5">
            <div class="w-11 h-11 rounded-xl bg-red-900/30 border border-red-800/40 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </div>
            <div>
                <h3 class="font-bold text-white text-base" style="font-family:'Syne',sans-serif">Remove Discount</h3>
                <p class="text-sm text-slate-400 mt-0.5">This will deactivate the promotion immediately.</p>
            </div>
        </div>
        <div class="bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 mb-5 space-y-2">
            <div class="flex justify-between text-sm"><span class="text-slate-500">Discount</span><span class="text-emerald-400 mono font-bold" id="ddModalPercent"></span></div>
            <div class="flex justify-between text-sm"><span class="text-slate-500">Target</span><span class="text-white font-medium" id="ddModalTarget"></span></div>
            <div class="flex justify-between text-sm"><span class="text-slate-500">Type</span><span class="text-slate-300" id="ddModalType"></span></div>
        </div>
        <p class="text-xs text-amber-400/80 flex items-center gap-1.5 mb-5">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            Active cart discounts will be recalculated on next page load.
        </p>
        <div class="flex gap-3">
            <button type="button" onclick="closeDeleteDiscountModal()" class="flex-1 border border-slate-700 hover:bg-slate-800 text-slate-300 py-2.5 rounded-xl text-sm font-medium transition">Cancel</button>
            <button type="button" id="ddModalConfirmBtn" class="flex-1 bg-red-600 hover:bg-red-500 text-white py-2.5 rounded-xl text-sm font-semibold transition">Remove</button>
        </div>
    </div>
</div>

<!-- ADD DISCOUNT MODAL -->
<div id="addModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)closeAddModal()">
    <div class="modal-panel bg-slate-900 rounded-2xl border border-slate-700 w-full max-w-md p-6">
        <div class="flex justify-between items-center mb-5">
            <h2 class="text-xl font-bold text-white" style="font-family:'Syne',sans-serif">Create Discount</h2>
            <button onclick="closeAddModal()" class="w-8 h-8 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition text-lg">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create_discount">
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Discount Type</label>
                <select name="discount_type" id="addType" class="input-field text-sm" required>
                    <option value="item">Specific Item</option>
                    <option value="category">Entire Category</option>
                </select>
            </div>
            <div id="addItemDiv">
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Select Item</label>
                <select id="addItemSelect" name="item_id" class="input-field text-sm" required>
                    <option value="">— Choose an item —</option>
                    <?php $all = $conn->query("SELECT item_id, item_name FROM items WHERE is_active=1 ORDER BY item_name"); while ($r = $all->fetch_assoc()): ?>
                    <option value="<?= $r['item_id'] ?>"><?= htmlspecialchars($r['item_name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div id="addCategoryDiv" style="display:none">
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Select Category</label>
                <select id="addCategorySelect" name="category_id" class="input-field text-sm">
                    <option value="">— Choose a category —</option>
                    <?php $cats = $conn->query("SELECT category_id, category_name FROM categories ORDER BY category_name"); while ($r = $cats->fetch_assoc()): ?>
                    <option value="<?= $r['category_id'] ?>"><?= htmlspecialchars($r['category_name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Discount %</label>
                <input type="number" name="discount_percent" min="1" max="100" step="1" required class="input-field text-sm mono">
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Start Date & Time</label>
                <input type="datetime-local" name="start_date" id="addStartDate" required class="input-field text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">End Date & Time <span class="text-slate-600 normal-case">(optional)</span></label>
                <input type="datetime-local" name="end_date" class="input-field text-sm">
            </div>
            <div class="flex gap-3 pt-1">
                <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-500 py-2.5 rounded-xl text-white font-semibold text-sm transition">Create</button>
                <button type="button" onclick="closeAddModal()" class="flex-1 bg-slate-800 hover:bg-slate-700 py-2.5 rounded-xl text-slate-300 font-medium text-sm transition">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT DISCOUNT MODAL -->
<div id="editModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)closeEditModal()">
    <div class="modal-panel bg-slate-900 rounded-2xl border border-slate-700 w-full max-w-md p-6">
        <div class="flex justify-between items-center mb-5">
            <h2 class="text-xl font-bold text-white" style="font-family:'Syne',sans-serif">Edit Discount</h2>
            <button onclick="closeEditModal()" class="w-8 h-8 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition text-lg">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action"      value="update_discount">
            <input type="hidden" name="discount_id" id="editId">
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Type</label>
                <input type="text" id="editTypeDisplay" readonly class="input-field text-sm opacity-60 cursor-default">
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Target</label>
                <input type="text" id="editTargetDisplay" readonly class="input-field text-sm opacity-60 cursor-default">
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Discount %</label>
                <input type="number" name="discount_percent" id="editPercent" min="1" max="100" step="1" required class="input-field text-sm mono">
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Start Date & Time</label>
                <input type="datetime-local" name="start_date" id="editStartDate" required class="input-field text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">End Date & Time <span class="text-slate-600 normal-case">(optional)</span></label>
                <input type="datetime-local" name="end_date" id="editEndDate" class="input-field text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Status</label>
                <select name="is_active" id="editActive" class="input-field text-sm">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="submit" class="flex-1 bg-amber-600 hover:bg-amber-500 py-2.5 rounded-xl text-white font-semibold text-sm transition">Update</button>
                <button type="button" onclick="closeEditModal()" class="flex-1 bg-slate-800 hover:bg-slate-700 py-2.5 rounded-xl text-slate-300 font-medium text-sm transition">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>


<script>
// ── Sidebar toggle ─────────────────────────────────────────────────────────
var sidebar = document.getElementById('sidebar');
var openBtn = document.getElementById('open-sidebar');
function openSidebar()  { sidebar.classList.remove('-translate-x-full'); }
function closeSidebar() { sidebar.classList.add('-translate-x-full'); }
if (openBtn) openBtn.addEventListener('click', openSidebar);
document.addEventListener('click', function (e) {
    if (window.innerWidth < 768 && sidebar &&
        !sidebar.contains(e.target) && openBtn && !openBtn.contains(e.target)) {
        closeSidebar();
    }
});
window.addEventListener('resize', function () {
    if (window.innerWidth >= 768) sidebar.classList.remove('-translate-x-full');
    else sidebar.classList.add('-translate-x-full');
});

// ── Live clock ─────────────────────────────────────────────────────────────
function updateClock() {
    var el = document.getElementById('liveTime');
    if (el) el.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
updateClock();
setInterval(updateClock, 1000);

// ── Auto-dismiss alerts ────────────────────────────────────────────────────
setTimeout(function () {
    ['successMsg', 'errorMsg'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.style.transition = 'opacity 0.5s ease';
        el.style.opacity = '0';
        setTimeout(function () { if (el && el.parentNode) el.parentNode.removeChild(el); }, 500);
    });
}, 5000);

// ── Receipt Modal ──────────────────────────────────────────────────────────
function openReceiptModal(saleId) {
    var modal = document.getElementById('receiptModal');
    var frame = document.getElementById('receiptFrame');
    frame.src = 'print_receipt.php?id=' + saleId;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeReceiptModal() {
    var modal = document.getElementById('receiptModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.getElementById('receiptFrame').src = '';
}
function printReceiptFrame() {
    var frame = document.getElementById('receiptFrame');
    if (frame && frame.contentWindow) {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    }
}
document.getElementById('receiptModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeReceiptModal();
});

// ── Delete Discount Modal ──────────────────────────────────────────────────
function confirmDeleteDiscount(id, percent, target, type) {
    document.getElementById('ddModalPercent').textContent = '-' + percent + '%';
    document.getElementById('ddModalTarget').textContent  = target;
    document.getElementById('ddModalType').textContent    = type;

    // FIX: Capture form reference BEFORE closing modal to avoid DOM race
    document.getElementById('ddModalConfirmBtn').onclick = function () {
        var form = document.getElementById('delete-discount-form-' + id);
        closeDeleteDiscountModal();
        setTimeout(function () { if (form) form.submit(); }, 80);
    };

    var panel = document.getElementById('deleteDiscountPanel');
    panel.style.animation = 'none'; panel.offsetHeight; panel.style.animation = '';
    var modal = document.getElementById('deleteDiscountModal');
    modal.classList.remove('hidden'); modal.classList.add('flex');
}
function closeDeleteDiscountModal() {
    var modal = document.getElementById('deleteDiscountModal');
    if (modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); }
}
document.getElementById('deleteDiscountModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeDeleteDiscountModal();
});

// ── Add Modal ──────────────────────────────────────────────────────────────
function openAddModal() {
    var modal = document.getElementById('addModal');
    if (!modal) return;
    var now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('addStartDate').value = now.toISOString().slice(0, 16);
    document.getElementById('addType').value = 'item';
    document.getElementById('addItemDiv').style.display     = 'block';
    document.getElementById('addCategoryDiv').style.display = 'none';
    document.getElementById('addItemSelect').required     = true;
    document.getElementById('addCategorySelect').required = false;
    modal.style.display = 'flex';
}
function closeAddModal() {
    var m = document.getElementById('addModal');
    if (m) m.style.display = 'none';
}

var addType = document.getElementById('addType');
if (addType) {
    addType.addEventListener('change', function () {
        var isItem = this.value === 'item';
        document.getElementById('addItemDiv').style.display     = isItem ? 'block' : 'none';
        document.getElementById('addCategoryDiv').style.display = isItem ? 'none'  : 'block';
        document.getElementById('addItemSelect').required     = isItem;
        document.getElementById('addCategorySelect').required = !isItem;
    });
}

// ── Edit Modal ─────────────────────────────────────────────────────────────
function editDiscount(d, targetName) {
    var modal = document.getElementById('editModal');
    if (!modal) return;
    document.getElementById('editId').value            = d.discount_id;
    document.getElementById('editPercent').value       = d.discount_percent;
    document.getElementById('editActive').value        = d.is_active;
    document.getElementById('editTypeDisplay').value   = d.discount_type === 'item' ? 'Specific Item' : 'Entire Category';
    document.getElementById('editTargetDisplay').value = targetName;
    var sd = new Date(d.start_date);
    sd.setMinutes(sd.getMinutes() - sd.getTimezoneOffset());
    document.getElementById('editStartDate').value = sd.toISOString().slice(0, 16);
    if (d.end_date) {
        var ed = new Date(d.end_date);
        ed.setMinutes(ed.getMinutes() - ed.getTimezoneOffset());
        document.getElementById('editEndDate').value = ed.toISOString().slice(0, 16);
    } else {
        document.getElementById('editEndDate').value = '';
    }
    modal.style.display = 'flex';
}
function closeEditModal() {
    var m = document.getElementById('editModal');
    if (m) m.style.display = 'none';
}

// ── ESC closes all modals ──────────────────────────────────────────────────
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeAddModal();
        closeEditModal();
        closeDeleteDiscountModal();
        closeReceiptModal();
    }
});

// ── Product search ─────────────────────────────────────────────────────────
var searchInput = document.getElementById('searchProduct');
if (searchInput) {
    searchInput.addEventListener('input', function () {
        var term = this.value.toLowerCase();
        document.querySelectorAll('.product-card').forEach(function (p) {
            p.style.display = (p.dataset.name || '').includes(term) ? '' : 'none';
        });
    });
}

// ── Customer search ────────────────────────────────────────────────────────
var cs = document.getElementById('customerSearch');
var cr = document.getElementById('customerResults');
var ci = document.getElementById('customerId');
var cn = document.getElementById('customerName');
var ce = document.getElementById('customerEmail');
var badge = document.getElementById('customerBadge');
var searchTimer = null;

function showBadge(type, text) {
    badge.className = 'mt-0 px-2.5 py-1.5 rounded-xl border text-xs flex items-center gap-1.5 ' +
        (type === 'new'
            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300'
            : 'bg-blue-500/10 border-blue-500/30 text-blue-300');
    badge.innerHTML = (type === 'new'
        ? '<svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>'
        : '<svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>'
    ) + '<span>' + text + '</span>';
    badge.classList.remove('hidden');
}
function hideBadge() { badge.classList.add('hidden'); badge.innerHTML = ''; }

if (cs) {
    cs.addEventListener('input', function () {
        var term = this.value.trim();
        ci.value = ''; cn.value = term;
        if (term.length < 2) { cr.classList.add('hidden'); hideBadge(); return; }
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            fetch('customer_ajax.php?action=search&term=' + encodeURIComponent(term))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.length) {
                        cr.innerHTML = data.map(function (c) {
                            return '<div class="px-3 py-2.5 hover:bg-slate-700 cursor-pointer text-sm flex items-center justify-between gap-2 border-b border-slate-700/50 last:border-0"' +
                                ' data-id="' + c.customer_id + '" data-email="' + (c.email || '') + '" data-name="' + c.name.replace(/"/g, '&quot;') + '">' +
                                '<span class="text-white font-medium">' + c.name + '</span>' +
                                (c.email ? '<span class="text-slate-500 text-xs truncate">' + c.email + '</span>' : '') +
                                '</div>';
                        }).join('');
                        cr.classList.remove('hidden');
                        cr.querySelectorAll('div').forEach(function (el) {
                            el.addEventListener('click', function () {
                                ci.value = this.dataset.id; cn.value = this.dataset.name;
                                ce.value = this.dataset.email; cs.value = this.dataset.name;
                                cr.classList.add('hidden');
                                showBadge('existing', 'Existing customer selected');
                            });
                        });
                        hideBadge();
                    } else {
                        cr.innerHTML = '<div class="px-3 py-2.5 text-slate-400 text-sm flex items-center gap-2">' +
                            '<svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>' +
                            '<span>No match — <strong class="text-emerald-300">"' + term + '"</strong> will be saved as new</span>' +
                            '</div>';
                        cr.classList.remove('hidden');
                        showBadge('new', 'New customer — will be saved on sale');
                    }
                })
                .catch(function () { cn.value = term; showBadge('new', 'New customer — will be saved on sale'); });
        }, 280);
    });
    document.addEventListener('click', function (e) {
        if (cr && !cs.contains(e.target) && !cr.contains(e.target)) cr.classList.add('hidden');
    });
}

// ── Cash & change ──────────────────────────────────────────────────────────
var cashInput     = document.getElementById('cashReceived');
var processBtn    = document.getElementById('processSaleBtn');
var changeDisplay = document.getElementById('changeDisplay');
var changeAmount  = document.getElementById('changeAmount');
var totalSpan     = document.getElementById('cartTotal');

function getTotal() {
    var text = totalSpan ? totalSpan.innerText.replace('₱', '').replace(/,/g, '') : '0';
    return parseFloat(text) || 0;
}

function updateUI() {
    var total  = getTotal();
    var cash   = parseFloat(cashInput ? cashInput.value : 0) || 0;
    var change = cash - total;
    if (cash > 0 && changeDisplay) {
        changeDisplay.classList.remove('hidden');
        if (changeAmount) {
            changeAmount.innerText = '₱' + Math.abs(change).toFixed(2);
            changeAmount.className = (change < 0)
                ? 'text-xl font-bold text-red-400 mono'
                : 'text-xl font-bold text-emerald-400 mono';
        }
    } else if (changeDisplay) {
        changeDisplay.classList.add('hidden');
    }
    if (processBtn) processBtn.disabled = !(total > 0 && cash > 0 && cash >= total);
}

if (cashInput) { cashInput.addEventListener('input', updateUI); updateUI(); }

// ── Process Sale ───────────────────────────────────────────────────────────
if (processBtn) {
    processBtn.addEventListener('click', function () {
        var total = getTotal();
        var cash  = parseFloat(cashInput ? cashInput.value : 0) || 0;
        if (total <= 0)   { alert('Cart is empty'); return; }
        if (cash < total) { alert('Insufficient cash. Need ₱' + total.toFixed(2)); return; }
        document.getElementById('loading').style.display = 'flex';
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML =
            '<input type="hidden" name="action"         value="process_sale">' +
            '<input type="hidden" name="cash_received"  value="' + cash.toFixed(2) + '">' +
            '<input type="hidden" name="customer_id"    value="' + (ci ? ci.value : '') + '">' +
            '<input type="hidden" name="customer_name"  value="' + (cn ? cn.value.replace(/"/g, '&quot;') : '') + '">' +
            '<input type="hidden" name="customer_email" value="' + (ce ? ce.value : '') + '">';
        document.body.appendChild(form);
        form.submit();
    });
}
</script>
</body>
</html>