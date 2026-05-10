<?php
date_default_timezone_set('Asia/Manila');
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: sales.php");
    exit;
}

$success = '';
$error   = '';

// ── Create User ───────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $username   = trim($_POST['username']);
    $email      = trim($_POST['email']);
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $role       = 'staff';
    $password   = $_POST['password'];
    $confirm    = $_POST['confirm_password'];

    if (empty($username) || empty($email) || empty($first_name) || empty($last_name) || empty($password)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $error = "Username or email already exists.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $username, $email, $hash, $role, $first_name, $last_name);
            if ($stmt->execute()) {
                $success = "User '{$first_name} {$last_name}' created successfully.";
            } else {
                $error = "Failed to create user.";
            }
            $stmt->close();
        }
        $check->close();
    }
}

// ── Edit User ─────────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $edit_id    = (int)$_POST['user_id'];
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $email      = trim($_POST['email']);
    $role       = $_POST['role'];
    $new_pass   = $_POST['new_password'];

    if (empty($first_name) || empty($last_name) || empty($email)) {
        $error = "Name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } else {
        $stmt = null;
        if (!empty($new_pass)) {
            if (strlen($new_pass) < 6) {
                $error = "Password must be at least 6 characters.";
            } else {
                $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, role=?, password_hash=? WHERE user_id=?");
                $stmt->bind_param("sssssi", $first_name, $last_name, $email, $role, $hash, $edit_id);
            }
        } else {
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, role=? WHERE user_id=?");
            $stmt->bind_param("ssssi", $first_name, $last_name, $email, $role, $edit_id);
        }
        if ($stmt !== null) {
            if ($stmt->execute()) { $success = "User updated successfully."; }
            else { $error = "Failed to update user."; }
            $stmt->close();
        }
    }
}

// ── Delete User ───────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $delete_id = (int)$_POST['user_id'];
    if ($delete_id === (int)$_SESSION['user_id']) {
        $error = "You cannot delete your own account.";
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) { $success = "User deleted successfully."; }
        else { $error = "Failed to delete user."; }
        $stmt->close();
    }
}

$total_users_all = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
$admin_count     = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'admin'")->fetch_assoc()['c'];
$staff_count     = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'staff'")->fetch_assoc()['c'];

$result       = $conn->query("SELECT user_id, username, email, first_name, last_name, role, created_at FROM users ORDER BY created_at DESC");
$users_rows   = $result->fetch_all(MYSQLI_ASSOC);
$result_count = count($users_rows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users — JOEBZ POS</title>
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

        /* Staggered card reveal */
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
        .reveal-7 { animation-delay: 0.38s; }

        /* Stat cards */
        .stat-card {
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.35);
        }

        /* Pulse dot */
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

        /* Input focus glow */
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

        /* Modal animation */
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-panel { animation: modalIn 0.25s ease both; }

        /* Table row hover */
        .user-row { transition: background 0.15s ease; }
        .user-row:hover { background: rgba(255,255,255,0.03); }

        /* Scrollbar */
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
                <h1 class="text-3xl font-bold text-white tracking-tight">User Management</h1>
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

        <!-- ══ ALERTS ══ -->
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

        <!-- ══ STAT CARDS ══ -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">

            <!-- Total Users — FIXED: removed extra inset-0 overlay div that was covering content -->
            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-2">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-blue-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Total Users</p>
                        <p class="text-3xl font-bold text-white mono"><?= $total_users_all ?></p>
                        <p class="text-xs text-slate-500 mt-1">Registered accounts</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-blue-500/15 border border-blue-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/></svg>
                    </div>
                </div>
            </div>

            <!-- Admins -->
            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-3">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-purple-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Admin Accounts</p>
                        <p class="text-3xl font-bold text-white mono"><?= $admin_count ?></p>
                        <p class="text-xs text-slate-500 mt-1">Administrators</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-purple-500/15 border border-purple-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                </div>
            </div>

            <!-- Staff -->
            <div class="stat-card relative bg-slate-900 rounded-2xl border border-slate-800 p-5 overflow-hidden reveal reveal-3">
                <div class="absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-emerald-500"></div>
                <div class="flex items-start justify-between pl-2">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Staff</p>
                        <p class="text-3xl font-bold text-white mono"><?= $staff_count ?></p>
                        <p class="text-xs text-slate-500 mt-1">Cashier staff</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/15 border border-emerald-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </div>
                </div>
            </div>

        </div>

        <!-- ══ SEARCH & ADD ROW ══ -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5 reveal reveal-4">
            <div class="flex-1 max-w-md">
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" id="searchInput" placeholder="Search name, username, or email…"
                        autocomplete="off"
                        class="input-field pl-9 pr-9 text-sm">
                    <button type="button" id="clearSearch"
                        class="absolute inset-y-0 right-0 pr-3 hidden items-center text-slate-500 hover:text-slate-200 transition"
                        title="Clear">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <p id="searchStatus" class="text-xs text-slate-500 mt-1.5 pl-1 hidden"></p>
            </div>
            <button onclick="openModal('createModal')"
                class="flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold px-5 py-2.5 rounded-xl transition shadow-lg shadow-blue-900/30 whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add New User
            </button>
        </div>

        <!-- ══ USERS TABLE ══ -->
        <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden reveal reveal-5">
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-white" style="font-family:'Syne',sans-serif">All Accounts</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Manage user roles and permissions</p>
                </div>
                <span id="tableCount" class="mono text-xs text-slate-400 bg-slate-800 border border-slate-700 px-2.5 py-1 rounded-xl">
                    <?= $result_count ?> user<?= $result_count !== 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if ($result_count === 0): ?>
            <div class="py-16 text-center">
                <svg class="w-10 h-10 text-slate-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/></svg>
                <p class="text-slate-500 text-sm">No users registered yet.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wider">
                            <th class="px-6 py-3 text-left">Name</th>
                            <th class="px-6 py-3 text-left">Username</th>
                            <th class="px-6 py-3 text-left">Email</th>
                            <th class="px-6 py-3 text-left">Role</th>
                            <th class="px-6 py-3 text-left">Created</th>
                            <th class="px-6 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users_rows as $user):
                            $is_admin   = $user['role'] === 'admin';
                            $role_label = $is_admin ? 'Admin' : 'Staff';
                            $role_class = $is_admin
                                ? 'bg-purple-500/15 text-purple-300 border border-purple-500/25'
                                : 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/25';
                            $search_haystack = strtolower(
                                $user['first_name'] . ' ' . $user['last_name'] . ' ' .
                                $user['username']   . ' ' . $user['email']
                            );
                        ?>
                        <tr class="user-row border-b border-slate-800/60" data-search="<?= htmlspecialchars($search_haystack) ?>">
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                                        <?= strtoupper(substr($user['first_name'], 0, 1)) ?>
                                    </div>
                                    <span class="font-medium text-white"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-3.5">
                                <span class="mono text-xs text-slate-400"><?= htmlspecialchars($user['username']) ?></span>
                            </td>
                            <td class="px-6 py-3.5 text-slate-300"><?= htmlspecialchars($user['email']) ?></td>
                            <td class="px-6 py-3.5">
                                <span class="px-2.5 py-1 rounded-lg text-xs font-semibold <?= $role_class ?>">
                                    <?= $role_label ?>
                                </span>
                            </td>
                            <td class="px-6 py-3.5 mono text-xs text-slate-500"><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                            <td class="px-6 py-3.5 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <button onclick='openEdit(<?= json_encode($user) ?>)'
                                        class="px-3 py-1.5 bg-blue-600/15 hover:bg-blue-600/30 text-blue-300 rounded-lg text-xs font-medium transition border border-blue-500/20">
                                        Edit
                                    </button>
                                    <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                    <button onclick="confirmDelete(<?= (int)$user['user_id'] ?>, '<?= htmlspecialchars(addslashes($user['first_name'] . ' ' . $user['last_name'])) ?>')"
                                        class="px-3 py-1.5 bg-red-600/15 hover:bg-red-600/30 text-red-300 rounded-lg text-xs font-medium transition border border-red-500/20">
                                        Delete
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr id="noResultsRow" class="hidden">
                            <td colspan="6" class="py-12 text-center text-slate-500 text-sm">No users match your search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /max-w -->
</div><!-- /main -->


<!-- ═══════════ CREATE MODAL ═══════════ -->
<div id="createModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="modal-panel bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-md p-6">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-xl font-bold text-white" style="font-family:'Syne',sans-serif">Create New User</h3>
            <button onclick="closeModal('createModal')" class="w-8 h-8 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition text-lg">&times;</button>
        </div>
        <form method="POST" autocomplete="off" class="space-y-4">
            <input type="hidden" name="action" value="create">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">First Name</label>
                    <input type="text" name="first_name" required class="input-field text-sm">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Last Name</label>
                    <input type="text" name="last_name" required class="input-field text-sm">
                </div>
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Username</label>
                <input type="text" name="username" required autocomplete="new-password" class="input-field text-sm mono">
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Email</label>
                <input type="email" name="email" required class="input-field text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Password</label>
                <input type="password" name="password" required minlength="6" autocomplete="new-password" class="input-field text-sm">
                <p class="text-xs text-slate-600 mt-1">Minimum 6 characters</p>
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Confirm Password</label>
                <input type="password" name="confirm_password" required autocomplete="new-password" class="input-field text-sm">
            </div>
            <div class="flex gap-3 pt-1">
                <button type="button" onclick="closeModal('createModal')" class="flex-1 border border-slate-700 hover:bg-slate-800 text-slate-300 py-2.5 rounded-xl text-sm font-medium transition">Cancel</button>
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-500 text-white py-2.5 rounded-xl text-sm font-semibold transition">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════ EDIT MODAL ═══════════ -->
<div id="editModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="modal-panel bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-md p-6">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-xl font-bold text-white" style="font-family:'Syne',sans-serif">Edit User</h3>
            <button onclick="closeModal('editModal')" class="w-8 h-8 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition text-lg">&times;</button>
        </div>
        <form method="POST" id="editForm" autocomplete="off" class="space-y-4">
            <input type="hidden" name="action"  value="edit">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">First Name</label>
                    <input type="text" name="first_name" id="edit_first_name" required class="input-field text-sm">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Last Name</label>
                    <input type="text" name="last_name" id="edit_last_name" required class="input-field text-sm">
                </div>
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Email</label>
                <input type="email" name="email" id="edit_email" required class="input-field text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Role</label>
                <select name="role" id="edit_role" class="input-field text-sm">
                    <option value="staff">Staff</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">New Password <span class="text-slate-600 normal-case">(optional)</span></label>
                <input type="password" name="new_password" id="edit_new_password" autocomplete="new-password" class="input-field text-sm">
                <p class="text-xs text-slate-600 mt-1">Leave blank to keep current password</p>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="button" onclick="closeModal('editModal')" class="flex-1 border border-slate-700 hover:bg-slate-800 text-slate-300 py-2.5 rounded-xl text-sm font-medium transition">Cancel</button>
                <button type="button" onclick="submitEditForm()" class="flex-1 bg-blue-600 hover:bg-blue-500 text-white py-2.5 rounded-xl text-sm font-semibold transition">Save Changes</button>
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
                <h3 class="font-bold text-white text-base" style="font-family:'Syne',sans-serif">Delete User</h3>
                <p class="text-sm text-slate-400 mt-0.5">This action cannot be undone.</p>
            </div>
        </div>
        <div class="bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 mb-5">
            <p class="text-sm text-slate-300">You are about to permanently delete <strong id="delete_name" class="text-white"></strong>.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="action"  value="delete">
            <input type="hidden" name="user_id" id="delete_user_id">
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('deleteModal')" class="flex-1 border border-slate-700 hover:bg-slate-800 text-slate-300 py-2.5 rounded-xl text-sm font-medium transition">Cancel</button>
                <button type="submit" class="flex-1 bg-red-600 hover:bg-red-500 text-white py-2.5 rounded-xl text-sm font-semibold transition">Delete</button>
            </div>
        </form>
    </div>
</div>


<script>
// ── Sidebar toggle ────────────────────────────────────────────────────────
var sidebar = document.getElementById('sidebar');
var openBtn = document.getElementById('open-sidebar');
function openSidebar()  { sidebar.classList.remove('-translate-x-full'); }
function closeSidebar() { sidebar.classList.add('-translate-x-full'); }
if (openBtn) openBtn.addEventListener('click', openSidebar);
document.addEventListener('click', function (e) {
    if (window.innerWidth < 768 && sidebar && openBtn &&
        !sidebar.contains(e.target) && !openBtn.contains(e.target)) closeSidebar();
});
window.addEventListener('resize', function () {
    if (!sidebar) return;
    if (window.innerWidth >= 768) sidebar.classList.remove('-translate-x-full');
    else sidebar.classList.add('-translate-x-full');
});

// ── Live clock ────────────────────────────────────────────────────────────
function updateClock() {
    var el = document.getElementById('liveTime');
    if (el) el.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
updateClock();
setInterval(updateClock, 1000);

// ── Modals ─────────────────────────────────────────────────────────────────
function openModal(id) {
    var el = document.getElementById(id);
    if (el) { el.classList.remove('hidden'); el.classList.add('flex'); }
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) { el.classList.add('hidden'); el.classList.remove('flex'); }
}

['createModal', 'editModal', 'deleteModal'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', function (e) { if (e.target === el) closeModal(id); });
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') ['createModal', 'editModal', 'deleteModal'].forEach(closeModal);
});

// ── Edit modal ─────────────────────────────────────────────────────────────
function openEdit(user) {
    document.getElementById('edit_user_id').value      = user.user_id;
    document.getElementById('edit_first_name').value   = user.first_name;
    document.getElementById('edit_last_name').value    = user.last_name;
    document.getElementById('edit_email').value        = user.email;
    document.getElementById('edit_role').value         = user.role;
    document.getElementById('edit_new_password').value = '';
    openModal('editModal');
}

function submitEditForm() {
    var pass = document.getElementById('edit_new_password').value;
    if (pass.length > 0 && pass.length < 6) {
        alert('New password must be at least 6 characters, or leave it blank to keep the current password.');
        document.getElementById('edit_new_password').focus();
        return;
    }
    document.getElementById('editForm').submit();
}

// ── Delete modal ───────────────────────────────────────────────────────────
function confirmDelete(id, name) {
    document.getElementById('delete_user_id').value    = id;
    document.getElementById('delete_name').textContent = name;
    openModal('deleteModal');
}

// ── Client-side search filter ──────────────────────────────────────────────
var searchInput  = document.getElementById('searchInput');
var clearBtn     = document.getElementById('clearSearch');
var tableCount   = document.getElementById('tableCount');
var searchStatus = document.getElementById('searchStatus');
var noResultsRow = document.getElementById('noResultsRow');
var allRows      = document.querySelectorAll('tbody tr[data-search]');

function runFilter() {
    var q = searchInput.value.trim().toLowerCase();
    var visible = 0;

    allRows.forEach(function (row) {
        var match = q === '' || row.dataset.search.indexOf(q) !== -1;
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });

    if (noResultsRow) noResultsRow.classList.toggle('hidden', visible > 0);

    if (tableCount) tableCount.textContent = visible + ' user' + (visible !== 1 ? 's' : '');

    if (searchStatus) {
        if (q === '') {
            searchStatus.classList.add('hidden');
        } else {
            searchStatus.classList.remove('hidden');
            searchStatus.innerHTML = 'Results for <span class="text-white font-medium">"' +
                searchInput.value.replace(/</g, '&lt;') + '"</span> — ' +
                visible + ' user' + (visible !== 1 ? 's' : '') + ' found';
        }
    }

    if (clearBtn) {
        if (q === '') { clearBtn.classList.add('hidden'); clearBtn.classList.remove('flex'); }
        else          { clearBtn.classList.remove('hidden'); clearBtn.classList.add('flex'); }
    }
}

if (searchInput) searchInput.addEventListener('input', runFilter);
if (clearBtn)    clearBtn.addEventListener('click', function () { searchInput.value = ''; runFilter(); searchInput.focus(); });

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
</script>
</body>
</html>