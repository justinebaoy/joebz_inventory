<?php
date_default_timezone_set('Asia/Manila');
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: sales.php");
    exit;
}

$spec_groups = [
    'LAPTOP / DESKTOP / COMPONENTS' => [
        'microprocessor'   => 'Microprocessor / CPU',
        'chipset'          => 'Chipset / Motherboard',
        'memory_standard'  => 'Memory / RAM',
        'video_graphics'   => 'Video Graphics / GPU',
        'hard_drive'       => 'Hard Drive / Storage',
        'display'          => 'Display / Screen',
        'battery'          => 'Battery / Power Supply',
        'operating_system' => 'Operating System',
        'connectivity'     => 'Connectivity (WiFi, Bluetooth, Ports)',
        'dimensions'       => 'Dimensions / Weight',
        'warranty'         => 'Warranty Period',
    ],
    'PERIPHERALS' => [
        'interface'       => 'Interface (USB, Wireless, PS/2)',
        'dpi_resolution'  => 'DPI / Resolution',
        'compatibility'   => 'Compatibility (OS / Devices)',
        'cable_length'    => 'Cable Length',
    ],
    'PRINTER / INKS' => [
        'print_technology' => 'Print Technology (Inkjet, Laser, etc.)',
        'print_speed'      => 'Print Speed (PPM)',
        'paper_size'       => 'Supported Paper Sizes',
        'ink_type'         => 'Ink / Toner Type',
        'page_yield'       => 'Page Yield',
        'duty_cycle'       => 'Monthly Duty Cycle',
    ],
    'SOFTWARE' => [
        'license_type'     => 'License Type (OEM, Retail, Subscription)',
        'license_duration' => 'License Duration',
        'min_requirements' => 'Minimum System Requirements',
        'supported_os'     => 'Supported Operating Systems',
        'users_allowed'    => 'Number of Users / Devices',
    ],
    'GENERAL' => [
        'model_number'   => 'Model Number',
        'manufacturer'   => 'Manufacturer / Brand',
        'color'          => 'Color / Finish',
        'product_number' => 'Product Number',
    ]
];

$all_spec_fields = [];
foreach ($spec_groups as $group => $fields) {
    $all_spec_fields = array_merge($all_spec_fields, $fields);
}

$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['category_name']);
        $selected_keys = $_POST['spec_fields'] ?? [];
        $selected_keys = array_values(array_unique(array_intersect($selected_keys, array_keys($all_spec_fields))));
        $spec_json = json_encode($selected_keys);
        if (!empty($name)) {
            $stmt = $conn->prepare("INSERT INTO categories (category_name, spec_fields) VALUES (?, ?)");
            $stmt->bind_param("ss", $name, $spec_json);
            $stmt->execute();
            $success_msg = 'Category "' . htmlspecialchars($name) . '" created successfully.';
        } else {
            $error_msg = 'Category name cannot be empty.';
        }
    }

    if ($action === 'edit') {
        $id   = (int)$_POST['category_id'];
        $name = trim($_POST['category_name']);
        $selected_keys = $_POST['spec_fields'] ?? [];
        $selected_keys = array_values(array_unique(array_intersect($selected_keys, array_keys($all_spec_fields))));
        $spec_json = json_encode($selected_keys);
        if (!empty($name)) {
            $stmt = $conn->prepare("UPDATE categories SET category_name = ?, spec_fields = ? WHERE category_id = ?");
            $stmt->bind_param("ssi", $name, $spec_json, $id);
            $stmt->execute();
            $success_msg = 'Category updated successfully.';
        } else {
            $error_msg = 'Category name cannot be empty.';
        }
    }

    if ($action === 'delete') {
        $id    = (int)$_POST['category_id'];
        $check = $conn->query("SELECT COUNT(*) as cnt FROM items WHERE category_id = $id")->fetch_assoc();
        if ($check['cnt'] > 0) {
            $error_msg = 'Cannot delete: this category still has ' . $check['cnt'] . ' item(s) assigned to it.';
        } else {
            $conn->query("DELETE FROM categories WHERE category_id = $id");
            $success_msg = 'Category deleted successfully.';
        }
    }
}

$search = trim($_GET['search'] ?? '');
$search_sql = $search ? "WHERE category_name LIKE '%" . $conn->real_escape_string($search) . "%'" : '';
$categories_result = $conn->query("SELECT * FROM categories $search_sql ORDER BY created_at DESC");
$all_count  = $conn->query("SELECT COUNT(*) as c FROM categories")->fetch_assoc()['c'];
$cats_data  = [];
while ($row = $categories_result->fetch_assoc()) $cats_data[] = $row;

$total_items_count = $conn->query("SELECT COUNT(*) as c FROM items WHERE is_active=1")->fetch_assoc()['c'];
$cats_with_items   = $conn->query("SELECT COUNT(DISTINCT category_id) as c FROM items WHERE is_active=1")->fetch_assoc()['c'];

$accents = [
    ['border' => 'bg-blue-500',   'icon_bg' => 'bg-blue-500/15',   'icon_border' => 'border-blue-500/20',   'icon_text' => 'text-blue-400',   'badge' => 'bg-blue-500/10 text-blue-300 border-blue-500/20'],
    ['border' => 'bg-violet-500', 'icon_bg' => 'bg-violet-500/15', 'icon_border' => 'border-violet-500/20', 'icon_text' => 'text-violet-400', 'badge' => 'bg-violet-500/10 text-violet-300 border-violet-500/20'],
    ['border' => 'bg-emerald-500','icon_bg' => 'bg-emerald-500/15','icon_border' => 'border-emerald-500/20','icon_text' => 'text-emerald-400','badge' => 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20'],
    ['border' => 'bg-amber-500',  'icon_bg' => 'bg-amber-500/15',  'icon_border' => 'border-amber-500/20',  'icon_text' => 'text-amber-400',  'badge' => 'bg-amber-500/10 text-amber-300 border-amber-500/20'],
    ['border' => 'bg-rose-500',   'icon_bg' => 'bg-rose-500/15',   'icon_border' => 'border-rose-500/20',   'icon_text' => 'text-rose-400',   'badge' => 'bg-rose-500/10 text-rose-300 border-rose-500/20'],
    ['border' => 'bg-cyan-500',   'icon_bg' => 'bg-cyan-500/15',   'icon_border' => 'border-cyan-500/20',   'icon_text' => 'text-cyan-400',   'badge' => 'bg-cyan-500/10 text-cyan-300 border-cyan-500/20'],
];

function buildSpecFieldsHTML($prefix, $selected, $spec_groups) {
    $groupColors = [
        'LAPTOP / DESKTOP / COMPONENTS' => 'text-blue-400',
        'PERIPHERALS'                   => 'text-violet-400',
        'PRINTER / INKS'                => 'text-emerald-400',
        'SOFTWARE'                      => 'text-amber-400',
        'GENERAL'                       => 'text-slate-400',
    ];
    $html  = '<div class="spec-scroll max-h-60 overflow-y-auto space-y-4 pr-1">';
    foreach ($spec_groups as $groupName => $fields) {
        $color = $groupColors[$groupName] ?? 'text-slate-400';
        $html .= '<div>';
        $html .= '<p class="text-xs font-semibold uppercase tracking-wider ' . $color . ' mb-2">' . htmlspecialchars($groupName) . '</p>';
        $html .= '<div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 pl-1">';
        foreach ($fields as $key => $label) {
            $chk = in_array($key, $selected) ? 'checked' : '';
            $html .= '<label class="flex items-center gap-2 text-sm text-slate-400 hover:text-slate-200 cursor-pointer transition py-0.5">';
            $html .= '<input type="checkbox" name="spec_fields[]" value="' . $key . '" class="spec-checkbox ' . $prefix . '-checkbox" ' . $chk . ' style="accent-color:#3b82f6;width:15px;height:15px;cursor:pointer;">';
            $html .= '<span class="leading-tight text-xs">' . htmlspecialchars($label) . '</span>';
            $html .= '</label>';
        }
        $html .= '</div></div>';
    }
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories — JOEBZ POS</title>
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

        .cat-card { transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease; }
        .cat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(0,0,0,0.35); border-color: rgba(100,116,139,0.5); }

        .spec-scroll::-webkit-scrollbar { width: 4px; }
        .spec-scroll::-webkit-scrollbar-track { background: #1e293b; border-radius: 4px; }
        .spec-scroll::-webkit-scrollbar-thumb { background: #475569; border-radius: 4px; }

        .field-pill {
            display: inline-block; font-size: 0.65rem;
            padding: 2px 7px; border-radius: 999px;
            font-weight: 500; line-height: 1.6; white-space: nowrap;
        }

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
                <?= strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1)) ?>
            </div>
            <div>
                <p class="text-sm font-medium text-slate-100"><?= htmlspecialchars($_SESSION['first_name'] ?? '') ?></p>
                <p class="text-xs text-slate-400 capitalize"><?= $_SESSION['role'] ?? '' ?></p>
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
                <h1 class="text-3xl font-bold text-white tracking-tight">Categories</h1>
                <p class="text-slate-400 mt-1 text-sm">
                    Welcome back, <span class="text-blue-300"><?= htmlspecialchars($_SESSION['first_name'] ?? '') ?></span>
                    &nbsp;·&nbsp; <?= date('l, F j, Y') ?>
                </p>
            </div>
            <div class="flex items-center gap-2 text-xs text-slate-400 bg-slate-900 border border-slate-800 px-3 py-1.5 rounded-full">
                <span class="live-dot"></span>
                <span id="liveTime" class="mono"></span>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($success_msg): ?>
        <div id="successMsg" class="mb-5 p-4 bg-emerald-900/30 border border-emerald-700/50 rounded-2xl text-emerald-200 flex items-center gap-3 reveal reveal-2">
            <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?= $success_msg ?>
        </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
        <div id="errorMsg" class="mb-5 p-4 bg-red-900/30 border border-red-700/50 rounded-2xl text-red-200 flex items-center gap-3 reveal reveal-2">
            <svg class="w-5 h-5 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?= $error_msg ?>
        </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">

            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-2">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-blue-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Total Categories</p>
                        <p class="text-3xl font-bold text-white mono"><?= $all_count ?></p>
                        <p class="text-xs text-slate-500 mt-1">Registered categories</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-blue-500/15 border border-blue-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    </div>
                </div>
            </div>

            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-3">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-emerald-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Active Items</p>
                        <p class="text-3xl font-bold text-white mono"><?= $total_items_count ?></p>
                        <p class="text-xs text-slate-500 mt-1">In inventory</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/15 border border-emerald-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>
                    </div>
                </div>
            </div>

            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-3">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-purple-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Used Categories</p>
                        <p class="text-3xl font-bold text-white mono"><?= $cats_with_items ?></p>
                        <p class="text-xs text-slate-500 mt-1">With active items</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-purple-500/15 border border-purple-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                </div>
            </div>

        </div>

        <!-- Search & Add Row -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5 reveal reveal-4">
            <div class="flex-1 max-w-md">
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" id="searchInput" placeholder="Search categories…"
                        value="<?= htmlspecialchars($search) ?>"
                        class="input-field pl-9 pr-9 text-sm">
                    <?php if ($search): ?>
                    <a href="categories.php" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-500 hover:text-slate-200 transition text-lg leading-none">&times;</a>
                    <?php endif; ?>
                </div>
            </div>
            <button onclick="openModal('createModal')"
                class="flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold px-5 py-2.5 rounded-xl transition shadow-lg shadow-blue-900/30 whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add Category
            </button>
        </div>

        <!-- Categories Table / Grid -->
        <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden reveal reveal-5">
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">All Categories</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Manage product categories and their spec fields</p>
                </div>
                <span class="mono text-xs text-slate-400 bg-slate-800 border border-slate-700 px-2.5 py-1 rounded-xl">
                    <?= count($cats_data) ?> categor<?= count($cats_data) !== 1 ? 'ies' : 'y' ?>
                </span>
            </div>

            <?php if (empty($cats_data)): ?>
            <div class="py-16 text-center">
                <svg class="w-10 h-10 text-slate-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                <p class="text-slate-500 text-sm"><?= $search ? 'No categories match your search.' : 'No categories yet. Click "Add Category" to get started.' ?></p>
            </div>
            <?php else: ?>
            <!-- Table view -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wider">
                            <th class="px-6 py-3 text-left">Category</th>
                            <th class="px-6 py-3 text-left">Spec Fields</th>
                            <th class="px-6 py-3 text-left">Items</th>
                            <th class="px-6 py-3 text-left">Created</th>
                            <th class="px-6 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cats_data as $i => $cat):
                            $spec_fields  = json_decode($cat['spec_fields'] ?? '[]', true);
                            if (!is_array($spec_fields)) $spec_fields = [];
                            $item_count   = $conn->query("SELECT COUNT(*) as cnt FROM items WHERE category_id = " . (int)$cat['category_id'])->fetch_assoc()['cnt'];
                            $active_count = $conn->query("SELECT COUNT(*) as cnt FROM items WHERE category_id = " . (int)$cat['category_id'] . " AND is_active = 1")->fetch_assoc()['cnt'];
                            $accent       = $accents[$i % count($accents)];
                            $selected_labels = [];
                            foreach ($spec_fields as $key) {
                                if (isset($all_spec_fields[$key])) $selected_labels[] = $all_spec_fields[$key];
                            }
                        ?>
                        <tr class="border-b border-slate-800/60 hover:bg-white/[0.02] transition">
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl <?= $accent['icon_bg'] ?> border <?= $accent['icon_border'] ?> flex items-center justify-center shrink-0">
                                        <span class="text-sm font-bold <?= $accent['icon_text'] ?>"><?= strtoupper(substr($cat['category_name'], 0, 1)) ?></span>
                                    </div>
                                    <span class="font-medium text-white"><?= htmlspecialchars($cat['category_name']) ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-3.5">
                                <?php if (empty($selected_labels)): ?>
                                    <span class="text-xs text-slate-600 italic">None</span>
                                <?php else: ?>
                                    <div class="flex flex-wrap gap-1" id="tags-<?= $cat['category_id'] ?>">
                                        <?php foreach (array_slice($selected_labels, 0, 3) as $label): ?>
                                            <span class="field-pill <?= $accent['badge'] ?> border"><?= htmlspecialchars($label) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($selected_labels) > 3): ?>
                                            <span class="field-pill bg-slate-700/50 text-slate-400 border border-slate-600/30">+<?= count($selected_labels) - 3 ?> more</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-2">
                                    <span class="mono text-white font-semibold text-sm"><?= $item_count ?></span>
                                    <span class="text-xs text-slate-500">total</span>
                                    <span class="text-slate-700">·</span>
                                    <span class="mono text-emerald-400 font-semibold text-sm"><?= $active_count ?></span>
                                    <span class="text-xs text-slate-500">active</span>
                                </div>
                            </td>
                            <td class="px-6 py-3.5 mono text-xs text-slate-500"><?= date('M d, Y', strtotime($cat['created_at'])) ?></td>
                            <td class="px-6 py-3.5 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <button onclick='openEditModal(<?= json_encode($cat) ?>)'
                                        class="px-3 py-1.5 bg-blue-600/15 hover:bg-blue-600/30 text-blue-300 rounded-lg text-xs font-medium transition border border-blue-500/20">
                                        Edit
                                    </button>
                                    <button onclick="confirmDelete(<?= $cat['category_id'] ?>, '<?= htmlspecialchars(addslashes($cat['category_name'])) ?>', <?= $item_count ?>)"
                                        class="px-3 py-1.5 bg-red-600/15 hover:bg-red-600/30 text-red-300 rounded-lg text-xs font-medium transition border border-red-500/20">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>


<!-- ═══════════ CREATE MODAL ═══════════ -->
<div id="createModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="modal-panel bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-2xl">
        <div class="flex items-center justify-between px-6 pt-6 pb-4 border-b border-slate-800">
            <div>
                <h3 class="text-xl font-bold text-white" style="font-family:'Syne',sans-serif">Add Category</h3>
                <p class="text-xs text-slate-500 mt-0.5">Choose a name and optional spec fields for this category.</p>
            </div>
            <button onclick="closeModal('createModal')" class="w-8 h-8 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition text-lg">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Category Name <span class="text-red-400">*</span></label>
                    <input type="text" name="category_name" required placeholder="e.g. Laptops, Accessories, Ink..."
                        class="input-field text-sm">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1">Optional Spec Fields</label>
                    <p class="text-xs text-slate-600 mb-3">Always included: Item Name, Price, Stock, Image, Details. Select additional fields below.</p>
                    <?php echo buildSpecFieldsHTML('create', [], $spec_groups); ?>
                </div>
            </div>
            <div class="flex gap-3 px-6 pb-6 pt-4 border-t border-slate-800">
                <button type="button" onclick="closeModal('createModal')" class="flex-1 border border-slate-700 hover:bg-slate-800 text-slate-300 py-2.5 rounded-xl text-sm font-medium transition">Cancel</button>
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 rounded-xl text-sm transition shadow-lg shadow-blue-900/20">Create Category</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════ EDIT MODAL ═══════════ -->
<div id="editModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="modal-panel bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-2xl">
        <div class="flex items-center justify-between px-6 pt-6 pb-4 border-b border-slate-800">
            <div>
                <h3 class="text-xl font-bold text-white" style="font-family:'Syne',sans-serif">Edit Category</h3>
                <p class="text-xs text-slate-500 mt-0.5">Update name and optional fields. Changes apply to all items in this category.</p>
            </div>
            <button onclick="closeModal('editModal')" class="w-8 h-8 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition text-lg">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="category_id" id="edit_category_id">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Category Name <span class="text-red-400">*</span></label>
                    <input type="text" name="category_name" id="edit_category_name" required class="input-field text-sm">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1">Optional Spec Fields</label>
                    <p class="text-xs text-slate-600 mb-3">Always included: Item Name, Price, Stock, Image, Details.</p>
                    <?php echo buildSpecFieldsHTML('edit', [], $spec_groups); ?>
                </div>
            </div>
            <div class="flex gap-3 px-6 pb-6 pt-4 border-t border-slate-800">
                <button type="button" onclick="closeModal('editModal')" class="flex-1 border border-slate-700 hover:bg-slate-800 text-slate-300 py-2.5 rounded-xl text-sm font-medium transition">Cancel</button>
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 rounded-xl text-sm transition shadow-lg shadow-blue-900/20">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════ DELETE MODAL ═══════════ -->
<div id="deleteModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="modal-panel bg-slate-900 border border-red-900/40 rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <div class="flex items-start gap-4 mb-5">
            <div class="w-11 h-11 rounded-xl bg-red-900/30 border border-red-800/40 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </div>
            <div>
                <h3 class="font-bold text-white text-base" style="font-family:'Syne',sans-serif">Delete Category</h3>
                <p class="text-sm text-slate-400 mt-0.5" id="deleteModalMsg">This action cannot be undone.</p>
            </div>
        </div>
        <div class="bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 mb-5">
            <p class="text-sm text-slate-300">You are about to permanently delete <strong id="delete_name" class="text-white"></strong>.</p>
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="category_id" id="delete_category_id">
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('deleteModal')" class="flex-1 border border-slate-700 hover:bg-slate-800 text-slate-300 py-2.5 rounded-xl text-sm font-medium transition">Cancel</button>
                <button type="submit" id="deleteSubmitBtn" class="flex-1 bg-red-600 hover:bg-red-500 text-white py-2.5 rounded-xl text-sm font-semibold transition">Delete</button>
            </div>
        </form>
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

// ── Modals ─────────────────────────────────────────────────────────────────
function openModal(id) {
    var el = document.getElementById(id);
    if (el) { el.classList.remove('hidden'); el.classList.add('flex'); }
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) { el.classList.add('hidden'); el.classList.remove('flex'); }
}
['createModal','editModal','deleteModal'].forEach(function(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', function(e) { if (e.target === el) closeModal(id); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') ['createModal','editModal','deleteModal'].forEach(closeModal);
});

// ── Edit modal ─────────────────────────────────────────────────────────────
function openEditModal(category) {
    document.getElementById('edit_category_id').value   = category.category_id;
    document.getElementById('edit_category_name').value = category.category_name;
    var selected = [];
    try { selected = JSON.parse(category.spec_fields || '[]'); } catch(e) { selected = []; }
    document.querySelectorAll('#editModal .edit-checkbox').forEach(function(cb) {
        cb.checked = selected.includes(cb.value);
    });
    openModal('editModal');
}

// ── Delete modal ───────────────────────────────────────────────────────────
function confirmDelete(id, name, itemCount) {
    document.getElementById('delete_category_id').value = id;
    document.getElementById('delete_name').textContent  = name;
    var msg = itemCount > 0
        ? 'Cannot delete — it has ' + itemCount + ' item(s) assigned. Reassign them first.'
        : 'This action cannot be undone.';
    document.getElementById('deleteModalMsg').textContent = msg;
    var submitBtn = document.getElementById('deleteSubmitBtn');
    if (itemCount > 0) {
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-40', 'cursor-not-allowed');
    } else {
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-40', 'cursor-not-allowed');
    }
    openModal('deleteModal');
}

// ── Search ─────────────────────────────────────────────────────────────────
var searchTimer;
var searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() {
            window.location.href = 'categories.php' + (searchInput.value ? '?search=' + encodeURIComponent(searchInput.value) : '');
        }, 350);
    });
}

// ── Auto-dismiss alerts ────────────────────────────────────────────────────
setTimeout(function() {
    ['successMsg', 'errorMsg'].forEach(function(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.style.transition = 'opacity 0.5s ease';
        el.style.opacity = '0';
        setTimeout(function() { if (el && el.parentNode) el.parentNode.removeChild(el); }, 500);
    });
}, 5000);
</script>
</body>
</html>