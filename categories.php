<?php
session_start();
require_once 'config/db.php';

const CATEGORY_OPTIONAL_FIELDS = [
    // ── Laptop / Desktop / Components ──
    'microprocessor'    => 'Microprocessor / CPU',
    'chipset'           => 'Chipset / Motherboard',
    'memory_standard'   => 'Memory / RAM',
    'video_graphics'    => 'Video Graphics / GPU',
    'hard_drive'        => 'Hard Drive / Storage',
    'display'           => 'Display / Screen',
    'battery'           => 'Battery / Power Supply',
    'operating_system'  => 'Operating System',
    'connectivity'      => 'Connectivity (WiFi, Bluetooth, Ports)',
    'dimensions'        => 'Dimensions / Weight',
    'warranty'          => 'Warranty Period',

    // ── Peripherals ──
    'interface'         => 'Interface (USB, Wireless, PS/2)',
    'dpi_resolution'    => 'DPI / Resolution',
    'compatibility'     => 'Compatibility (OS / Devices)',
    'cable_length'      => 'Cable Length',

    // ── Printer / Inks ──
    'print_technology'  => 'Print Technology (Inkjet, Laser, etc.)',
    'print_speed'       => 'Print Speed (PPM)',
    'paper_size'        => 'Supported Paper Sizes',
    'ink_type'          => 'Ink / Toner Type',
    'page_yield'        => 'Page Yield',
    'duty_cycle'        => 'Monthly Duty Cycle',

    // ── Software ──
    'license_type'      => 'License Type (OEM, Retail, Subscription)',
    'license_duration'  => 'License Duration',
    'min_requirements'  => 'Minimum System Requirements',
    'supported_os'      => 'Supported Operating Systems',
    'users_allowed'     => 'Number of Users / Devices',

    // ── General ──
    'model_number'      => 'Model Number',
    'manufacturer'      => 'Manufacturer / Brand',
    'color'             => 'Color / Finish',
];

const CATEGORY_FIELD_GROUPS = [
    'Laptop / Desktop / Components' => [
        'microprocessor', 'chipset', 'memory_standard', 'video_graphics',
        'hard_drive', 'display', 'battery', 'operating_system',
        'connectivity', 'dimensions', 'warranty',
    ],
    'Peripherals' => [
        'interface', 'dpi_resolution', 'compatibility', 'cable_length',
    ],
    'Printer / Inks' => [
        'print_technology', 'print_speed', 'paper_size',
        'ink_type', 'page_yield', 'duty_cycle',
    ],
    'Software' => [
        'license_type', 'license_duration', 'min_requirements',
        'supported_os', 'users_allowed',
    ],
    'General' => [
        'model_number', 'manufacturer', 'color',
    ],
];

function ensureCategorySpecFieldsColumn(mysqli $conn): void {
    $result = $conn->query("SHOW COLUMNS FROM categories LIKE 'spec_fields'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE categories ADD COLUMN spec_fields TEXT DEFAULT NULL");
    }
    if ($result) $result->free();
}

function sanitizeOptionalFields(array $incomingFields): array {
    $allowed    = array_keys(CATEGORY_OPTIONAL_FIELDS);
    $sanitized  = array_values(array_unique(array_intersect($incomingFields, $allowed)));
    sort($sanitized);
    return $sanitized;
}

function decodeSpecFields(?string $raw): array {
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    return sanitizeOptionalFields($decoded);
}

ensureCategorySpecFieldsColumn($conn);

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$success = '';
$error   = '';
$modal_to_open = '';

// ── CREATE ────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $category_name   = trim($_POST['category_name']);
    $optional_fields = sanitizeOptionalFields($_POST['category_fields'] ?? []);
    $spec_fields_json = json_encode($optional_fields);

    if (empty($category_name)) {
        $error = "Category name is required.";
    } elseif (strlen($category_name) > 100) {
        $error = "Category name must not exceed 100 characters.";
    } else {
        $check = $conn->prepare("SELECT category_id FROM categories WHERE category_name = ?");
        $check->bind_param("s", $category_name);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Category '{$category_name}' already exists.";
        } else {
            $stmt = $conn->prepare("INSERT INTO categories (category_name, spec_fields) VALUES (?, ?)");
            $stmt->bind_param("ss", $category_name, $spec_fields_json);
            if ($stmt->execute()) {
                $success = "Category '{$category_name}' created successfully!";
            } else {
                $error = "Failed to create category. Please try again.";
            }
            $stmt->close();
        }
        if ($error) $modal_to_open = 'createModal';
        $check->close();
    }
}

// ── EDIT ──────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $category_id     = (int)$_POST['category_id'];
    $category_name   = trim($_POST['category_name']);
    $optional_fields = sanitizeOptionalFields($_POST['category_fields'] ?? []);
    $spec_fields_json = json_encode($optional_fields);

    if (empty($category_name)) {
        $error = "Category name is required.";
    } elseif (strlen($category_name) > 100) {
        $error = "Category name must not exceed 100 characters.";
    } else {
        $check = $conn->prepare("SELECT category_id FROM categories WHERE category_name = ? AND category_id != ?");
        $check->bind_param("si", $category_name, $category_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Category '{$category_name}' already exists.";
        } else {
            $stmt = $conn->prepare("UPDATE categories SET category_name = ?, spec_fields = ? WHERE category_id = ?");
            $stmt->bind_param("ssi", $category_name, $spec_fields_json, $category_id);
            if ($stmt->execute()) {
                $success = "Category updated successfully!";
            } else {
                $error = "Failed to update category.";
            }
            $stmt->close();
        }
        if ($error) $modal_to_open = 'editModal';
        $check->close();
    }
}

// ── DELETE ────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $category_id = (int)$_POST['category_id'];
    $force_delete = isset($_POST['force_delete']) && $_POST['force_delete'] === '1';

    // Check active items
    $check_active = $conn->prepare("SELECT COUNT(*) AS total FROM items WHERE category_id = ? AND is_active = 1");
    $check_active->bind_param("i", $category_id);
    $check_active->execute();
    $active_result = $check_active->get_result()->fetch_assoc();
    $active_count = (int)$active_result['total'];
    $check_active->close();

    if ($active_count > 0) {
        $error = "Cannot delete — this category has {$active_count} active item(s). Remove or reassign those items first.";
    } else {
        // Check inactive items
        $check_inactive = $conn->prepare("SELECT COUNT(*) AS total FROM items WHERE category_id = ? AND is_active = 0");
        $check_inactive->bind_param("i", $category_id);
        $check_inactive->execute();
        $inactive_result = $check_inactive->get_result()->fetch_assoc();
        $inactive_count = (int)$inactive_result['total'];
        $check_inactive->close();

        if ($inactive_count > 0 && !$force_delete) {
            $error = "This category has {$inactive_count} inactive/hidden item(s). Enable 'Force delete' to remove them too.";
        } else {
            // Delete all linked items first (both active and inactive)
            $del_items = $conn->prepare("DELETE FROM items WHERE category_id = ?");
            $del_items->bind_param("i", $category_id);
            $del_items->execute();
            $del_items->close();

            // Now delete the category
            $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
            $stmt->bind_param("i", $category_id);
            if ($stmt->execute()) {
                $success = "Category and all linked items deleted successfully.";
            } else {
                $error = "Failed to delete category.";
            }
            $stmt->close();
        }
    }
}

// ── SEARCH ────────────────────────────────────────────
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare(
        "SELECT c.category_id, c.category_name, c.spec_fields, c.created_at,
                COUNT(CASE WHEN i.is_active = 1 THEN i.item_id END) AS item_count,
                COUNT(CASE WHEN i.is_active = 0 THEN i.item_id END) AS inactive_count
         FROM categories c
         LEFT JOIN items i ON c.category_id = i.category_id
         WHERE c.category_name LIKE ?
         GROUP BY c.category_id
         ORDER BY c.created_at DESC"
    );
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $categories = $stmt->get_result();
} else {
    $categories = $conn->query(
        "SELECT c.category_id, c.category_name, c.spec_fields, c.created_at,
                COUNT(CASE WHEN i.is_active = 1 THEN i.item_id END) AS item_count,
                COUNT(CASE WHEN i.is_active = 0 THEN i.item_id END) AS inactive_count
         FROM categories c
         LEFT JOIN items i ON c.category_id = i.category_id
         GROUP BY c.category_id
         ORDER BY c.created_at DESC"
    );
}

$total_result = $conn->query("SELECT COUNT(*) AS total FROM categories");
if ($total_result) {
    $total_cats = $total_result->fetch_assoc()['total'];
    $total_result->free();
} else {
    $total_cats = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Categories — JOEBZ Inventory</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    /* Smooth modal backdrop */
    .modal-backdrop { backdrop-filter: blur(2px); }
    /* Card hover lift */
    .cat-card { transition: transform 0.18s ease, box-shadow 0.18s ease; }
    .cat-card:hover { transform: translateY(-3px); box-shadow: 0 24px 48px rgba(0,0,0,0.35); }
    /* Checkbox custom accent */
    input[type=checkbox]:checked { accent-color: #2563eb; }
    /* Alert auto-hide animation */
    @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
    .alert-anim { animation: slideDown 0.25s ease; }
    /* Toast slide-in */
    @keyframes toastIn { from { opacity:0; transform:translateX(100px); } to { opacity:1; transform:translateX(0); } }
    .toast-in { animation: toastIn 0.3s ease; }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

<div class="flex min-h-screen">

  <!-- ── SIDEBAR ── -->
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 transform bg-slate-950 border-r border-slate-800 flex flex-col transition-transform duration-200 ease-out -translate-x-full md:translate-x-0">
   <div class="flex items-center gap-3 px-6 py-5 border-b border-slate-800">
      <div class="w-10 h-10 rounded-xl flex items-center justify-center overflow-hidden flex-shrink-0">
        <?php include 'includes/logo.php'; ?>
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
      <a href="categories.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-blue-600/20 text-blue-200 font-medium text-sm">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
        Categories
      </a>
      <?php if ($_SESSION['role'] === 'admin'): ?>
      <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
        Reports
      </a>
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

  <!-- ── MAIN ── -->
  <main class="flex-1 w-full min-w-0 overflow-y-auto p-4 sm:p-6 md:ml-64">

    <!-- Mobile top bar -->
    <div class="mb-4 flex items-center justify-between gap-3 md:hidden">
      <button type="button" id="open-sidebar" class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium text-slate-200">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        Menu
      </button>
      <a href="logout.php" class="inline-flex items-center gap-2 rounded-xl border border-red-700/50 bg-red-900/20 px-3 py-2 text-sm text-red-200">Logout</a>
    </div>

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-slate-100">Categories</h1>
        <p class="text-sm text-slate-400 mt-1">
          Manage your product categories —
          <span class="font-medium text-slate-100"><?= $total_cats ?></span> total
        </p>
      </div>
      <button type="button" onclick="openModal('createModal')"
              class="flex items-center gap-2 bg-blue-600 hover:bg-blue-500 active:bg-blue-700
                     text-white text-sm font-medium px-4 py-2.5 rounded-xl transition-all duration-150 shadow-lg shadow-blue-900/30">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Add Category
      </button>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
      <div class="alert-anim flex items-center gap-3 bg-emerald-900/40 border border-emerald-700 text-emerald-200 rounded-xl px-4 py-3 mb-5 text-sm">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert-anim flex items-center gap-3 bg-red-900/40 border border-red-700 text-red-200 rounded-xl px-4 py-3 mb-5 text-sm">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <!-- Search -->
    <div class="mb-5">
      <form method="GET" action="" id="searchForm">
        <div class="relative max-w-sm">
          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          </div>
          <input type="text" name="search" id="search-input"
                 placeholder="Search categories..."
                 value="<?= htmlspecialchars($search) ?>"
                 class="w-full pl-9 pr-8 py-2.5 border border-slate-700 rounded-xl text-sm text-slate-100
                        bg-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 transition"
                 aria-label="Search categories">
          <?php if ($search): ?>
            <a href="categories.php" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-100 transition">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Grid -->
    <?php if ($categories->num_rows === 0): ?>
      <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl flex flex-col items-center justify-center py-16">
        <div class="w-16 h-16 bg-slate-800 rounded-2xl flex items-center justify-center mb-4">
          <svg class="w-8 h-8 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
        </div>
        <p class="text-slate-400 font-medium">No categories found</p>
        <p class="text-slate-500 text-sm mt-1"><?= $search ? 'Try a different search term.' : 'Click "Add Category" to get started.' ?></p>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php while ($cat = $categories->fetch_assoc()): ?>
          <div class="cat-card bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl p-5">
            <div class="flex items-start justify-between">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-blue-600/20 rounded-xl flex items-center justify-center">
                  <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                </div>
                <div>
                  <h3 class="font-semibold text-slate-100 text-sm"><?= htmlspecialchars($cat['category_name']) ?></h3>
                  <p class="text-xs text-slate-500 mt-0.5"><?= date('M d, Y', strtotime($cat['created_at'])) ?></p>
                </div>
              </div>
              <div class="flex items-center gap-1">
                <button type="button"
                        onclick="openEdit(
                          <?= json_encode((int)$cat['category_id']) ?>,
                          <?= htmlspecialchars(json_encode($cat['category_name']), ENT_QUOTES, 'UTF-8') ?>,
                          <?= htmlspecialchars(json_encode(decodeSpecFields($cat['spec_fields'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                        )"
                        class="p-1.5 rounded-lg text-blue-400 hover:bg-blue-600/20 transition"
                        aria-label="Edit <?= htmlspecialchars($cat['category_name'], ENT_QUOTES) ?>">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </button>
                <button type="button"
                        onclick="confirmDelete(
                          <?= json_encode((int)$cat['category_id']) ?>,
                          <?= htmlspecialchars(json_encode($cat['category_name']), ENT_QUOTES, 'UTF-8') ?>,
                          <?= json_encode((int)($cat['inactive_count'] ?? 0)) ?>
                        )"
                        class="p-1.5 rounded-lg text-red-400 hover:bg-red-600/20 transition"
                        aria-label="Delete <?= htmlspecialchars($cat['category_name'], ENT_QUOTES) ?>">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
              </div>
            </div>

            <div class="mt-4 pt-4 border-t border-slate-800 flex items-center justify-between">
              <span class="text-xs text-slate-500">Items in category</span>
              <span class="text-sm font-bold <?= $cat['item_count'] > 0 ? 'text-blue-400' : 'text-slate-500' ?>">
                <?= $cat['item_count'] ?> <?= $cat['item_count'] == 1 ? 'item' : 'items' ?>
              </span>
            </div>

            <?php $configured = decodeSpecFields($cat['spec_fields'] ?? ''); ?>
            <div class="mt-3 text-xs text-slate-500">
              Optional fields: <span class="text-slate-300">
                <?= !empty($configured)
                    ? htmlspecialchars(implode(', ', array_map(fn($k) => CATEGORY_OPTIONAL_FIELDS[$k] ?? $k, $configured)))
                    : 'None (required fields only)' ?>
              </span>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    <?php endif; ?>

  </main>
</div>

<!-- ── CREATE MODAL ── -->
<div id="createModal" class="modal-backdrop fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden"
     role="dialog" aria-modal="true" aria-labelledby="createModalTitle">
  <div class="bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-sm mx-4 p-6 transition-all">
    <div class="flex items-center justify-between mb-5">
      <h3 class="text-lg font-semibold text-slate-100" id="createModalTitle">Add Category</h3>
      <button type="button" onclick="closeModal('createModal')" class="text-slate-400 hover:text-slate-100 transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="create">
      <div class="mb-4">
        <label class="block text-sm font-medium text-slate-300 mb-1.5">Category Name</label>
        <input type="text" name="category_name"
               placeholder="e.g. Processors, RAM, Storage..."
               maxlength="100"
               value="<?= htmlspecialchars($_POST['category_name'] ?? '') ?>"
               class="w-full border border-slate-700 rounded-xl bg-slate-800 px-4 py-2.5 text-sm text-slate-100
                      placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition"
               required autofocus>
        <p class="text-xs text-slate-500 mt-1">Maximum 100 characters</p>
      </div>
      <div class="mb-5">
        <p class="block text-sm font-medium text-slate-300 mb-1.5">Optional Item Fields</p>
        <p class="text-xs text-slate-500 mb-3">Always required: Item Name, Product Number, Price, Stock, Image, Description.</p>
        <div class="max-h-64 overflow-y-auto rounded-xl border border-slate-700 bg-slate-800/60 p-3 space-y-4">
          <?php foreach (CATEGORY_FIELD_GROUPS as $groupName => $groupKeys): ?>
            <div>
              <p class="text-xs font-semibold text-blue-400 uppercase tracking-wider mb-2"><?= htmlspecialchars($groupName) ?></p>
              <div class="grid grid-cols-1 gap-2">
                <?php foreach ($groupKeys as $fieldKey): ?>
                  <?php if (isset(CATEGORY_OPTIONAL_FIELDS[$fieldKey])): ?>
                    <label class="flex items-center gap-2.5 text-sm text-slate-200 cursor-pointer hover:text-white transition">
                      <input type="checkbox" name="category_fields[]" value="<?= $fieldKey ?>"
                             <?= in_array($fieldKey, sanitizeOptionalFields($_POST['category_fields'] ?? []), true) ? 'checked' : '' ?>
                             class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-blue-600 focus:ring-blue-500">
                      <?= htmlspecialchars(CATEGORY_OPTIONAL_FIELDS[$fieldKey]) ?>
                    </label>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="flex gap-3">
        <button type="button" onclick="closeModal('createModal')"
                class="flex-1 border border-slate-700 text-slate-300 rounded-xl py-2.5 text-sm hover:bg-slate-800 transition">
          Cancel
        </button>
        <button type="submit"
                class="flex-1 bg-blue-600 hover:bg-blue-500 active:bg-blue-700 text-white rounded-xl py-2.5 text-sm font-medium transition">
          Add Category
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── EDIT MODAL ── -->
<div id="editModal" class="modal-backdrop fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden"
     role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
  <div class="bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-sm mx-4 p-6">
    <div class="flex items-center justify-between mb-5">
      <h3 class="text-lg font-semibold text-slate-100" id="editModalTitle">Edit Category</h3>
      <button type="button" onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-100 transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="category_id" id="edit_category_id">
      <div class="mb-4">
        <label class="block text-sm font-medium text-slate-300 mb-1.5">Category Name</label>
        <input type="text" name="category_name" id="edit_category_name"
               maxlength="100"
               class="w-full border border-slate-700 rounded-xl bg-slate-800 px-4 py-2.5 text-sm text-slate-100
                      focus:outline-none focus:ring-2 focus:ring-blue-500 transition"
               required>
      </div>
      <div class="mb-5">
        <p class="block text-sm font-medium text-slate-300 mb-1.5">Optional Item Fields</p>
        <p class="text-xs text-slate-500 mb-3">Always required: Item Name, Product Number, Price, Stock, Image, Description.</p>
        <div class="max-h-64 overflow-y-auto rounded-xl border border-slate-700 bg-slate-800/60 p-3 space-y-4">
          <?php foreach (CATEGORY_FIELD_GROUPS as $groupName => $groupKeys): ?>
            <div>
              <p class="text-xs font-semibold text-blue-400 uppercase tracking-wider mb-2"><?= htmlspecialchars($groupName) ?></p>
              <div class="grid grid-cols-1 gap-2">
                <?php foreach ($groupKeys as $fieldKey): ?>
                  <?php if (isset(CATEGORY_OPTIONAL_FIELDS[$fieldKey])): ?>
                    <label class="flex items-center gap-2.5 text-sm text-slate-200 cursor-pointer hover:text-white transition">
                      <input type="checkbox" name="category_fields[]" value="<?= $fieldKey ?>"
                             data-edit-category-field
                             class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-blue-600 focus:ring-blue-500">
                      <?= htmlspecialchars(CATEGORY_OPTIONAL_FIELDS[$fieldKey]) ?>
                    </label>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="flex gap-3">
        <button type="button" onclick="closeModal('editModal')"
                class="flex-1 border border-slate-700 text-slate-300 rounded-xl py-2.5 text-sm hover:bg-slate-800 transition">
          Cancel
        </button>
        <button type="submit"
                class="flex-1 bg-blue-600 hover:bg-blue-500 active:bg-blue-700 text-white rounded-xl py-2.5 text-sm font-medium transition">
          Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── DELETE MODAL ── -->
<div id="deleteModal" class="modal-backdrop fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden"
     role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
  <div class="bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
    <div class="w-14 h-14 bg-red-900/40 border border-red-700 rounded-full flex items-center justify-center mx-auto mb-4">
      <svg class="w-7 h-7 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
    </div>
    <h3 class="text-lg font-semibold text-slate-100 mb-2" id="deleteModalTitle">Delete Category?</h3>
    <p class="text-sm text-slate-400 mb-6">
      You are about to delete <strong id="delete_cat_name" class="text-slate-100"></strong>.
      This cannot be undone.
    </p>
    <form method="POST" id="delete-category-form">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="category_id" id="delete_category_id">
      <div id="force-delete-wrapper" class="hidden mb-4">
        <label class="flex items-center gap-2.5 text-sm text-amber-300 cursor-pointer">
          <input type="checkbox" name="force_delete" value="1" id="force-delete-check"
                 class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-amber-500 focus:ring-amber-500">
          Force delete — also remove inactive/hidden items linked to this category
        </label>
      </div>
      <div class="flex gap-3">
        <button type="button" onclick="closeModal('deleteModal')"
                class="flex-1 border border-slate-700 text-slate-300 rounded-xl py-2.5 text-sm hover:bg-slate-800 transition">
          Cancel
        </button>
        <button type="submit"
                class="flex-1 bg-red-600 hover:bg-red-500 active:bg-red-700 text-white rounded-xl py-2.5 text-sm font-medium transition">
          Yes, Delete
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Sidebar ──────────────────────────────────────────
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

// ── Modals ───────────────────────────────────────────
const modalIds = ['createModal', 'editModal', 'deleteModal'];

function openModal(id) {
  const modal = document.getElementById(id);
  if (!modal) return;
  modal.classList.remove('hidden');
  // Focus first focusable element
  setTimeout(() => {
    const el = modal.querySelector('input:not([type=hidden]), button, select, textarea');
    if (el) el.focus();
  }, 50);
}

function closeModal(id) {
  const modal = document.getElementById(id);
  if (modal) modal.classList.add('hidden');
}

function openEdit(id, name, optionalFields) {
  document.getElementById('edit_category_id').value   = id;
  document.getElementById('edit_category_name').value = name;
  const selected = Array.isArray(optionalFields) ? optionalFields : [];
  document.querySelectorAll('[data-edit-category-field]').forEach(cb => {
    cb.checked = selected.includes(cb.value);
  });
  openModal('editModal');
}

function confirmDelete(id, name, hasInactive = false) {
  document.getElementById('delete_category_id').value   = id;
  document.getElementById('delete_cat_name').textContent = name;
  const forceWrapper = document.getElementById('force-delete-wrapper');
  const forceCheck = document.getElementById('force-delete-check');
  if (forceWrapper) {
    forceWrapper.classList.toggle('hidden', !hasInactive);
  }
  if (forceCheck) {
    forceCheck.checked = false;
  }
  openModal('deleteModal');
}

// Close on backdrop click
window.addEventListener('click', e => {
  modalIds.forEach(id => {
    const modal = document.getElementById(id);
    if (e.target === modal) closeModal(id);
  });
});

// Close on Escape
window.addEventListener('keydown', e => {
  if (e.key === 'Escape') modalIds.forEach(closeModal);
});

// ── Search debounce ──────────────────────────────────
const searchInput = document.getElementById('search-input');
if (searchInput) {
  let timer;
  searchInput.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(() => this.closest('form').submit(), 400);
  });
}

// ── Auto-open modal on error ─────────────────────────
<?php if (!empty($modal_to_open)): ?>
  openModal('<?= $modal_to_open ?>');
<?php endif; ?>

// ── Auto-dismiss alerts after 4s ─────────────────────
document.querySelectorAll('.alert-anim').forEach(el => {
  setTimeout(() => {
    el.style.transition = 'opacity 0.5s';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 500);
  }, 4000);
});
</script>

</body>
</html>