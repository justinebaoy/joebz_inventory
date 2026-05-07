<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$success = '';
$error = '';

// Handle delete
if (isset($_GET['delete'])) {
    $item_id = (int)$_GET['delete'];
    
    if ($item_id > 0) {
        $check = $conn->prepare("SELECT COUNT(*) as cnt FROM sale_items WHERE item_id = ?");
        $check->bind_param("i", $item_id);
        $check->execute();
        $has_sales = $check->get_result()->fetch_assoc()['cnt'] > 0;
        $check->close();
        
        if ($has_sales) {
            $update = $conn->prepare("UPDATE items SET is_active = 0 WHERE item_id = ?");
            $update->bind_param("i", $item_id);
            $update->execute();
            $update->close();
            $success = "Item has sales history, so it was deactivated.";
        } else {
            $img = $conn->prepare("SELECT image_path FROM items WHERE item_id = ?");
            $img->bind_param("i", $item_id);
            $img->execute();
            $image = $img->get_result()->fetch_assoc()['image_path'] ?? '';
            $img->close();
            
            $delete = $conn->prepare("DELETE FROM items WHERE item_id = ?");
            $delete->bind_param("i", $item_id);
            $delete->execute();
            $delete->close();
            
            if ($image && file_exists($image)) unlink($image);
            $success = "Item deleted successfully!";
        }
        header("Location: items.php?success=" . urlencode($success));
        exit;
    }
}

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $item_name = trim($_POST['item_name']);
    $category_id = (int)$_POST['category_id'];
    $price = (float)$_POST['price'];
    $stock = (int)$_POST['stock'];
    
    // Build description from spec fields
    $description = '';
    $product_number = trim($_POST['product_number'] ?? '');
    $model_number = trim($_POST['model_number'] ?? '');
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    $battery = trim($_POST['battery'] ?? '');
    $display = trim($_POST['display'] ?? '');
    $hard_drive = trim($_POST['hard_drive'] ?? '');
    $memory_standard = trim($_POST['memory_standard'] ?? '');
    $license_type = trim($_POST['license_type'] ?? '');
    $cable_length = trim($_POST['cable_length'] ?? '');
    $compatibility = trim($_POST['compatibility'] ?? '');
    $dpi_resolution = trim($_POST['dpi_resolution'] ?? '');
    $interface = trim($_POST['interface'] ?? '');
    $details = trim($_POST['details'] ?? '');
    
    // Get allowed spec fields for this category
    $cat_spec = $conn->query("SELECT spec_fields FROM categories WHERE category_id = $category_id")->fetch_assoc();
    $allowed = json_decode($cat_spec['spec_fields'] ?? '[]', true);
    if (!is_array($allowed)) $allowed = [];
    
    if (!empty($product_number)) {
        $description .= "Product number: $product_number\n";
    }
    
    $spec_map = [
        'model_number' => 'Model Number',
        'manufacturer' => 'Manufacturer',
        'battery' => 'Battery',
        'display' => 'Display',
        'hard_drive' => 'Hard Drive',
        'memory_standard' => 'Memory',
        'license_type' => 'License Type',
        'cable_length' => 'Cable Length',
        'compatibility' => 'Compatibility',
        'dpi_resolution' => 'DPI Resolution',
        'interface' => 'Interface'
    ];
    
    foreach ($spec_map as $key => $label) {
        if (in_array($key, $allowed)) {
            $value = trim($_POST[$key] ?? '');
            if (!empty($value)) {
                $description .= "$label: $value\n";
            }
        }
    }
    
    if (!empty($details)) {
        $description .= "Details: $details\n";
    }
    
    // Handle image upload
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
        $success = "Item added successfully!";
    } elseif ($action === 'edit') {
        $item_id = (int)$_POST['item_id'];
        $current_image = $_POST['current_image'] ?? '';
        if (empty($image_path)) $image_path = $current_image;
        
        $stmt = $conn->prepare("UPDATE items SET item_name=?, category_id=?, price=?, stock=?, description=?, image_path=? WHERE item_id=?");
        $stmt->bind_param("siidssi", $item_name, $category_id, $price, $stock, $description, $image_path, $item_id);
        $stmt->execute();
        $success = "Item updated successfully!";
    }
    header("Location: items.php?success=" . urlencode($success));
    exit;
}

// Get edit item data and parse description
$edit_item = null;
$edit_specs = [
    'product_number' => '',
    'model_number' => '',
    'manufacturer' => '',
    'battery' => '',
    'display' => '',
    'hard_drive' => '',
    'memory_standard' => '',
    'license_type' => '',
    'cable_length' => '',
    'compatibility' => '',
    'dpi_resolution' => '',
    'interface' => '',
    'details' => ''
];

if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $result = $conn->query("SELECT * FROM items WHERE item_id = $edit_id");
    $edit_item = $result->fetch_assoc();
    
    if ($edit_item && $edit_item['description']) {
        $lines = explode("\n", $edit_item['description']);
        foreach ($lines as $line) {
            if (strpos($line, 'Product number:') === 0) {
                $edit_specs['product_number'] = trim(str_replace('Product number:', '', $line));
            } elseif (strpos($line, 'Model Number:') === 0) {
                $edit_specs['model_number'] = trim(str_replace('Model Number:', '', $line));
            } elseif (strpos($line, 'Manufacturer:') === 0) {
                $edit_specs['manufacturer'] = trim(str_replace('Manufacturer:', '', $line));
            } elseif (strpos($line, 'Battery:') === 0) {
                $edit_specs['battery'] = trim(str_replace('Battery:', '', $line));
            } elseif (strpos($line, 'Display:') === 0) {
                $edit_specs['display'] = trim(str_replace('Display:', '', $line));
            } elseif (strpos($line, 'Hard Drive:') === 0) {
                $edit_specs['hard_drive'] = trim(str_replace('Hard Drive:', '', $line));
            } elseif (strpos($line, 'Memory:') === 0) {
                $edit_specs['memory_standard'] = trim(str_replace('Memory:', '', $line));
            } elseif (strpos($line, 'License Type:') === 0) {
                $edit_specs['license_type'] = trim(str_replace('License Type:', '', $line));
            } elseif (strpos($line, 'Cable Length:') === 0) {
                $edit_specs['cable_length'] = trim(str_replace('Cable Length:', '', $line));
            } elseif (strpos($line, 'Compatibility:') === 0) {
                $edit_specs['compatibility'] = trim(str_replace('Compatibility:', '', $line));
            } elseif (strpos($line, 'DPI Resolution:') === 0) {
                $edit_specs['dpi_resolution'] = trim(str_replace('DPI Resolution:', '', $line));
            } elseif (strpos($line, 'Interface:') === 0) {
                $edit_specs['interface'] = trim(str_replace('Interface:', '', $line));
            } elseif (strpos($line, 'Details:') === 0) {
                $edit_specs['details'] = trim(str_replace('Details:', '', $line));
            }
        }
    }
}

// Get categories with spec_fields
$categories = $conn->query("SELECT category_id, category_name, spec_fields FROM categories ORDER BY category_name");
$categories_data = [];
while ($cat = $categories->fetch_assoc()) {
    $cat['spec_array'] = json_decode($cat['spec_fields'] ?? '[]', true);
    if (!is_array($cat['spec_array'])) $cat['spec_array'] = [];
    $categories_data[] = $cat;
}
$categories->data_seek(0);

// Get filter values
$search = $_GET['search'] ?? '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$show_inactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] === '1';

// Build items query
$where = [];
if (!$show_inactive) $where[] = "i.is_active = 1";
if ($search) $where[] = "(i.item_name LIKE '%$search%' OR i.description LIKE '%$search%')";
if ($category_filter > 0) $where[] = "i.category_id = $category_filter";

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
$items = $conn->query("
    SELECT i.*, c.category_name 
    FROM items i 
    JOIN categories c ON i.category_id = c.category_id 
    $where_sql 
    ORDER BY i.item_id DESC
");

$total_items = $items->num_rows;
$low_stock = $conn->query("SELECT COUNT(*) as total FROM items WHERE stock <= 5 AND is_active = 1")->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Items - JOEBZ POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .stat-card { transition: all 0.2s ease; }
        .stat-card:hover { transform: translateY(-2px); }
        .item-card { transition: all 0.2s ease; }
        .item-card:hover { transform: translateY(-3px); }
        .spec-field { transition: all 0.2s ease; }
        .spec-field.hidden { display: none; }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100">

<div class="flex min-h-screen">
    <!-- SIDEBAR -->
    <aside class="fixed inset-y-0 left-0 z-40 w-64 transform bg-slate-950 border-r border-slate-800 flex flex-col transition-transform duration-200 ease-out -translate-x-full md:translate-x-0">
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
            <a href="items.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-blue-600/20 text-blue-200 font-medium text-sm transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>
                Items
            </a>
            <a href="categories.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
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
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                Logout
            </a>
        </div>
    </aside>

    <div class="flex-1 w-full min-w-0 overflow-y-auto p-4 sm:p-6 lg:p-8 md:ml-64">
        
        <div class="mb-4 flex items-center justify-between gap-3 md:hidden">
            <button id="open-sidebar" class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium text-slate-200">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>Menu
            </button>
            <a href="logout.php" class="inline-flex items-center gap-2 rounded-xl border border-red-700/50 bg-red-900/20 px-3 py-2 text-sm text-red-200">Logout</a>
        </div>

        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-100">Items Management</h1>
            <p class="text-sm text-slate-400 mt-1">Manage your inventory items and stock levels</p>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="mb-4 p-4 bg-emerald-900/40 border border-emerald-700 rounded-xl text-emerald-200 text-sm"><?= htmlspecialchars($_GET['success']) ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="stat-card bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wider mb-2">Total Items</p>
                <p class="text-2xl font-bold text-white"><?= $total_items ?></p>
            </div>
            <div class="stat-card bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wider mb-2">Categories</p>
                <p class="text-2xl font-bold text-white"><?= $categories->num_rows ?></p>
            </div>
            <div class="stat-card bg-slate-900/95 rounded-2xl p-5 border border-slate-800 shadow-xl">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wider mb-2">Low Stock</p>
                <p class="text-2xl font-bold text-white <?= $low_stock > 0 ? 'text-amber-400' : '' ?>"><?= $low_stock ?></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- ADD ITEM FORM -->
            <div class="lg:col-span-1">
                <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl p-6 sticky top-6">
                    <h2 class="text-lg font-semibold text-white mb-4"><?= $edit_item ? 'Edit Item' : 'Add New Item' ?></h2>
                    <form method="POST" enctype="multipart/form-data" id="itemForm">
                        <input type="hidden" name="action" value="<?= $edit_item ? 'edit' : 'create' ?>">
                        <?php if ($edit_item): ?>
                            <input type="hidden" name="item_id" value="<?= $edit_item['item_id'] ?>">
                            <input type="hidden" name="current_image" value="<?= $edit_item['image_path'] ?>">
                        <?php endif; ?>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-medium text-slate-300 mb-1">Item Name</label>
                                <input type="text" name="item_name" value="<?= $edit_item['item_name'] ?? '' ?>" required class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-300 mb-1">Category</label>
                                <select name="category_id" id="category_id" required class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories_data as $cat): ?>
                                        <option value="<?= $cat['category_id'] ?>" 
                                            data-spec='<?= htmlspecialchars(json_encode($cat['spec_array'])) ?>'
                                            <?= ($edit_item && $edit_item['category_id'] == $cat['category_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['category_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-slate-300 mb-1">Price (₱)</label>
                                    <input type="number" name="price" step="0.01" min="0" value="<?= $edit_item['price'] ?? '' ?>" required class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-300 mb-1">Stock</label>
                                    <input type="number" name="stock" min="0" value="<?= $edit_item['stock'] ?? '' ?>" required class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-300 mb-1">Product Image</label>
                                <input type="file" name="image" accept="image/*" class="w-full text-sm text-slate-400 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:bg-blue-600 file:text-white">
                                <?php if ($edit_item && $edit_item['image_path']): ?>
                                    <p class="text-xs text-slate-500 mt-1">Current: <?= basename($edit_item['image_path']) ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <div id="specFieldsContainer"></div>
                            
                            <button type="submit" class="w-full mt-4 bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg transition text-sm">
                                <?= $edit_item ? 'Update Item' : 'Add Item' ?>
                            </button>
                            <?php if ($edit_item): ?>
                                <a href="items.php" class="block text-center text-slate-400 hover:text-slate-300 text-sm mt-2">Cancel Edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ITEMS LIST -->
            <div class="lg:col-span-2">
                <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl p-4 mb-4">
                    <form method="GET" class="flex flex-wrap gap-3">
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search items..." class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                        <select name="category" class="w-40 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                            <option value="0">All Categories</option>
                            <?php 
                            $cats = $conn->query("SELECT category_id, category_name FROM categories");
                            while ($cat = $cats->fetch_assoc()): 
                                $selected = ($category_filter == $cat['category_id']) ? 'selected' : '';
                            ?>
                                <option value="<?= $cat['category_id'] ?>" <?= $selected ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-white text-sm">Filter</button>
                        <a href="items.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-white text-sm">Clear</a>
                    </form>
                </div>

                <?php if ($items->num_rows === 0): ?>
                    <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl p-12 text-center text-slate-500">No items found</div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php while ($item = $items->fetch_assoc()): ?>
                            <div class="item-card bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl overflow-hidden hover:border-blue-500/50">
                                <div class="h-40 bg-slate-800">
                                    <?php if ($item['image_path'] && file_exists($item['image_path'])): ?>
                                        <img src="<?= $item['image_path'] ?>" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center text-slate-500 text-sm">No Image</div>
                                    <?php endif; ?>
                                </div>
                                <div class="p-4">
                                    <p class="text-xs text-blue-400"><?= htmlspecialchars($item['category_name']) ?></p>
                                    <h3 class="font-semibold text-white"><?= htmlspecialchars($item['item_name']) ?></h3>
                                    <div class="flex justify-between items-center mt-2">
                                        <p class="text-xl font-bold text-emerald-400">₱<?= number_format($item['price'], 2) ?></p>
                                        <p class="text-sm <?= $item['stock'] <= 5 ? 'text-amber-400' : 'text-slate-400' ?>">Stock: <?= $item['stock'] ?></p>
                                    </div>
                                    <div class="flex gap-2 mt-3">
                                        <a href="?edit=<?= $item['item_id'] ?>" class="flex-1 text-center px-3 py-1.5 bg-blue-600/20 hover:bg-blue-600/30 text-blue-300 rounded-lg text-sm">Edit</a>
                                        <a href="?delete=<?= $item['item_id'] ?>" class="flex-1 text-center px-3 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-300 rounded-lg text-sm" onclick="return confirm('Delete this item?')">Delete</a>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const sidebar = document.getElementById('sidebar');
const openSidebarBtn = document.getElementById('open-sidebar');

function openSidebar() { if (window.innerWidth < 768) sidebar.classList.remove('-translate-x-full'); }
function closeSidebar() { if (window.innerWidth < 768) sidebar.classList.add('-translate-x-full'); }
if (openSidebarBtn) openSidebarBtn.addEventListener('click', openSidebar);
document.addEventListener('click', function(e) { if (window.innerWidth < 768 && !sidebar.contains(e.target) && !openSidebarBtn.contains(e.target)) closeSidebar(); });
window.addEventListener('resize', () => { if (window.innerWidth >= 768) sidebar.classList.remove('-translate-x-full'); else sidebar.classList.add('-translate-x-full'); });

const specFieldsConfig = {
    product_number: { label: 'Product Number', type: 'text', always: true, value: '<?= addslashes($edit_specs['product_number']) ?>' },
    model_number: { label: 'Model Number', type: 'text', always: false, value: '<?= addslashes($edit_specs['model_number']) ?>' },
    manufacturer: { label: 'Manufacturer', type: 'text', always: false, value: '<?= addslashes($edit_specs['manufacturer']) ?>' },
    battery: { label: 'Battery / Power Supply', type: 'text', always: false, value: '<?= addslashes($edit_specs['battery']) ?>' },
    display: { label: 'Display / Screen', type: 'text', always: false, value: '<?= addslashes($edit_specs['display']) ?>' },
    hard_drive: { label: 'Hard Drive / Storage', type: 'text', always: false, value: '<?= addslashes($edit_specs['hard_drive']) ?>' },
    memory_standard: { label: 'Memory / RAM', type: 'text', always: false, value: '<?= addslashes($edit_specs['memory_standard']) ?>' },
    license_type: { label: 'License Type', type: 'text', always: false, value: '<?= addslashes($edit_specs['license_type']) ?>' },
    cable_length: { label: 'Cable Length', type: 'text', always: false, value: '<?= addslashes($edit_specs['cable_length']) ?>' },
    compatibility: { label: 'Compatibility', type: 'text', always: false, value: '<?= addslashes($edit_specs['compatibility']) ?>' },
    dpi_resolution: { label: 'DPI / Resolution', type: 'text', always: false, value: '<?= addslashes($edit_specs['dpi_resolution']) ?>' },
    interface: { label: 'Interface', type: 'text', always: false, value: '<?= addslashes($edit_specs['interface']) ?>' },
    details: { label: 'Details', type: 'textarea', always: true, value: '<?= addslashes($edit_specs['details']) ?>' }
};

const categorySelect = document.getElementById('category_id');
const specContainer = document.getElementById('specFieldsContainer');

function updateSpecFields() {
    const selectedOption = categorySelect.options[categorySelect.selectedIndex];
    let allowedSpecs = [];
    
    if (selectedOption && selectedOption.value) {
        try {
            allowedSpecs = JSON.parse(selectedOption.getAttribute('data-spec') || '[]');
        } catch(e) {
            allowedSpecs = [];
        }
    }
    
    let html = '';
    
    for (const [key, field] of Object.entries(specFieldsConfig)) {
        const isVisible = field.always || allowedSpecs.includes(key);
        
        if (isVisible) {
            if (field.type === 'textarea') {
                html += `<div class="spec-field"><label class="block text-xs font-medium text-slate-300 mb-1">${field.label}</label><textarea name="${key}" rows="3" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white">${field.value.replace(/</g, '&lt;')}</textarea></div>`;
            } else {
                html += `<div class="spec-field"><label class="block text-xs font-medium text-slate-300 mb-1">${field.label}</label><input type="text" name="${key}" value="${field.value.replace(/</g, '&lt;')}" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white"></div>`;
            }
        }
    }
    
    specContainer.innerHTML = html;
}

if (categorySelect) {
    categorySelect.addEventListener('change', updateSpecFields);
    updateSpecFields();
}
</script>

</body>
</html>