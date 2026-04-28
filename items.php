<?php
session_start();
require_once 'config/db.php';

// ── CSRF PROTECTION ────────────────────────────────────
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function validateCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        die('Invalid CSRF token');
    }
}

define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_URL', 'uploads');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

function ensureImageColumnExists(mysqli $conn): void {
    $r = $conn->query("SHOW COLUMNS FROM items LIKE 'image_path'");
    if ($r && $r->num_rows === 0) {
        $conn->query("ALTER TABLE items ADD COLUMN image_path VARCHAR(255) DEFAULT NULL");
    }
    if ($r) $r->free();
}

function ensureItemActiveColumnExists(mysqli $conn): void {
    $r = $conn->query("SHOW COLUMNS FROM items LIKE 'is_active'");
    if ($r && $r->num_rows === 0) {
        $conn->query("ALTER TABLE items ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    }
    if ($r) $r->free();
}

ensureImageColumnExists($conn);
ensureItemActiveColumnExists($conn);

function saveUploadedImage(array $file, ?string &$error): ?string {
    $error = null;
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;
        $error = 'Image upload failed. Please try again.';
        return null;
    }
    
    // Strict MIME validation
    $validTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];
    
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $type = $finfo->file($file['tmp_name']);
    
    if (!isset($validTypes[$type])) {
        $error = 'Only JPG, PNG, GIF, and WEBP images are allowed.';
        return null;
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        $error = 'Image file must be 5MB or smaller.';
        return null;
    }
    
    // Extension whitelist
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowedExts)) {
        $error = 'Invalid file extension.';
        return null;
    }
    
    $filename = sprintf('%s.%s', uniqid('item_', true), $validTypes[$type]);
    $destination = UPLOAD_DIR . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        $error = 'Failed to save the uploaded image.';
        return null;
    }
    
    return UPLOAD_URL . '/' . $filename;
}

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$success = '';
$error = '';

function parseSpecLines(string $text): array {
    $specs = [];
    foreach (preg_split('/\r?\n/', trim($text)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, ':') !== false) {
            [$label, $value] = array_map('trim', explode(':', $line, 2));
            $specs[$label] = $value;
        } else {
            $specs[] = $line;
        }
    }
    return $specs;
}

function getDefaultSpecValues(): array {
    return [
        'product_number' => '',
        'microprocessor' => '',
        'chipset' => '',
        'memory_standard' => '',
        'video_graphics' => '',
        'hard_drive' => '',
        'display' => '',
        'details' => ''
    ];
}

function decodeCategorySpecFields(?string $specFields): array {
    if ($specFields === null || trim($specFields) === '') {
        return array_keys(getDefaultSpecValues());
    }
    $decoded = json_decode($specFields, true);
    if (!is_array($decoded)) return array_keys(getDefaultSpecValues());
    $always = ['product_number', 'details'];
    $allowed = array_intersect(array_keys(getDefaultSpecValues()), $decoded);
    return array_values(array_unique(array_merge($always, $allowed)));
}

function buildItemDescription(array $specValues, array $allowedSpecKeys): string {
    $labels = [
        'product_number' => 'Product number',
        'microprocessor' => 'Microprocessor',
        'chipset' => 'Chipset',
        'memory_standard' => 'Memory, standard',
        'video_graphics' => 'Video graphics',
        'hard_drive' => 'Hard drive',
        'display' => 'Display',
        'details' => 'Details'
    ];
    $lines = [];
    foreach ($labels as $key => $label) {
        if (!in_array($key, $allowedSpecKeys, true)) continue;
        $value = trim((string)($specValues[$key] ?? ''));
        if ($value !== '') $lines[] = "{$label}: {$value}";
    }
    return implode("\n", $lines);
}

function parseDescriptionToSpecValues(string $description, array $allowedKeys): array {
    $specValues = getDefaultSpecValues();
    $lines = explode("\n", $description);
    
    $labelsToKeys = [
        'Product number' => 'product_number',
        'Microprocessor' => 'microprocessor',
        'Chipset' => 'chipset',
        'Memory, standard' => 'memory_standard',
        'Video graphics' => 'video_graphics',
        'Hard drive' => 'hard_drive',
        'Display' => 'display',
        'Details' => 'details'
    ];
    
    foreach ($lines as $line) {
        if (strpos($line, ':') !== false) {
            [$label, $value] = array_map('trim', explode(':', $line, 2));
            if (isset($labelsToKeys[$label])) {
                $specValues[$labelsToKeys[$label]] = $value;
            }
        }
    }
    
    return $specValues;
}

function handleItemDelete(int $item_id, mysqli $conn, string &$success, string &$error): void {
    if ($item_id <= 0) {
        $error = 'Invalid item selected.';
        return;
    }

    // Check if item has sales history
    $check = $conn->prepare("SELECT COUNT(*) as cnt FROM sale_items WHERE item_id = ?");
    if (!$check) {
        $error = 'Database error.';
        return;
    }
    $check->bind_param("i", $item_id);
    $check->execute();
    $result = $check->get_result()->fetch_assoc();
    $sales_count = (int)($result['cnt'] ?? 0);
    $check->close();

    if ($sales_count > 0) {
        // Soft delete - just mark as inactive
        $d = $conn->prepare("UPDATE items SET is_active = 0 WHERE item_id = ?");
        if (!$d) {
            $error = 'Cannot deactivate item.';
            return;
        }
        $d->bind_param("i", $item_id);
        if ($d->execute() && $d->affected_rows > 0) {
            $success = 'Item has sales history, so it was deactivated instead of deleted.';
        } else {
            $error = 'Item is already inactive or could not be deactivated.';
        }
        $d->close();
        return;
    }

    // No sales history - hard delete
    $iq = $conn->prepare("SELECT image_path FROM items WHERE item_id = ?");
    if (!$iq) {
        $error = 'Database error.';
        return;
    }
    $iq->bind_param("i", $item_id);
    $iq->execute();
    $iq->bind_result($current_image);
    $iq->fetch();
    $current_image = $current_image ?? '';
    $iq->close();

    $stmt = $conn->prepare("DELETE FROM items WHERE item_id = ?");
    if (!$stmt) {
        $error = 'Database error.';
        return;
    }
    $stmt->bind_param("i", $item_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        if (!empty($current_image)) {
            $f = __DIR__ . '/' . $current_image;
            if (file_exists($f)) @unlink($f);
        }
        $success = 'Item deleted successfully!';
    } else {
        $error = 'Failed to delete item.';
    }
    $stmt->close();
}

// ── CREATE ───────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    validateCsrf();
    $item_name = trim($_POST['item_name'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $specValues = getDefaultSpecValues();
    foreach ($specValues as $k => $v) {
        $specValues[$k] = trim($_POST[$k] ?? '');
    }
    $image_path = null;

    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $image_path = saveUploadedImage($_FILES['image'], $uploadError);
        if (!empty($uploadError)) $error = $uploadError;
    } elseif (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = "Product image is required.";
    }

    $catName = '';
    $catSpecFields = null;
    if ($category_id > 0) {
        $cs = $conn->prepare("SELECT category_name, spec_fields FROM categories WHERE category_id = ?");
        $cs->bind_param("i", $category_id);
        $cs->execute();
        $row = $cs->get_result()->fetch_assoc();
        $catName = $row['category_name'] ?? '';
        $catSpecFields = $row['spec_fields'] ?? null;
        $cs->close();
    }
    $allowedSpecKeys = decodeCategorySpecFields($catSpecFields);
    $description = buildItemDescription($specValues, $allowedSpecKeys);

    if (empty($item_name)) {
        $error = "Item name is required.";
    } elseif ($category_id <= 0) {
        $error = "Please select a category.";
    } elseif ($price <= 0) {
        $error = "Price must be greater than 0.";
    } elseif ($stock < 0) {
        $error = "Stock cannot be negative.";
    } elseif (empty($error)) {
        $check = $conn->prepare("SELECT item_id FROM items WHERE item_name = ? AND is_active = 1");
        $check->bind_param("s", $item_name);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "An item with this name already exists.";
        } else {
            $stmt = $conn->prepare("INSERT INTO items (item_name, category_id, price, stock, description, image_path, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
            if ($stmt === false) {
                $error = 'Database error: ' . htmlspecialchars($conn->error);
            } else {
                $stmt->bind_param("siddss", $item_name, $category_id, $price, $stock, $description, $image_path);
                if ($stmt->execute()) {
                    $success = "Item '" . htmlspecialchars($item_name) . "' created successfully!";
                    // Clear form after successful creation via redirect to prevent resubmission
                    header("Location: items.php?success=" . urlencode($success));
                    exit;
                } else {
                    $error = "Failed to create item: " . $stmt->error;
                }
                $stmt->close();
            }
        }
        $check->close();
    }
}

// ── EDIT ─────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    validateCsrf();
    $item_id = (int)($_POST['item_id'] ?? 0);
    $item_name = trim($_POST['item_name'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $specValues = getDefaultSpecValues();
    foreach ($specValues as $k => $v) {
        $specValues[$k] = trim($_POST[$k] ?? '');
    }
    $current_image = trim($_POST['current_image'] ?? '');
    $image_path = $current_image;

    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $new_upload = saveUploadedImage($_FILES['image'], $uploadError);
        if (!empty($uploadError)) {
            $error = $uploadError;
        } elseif ($new_upload !== null) {
            $image_path = $new_upload;
            if (!empty($current_image)) {
                $f = __DIR__ . '/' . $current_image;
                if (file_exists($f)) @unlink($f);
            }
        }
    }

    $catSpecFields = null;
    if ($category_id > 0) {
        $cs = $conn->prepare("SELECT spec_fields FROM categories WHERE category_id = ?");
        $cs->bind_param("i", $category_id);
        $cs->execute();
        $catSpecFields = $cs->get_result()->fetch_assoc()['spec_fields'] ?? null;
        $cs->close();
    }
    $allowedSpecKeys = decodeCategorySpecFields($catSpecFields);
    $description = buildItemDescription($specValues, $allowedSpecKeys);

    if (empty($item_name)) {
        $error = "Item name is required.";
    } elseif ($category_id <= 0) {
        $error = "Please select a category.";
    } elseif ($price <= 0) {
        $error = "Price must be greater than 0.";
    } elseif ($stock < 0) {
        $error = "Stock cannot be negative.";
    } elseif (empty($error)) {
        $check = $conn->prepare("SELECT item_id FROM items WHERE item_name = ? AND item_id != ? AND is_active = 1");
        $check->bind_param("si", $item_name, $item_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Another item with this name already exists.";
        } else {
            $stmt = $conn->prepare("UPDATE items SET item_name=?, category_id=?, price=?, stock=?, description=?, image_path=? WHERE item_id=?");
            if ($stmt === false) {
                $error = 'Database error: ' . htmlspecialchars($conn->error);
            } else {
                $stmt->bind_param("siddssi", $item_name, $category_id, $price, $stock, $description, $image_path, $item_id);
                if ($stmt->execute()) {
                    $success = "Item '" . htmlspecialchars($item_name) . "' updated successfully!";
                    header("Location: items.php?success=" . urlencode($success));
                    exit;
                } else {
                    $error = "Failed to update item.";
                }
                $stmt->close();
            }
        }
        $check->close();
    }
}

// ── DELETE ───────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    validateCsrf();
    handleItemDelete((int)($_POST['item_id'] ?? 0), $conn, $success, $error);
}

// ── RESTORE (Reactivate soft-deleted item) ───────────
if (isset($_POST['action']) && $_POST['action'] === 'restore') {
    validateCsrf();
    $item_id = (int)($_POST['item_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE items SET is_active = 1 WHERE item_id = ?");
    $stmt->bind_param("i", $item_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $success = "Item restored successfully!";
    } else {
        $error = "Failed to restore item.";
    }
    $stmt->close();
}

// Handle success message from redirect
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// ── CATEGORIES FOR DROPDOWN ──────────────────────────
$categories_result = $conn->query("SELECT category_id, category_name, spec_fields FROM categories ORDER BY category_name");
$categories = [];
if ($categories_result) {
    while ($row = $categories_result->fetch_assoc()) {
        $row['allowed_specs'] = decodeCategorySpecFields($row['spec_fields'] ?? null);
        $categories[] = $row;
    }
}

$catSpecsMap = [];
foreach ($categories as $cat) {
    $catSpecsMap[$cat['category_id']] = $cat['allowed_specs'];
}

// Low stock count
$q_low_stock = $conn->query("SELECT COUNT(*) AS total FROM items WHERE stock <= 5 AND is_active = 1");
$low_stock_count = $q_low_stock ? (int)$q_low_stock->fetch_assoc()['total'] : 0;

// ── PAGINATION & FILTER (SAFE) ───────────────────────
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$per_page = 10;
$offset = ($page - 1) * $per_page;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$show_inactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] === '1';

// Build query safely with prepared statements
$params = [];
$types = "";
$where_conditions = [];

if (!$show_inactive) {
    $where_conditions[] = "i.is_active = 1";
}

if ($search !== '') {
    $where_conditions[] = "(i.item_name LIKE ? OR i.description LIKE ?)";
    $search_like = '%' . $search . '%';
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= "ss";
}

if ($category_filter > 0) {
    $where_conditions[] = "i.category_id = ?";
    $params[] = $category_filter;
    $types .= "i";
}

$where_sql = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

// Count total
$count_sql = "SELECT COUNT(*) as total FROM items i $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($count_stmt && !empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_items = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$count_stmt->close();

$total_pages = max(1, (int)ceil($total_items / $per_page));

// Fetch items
$items_sql = "
    SELECT i.item_id, i.item_name, i.category_id, i.price, i.stock, i.description, i.image_path, i.is_active, i.created_at,
           c.category_name
    FROM items i
    JOIN categories c ON i.category_id = c.category_id
    $where_sql
    ORDER BY i.is_active DESC, i.created_at DESC
    LIMIT ? OFFSET ?
";
$items_stmt = $conn->prepare($items_sql);
$limit_types = $types . "ii";
$params[] = $per_page;
$params[] = $offset;
if (!empty($params)) {
    $items_stmt->bind_param($limit_types, ...$params);
}
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$items_stmt->close();

// Get a single item for editing (populate form)
$edit_item = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM items WHERE item_id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($edit_item) {
        // Parse description back into spec values
        $cat_spec = $conn->prepare("SELECT spec_fields FROM categories WHERE category_id = ?");
        $cat_spec->bind_param("i", $edit_item['category_id']);
        $cat_spec->execute();
        $cat_spec_fields = $cat_spec->get_result()->fetch_assoc()['spec_fields'] ?? null;
        $cat_spec->close();
        $allowed_keys = decodeCategorySpecFields($cat_spec_fields);
        $spec_values = parseDescriptionToSpecValues($edit_item['description'], $allowed_keys);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Items — JOEBZ Inventory</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    input[type=number]::-webkit-outer-spin-button,
    input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
    input[type=number] { -moz-appearance:textfield; appearance:textfield; }
    .item-card { transition: transform 0.18s ease, box-shadow 0.18s ease; }
    .item-card:hover { transform: translateY(-3px); box-shadow: 0 24px 48px rgba(0,0,0,0.35); }
    @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
    .alert-anim { animation: slideDown 0.25s ease; }
    .modal-backdrop { backdrop-filter: blur(2px); }
    [data-spec-field] { transition: opacity 0.15s ease; }
    [data-spec-field].hidden { display: none; }
    .image-preview { 
        width: 80px; 
        height: 80px; 
        object-fit: cover; 
        border-radius: 8px;
        border: 1px solid #334155;
    }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

<div class="flex min-h-screen">

  <!-- ── SIDEBAR ── -->
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 transform bg-slate-950 border-r border-slate-800 flex flex-col transition-transform duration-200 ease-out -translate-x-full md:translate-x-0">
    <div class="flex items-center gap-3 px-6 py-5 border-b border-slate-800">
      <div class="w-10 h-10 rounded-xl flex items-center justify-center overflow-hidden flex-shrink-0">
        <img src="assets/logo.png" alt="JOEBZ Logo" class="w-full h-full object-cover rounded-xl">
      </div>
      <span class="text-lg font-bold text-slate-100 tracking-tight">JOEBZ</span>
    </div>
    <nav class="flex-1 px-4 py-4 space-y-1 overflow-y-auto">
      <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Dashboard
      </a>
      <a href="sales.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>Sales / POS
      </a>
      <a href="items.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-blue-600/20 text-blue-200 font-medium text-sm">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>Items
      </a>
      <a href="categories.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>Categories
      </a>
      <?php if ($_SESSION['role'] === 'admin'): ?>
      <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>Reports
      </a>
      <a href="users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>Users
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
      <a href="logout.php" class="flex items-center gap-3 px-3 py-2 rounded-xl text-red-300 hover:bg-red-900/40 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>Logout
      </a>
    </div>
  </aside>

  <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-black/60 md:hidden"></div>

  <!-- ── DELETE MODAL ── -->
  <div id="deleteItemModal" class="modal-backdrop fixed inset-0 bg-black/50 hidden flex items-center justify-center z-50" role="dialog" aria-modal="true" aria-labelledby="deleteItemModalTitle">
    <div class="bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
      <div class="w-14 h-14 bg-red-900/40 border border-red-700 rounded-full flex items-center justify-center mx-auto mb-4">
        <svg class="w-7 h-7 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
      </div>
      <h3 class="text-lg font-semibold text-slate-100 mb-2" id="deleteItemModalTitle">Delete Item?</h3>
      <p class="text-sm text-slate-400 mb-6">
        You are about to delete <strong id="delete_item_name" class="text-slate-100"></strong>.
        This cannot be undone.
      </p>
      <form id="delete-item-form" method="POST" action="items.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="item_id" id="delete_item_id">
        <div class="flex gap-3">
          <button type="button" onclick="closeDeleteItemModal()" class="flex-1 border border-slate-700 text-slate-300 rounded-xl py-2.5 text-sm hover:bg-slate-800 transition">Cancel</button>
          <button type="submit" class="flex-1 bg-red-600 hover:bg-red-500 active:bg-red-700 text-white rounded-xl py-2.5 text-sm font-medium transition">Yes, Delete</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── MAIN ── -->
  <main class="flex-1 w-full min-w-0 overflow-y-auto p-4 sm:p-6 lg:p-8 md:ml-64">
    <div class="mb-4 flex items-center justify-between gap-3 md:hidden">
      <button type="button" id="open-sidebar" class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium text-slate-200">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>Menu
      </button>
      <a href="logout.php" class="inline-flex items-center gap-2 rounded-xl border border-red-700/50 bg-red-900/20 px-3 py-2 text-sm text-red-200">Logout</a>
    </div>

    <div class="mb-6">
      <h1 class="text-2xl font-bold text-slate-100">Items Management</h1>
      <p class="text-sm text-slate-400 mt-1">Manage your inventory items and stock levels</p>
    </div>

    <!-- Stat cards -->
    <div class="grid gap-4 xl:grid-cols-3 mb-6">
      <div class="rounded-2xl border border-slate-800 bg-slate-900/95 p-5 shadow-xl">
        <p class="text-xs uppercase tracking-widest text-slate-400">Total Items</p>
        <p class="mt-2 text-3xl font-bold text-slate-100"><?= number_format($total_items) ?></p>
      </div>
      <div class="rounded-2xl border border-slate-800 bg-slate-900/95 p-5 shadow-xl">
        <p class="text-xs uppercase tracking-widest text-slate-400">Current Page</p>
        <p class="mt-2 text-3xl font-bold text-slate-100"><?= $page ?> / <?= $total_pages ?></p>
      </div>
      <div class="rounded-2xl border border-slate-800 bg-slate-900/95 p-5 shadow-xl">
        <p class="text-xs uppercase tracking-widest text-slate-400">Low Stock</p>
        <p class="mt-2 text-3xl font-bold text-amber-400"><?= number_format($low_stock_count) ?></p>
      </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
      <div class="alert-anim mb-4 flex items-center gap-3 bg-emerald-900/40 border border-emerald-700 text-emerald-200 rounded-xl px-4 py-3 text-sm">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert-anim mb-4 flex items-center gap-3 bg-red-900/40 border border-red-700 text-red-200 rounded-xl px-4 py-3 text-sm">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
      <!-- ── FORM PANEL ── -->
      <div class="lg:col-span-1">
        <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-2xl p-6 lg:sticky lg:top-6">
          <h2 class="text-lg font-bold text-slate-100 mb-4" id="form-title"><?= $edit_item ? 'Edit Item' : 'Add New Item' ?></h2>
          
          <!-- Image Preview -->
          <?php if ($edit_item && !empty($edit_item['image_path'])): ?>
          <div class="mb-4 text-center">
            <img src="<?= htmlspecialchars($edit_item['image_path']) ?>" alt="Current image" class="image-preview mx-auto">
            <p class="text-xs text-slate-500 mt-1">Current image</p>
          </div>
          <?php endif; ?>
          
          <form method="POST" enctype="multipart/form-data" id="item-form">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" id="form-action" value="<?= $edit_item ? 'edit' : 'create' ?>">
            <input type="hidden" name="item_id" id="item-id" value="<?= $edit_item['item_id'] ?? '' ?>">
            <input type="hidden" name="current_image" id="current-image" value="<?= htmlspecialchars($edit_item['image_path'] ?? '') ?>">
            <div class="space-y-3">
              <div>
                <label class="block text-xs font-medium text-slate-300 mb-1">Item Name *</label>
                <input type="text" name="item_name" id="item-name" required value="<?= htmlspecialchars($edit_item['item_name'] ?? '') ?>" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-300 mb-1">Category *</label>
                <select name="category_id" id="category-id" required class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 transition" onchange="updateSpecFields()">
                  <option value="">Select Category</option>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['category_id'] ?>" <?= ($edit_item && $edit_item['category_id'] == $cat['category_id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-slate-300 mb-1">Price (₱) *</label>
                  <input type="number" name="price" id="item-price" step="0.01" min="0.01" required value="<?= $edit_item ? number_format($edit_item['price'], 2) : '' ?>" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                </div>
                <div>
                  <label class="block text-xs font-medium text-slate-300 mb-1">Stock *</label>
                  <input type="number" name="stock" id="item-stock" min="0" required value="<?= $edit_item['stock'] ?? '' ?>" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                </div>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-300 mb-1">Product Image <?= !$edit_item ? '*' : '' ?></label>
                <input type="file" name="image" id="item-image" accept="image/*" <?= !$edit_item ? 'required' : '' ?> class="w-full text-sm text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-500 bg-slate-800 border border-slate-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                <p class="text-xs text-slate-500 mt-1"><?= !$edit_item ? 'Required for new items.' : 'Leave blank to keep current image.' ?></p>
              </div>
              
              <!-- Dynamic Spec Fields Container -->
              <div id="spec-fields-container">
                <?php
                $current_category_id = $edit_item['category_id'] ?? 0;
                $current_allowed_specs = [];
                if ($current_category_id > 0) {
                    foreach ($categories as $cat) {
                        if ($cat['category_id'] == $current_category_id) {
                            $current_allowed_specs = $cat['allowed_specs'];
                            break;
                        }
                    }
                }
                $spec_labels = [
                    'product_number' => 'Product Number',
                    'microprocessor' => 'Microprocessor',
                    'chipset' => 'Chipset',
                    'memory_standard' => 'Memory, Standard',
                    'video_graphics' => 'Video Graphics',
                    'hard_drive' => 'Hard Drive',
                    'display' => 'Display',
                    'details' => 'Details'
                ];
                ?>
                <?php foreach ($spec_labels as $key => $label): ?>
                  <?php if (in_array($key, $current_allowed_specs) || (!$edit_item && $key === 'product_number') || (!$edit_item && $key === 'details')): ?>
                    <div data-spec-field="<?= $key ?>">
                      <label class="block text-xs font-medium text-slate-300 mb-1"><?= $label ?></label>
                      <?php if ($key === 'details'): ?>
                        <textarea name="<?= $key ?>" id="spec-<?= $key ?>" rows="3" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition"><?= htmlspecialchars($spec_values[$key] ?? '') ?></textarea>
                      <?php else: ?>
                        <input type="text" name="<?= $key ?>" id="spec-<?= $key ?>" value="<?= htmlspecialchars($spec_values[$key] ?? '') ?>" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <div data-spec-field="<?= $key ?>" class="hidden">
                      <input type="hidden" name="<?= $key ?>" value="">
                    </div>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
              
              <button type="submit" class="w-full mt-2 bg-blue-600 hover:bg-blue-500 active:bg-blue-700 text-white font-medium py-2.5 rounded-xl transition">
                <?= $edit_item ? 'Update Item' : 'Add Item' ?>
              </button>
              <?php if ($edit_item): ?>
              <a href="items.php" class="block text-center text-slate-400 hover:text-slate-300 text-sm transition">Cancel Edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <!-- ── ITEMS LIST ── -->
      <div class="lg:col-span-3">
        <!-- Search and Filters -->
        <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl p-4 mb-4">
          <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[150px]">
              <label class="block text-xs font-medium text-slate-300 mb-1">Search</label>
              <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Item name or description..." class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="w-40">
              <label class="block text-xs font-medium text-slate-300 mb-1">Category</label>
              <select name="category" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= $cat['category_id'] ?>" <?= $category_filter == $cat['category_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-300 mb-1">&nbsp;</label>
              <label class="flex items-center gap-2 text-sm text-slate-300">
                <input type="checkbox" name="show_inactive" value="1" <?= $show_inactive ? 'checked' : '' ?> onchange="this.form.submit()" class="rounded border-slate-600 bg-slate-800 text-blue-600 focus:ring-blue-500">
                Show inactive items
              </label>
            </div>
            <div>
              <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 rounded-xl text-white text-sm font-medium transition">Filter</button>
              <a href="items.php" class="inline-block ml-2 px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-xl text-white text-sm transition">Clear</a>
            </div>
          </form>
        </div>

        <!-- Items Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          <?php if ($items_result && $items_result->num_rows > 0): ?>
            <?php while ($item = $items_result->fetch_assoc()): ?>
              <div class="item-card bg-slate-900/95 rounded-2xl border <?= $item['is_active'] ? 'border-slate-800' : 'border-red-800/50 bg-red-900/20' ?> shadow-xl overflow-hidden">
                <?php if (!empty($item['image_path'])): ?>
                  <div class="h-40 bg-slate-900 overflow-hidden">
                    <img src="<?= htmlspecialchars($item['image_path']) ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" class="h-full w-full object-cover">
                  </div>
                <?php else: ?>
                  <div class="h-40 bg-slate-800 flex items-center justify-center">
                    <svg class="w-12 h-12 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                  </div>
                <?php endif; ?>
                <div class="p-4">
                  <div class="flex justify-between items-start mb-2">
                    <div>
                      <p class="text-xs text-blue-400 font-medium"><?= htmlspecialchars($item['category_name']) ?></p>
                      <h3 class="font-semibold text-slate-100 text-base"><?= htmlspecialchars($item['item_name']) ?></h3>
                    </div>
                    <?php if (!$item['is_active']): ?>
                      <span class="px-2 py-0.5 bg-red-600/30 text-red-300 text-xs rounded-full">Inactive</span>
                    <?php endif; ?>
                  </div>
                  <div class="flex items-center justify-between mb-3">
                    <p class="text-xl font-bold text-blue-300">₱<?= number_format($item['price'], 2) ?></p>
                    <p class="text-sm <?= $item['stock'] <= 5 ? 'text-amber-400 font-bold' : 'text-slate-400' ?>">Stock: <?= $item['stock'] ?></p>
                  </div>
                  <div class="flex gap-2 mt-3">
                    <a href="?edit=<?= $item['item_id'] ?>" class="flex-1 text-center px-3 py-1.5 bg-blue-600/20 hover:bg-blue-600/30 text-blue-300 rounded-lg text-sm font-medium transition">Edit</a>
                    <button type="button" onclick="confirmDeleteItem(<?= json_encode((int)$item['item_id']) ?>, <?= htmlspecialchars(json_encode($item['item_name']), ENT_QUOTES, 'UTF-8') ?>)" class="flex-1 px-3 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-300 rounded-lg text-sm font-medium transition">Delete</button>
                  </div>
                </div>
              </div>
            <?php endwhile; ?>
          <?php else: ?>
            <div class="col-span-full bg-slate-900/95 rounded-2xl border border-slate-800 p-12 text-center">
              <svg class="w-16 h-16 mx-auto text-slate-700 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
              <p class="text-slate-400 font-medium">No items found</p>
              <p class="text-slate-500 text-sm mt-1"><?= $search ? 'Try a different search term.' : 'Click "Add New Item" to get started.' ?></p>
            </div>
          <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="flex justify-center gap-2 mt-6">
          <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&category=<?= $category_filter ?>&show_inactive=<?= $show_inactive ? '1' : '0' ?>" class="px-3 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-sm transition">Previous</a>
          <?php endif; ?>
          <span class="px-3 py-2 bg-blue-600 rounded-lg text-sm"><?= $page ?> / <?= $total_pages ?></span>
          <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&category=<?= $category_filter ?>&show_inactive=<?= $show_inactive ? '1' : '0' ?>" class="px-3 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-sm transition">Next</a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
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
  if (window.innerWidth >= 768) {
    sidebar.classList.remove('-translate-x-full');
    sidebarOverlay.classList.add('hidden');
  } else {
    sidebar.classList.add('-translate-x-full');
  }
});

// ── Dynamic Spec Fields ──────────────────────────────
const categorySpecMap = <?php 
    $map = [];
    foreach ($categories as $cat) {
        $map[$cat['category_id']] = $cat['allowed_specs'];
    }
    echo json_encode($map);
?>;

const specLabels = {
    product_number: 'Product Number',
    microprocessor: 'Microprocessor',
    chipset: 'Chipset',
    memory_standard: 'Memory, Standard',
    video_graphics: 'Video Graphics',
    hard_drive: 'Hard Drive',
    display: 'Display',
    details: 'Details'
};

function updateSpecFields() {
    const categorySelect = document.getElementById('category-id');
    const categoryId = parseInt(categorySelect.value);
    const allowedSpecs = categorySpecMap[categoryId] || ['product_number', 'details'];
    
    for (const [specKey, specLabel] of Object.entries(specLabels)) {
        const specDiv = document.querySelector(`[data-spec-field="${specKey}"]`);
        if (specDiv) {
            if (allowedSpecs.includes(specKey) || specKey === 'product_number' || specKey === 'details') {
                specDiv.classList.remove('hidden');
                // Ensure input is enabled and has proper name
                const input = specDiv.querySelector('input, textarea');
                if (input) {
                    input.name = specKey;
                    input.disabled = false;
                }
            } else {
                specDiv.classList.add('hidden');
                const input = specDiv.querySelector('input, textarea');
                if (input) {
                    input.name = '';
                    input.disabled = true;
                    input.value = '';
                }
            }
        }
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateSpecFields();
});

// ── Delete Modal ─────────────────────────────────────
function confirmDeleteItem(id, name) {
    document.getElementById('delete_item_id').value = id;
    document.getElementById('delete_item_name').textContent = name;
    document.getElementById('deleteItemModal').classList.remove('hidden');
}

function closeDeleteItemModal() {
    document.getElementById('deleteItemModal').classList.add('hidden');
}

// Close modal on backdrop click
window.addEventListener('click', function(e) {
    const modal = document.getElementById('deleteItemModal');
    if (e.target === modal) closeDeleteItemModal();
});

// Close on Escape
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDeleteItemModal();
});

// ── Auto-dismiss alerts ──────────────────────────────
document.querySelectorAll('.alert-anim').forEach(el => {
    setTimeout(() => {
        el.style.transition = 'opacity 0.5s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
    }, 4000);
});

// Image preview (optional enhancement)
const imageInput = document.getElementById('item-image');
if (imageInput) {
    imageInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(ev) {
                // You can add preview element here if desired
            };
            reader.readAsDataURL(file);
        }
    });
}
</script>

</body>
</html>