<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$is_admin = ($_SESSION['role'] === 'admin');
$success = $_GET['success'] ?? '';
$error = '';

function getCategorySpecFields($conn, $category_id) {
    $stmt = $conn->prepare("SELECT spec_fields FROM categories WHERE category_id = ?");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return json_decode($row['spec_fields'] ?? '[]', true);
}

function buildDescriptionFromPost($post, $allowedKeys) {
    $lines = [];
    if (!empty($post['product_number'])) $lines[] = "Product number: " . trim($post['product_number']);
    $labelMap = [
        'microprocessor' => 'Microprocessor', 'chipset' => 'Chipset', 'memory_standard' => 'Memory',
        'video_graphics' => 'Video Graphics', 'hard_drive' => 'Hard Drive', 'display' => 'Display',
        'battery' => 'Battery', 'operating_system' => 'Operating System', 'connectivity' => 'Connectivity',
        'dimensions' => 'Dimensions', 'warranty' => 'Warranty', 'interface' => 'Interface',
        'dpi_resolution' => 'DPI Resolution', 'compatibility' => 'Compatibility', 'cable_length' => 'Cable Length',
        'print_technology' => 'Print Technology', 'print_speed' => 'Print Speed', 'paper_size' => 'Paper Size',
        'ink_type' => 'Ink Type', 'page_yield' => 'Page Yield', 'duty_cycle' => 'Duty Cycle',
        'license_type' => 'License Type', 'license_duration' => 'License Duration', 'min_requirements' => 'Min Requirements',
        'supported_os' => 'Supported OS', 'users_allowed' => 'Users Allowed', 'model_number' => 'Model Number',
        'manufacturer' => 'Manufacturer', 'color' => 'Color'
    ];
    foreach ($allowedKeys as $key) {
        if ($key !== 'product_number' && $key !== 'details' && isset($post[$key]) && trim($post[$key]) !== '') {
            $label = $labelMap[$key] ?? ucfirst(str_replace('_', ' ', $key));
            $lines[] = "$label: " . trim($post[$key]);
        }
    }
    if (!empty($post['details'])) $lines[] = "Details: " . trim($post['details']);
    return implode("\n", $lines);
}

function parseDescriptionForEdit($description, $allowedKeys) {
    $values = [];
    if (!$description) return $values;
    $lines = explode("\n", $description);
    $labelToKey = [
        'Product number' => 'product_number', 'Microprocessor' => 'microprocessor', 'Chipset' => 'chipset',
        'Memory' => 'memory_standard', 'Video Graphics' => 'video_graphics', 'Hard Drive' => 'hard_drive',
        'Display' => 'display', 'Battery' => 'battery', 'Operating System' => 'operating_system',
        'Connectivity' => 'connectivity', 'Dimensions' => 'dimensions', 'Warranty' => 'warranty',
        'Interface' => 'interface', 'DPI Resolution' => 'dpi_resolution', 'Compatibility' => 'compatibility',
        'Cable Length' => 'cable_length', 'Print Technology' => 'print_technology', 'Print Speed' => 'print_speed',
        'Paper Size' => 'paper_size', 'Ink Type' => 'ink_type', 'Page Yield' => 'page_yield',
        'Duty Cycle' => 'duty_cycle', 'License Type' => 'license_type', 'License Duration' => 'license_duration',
        'Min Requirements' => 'min_requirements', 'Supported OS' => 'supported_os', 'Users Allowed' => 'users_allowed',
        'Model Number' => 'model_number', 'Manufacturer' => 'manufacturer', 'Color' => 'color',
        'Details' => 'details'
    ];
    foreach ($lines as $line) {
        if (strpos($line, ':') !== false) {
            list($key, $val) = explode(':', $line, 2);
            $key = trim($key);
            if (isset($labelToKey[$key])) $values[$labelToKey[$key]] = trim($val);
        }
    }
    return $values;
}

// Handle POST (admin only)
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $item_name = trim($_POST['item_name']);
    $category_id = (int)$_POST['category_id'];
    $price = (float)$_POST['price'];
    $stock = (int)$_POST['stock'];
    $allowedKeys = getCategorySpecFields($conn, $category_id);
    if (!in_array('product_number', $allowedKeys)) $allowedKeys[] = 'product_number';
    if (!in_array('details', $allowedKeys)) $allowedKeys[] = 'details';
    $description = buildDescriptionFromPost($_POST, $allowedKeys);

    $image_path = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $filename = 'item_' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $filename);
        $image_path = $upload_dir . $filename;
    }

    if ($action === 'create') {
        $stmt = $conn->prepare("INSERT INTO items (item_name, category_id, price, stock, description, image_path, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("siidss", $item_name, $category_id, $price, $stock, $description, $image_path);
        $stmt->execute();
        $stmt->close();
        header("Location: items.php?success=Item added");
        exit;
    }
    if ($action === 'edit') {
        $item_id = (int)$_POST['item_id'];
        $current_image = $_POST['current_image'] ?? '';
        if (empty($image_path)) $image_path = $current_image;
        $stmt = $conn->prepare("UPDATE items SET item_name=?, category_id=?, price=?, stock=?, description=?, image_path=? WHERE item_id=?");
        $stmt->bind_param("siidssi", $item_name, $category_id, $price, $stock, $description, $image_path, $item_id);
        $stmt->execute();
        $stmt->close();
        header("Location: items.php?success=Item updated");
        exit;
    }
}

// Delete (admin only)
if ($is_admin && isset($_GET['delete'])) {
    $item_id = (int)$_GET['delete'];
    $conn->query("DELETE FROM items WHERE item_id = $item_id");
    header("Location: items.php?success=Item deleted");
    exit;
}

// Get edit data
$edit_item = null;
$edit_values = [];
if ($is_admin && isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $result = $conn->query("SELECT * FROM items WHERE item_id = $edit_id");
    $edit_item = $result->fetch_assoc();
    if ($edit_item) {
        $allowedKeys = getCategorySpecFields($conn, $edit_item['category_id']);
        $allowedKeys[] = 'product_number';
        $allowedKeys[] = 'details';
        $edit_values = parseDescriptionForEdit($edit_item['description'], $allowedKeys);
    }
}

// Get categories
$categories = $conn->query("SELECT category_id, category_name, spec_fields FROM categories ORDER BY category_name");
$cats_data = [];
while ($c = $categories->fetch_assoc()) $cats_data[] = $c;

// Filtering and listing
$search = $_GET['search'] ?? '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$show_inactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] === '1';
$where = [];
if (!$show_inactive) $where[] = "i.is_active = 1";
if ($search) $where[] = "(i.item_name LIKE '%$search%' OR i.description LIKE '%$search%')";
if ($category_filter > 0) $where[] = "i.category_id = $category_filter";
$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
$items = $conn->query("SELECT i.*, c.category_name FROM items i JOIN categories c ON i.category_id = c.category_id $where_sql ORDER BY i.item_id DESC");
$total_items = $items->num_rows;
$low_stock = $conn->query("SELECT COUNT(*) as total FROM items WHERE stock <= 5 AND is_active = 1")->fetch_assoc()['total'];
$cat_count = count($cats_data);
$all_items_count = $conn->query("SELECT COUNT(*) as c FROM items WHERE is_active = 1")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Items — JOEBZ POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
        .input-field::placeholder { color: #475569; }

        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-panel { animation: modalIn 0.25s ease both; }

        .item-row { transition: background 0.15s ease; }
        .item-row:hover { background: rgba(255,255,255,0.03); }

        /* Item card */
        .item-card { transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease; }
        .item-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(0,0,0,0.35); border-color: rgba(59,130,246,0.4); }

        /* Form scroll */
        .form-scroll { max-height: calc(100vh - 220px); overflow-y: auto; padding-right: 4px; }
        .form-scroll::-webkit-scrollbar { width: 4px; }
        .form-scroll::-webkit-scrollbar-track { background: transparent; }
        .form-scroll::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }

        /* Specs accordion */
        #specsBody { transition: max-height 0.3s ease, opacity 0.3s ease; }
        #specsBody.open  { max-height: 280px; opacity: 1; overflow-y: auto; }
        #specsBody.closed { max-height: 0; opacity: 0; overflow: hidden; }
        #specsBody::-webkit-scrollbar { width: 4px; }
        #specsBody::-webkit-scrollbar-track { background: #1e293b; border-radius: 4px; }
        #specsBody::-webkit-scrollbar-thumb { background: #475569; border-radius: 4px; }
        #specsChevron { transition: transform 0.25s ease; }
        #specsChevron.rotated { transform: rotate(180deg); }

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 9999px; }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

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
                <h1 class="text-3xl font-bold text-white tracking-tight">Items Management</h1>
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

        <!-- Stat Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">

            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-2">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-blue-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Total Items</p>
                        <p class="text-3xl font-bold text-white mono"><?= $all_items_count ?></p>
                        <p class="text-xs text-slate-500 mt-1">Active inventory</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-blue-500/15 border border-blue-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>
                    </div>
                </div>
            </div>

            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-3">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-purple-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Categories</p>
                        <p class="text-3xl font-bold text-white mono"><?= $cat_count ?></p>
                        <p class="text-xs text-slate-500 mt-1">Product categories</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-purple-500/15 border border-purple-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    </div>
                </div>
            </div>

            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-3">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-amber-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Low Stock</p>
                        <p class="text-3xl font-bold mono <?= $low_stock > 0 ? 'text-amber-400' : 'text-white' ?>"><?= $low_stock ?></p>
                        <p class="text-xs text-slate-500 mt-1">Items at ≤ 5 units</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-500/15 border border-amber-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                </div>
            </div>

        </div>

        <!-- Layout: Form + List -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- ══ ADD / EDIT FORM (Admin only) ══ -->
            <?php if ($is_admin): ?>
            <div class="lg:col-span-1 reveal reveal-4">
                <div class="bg-slate-900 rounded-2xl border border-slate-800 shadow-xl sticky top-6">
                    <div class="flex items-center justify-between px-6 pt-5 pb-4 border-b border-slate-800">
                        <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">
                            <?= $edit_item ? 'Edit Item' : 'Add New Item' ?>
                        </h2>
                        <?php if ($edit_item): ?>
                            <a href="items.php" class="text-xs text-slate-400 hover:text-slate-200 transition px-2 py-1 rounded-lg hover:bg-slate-800">✕ Cancel</a>
                        <?php endif; ?>
                    </div>

                    <div class="form-scroll px-6 py-4">
                        <form method="POST" enctype="multipart/form-data" id="itemForm" class="space-y-3">
                            <input type="hidden" name="action" value="<?= $edit_item ? 'edit' : 'create' ?>">
                            <?php if ($edit_item): ?>
                                <input type="hidden" name="item_id" value="<?= $edit_item['item_id'] ?>">
                                <input type="hidden" name="current_image" value="<?= $edit_item['image_path'] ?>">
                            <?php endif; ?>

                            <div>
                                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Item Name <span class="text-red-400">*</span></label>
                                <input type="text" name="item_name" value="<?= htmlspecialchars($edit_item['item_name'] ?? '') ?>" required class="input-field text-sm">
                            </div>

                            <div>
                                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Category <span class="text-red-400">*</span></label>
                                <select name="category_id" id="category_id" required class="input-field text-sm">
                                    <option value="">Select Category</option>
                                    <?php foreach ($cats_data as $cat): ?>
                                        <option value="<?= $cat['category_id'] ?>"
                                            data-spec='<?= htmlspecialchars(json_encode(json_decode($cat['spec_fields'] ?? '[]', true))) ?>'
                                            <?= ($edit_item && $edit_item['category_id'] == $cat['category_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['category_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Price (₱) <span class="text-red-400">*</span></label>
                                    <input type="number" name="price" step="0.01" min="0" value="<?= $edit_item['price'] ?? '' ?>" required class="input-field text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Stock <span class="text-red-400">*</span></label>
                                    <input type="number" name="stock" min="0" value="<?= $edit_item['stock'] ?? '' ?>" required class="input-field text-sm">
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Product Image</label>
                                <input type="file" name="image" accept="image/*"
                                    class="w-full text-sm text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-blue-600 file:text-white file:text-xs file:cursor-pointer hover:file:bg-blue-700 transition">
                                <?php if ($edit_item && $edit_item['image_path']): ?>
                                    <p class="text-xs text-slate-500 mt-1">Current: <?= basename($edit_item['image_path']) ?></p>
                                <?php endif; ?>
                            </div>

                            <!-- Specs Accordion -->
                            <div id="specFieldsContainer">
                                <button type="button" id="toggleSpecs"
                                    class="w-full flex items-center justify-between px-3 py-2.5 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-xl text-slate-300 text-sm transition mt-1">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                        <span>Product Specifications</span>
                                        <span id="specsCount" class="text-xs bg-blue-600/30 text-blue-300 px-1.5 py-0.5 rounded-full mono">0</span>
                                    </div>
                                    <svg id="specsChevron" class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                <div id="specsBody" class="closed mt-2 space-y-2 rounded-xl border border-slate-700/50 bg-slate-800/40 px-3 py-3"></div>
                            </div>

                            <button type="submit"
                                class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 rounded-xl transition text-sm shadow-lg shadow-blue-900/30 mt-1">
                                <?= $edit_item ? '✓ Update Item' : '+ Add Item' ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══ ITEMS LIST ══ -->
            <div class="<?= $is_admin ? 'lg:col-span-2' : 'lg:col-span-3' ?> reveal reveal-5">

                <!-- Filter bar -->
                <div class="bg-slate-900 rounded-2xl border border-slate-800 p-4 mb-4">
                    <form method="GET" class="flex flex-wrap gap-3 items-end">
                        <div class="flex-1 min-w-[150px]">
                            <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Search</label>
                            <div class="relative">
                                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name or description..."
                                    class="input-field pl-9 text-sm">
                            </div>
                        </div>
                        <div class="w-44">
                            <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Category</label>
                            <select name="category" class="input-field text-sm">
                                <option value="0">All Categories</option>
                                <?php
                                $cat_res = $conn->query("SELECT category_id, category_name FROM categories ORDER BY category_name");
                                while ($cr = $cat_res->fetch_assoc()):
                                    $sel = ($category_filter == $cr['category_id']) ? 'selected' : '';
                                ?>
                                    <option value="<?= $cr['category_id'] ?>" <?= $sel ?>><?= htmlspecialchars($cr['category_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <?php if ($is_admin): ?>
                        <div class="flex items-end pb-1">
                            <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                                <input type="checkbox" name="show_inactive" value="1" <?= $show_inactive ? 'checked' : '' ?> onchange="this.form.submit()"
                                    class="rounded border-slate-600 bg-slate-800 text-blue-500 w-4 h-4">
                                Show inactive
                            </label>
                        </div>
                        <?php endif; ?>
                        <div class="flex gap-2">
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 rounded-xl text-white text-sm font-medium transition">Filter</button>
                            <a href="items.php" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-xl text-slate-300 text-sm transition">Clear</a>
                        </div>
                    </form>
                </div>

                <!-- Table header -->
                <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                        <div>
                            <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">All Items</h2>
                            <p class="text-xs text-slate-500 mt-0.5">Manage inventory stock and pricing</p>
                        </div>
                        <span class="mono text-xs text-slate-400 bg-slate-800 border border-slate-700 px-2.5 py-1 rounded-xl">
                            <?= $total_items ?> item<?= $total_items !== 1 ? 's' : '' ?>
                        </span>
                    </div>

                    <?php if ($items->num_rows === 0): ?>
                    <div class="py-16 text-center">
                        <svg class="w-10 h-10 text-slate-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>
                        <p class="text-slate-500 text-sm">No items found.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wider">
                                    <th class="px-6 py-3 text-left">Item</th>
                                    <th class="px-6 py-3 text-left">Category</th>
                                    <th class="px-6 py-3 text-left">Price</th>
                                    <th class="px-6 py-3 text-left">Stock</th>
                                    <th class="px-6 py-3 text-left">Status</th>
                                    <?php if ($is_admin): ?><th class="px-6 py-3 text-center">Actions</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($item = $items->fetch_assoc()): ?>
                                <tr class="item-row border-b border-slate-800/60">
                                    <td class="px-6 py-3.5">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-xl bg-slate-800 border border-slate-700 overflow-hidden shrink-0 flex items-center justify-center">
                                                <?php if ($item['image_path'] && file_exists($item['image_path'])): ?>
                                                    <img src="<?= $item['image_path'] ?>" class="w-full h-full object-cover">
                                                <?php else: ?>
                                                    <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                <?php endif; ?>
                                            </div>
                                            <span class="font-medium text-white"><?= htmlspecialchars($item['item_name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3.5">
                                        <span class="px-2.5 py-1 rounded-lg text-xs font-medium bg-blue-500/10 text-blue-300 border border-blue-500/20">
                                            <?= htmlspecialchars($item['category_name']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3.5">
                                        <span class="mono text-emerald-400 font-semibold">₱<?= number_format($item['price'], 2) ?></span>
                                    </td>
                                    <td class="px-6 py-3.5">
                                        <span class="mono text-sm font-semibold <?= $item['stock'] == 0 ? 'text-red-400' : ($item['stock'] <= 5 ? 'text-amber-400' : 'text-slate-300') ?>">
                                            <?= $item['stock'] ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3.5 whitespace-nowrap">
                                        <?php if ($item['stock'] == 0): ?>
                                            <span class="inline-flex px-2.5 py-1 rounded-lg text-xs font-semibold whitespace-nowrap bg-red-500/15 text-red-300 border border-red-500/25">Out of Stock</span>
                                        <?php elseif ($item['stock'] <= 5): ?>
                                            <span class="inline-flex px-2.5 py-1 rounded-lg text-xs font-semibold whitespace-nowrap bg-amber-500/15 text-amber-300 border border-amber-500/25">Low Stock</span>
                                        <?php elseif (!$item['is_active']): ?>
                                            <span class="inline-flex px-2.5 py-1 rounded-lg text-xs font-semibold whitespace-nowrap bg-slate-500/15 text-slate-400 border border-slate-500/25">Inactive</span>
                                        <?php else: ?>
                                            <span class="inline-flex px-2.5 py-1 rounded-lg text-xs font-semibold whitespace-nowrap bg-emerald-500/15 text-emerald-300 border border-emerald-500/25">In Stock</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($is_admin): ?>
                                    <td class="px-6 py-3.5 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="?edit=<?= $item['item_id'] ?>"
                                                class="px-3 py-1.5 bg-blue-600/15 hover:bg-blue-600/30 text-blue-300 rounded-lg text-xs font-medium transition border border-blue-500/20">
                                                Edit
                                            </a>
                                            <a href="?delete=<?= $item['item_id'] ?>"
                                                class="px-3 py-1.5 bg-red-600/15 hover:bg-red-600/30 text-red-300 rounded-lg text-xs font-medium transition border border-red-500/20"
                                                onclick="return confirm('Delete this item? This cannot be undone.')">
                                                Delete
                                            </a>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

            </div>

        </div>
    </div>
</div>

<script>
// ── Live clock ────────────────────────────────────────────────────────────
function updateClock() {
    var el = document.getElementById('liveTime');
    if (el) el.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
updateClock(); setInterval(updateClock, 1000);

// ── Sidebar toggle ────────────────────────────────────────────────────────
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

// ── Spec field config ─────────────────────────────────────────────────────
const specFieldConfig = {
    product_number:  { label: 'Product Number',   type: 'text',     always: true,  value: <?= json_encode($edit_values['product_number']  ?? '') ?> },
    microprocessor:  { label: 'Microprocessor',   type: 'text',     always: false, value: <?= json_encode($edit_values['microprocessor']  ?? '') ?> },
    chipset:         { label: 'Chipset',           type: 'text',     always: false, value: <?= json_encode($edit_values['chipset']         ?? '') ?> },
    memory_standard: { label: 'Memory',           type: 'text',     always: false, value: <?= json_encode($edit_values['memory_standard'] ?? '') ?> },
    video_graphics:  { label: 'Video Graphics',   type: 'text',     always: false, value: <?= json_encode($edit_values['video_graphics']  ?? '') ?> },
    hard_drive:      { label: 'Hard Drive',        type: 'text',     always: false, value: <?= json_encode($edit_values['hard_drive']      ?? '') ?> },
    display:         { label: 'Display',           type: 'text',     always: false, value: <?= json_encode($edit_values['display']         ?? '') ?> },
    battery:         { label: 'Battery',           type: 'text',     always: false, value: <?= json_encode($edit_values['battery']         ?? '') ?> },
    operating_system:{ label: 'Operating System', type: 'text',     always: false, value: <?= json_encode($edit_values['operating_system']?? '') ?> },
    connectivity:    { label: 'Connectivity',      type: 'text',     always: false, value: <?= json_encode($edit_values['connectivity']    ?? '') ?> },
    dimensions:      { label: 'Dimensions',        type: 'text',     always: false, value: <?= json_encode($edit_values['dimensions']      ?? '') ?> },
    warranty:        { label: 'Warranty',          type: 'text',     always: false, value: <?= json_encode($edit_values['warranty']        ?? '') ?> },
    interface:       { label: 'Interface',         type: 'text',     always: false, value: <?= json_encode($edit_values['interface']       ?? '') ?> },
    dpi_resolution:  { label: 'DPI Resolution',   type: 'text',     always: false, value: <?= json_encode($edit_values['dpi_resolution']  ?? '') ?> },
    compatibility:   { label: 'Compatibility',    type: 'text',     always: false, value: <?= json_encode($edit_values['compatibility']   ?? '') ?> },
    cable_length:    { label: 'Cable Length',      type: 'text',     always: false, value: <?= json_encode($edit_values['cable_length']    ?? '') ?> },
    print_technology:{ label: 'Print Technology', type: 'text',     always: false, value: <?= json_encode($edit_values['print_technology']?? '') ?> },
    print_speed:     { label: 'Print Speed',       type: 'text',     always: false, value: <?= json_encode($edit_values['print_speed']     ?? '') ?> },
    paper_size:      { label: 'Paper Size',        type: 'text',     always: false, value: <?= json_encode($edit_values['paper_size']      ?? '') ?> },
    ink_type:        { label: 'Ink Type',          type: 'text',     always: false, value: <?= json_encode($edit_values['ink_type']        ?? '') ?> },
    page_yield:      { label: 'Page Yield',        type: 'text',     always: false, value: <?= json_encode($edit_values['page_yield']      ?? '') ?> },
    duty_cycle:      { label: 'Duty Cycle',        type: 'text',     always: false, value: <?= json_encode($edit_values['duty_cycle']      ?? '') ?> },
    license_type:    { label: 'License Type',      type: 'text',     always: false, value: <?= json_encode($edit_values['license_type']    ?? '') ?> },
    license_duration:{ label: 'License Duration', type: 'text',     always: false, value: <?= json_encode($edit_values['license_duration']?? '') ?> },
    min_requirements:{ label: 'Min Requirements', type: 'text',     always: false, value: <?= json_encode($edit_values['min_requirements']?? '') ?> },
    supported_os:    { label: 'Supported OS',      type: 'text',     always: false, value: <?= json_encode($edit_values['supported_os']    ?? '') ?> },
    users_allowed:   { label: 'Users Allowed',    type: 'text',     always: false, value: <?= json_encode($edit_values['users_allowed']   ?? '') ?> },
    model_number:    { label: 'Model Number',      type: 'text',     always: false, value: <?= json_encode($edit_values['model_number']    ?? '') ?> },
    manufacturer:    { label: 'Manufacturer',      type: 'text',     always: false, value: <?= json_encode($edit_values['manufacturer']    ?? '') ?> },
    color:           { label: 'Color',             type: 'text',     always: false, value: <?= json_encode($edit_values['color']           ?? '') ?> },
    details:         { label: 'Details / Notes',   type: 'textarea', always: true,  value: <?= json_encode($edit_values['details']         ?? '') ?> },
};

const categorySelect = document.getElementById('category_id');
const specsBody      = document.getElementById('specsBody');
const specsChevron   = document.getElementById('specsChevron');
const specsCount     = document.getElementById('specsCount');
const toggleBtn      = document.getElementById('toggleSpecs');

function updateSpecFields() {
    if (!categorySelect) return;
    const opt = categorySelect.options[categorySelect.selectedIndex];
    let allowed = [];
    if (opt && opt.value) { try { allowed = JSON.parse(opt.dataset.spec || '[]'); } catch(e) { allowed = []; } }
    if (!allowed.includes('product_number')) allowed.push('product_number');
    if (!allowed.includes('details'))        allowed.push('details');

    let html = ''; let count = 0;
    const baseClass = 'w-full px-2.5 py-1.5 bg-slate-900 border border-slate-700 rounded-lg text-white text-xs focus:outline-none focus:border-blue-500 transition';
    const esc = v => String(v || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    for (const [k, f] of Object.entries(specFieldConfig)) {
        if (!f.always && !allowed.includes(k)) continue;
        count++;
        if (f.type === 'textarea') {
            html += `<div><label class="block text-xs text-slate-400 mb-1">${f.label}</label><textarea name="${k}" rows="2" class="${baseClass}">${esc(f.value)}</textarea></div>`;
        } else {
            html += `<div><label class="block text-xs text-slate-400 mb-1">${f.label}</label><input type="text" name="${k}" value="${esc(f.value)}" class="${baseClass}"></div>`;
        }
    }
    specsBody.innerHTML = html;
    if (specsCount) specsCount.textContent = count;
}

let specsOpen = <?= $edit_item ? 'true' : 'false' ?>;
function setSpecsOpen(open) {
    specsOpen = open;
    if (open) { specsBody.classList.remove('closed'); specsBody.classList.add('open'); specsChevron.classList.add('rotated'); }
    else       { specsBody.classList.remove('open'); specsBody.classList.add('closed'); specsChevron.classList.remove('rotated'); }
}
if (toggleBtn) toggleBtn.addEventListener('click', () => setSpecsOpen(!specsOpen));
if (categorySelect) { categorySelect.addEventListener('change', updateSpecFields); updateSpecFields(); }
setSpecsOpen(specsOpen);

// ── Auto-dismiss alerts ────────────────────────────────────────────────────
setTimeout(function() {
    var el = document.getElementById('successMsg');
    if (!el) return;
    el.style.transition = 'opacity 0.5s ease';
    el.style.opacity = '0';
    setTimeout(function() { if (el && el.parentNode) el.parentNode.removeChild(el); }, 500);
}, 5000);
</script>
</body>
</html>