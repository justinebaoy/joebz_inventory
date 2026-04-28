<?php
session_start();
require_once 'config/db.php';

// Protect page - must be logged in and must be admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$success = '';
$error   = '';

// ── CREATE USER ────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $username   = trim($_POST['username']);
    $email      = trim($_POST['email']);
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $role       = $_POST['role'];
    $password   = $_POST['password'];
    $confirm    = $_POST['confirm_password'];

    if (empty($username) || empty($email) || empty($first_name) ||
        empty($last_name) || empty($password)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $check_user = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $check_user->bind_param("s", $username);
        $check_user->execute();
        $check_user->store_result();

        if ($check_user->num_rows > 0) {
            $error = "This username has already been taken.";
        } else {
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_email->bind_param("s", $email);
            $check_email->execute();
            $check_email->store_result();

            if ($check_email->num_rows > 0) {
                $error = "This email has already been taken.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssss", $username, $email, $hash, $role, $first_name, $last_name);
                if ($stmt->execute()) {
                    $success = "User '{$first_name} {$last_name}' created successfully!";
                } else {
                    $error = "Failed to create user. Please try again.";
                }
                $stmt->close();
            }
            $check_email->close();
        }
        $check_user->close();
    }
}

// ── DELETE USER ────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $delete_id = (int)$_POST['user_id'];

    if ($delete_id === (int)$_SESSION['user_id']) {
        $error = "You cannot delete your own account.";
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
            $success = "User deleted successfully.";
        } else {
            $error = "Failed to delete user.";
        }
        $stmt->close();
    }
}

// ── EDIT USER ──────────────────────────────────────────
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
        if (!empty($new_pass)) {
            if (strlen($new_pass) < 6) {
                $error = "New password must be at least 6 characters.";
            } else {
                $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, role=?, password_hash=? WHERE user_id=?");
                $stmt->bind_param("sssssi", $first_name, $last_name, $email, $role, $hash, $edit_id);
            }
        } else {
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, role=? WHERE user_id=?");
            $stmt->bind_param("ssssi", $first_name, $last_name, $email, $role, $edit_id);
        }

        if (empty($error)) {
            if ($stmt->execute()) {
                $success = "User updated successfully.";
            } else {
                $error = "Failed to update user.";
            }
            $stmt->close();
        }
    }
}

// ── SEARCH ─────────────────────────────────────────────
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare("SELECT user_id, username, email, first_name, last_name, role, created_at FROM users WHERE first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR email LIKE ? ORDER BY created_at DESC");
    $stmt->bind_param("ssss", $like, $like, $like, $like);
    $stmt->execute();
    $users = $stmt->get_result();
    $stmt->close();
} else {
    $users = $conn->query("SELECT user_id, username, email, first_name, last_name, role, created_at FROM users ORDER BY created_at DESC");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users — JOEBZ Inventory</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        .alert-anim { animation: slideDown 0.3s ease; }
        .stat-card { transition: all 0.2s ease; }
        .stat-card:hover { transform: translateY(-2px); }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

<div class="flex min-h-screen">
    <!-- SIDEBAR - MATCHES OTHER PAGES -->
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
            <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                Reports
            </a>
            <a href="users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-blue-600/20 text-blue-200 font-medium text-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                Users
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
            <h1 class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">User Management</h1>
            <p class="text-sm text-slate-400 mt-1">Create and manage system accounts</p>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="stat-card bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm text-slate-400 font-medium">Total Users</p>
                    <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    </div>
                </div>
                <p class="text-2xl font-bold text-slate-100"><?= $users->num_rows ?></p>
                <p class="text-xs text-slate-400 mt-1">Registered accounts</p>
            </div>
            <div class="stat-card bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm text-slate-400 font-medium">Admin Accounts</p>
                    <div class="w-9 h-9 bg-purple-600 rounded-xl flex items-center justify-center text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                </div>
                <?php 
                $admin_count = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'admin'")->fetch_assoc()['total'];
                ?>
                <p class="text-2xl font-bold text-slate-100"><?= $admin_count ?></p>
                <p class="text-xs text-slate-400 mt-1">Administrators</p>
            </div>
            <div class="stat-card bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm text-slate-400 font-medium">Cashiers</p>
                    <div class="w-9 h-9 bg-emerald-600 rounded-xl flex items-center justify-center text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                </div>
                <?php 
                $cashier_count = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'cashier'")->fetch_assoc()['total'];
                ?>
                <p class="text-2xl font-bold text-slate-100"><?= $cashier_count ?></p>
                <p class="text-xs text-slate-400 mt-1">Cashier staff</p>
            </div>
        </div>

        <!-- Search and Add Button -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-5">
            <form method="GET" action="" class="flex-1 max-w-md">
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>
                    <input type="text" name="search" placeholder="Search users by name, username, or email..." value="<?= htmlspecialchars($search) ?>" class="w-full pl-10 pr-10 py-3 border border-slate-700 rounded-xl bg-slate-900/95 text-slate-100 placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                    <button type="submit" class="absolute inset-y-0 right-10 pr-3 flex items-center text-slate-400 hover:text-blue-400 transition">🔍</button>
                    <?php if ($search): ?>
                        <a href="users.php" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-100">✖</a>
                    <?php endif; ?>
                </div>
            </form>
            <button type="button" onclick="openModal('createModal')" class="flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-5 py-2.5 rounded-xl transition shadow-lg shadow-blue-900/30">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add New User
            </button>
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

        <!-- Users Table -->
        <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-800">
                <h2 class="text-sm font-semibold text-slate-100">All Accounts</h2>
                <p class="text-xs text-slate-500 mt-0.5">Manage user roles and permissions</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-400 uppercase tracking-wide border-b border-slate-800 bg-slate-800/30">
                            <th class="px-6 py-3 text-left">Name</th>
                            <th class="px-6 py-3 text-left">Username</th>
                            <th class="px-6 py-3 text-left">Email</th>
                            <th class="px-6 py-3 text-left">Role</th>
                            <th class="px-6 py-3 text-left">Created</th>
                            <th class="px-6 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php if ($users->num_rows === 0): ?>
                            <tr><td colspan="6" class="px-6 py-12 text-center text-slate-500">No users found.<?= $search ? ' Try a different search.' : '' ?></td></tr>
                        <?php else: ?>
                            <?php while ($u = $users->fetch_assoc()): ?>
                                <tr class="hover:bg-white/5 transition">
                                    <td class="px-6 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-gradient-to-r from-blue-500 to-indigo-600 flex items-center justify-center text-white text-xs font-bold shadow-lg flex-shrink-0">
                                                <?= strtoupper(substr($u['first_name'], 0, 1)) ?>
                                            </div>
                                            <span class="font-medium text-slate-100"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3 text-slate-300 font-mono text-xs"><?= htmlspecialchars($u['username']) ?></td>
                                    <td class="px-6 py-3 text-slate-300"><?= htmlspecialchars($u['email']) ?></td>
                                    <td class="px-6 py-3">
                                        <span class="px-2.5 py-1 rounded-lg text-xs font-semibold <?= $u['role'] === 'admin' ? 'bg-purple-600/30 text-purple-300 border border-purple-500/30' : 'bg-emerald-600/30 text-emerald-300 border border-emerald-500/30' ?>">
                                            <?= ucfirst($u['role']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-slate-400 text-xs"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                                    <td class="px-6 py-3 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <button type="button" onclick="openEdit(<?= json_encode((int)$u['user_id']) ?>, <?= htmlspecialchars(json_encode($u['first_name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($u['last_name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($u['email']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($u['role']), ENT_QUOTES, 'UTF-8') ?>)" class="p-1.5 rounded-lg text-blue-400 hover:text-blue-300 hover:bg-blue-500/20 transition" aria-label="Edit">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                            </button>
                                            <?php if ($u['user_id'] !== (int)$_SESSION['user_id']): ?>
                                                <button type="button" onclick="confirmDelete(<?= json_encode((int)$u['user_id']) ?>, <?= htmlspecialchars(json_encode($u['first_name'] . ' ' . $u['last_name']), ENT_QUOTES, 'UTF-8') ?>)" class="p-1.5 rounded-lg text-red-400 hover:text-red-300 hover:bg-red-500/20 transition" aria-label="Delete">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            <?php endif; ?>
                                        </div>
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

<!-- CREATE USER MODAL -->
<div id="createModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden" role="dialog" aria-modal="true">
    <div class="bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-md mx-4 p-6">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-semibold text-slate-100">Create New User</h3>
            <button type="button" onclick="closeModal('createModal')" class="text-slate-400 hover:text-slate-100 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">First Name *</label>
                    <input type="text" name="first_name" required class="w-full border border-slate-700 bg-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Last Name *</label>
                    <input type="text" name="last_name" required class="w-full border border-slate-700 bg-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-medium text-slate-300 mb-1">Username *</label>
                <input type="text" name="username" required class="w-full border border-slate-700 bg-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="mb-4">
                <label class="block text-xs font-medium text-slate-300 mb-1">Email *</label>
                <input type="email" name="email" required class="w-full border border-slate-700 bg-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="mb-4">
                <label class="block text-xs font-medium text-slate-300 mb-1">Role *</label>
                <select name="role" class="w-full border border-slate-700 bg-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="cashier">Cashier</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-medium text-slate-300 mb-1">Password *</label>
                <input type="password" name="password" required minlength="6" class="w-full border border-slate-700 bg-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                <p class="text-xs text-slate-500 mt-1">Minimum 6 characters</p>
            </div>
            <div class="mb-5">
                <label class="block text-xs font-medium text-slate-300 mb-1">Confirm Password *</label>
                <input type="password" name="confirm_password" required class="w-full border border-slate-700 bg-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('createModal')" class="flex-1 border border-slate-700 text-slate-300 rounded-xl py-2.5 text-sm hover:bg-slate-800 transition">Cancel</button>
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-2.5 text-sm font-medium transition">Create Account</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT USER MODAL -->
<div id="editModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden" role="dialog" aria-modal="true">
    <div class="bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-md mx-4 p-6">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-semibold text-slate-100">Edit User</h3>
            <button type="button" onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-100 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">First Name</label>
                    <input type="text" name="first_name" id="edit_first_name" required class="w-full border border-slate-700 bg-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Last Name</label>
                    <input type="text" name="last_name" id="edit_last_name" required class="w-full border border-slate-700 bg-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-medium text-slate-300 mb-1">Email</label>
                <input type="email" name="email" id="edit_email" required class="w-full border border-slate-700 bg-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="mb-4">
                <label class="block text-xs font-medium text-slate-300 mb-1">Role</label>
                <select name="role" id="edit_role" class="w-full border border-slate-700 bg-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="cashier">Cashier</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="mb-5">
                <label class="block text-xs font-medium text-slate-300 mb-1">New Password <span class="text-slate-500 font-normal">(leave blank to keep current)</span></label>
                <input type="password" name="new_password" minlength="6" class="w-full border border-slate-700 bg-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('editModal')" class="flex-1 border border-slate-700 text-slate-300 rounded-xl py-2.5 text-sm hover:bg-slate-800 transition">Cancel</button>
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-2.5 text-sm font-medium transition">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden" role="dialog" aria-modal="true">
    <div class="bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
        <div class="w-14 h-14 bg-red-900/40 border border-red-700 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-7 h-7 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
        </div>
        <h3 class="text-lg font-semibold text-slate-100 mb-2">Delete User?</h3>
        <p class="text-sm text-slate-400 mb-6">You are about to delete <strong id="delete_name" class="text-slate-100"></strong>. This action cannot be undone.</p>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="delete_user_id">
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('deleteModal')" class="flex-1 border border-slate-700 text-slate-300 rounded-xl py-2.5 text-sm hover:bg-slate-800 transition">Cancel</button>
                <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white rounded-xl py-2.5 text-sm font-medium transition">Yes, Delete</button>
            </div>
        </form>
    </div>
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

const modalIds = ['createModal', 'editModal', 'deleteModal'];

function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('hidden');
    const focusable = modal.querySelector('input, button, textarea, select');
    if (focusable) focusable.focus();
}
function closeModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('hidden');
}
function openEdit(id, first, last, email, role) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_first_name').value = first;
    document.getElementById('edit_last_name').value = last;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_role').value = role;
    openModal('editModal');
}
function confirmDelete(id, name) {
    document.getElementById('delete_user_id').value = id;
    document.getElementById('delete_name').textContent = name;
    openModal('deleteModal');
}
window.addEventListener('click', function(e) {
    modalIds.forEach(id => {
        const modal = document.getElementById(id);
        if (e.target === modal) closeModal(id);
    });
});
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') modalIds.forEach(closeModal);
});

const searchInput = document.querySelector('input[name="search"]');
if (searchInput) {
    let timer;
    searchInput.addEventListener('input', function() {
        clearTimeout(timer);
        timer = setTimeout(() => this.closest('form').submit(), 400);
    });
}

document.querySelectorAll('.alert-anim').forEach(el => {
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 500); }, 5000);
});
</script>

</body>
</html>