<?php
session_start();
require_once 'config/db.php';

// Protect page - must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// ── CSRF TOKEN ─────────────────────────────────────────
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── HELPER: Safe date query ────────────────────────────
function getSalesByDate(mysqli $conn, string $date): float {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE DATE(sale_date) = ?
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($result['total'] ?? 0);
}

// ── STATS ──────────────────────────────────────────────
$today = date('Y-m-d');
$today_sales = getSalesByDate($conn, $today);

// Total sales this month
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) AS total
    FROM sales
    WHERE DATE(sale_date) BETWEEN ? AND ?
");
$stmt->bind_param("ss", $month_start, $month_end);
$stmt->execute();
$month_sales = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

// Total items
$q_items = $conn->query("SELECT COUNT(*) AS total FROM items WHERE is_active = 1");
$total_items = (int)($q_items->fetch_assoc()['total'] ?? 0);

// Low stock items (using configurable threshold)
$low_threshold = 5;
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM items WHERE stock <= ? AND is_active = 1");
$stmt->bind_param("i", $low_threshold);
$stmt->execute();
$low_stock = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

// Total transactions today
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM sales WHERE DATE(sale_date) = ?");
$stmt->bind_param("s", $today);
$stmt->execute();
$today_transactions = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

// Total users (admin only)
$total_users = 0;
if ($_SESSION['role'] === 'admin') {
    $q_users = $conn->query("SELECT COUNT(*) AS total FROM users");
    $total_users = (int)($q_users->fetch_assoc()['total'] ?? 0);
}

// ── SEARCH & RECENT SALES ──────────────────────────────
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare("
        SELECT s.sale_id, s.total_amount, s.sale_date,
               u.first_name, u.last_name
        FROM sales s
        JOIN users u ON s.user_id = u.user_id
        WHERE CAST(s.sale_id AS CHAR) LIKE ?
           OR u.first_name LIKE ?
           OR u.last_name LIKE ?
        ORDER BY s.sale_date DESC
        LIMIT 7
    ");
    $stmt->bind_param('sss', $like, $like, $like);
    $stmt->execute();
    $q_recent = $stmt->get_result();
    $stmt->close();
} else {
    $q_recent = $conn->query("
        SELECT s.sale_id, s.total_amount, s.sale_date,
               u.first_name, u.last_name
        FROM sales s
        JOIN users u ON s.user_id = u.user_id
        ORDER BY s.sale_date DESC
        LIMIT 7
    ");
}

// Low stock items list
$stmt = $conn->prepare("
    SELECT i.item_name, i.stock, c.category_name
    FROM items i
    JOIN categories c ON i.category_id = c.category_id
    WHERE i.stock <= ? AND i.is_active = 1
    ORDER BY i.stock ASC
    LIMIT 7
");
$stmt->bind_param("i", $low_threshold);
$stmt->execute();
$q_lowlist = $stmt->get_result();
$stmt->close();

// Sales last 7 days for chart
$chart_labels = [];
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $label = date('M d', strtotime("-$i days"));
    $chart_labels[] = $label;
    $chart_data[] = getSalesByDate($conn, $date);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — JOEBZ Inventory</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

<!-- ── SIDEBAR ── -->
<div class="flex min-h-screen">
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 transform bg-slate-950 border-r border-slate-800 flex flex-col transition-transform duration-200 ease-out -translate-x-full md:translate-x-0">
    <div class="flex items-center gap-3 px-6 py-5 border-b border-slate-800">
      <div class="w-10 h-10 rounded-xl flex items-center justify-center overflow-hidden flex-shrink-0">
        <?php include 'includes/logo.php'; ?>
      </div>
      <span class="text-lg font-bold text-slate-100 tracking-tight">JOEBZ</span>
    </div>
    <nav class="flex-1 px-4 py-4 space-y-1 overflow-y-auto">
      <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-blue-600/20 text-blue-200 font-medium text-sm transition">
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
      <?php if ($_SESSION['role'] === 'admin'): ?>
      <?php if ($_SESSION['role'] === 'admin'): ?>
<a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
    </svg>
    Reports
</a>
<?php endif; ?>
      <a href="users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        Users
      </a>
      <?php endif; ?>
    </nav>
    <div class="px-4 py-4 border-t border-slate-800">
      <div class="flex items-center gap-3 px-3 py-2 rounded-xl bg-slate-900 mb-2">
        <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold">
          <?= strtoupper(substr(htmlspecialchars($_SESSION['first_name']), 0, 1)) ?>
        </div>
        <div>
          <p class="text-sm font-medium text-slate-100"><?= htmlspecialchars($_SESSION['first_name']) ?></p>
          <p class="text-xs text-slate-400 capitalize"><?= htmlspecialchars($_SESSION['role']) ?></p>
        </div>
      </div>
      <a href="logout.php" class="flex items-center gap-3 px-3 py-2 rounded-xl text-red-300 hover:bg-red-800 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        Logout
      </a>
    </div>
  </aside>

  <!-- ── MAIN CONTENT ── -->
  <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-black/60 md:hidden"></div>
  <main class="flex-1 w-full overflow-y-auto p-4 sm:p-6 md:ml-64 bg-slate-950/40">

    <div class="mb-4 flex items-center justify-between gap-3 md:hidden">
      <button type="button" id="open-sidebar" class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium text-slate-200">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        Menu
      </button>
      <a href="logout.php" class="inline-flex items-center gap-2 rounded-xl border border-red-700/50 bg-red-900/20 px-3 py-2 text-sm text-red-200">Logout</a>
    </div>

    <!-- Header -->
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-slate-100">Dashboard</h1>
      <p class="text-sm text-slate-400 mt-1">
        Welcome back, <?= htmlspecialchars($_SESSION['first_name']) ?>!
        &nbsp;·&nbsp; <?= date('l, F j, Y') ?>
      </p>
    </div>

    <!-- ── STAT CARDS ── -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
      <!-- Today's Sales -->
      <div class="bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl shadow-black/20">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm text-slate-400 font-medium">Today's Sales</p>
          <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </div>
        </div>
        <p class="text-2xl font-bold text-slate-100">₱<?= number_format($today_sales, 2) ?></p>
        <p class="text-xs text-slate-400 mt-1"><?= $today_transactions ?> transaction(s) today</p>
      </div>

      <!-- Monthly Sales -->
      <div class="bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl shadow-black/20">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm text-slate-400 font-medium">Monthly Sales</p>
          <div class="w-9 h-9 bg-blue-700 rounded-xl flex items-center justify-center text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
          </div>
        </div>
        <p class="text-2xl font-bold text-slate-100">₱<?= number_format($month_sales, 2) ?></p>
        <p class="text-xs text-slate-400 mt-1"><?= date('F Y') ?></p>
      </div>

      <!-- Total Items -->
      <div class="bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl shadow-black/20">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm text-slate-400 font-medium">Total Items</p>
          <div class="w-9 h-9 bg-indigo-700 rounded-xl flex items-center justify-center text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>
          </div>
        </div>
        <p class="text-2xl font-bold text-slate-100"><?= $total_items ?></p>
        <p class="text-xs text-slate-400 mt-1">Products in inventory</p>
      </div>

      <!-- Low Stock -->
      <div class="bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl shadow-black/20">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm text-slate-400 font-medium">Low Stock Alert</p>
          <div class="w-9 h-9 bg-red-700 rounded-xl flex items-center justify-center text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
          </div>
        </div>
        <p class="text-2xl font-bold text-slate-100"><?= $low_stock ?></p>
        <p class="text-xs text-red-300 mt-1">Items with stock ≤ <?= $low_threshold ?></p>
      </div>

      <!-- Today's Transactions -->
      <div class="bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl shadow-black/20">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm text-slate-400 font-medium">Transactions Today</p>
          <div class="w-9 h-9 bg-amber-600 rounded-xl flex items-center justify-center text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
          </div>
        </div>
        <p class="text-2xl font-bold text-slate-100"><?= $today_transactions ?></p>
        <p class="text-xs text-slate-400 mt-1">Completed sales</p>
      </div>

      <!-- Total Users (admin only) -->
      <?php if ($_SESSION['role'] === 'admin'): ?>
      <div class="bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl shadow-black/20">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm text-slate-400 font-medium">System Users</p>
          <div class="w-9 h-9 bg-indigo-700 rounded-xl flex items-center justify-center text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
          </div>
        </div>
        <p class="text-2xl font-bold text-slate-100"><?= $total_users ?></p>
        <p class="text-xs text-slate-400 mt-1">Registered accounts</p>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── CHART + LOW STOCK ── -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">
      <!-- Sales Chart -->
      <div class="xl:col-span-2 bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl shadow-black/20">
        <h2 class="text-sm font-semibold text-slate-100 mb-4">Sales — Last 7 Days</h2>
        <canvas id="salesChart" height="100"></canvas>
      </div>

      <!-- Low Stock List -->
      <div class="bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl shadow-black/20">
        <h2 class="text-sm font-semibold text-slate-100 mb-4 flex items-center gap-2">
          <span class="w-2 h-2 bg-red-500 rounded-full inline-block"></span>
          Low Stock Items
        </h2>
        <?php if ($q_lowlist->num_rows === 0): ?>
          <p class="text-sm text-slate-400 text-center py-6">All items are well stocked!</p>
        <?php else: ?>
          <div class="space-y-3">
            <?php while ($row = $q_lowlist->fetch_assoc()): ?>
              <div class="flex items-center justify-between">
                <div>
                  <p class="text-sm font-medium text-slate-100"><?= htmlspecialchars($row['item_name']) ?></p>
                  <p class="text-xs text-slate-400"><?= htmlspecialchars($row['category_name']) ?></p>
                </div>
                <span class="text-xs font-semibold px-2 py-1 rounded-lg <?= $row['stock'] == 0 ? 'bg-red-100 text-red-600' : 'bg-yellow-100 text-yellow-700' ?>">
                  <?= $row['stock'] ?> left
                </span>
              </div>
            <?php endwhile; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── RECENT SALES ── -->
    <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between px-5 py-4 border-b border-slate-800 gap-4">
        <div>
          <h2 class="text-sm font-semibold text-slate-100">Recent Transactions</h2>
          <p class="text-xs text-slate-400">Search by sale ID, cashier first name, or last name.</p>
        </div>
        <div class="flex items-center gap-2">
          <form method="GET" action="dashboard.php" class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </div>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Search transactions..."
                   class="w-full sm:w-72 pl-10 pr-3 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 transition" />
          </form>
          <a href="sales.php" class="text-xs text-blue-300 hover:text-blue-100 transition">View all</a>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-xs text-slate-400 uppercase tracking-wide border-b border-slate-700">
              <th class="px-5 py-3 text-left">Sale ID</th>
              <th class="px-5 py-3 text-left">Cashier</th>
              <th class="px-5 py-3 text-left">Date & Time</th>
              <th class="px-5 py-3 text-right">Amount</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <?php if ($q_recent->num_rows === 0): ?>
              <tr>
                <td colspan="4" class="px-5 py-8 text-center text-slate-400 text-sm">No transactions yet.</td>
              </tr>
            <?php else: ?>
              <?php while ($row = $q_recent->fetch_assoc()): ?>
                <tr class="hover:bg-slate-800 transition">
                  <td class="px-5 py-3 font-mono text-slate-300">#<?= str_pad((int)$row['sale_id'], 4, '0', STR_PAD_LEFT) ?></td>
                  <td class="px-5 py-3 text-slate-100"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
                  <td class="px-5 py-3 text-slate-400"><?= date('M d, Y h:i A', strtotime($row['sale_date'])) ?></td>
                  <td class="px-5 py-3 text-right font-semibold text-slate-100">₱<?= number_format((float)$row['total_amount'], 2) ?></td>
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
if (openSidebarBtn) openSidebarBtn.addEventListener('click', openSidebar);
if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);
window.addEventListener('resize', () => {
  if (!sidebar || !sidebarOverlay) return;
  if (window.innerWidth >= 768) {
    sidebar.classList.remove('-translate-x-full');
    sidebarOverlay.classList.add('hidden');
  } else {
    sidebar.classList.add('-translate-x-full');
  }
});

const ctx = document.getElementById('salesChart').getContext('2d');
new Chart(ctx, {
  type: 'bar',
  data: {
    labels: <?= json_encode($chart_labels) ?>,
    datasets: [{
      label: 'Sales (₱)',
      data: <?= json_encode($chart_data) ?>,
      backgroundColor: 'rgba(59,130,246,0.15)',
      borderColor: 'rgba(59,130,246,1)',
      borderWidth: 2,
      borderRadius: 8,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: ctx => '₱' + ctx.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2})
        }
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        grid: { color: 'rgba(0,0,0,0.04)' },
        ticks: { callback: val => '₱' + val.toLocaleString() }
      },
      x: { grid: { display: false } }
    }
  }
});
</script>
</body>
</html>