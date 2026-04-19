<?php
session_start();
require_once 'config/db.php';
require_once 'includes/sidebar.php';

define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_URL', 'uploads');

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

function ensureImageColumnExists(mysqli $conn): void {
    $result = $conn->query("SHOW COLUMNS FROM items LIKE 'image_path'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE items ADD COLUMN image_path VARCHAR(255) DEFAULT NULL");
    }
    if ($result) {
        $result->free();
    }
}

ensureImageColumnExists($conn);

function ensureItemActiveColumnExists(mysqli $conn): void {
    $result = $conn->query("SHOW COLUMNS FROM items LIKE 'is_active'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE items ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    }
    if ($result) {
        $result->free();
    }
}

ensureItemActiveColumnExists($conn);

function saveUploadedImage(array $file, ?string &$error): ?string {
    $error = null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $error = 'Image upload failed. Please try again.';
        return null;
    }

    $validTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $type = $finfo->file($file['tmp_name']);

    if (!isset($validTypes[$type])) {
        $error = 'Only JPG, PNG, GIF, and WEBP images are allowed.';
        return null;
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        $error = 'Image file must be 5MB or smaller.';
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

// Protect page - must be logged in
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
        if ($line === '') {
            continue;
        }
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
        'details' => '',
    ];
}

function getAllowedSpecKeysByCategory(string $categoryName): array {
    $normalized = strtolower(trim($categoryName));
    $allSpecs = array_keys(getDefaultSpecValues());

    if ($normalized === '') {
        return $allSpecs;
    }

    if (textContains($normalized, 'ram') || $normalized === 'memory') {
        return ['memory_standard'];
    }

    if (textContains($normalized, 'laptop')) {
        return ['microprocessor', 'memory_standard', 'video_graphics', 'hard_drive'];
    }

    if (textContains($normalized, 'storage') || textContains($normalized, 'ssd') || textContains($normalized, 'hdd')) {
        return ['hard_drive'];
    }

    if (textContains($normalized, 'gpu') || textContains($normalized, 'graphics')) {
        return ['video_graphics'];
    }

    if (textContains($normalized, 'cpu') || textContains($normalized, 'processor')) {
        return ['microprocessor', 'chipset'];
    }

    return $allSpecs;
}

function textContains(string $haystack, string $needle): bool {
    return $needle !== '' && strpos($haystack, $needle) !== false;
}

function decodeCategorySpecFields(?string $specFields): array {
    if ($specFields === null || trim($specFields) === '') {
        return array_keys(getDefaultSpecValues());
    }

    $decoded = json_decode($specFields, true);
    if (!is_array($decoded)) {
        return array_keys(getDefaultSpecValues());
    }

    $allowed = array_intersect(array_keys(getDefaultSpecValues()), $decoded);
    return array_values($allowed);
}

function buildItemDescription(array $specValues, array $allowedSpecKeys): string {
    $lines = [];

    $labels = [
        'product_number' => 'Product number',
        'microprocessor' => 'Microprocessor',
        'chipset' => 'Chipset',
        'memory_standard' => 'Memory, standard',
        'video_graphics' => 'Video graphics',
        'hard_drive' => 'Hard drive',
        'display' => 'Display',
        'details' => 'Details',
    ];

    foreach ($labels as $key => $label) {
        if (!in_array($key, $allowedSpecKeys, true)) {
            continue;
        }
        $value = trim((string)($specValues[$key] ?? ''));
        if ($value !== '') {
            $lines[] = "{$label}: {$value}";
        }
    }

    return implode("\n", $lines);
}

// ── CREATE ITEM ──────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $item_name = trim($_POST['item_name']);
    $category_id = (int)$_POST['category_id'];
    $price = (float)$_POST['price'];
    $stock = (int)$_POST['stock'];
    $specValues = getDefaultSpecValues();
    foreach ($specValues as $key => $value) {
      $specValues[$key] = trim($_POST[$key] ?? '');
    }

    $image_path = null;

    if (isset($_FILES['image'])) {
        $image_path = saveUploadedImage($_FILES['image'], $uploadError);
        if (!empty($uploadError)) {
            $error = $uploadError;
        }
    }

    $categoryName = '';
    if ($category_id > 0) {
        $categoryStmt = $conn->prepare("SELECT category_name FROM categories WHERE category_id = ?");
        if ($categoryStmt) {
            $categoryStmt->bind_param("i", $category_id);
            $categoryStmt->execute();
            $categoryName = $categoryStmt->get_result()->fetch_assoc()['category_name'] ?? '';
            $categoryStmt->close();
        }
    }
    $allowedSpecKeys = getAllowedSpecKeysByCategory($categoryName);
    $description = buildItemDescription($specValues, $allowedSpecKeys);


    if (empty($item_name) || $price <= 0 || $stock < 0) {
        $error = "Please fill in all required fields with valid values.";
    } elseif (empty($error)) {
        // Check if item name already exists
        $check = $conn->prepare("SELECT item_id FROM items WHERE item_name = ?");
        $check->bind_param("s", $item_name);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "An item with this name already exists.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO items (item_name, category_id, price, stock, description, image_path)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            if ($stmt === false) {
                $error = 'Database error: ' . $conn->error;
            } else {
                $stmt->bind_param("siddss", $item_name, $category_id, $price, $stock, $description, $image_path);
                if ($stmt->execute()) {
                    $success = "Item '{$item_name}' created successfully!";
                } else {
                    $error = "Failed to create item. Please try again.";
                }
                $stmt->close();
            }
        }
        $check->close();
    }
}

// ── EDIT ITEM ────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $item_id = (int)$_POST['item_id'];
    $item_name = trim($_POST['item_name']);
    $category_id = (int)$_POST['category_id'];
    $price = (float)$_POST['price'];
    $stock = (int)$_POST['stock'];
    $specValues = getDefaultSpecValues();
    foreach ($specValues as $key => $value) {
        $specValues[$key] = trim($_POST[$key] ?? '');
    }

    $current_image = trim($_POST['current_image'] ?? '');
    $image_path = $current_image;

    if (isset($_FILES['image'])) {
        $new_upload = saveUploadedImage($_FILES['image'], $uploadError);
        if (!empty($uploadError)) {
            $error = $uploadError;
        } elseif ($new_upload !== null) {
            $image_path = $new_upload;
            if (!empty($current_image)) {
                $oldFile = __DIR__ . '/' . $current_image;
                if (file_exists($oldFile)) {
                    @unlink($oldFile);
                }
            }
        }
    }

     $categoryName = '';
    if ($category_id > 0) {
        $categoryStmt = $conn->prepare("SELECT category_name FROM categories WHERE category_id = ?");
        if ($categoryStmt) {
            $categoryStmt->bind_param("i", $category_id);
            $categoryStmt->execute();
            $categoryName = $categoryStmt->get_result()->fetch_assoc()['category_name'] ?? '';
            $categoryStmt->close();
        }
    }
    $allowedSpecKeys = getAllowedSpecKeysByCategory($categoryName);
    $description = buildItemDescription($specValues, $allowedSpecKeys);


    if (empty($item_name) || $price <= 0 || $stock < 0) {
        $error = "Please fill in all required fields with valid values.";
    } elseif (empty($error)) {
        // Check if name conflicts with other items
        $check = $conn->prepare("SELECT item_id FROM items WHERE item_name = ? AND item_id != ?");
        $check->bind_param("si", $item_name, $item_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Another item with this name already exists.";
        } else {
            $stmt = $conn->prepare("
                UPDATE items
                SET item_name = ?, category_id = ?, price = ?, stock = ?, description = ?, image_path = ?
                WHERE item_id = ?
            ");
            if ($stmt === false) {
                $error = 'Database error: ' . $conn->error;
            } else {
                $stmt->bind_param("siddssi", $item_name, $category_id, $price, $stock, $description, $image_path, $item_id);
                if ($stmt->execute()) {
                    $success = "Item '{$item_name}' updated successfully!";
                } else {
                    $error = "Failed to update item. Please try again.";
                }
                $stmt->close();
            }
        }
        $check->close();
    }
}

// ── DELETE ITEM ──────────────────────────────────────
if (isset($_GET['delete'])) {
    $item_id = (int)$_GET['delete'];

    // Check if item has been sold (exists in sale_items)
    $check_sales = $conn->prepare("SELECT COUNT(*) as count FROM sale_items WHERE item_id = ?");
    $check_sales->bind_param("i", $item_id);
    $check_sales->execute();
    $sales_count = $check_sales->get_result()->fetch_assoc()['count'];
    $check_sales->close();

    if ($sales_count > 0) {
        $deactivate = $conn->prepare("UPDATE items SET is_active = 0 WHERE item_id = ?");
        if ($deactivate) {
            $deactivate->bind_param("i", $item_id);
            if ($deactivate->execute() && $deactivate->affected_rows > 0) {
                $success = "Item has sales history, so it was deactivated instead of deleted.";
            } else {
                $error = "Item is already inactive or could not be deactivated.";
            }
            $deactivate->close();
        } else {
            $error = "Cannot deactivate item right now. Please try again.";
        }
    } else {
        $image_query = $conn->prepare("SELECT image_path FROM items WHERE item_id = ?");
        $image_query->bind_param("i", $item_id);
        $image_query->execute();
        $current_image = $image_query->get_result()->fetch_assoc()['image_path'] ?? '';
        $image_query->close();

        $stmt = $conn->prepare("DELETE FROM items WHERE item_id = ?");
        $stmt->bind_param("i", $item_id);

        if ($stmt->execute()) {
            if (!empty($current_image)) {
                $oldFile = __DIR__ . '/' . $current_image;
                if (file_exists($oldFile)) {
                    @unlink($oldFile);
                }
            }
            $success = "Item deleted successfully!";
        } else {
            $error = "Failed to delete item.";
        }
        $stmt->close();
    }
}

// ── GET CATEGORIES FOR DROPDOWN ──────────────────────
$categories_result = $conn->query("SELECT category_id, category_name, spec_fields FROM categories ORDER BY category_name");
$categories = [];
if ($categories_result) {
    while ($row = $categories_result->fetch_assoc()) {
        $row['allowed_specs'] = decodeCategorySpecFields($row['spec_fields'] ?? null);
        $categories[] = $row;
    }
}


// Low stock count
$q_low_stock = $conn->query("SELECT COUNT(*) AS total FROM items WHERE stock <= 5 AND is_active = 1");
$low_stock_count = $q_low_stock ? $q_low_stock->fetch_assoc()['total'] : 0;

// ── GET ITEMS WITH PAGINATION ────────────────────────
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;

$where_clauses = [];

if ($search !== '') {
    $escaped_search = $conn->real_escape_string($search);
    $where_clauses[] = "(i.item_name LIKE '%$escaped_search%' OR i.description LIKE '%$escaped_search%')";
}

if ($category_filter > 0) {
    $where_clauses[] = "i.category_id = $category_filter";
}
$where_clauses[] = "i.is_active = 1";

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$count_query = "SELECT COUNT(*) as total FROM items i $where_sql";
$count_result = $conn->query($count_query);
$total_items = $count_result ? $count_result->fetch_assoc()['total'] : 0;

$total_pages = ceil($total_items / $per_page);

$query = "
    SELECT i.item_id, i.item_name, i.category_id, i.price, i.stock, i.description, i.image_path, i.is_active, i.created_at,
           c.category_name
    FROM items i
    JOIN categories c ON i.category_id = c.category_id
    $where_sql
    ORDER BY i.created_at DESC
    LIMIT $per_page OFFSET $offset
";

$items_result = $conn->query($query);
if ($items_result === false) {
    error_log('Items query failed: ' . $conn->error);
    $items_result = $conn->query("SELECT i.item_id, i.item_name, i.price, i.stock, i.description, i.image_path, i.is_active, i.created_at, c.category_name FROM items i JOIN categories c ON i.category_id = c.category_id WHERE i.is_active = 1 ORDER BY i.created_at DESC LIMIT $per_page OFFSET $offset");
}
if ($items_result === false) {
    $items_result = new class {
        public function fetch_assoc() { return false; }
    };
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
    /* Remove number input spinner controls */
    input[type=number]::-webkit-outer-spin-button,
    input[type=number]::-webkit-inner-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    input[type=number] {
      -moz-appearance: textfield;
      appearance: textfield;
    }
    /* Soften card hover and elevate form panels */
    .card-hover:hover {
      transform: translateY(-2px);
      box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25);
    }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

<!-- ── SIDEBAR ── -->
<div class="flex min-h-screen">
  <?php renderSidebar('items'); ?>

  <!-- ── MAIN CONTENT ── -->
  <main id="app-main" class="flex-1 w-full overflow-y-auto p-4 sm:p-6 lg:p-8 md:ml-64 bg-slate-950/20">

    <div class="mb-4 flex items-center justify-between gap-3 md:hidden">
      <button type="button" id="open-sidebar" class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium text-slate-200">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
        Menu
      </button>
      <a href="logout.php" class="inline-flex items-center gap-2 rounded-xl border border-red-700/50 bg-red-900/20 px-3 py-2 text-sm text-red-200">Logout</a>
    </div>

    <!-- Header -->
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-slate-100">Items Management</h1>
      <p class="text-sm text-slate-400 mt-1">
        Manage your inventory items and stock levels
      </p>
    </div>

    <div class="grid gap-4 xl:grid-cols-3 mb-6">
      <div class="rounded-3xl border border-slate-800 bg-slate-900/95 p-5 shadow-xl shadow-black/20">
        <p class="text-sm uppercase tracking-[0.2em] text-slate-400">Total items</p>
        <p class="mt-3 text-3xl font-semibold text-slate-100"><?= number_format($total_items) ?></p>
      </div>
      <div class="rounded-3xl border border-slate-800 bg-slate-900/95 p-5 shadow-xl shadow-black/20">
        <p class="text-sm uppercase tracking-[0.2em] text-slate-400">Current page</p>
        <p class="mt-3 text-3xl font-semibold text-slate-100"><?= $page ?> / <?= max($total_pages, 1) ?></p>
      </div>
      <div class="rounded-3xl border border-slate-800 bg-slate-900/95 p-5 shadow-xl shadow-black/20">
        <p class="text-sm uppercase tracking-[0.2em] text-slate-400">Low stock</p>
        <p class="mt-3 text-3xl font-semibold text-amber-300"><?= number_format($low_stock_count) ?></p>
      </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($success): ?>
    <div class="mb-4 p-4 bg-green-900/20 border border-green-700 rounded-xl">
      <p class="text-green-400 text-sm"><?= $success ?></p>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="mb-4 p-4 bg-red-900/20 border border-red-700 rounded-xl">
      <p class="text-red-400 text-sm"><?= $error ?></p>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

      <!-- ── CREATE/EDIT FORM ── -->
      <div class="lg:col-span-1">
        <div class="bg-slate-900/95 rounded-3xl border border-slate-800 shadow-2xl shadow-black/20 p-7 lg:sticky lg:top-6">

          <h2 class="text-lg font-bold text-slate-100 mb-4" id="form-title">Add New Item</h2>

          <form method="POST" enctype="multipart/form-data" id="item-form">
            <input type="hidden" name="action" value="create" id="form-action">
            <input type="hidden" name="item_id" id="item-id">

            <div class="space-y-4">

              <!-- Item Name -->
              <div>
                <label class="block text-sm font-medium text-slate-300 mb-1">Item Name *</label>
                <input type="text" name="item_name" id="item-name" required
                       class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
              </div>

              <!-- Category -->
              <div>
                <label class="block text-sm font-medium text-slate-300 mb-1">Category *</label>
                <select name="category_id" id="category-id" required
                        class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                  <option value="">Select Category</option>
                 <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['category_id'] ?>" <?= (int)($cat['category_id']) === (int)($_POST['category_id'] ?? 0) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($cat['category_name']) ?>
                    </option>
                  <?php endforeach; ?>

                </select>
              </div>

              <!-- Price -->
              <div>
                <label class="block text-sm font-medium text-slate-300 mb-1">Price (₱) *</label>
                <input type="number" name="price" id="item-price" step="0.01" min="0" required
                       class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
              </div>

              <!-- Stock -->
              <div>
                <label class="block text-sm font-medium text-slate-300 mb-1">Stock Quantity *</label>
                <input type="number" name="stock" id="item-stock" min="0" required
                       value="<?= htmlspecialchars($_POST['stock'] ?? '') ?>"
                       class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
              </div>

              <!-- Product Image -->
              <div>
                <label class="block text-sm font-medium text-slate-300 mb-1">Product Image</label>
                <input type="file" name="image" id="item-image" accept="image/*"
                       required
                       class="w-full text-slate-100 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-600 file:text-white bg-slate-800 border border-slate-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500">
                <input type="hidden" name="current_image" id="current-image" value="">
              </div>

              <!-- Specs -->
              <div class="grid gap-4">
                 <div data-spec-field="product_number">
                  <label class="block text-sm font-medium text-slate-300 mb-1">Product Number</label>
                  <input type="text" name="product_number" id="product-number"
                         required
                         value="<?= htmlspecialchars($_POST['product_number'] ?? '') ?>"
                         class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div data-spec-field="microprocessor">
                  <label class="block text-sm font-medium text-slate-300 mb-1">Microprocessor</label>
                  <input type="text" name="microprocessor" id="microprocessor"
                         value="<?= htmlspecialchars($_POST['microprocessor'] ?? '') ?>"
                         class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div data-spec-field="chipset">
                  <label class="block text-sm font-medium text-slate-300 mb-1">Chipset</label>
                  <input type="text" name="chipset" id="chipset"
                         value="<?= htmlspecialchars($_POST['chipset'] ?? '') ?>"
                         class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div data-spec-field="memory_standard">
                  <label class="block text-sm font-medium text-slate-300 mb-1">Memory</label>
                  <input type="text" name="memory_standard" id="memory-standard"
                         value="<?= htmlspecialchars($_POST['memory_standard'] ?? '') ?>"
                         class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div data-spec-field="video_graphics">
                  <label class="block text-sm font-medium text-slate-300 mb-1">Video Graphics</label>
                  <input type="text" name="video_graphics" id="video-graphics"
                         value="<?= htmlspecialchars($_POST['video_graphics'] ?? '') ?>"
                         class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                 <div data-spec-field="hard_drive">
                  <label class="block text-sm font-medium text-slate-300 mb-1">Hard Drive</label>
                  <input type="text" name="hard_drive" id="hard-drive"
                         value="<?= htmlspecialchars($_POST['hard_drive'] ?? '') ?>"
                         class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                 <div data-spec-field="display">
                  <label class="block text-sm font-medium text-slate-300 mb-1">Display</label>
                  <input type="text" name="display" id="display"
                         value="<?= htmlspecialchars($_POST['display'] ?? '') ?>"
                         class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
              </div>

              <!-- Details -->
              <div data-spec-field="details">
                <label class="block text-sm font-medium text-slate-300 mb-1">Additional Details</label>
                <textarea name="details" id="item-description" rows="5"
                          required
                          class="w-full min-h-[140px] px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500 resize-y"><?= htmlspecialchars($_POST['details'] ?? '') ?></textarea>
              </div>

              <!-- Buttons -->
              <div class="flex gap-2 pt-2">
                <button type="submit"
                        class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-xl text-white font-medium transition">
                  <span id="submit-text">Add Item</span>
                </button>
                <button type="button" onclick="resetForm()"
                        class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-xl text-slate-300 font-medium transition">
                  Reset
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- ── ITEMS LIST ── -->
      <div class="lg:col-span-3">

        <!-- Filters -->
        <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20 mb-6">
          <div class="p-6">
            <form method="GET" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:gap-4">
              <div class="w-full sm:flex-1 sm:min-w-64">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Search items..."
                       class="w-full px-4 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
              </div>
              <div class="w-full sm:min-w-48">
                <select name="category" class="w-full px-4 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                  <option value="0">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['category_id'] ?>" <?= $category_filter == $cat['category_id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($cat['category_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit"
                      class="w-full sm:w-auto px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded-xl text-white font-medium transition">
                Filter
              </button>
              <a href="items.php"
                 class="w-full text-center sm:w-auto px-6 py-2 bg-slate-700 hover:bg-slate-600 rounded-xl text-slate-300 font-medium transition">
                Clear
              </a>
            </form>
          </div>
        </div>

        <!-- Product Grid -->
        <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20 overflow-hidden">

          <div class="px-6 py-4 border-b border-slate-800">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
              <div>
                <h2 class="text-lg font-bold text-slate-100">Items (<?= $total_items ?>)</h2>
                <p class="text-sm text-slate-400">Showing <?= min((($page - 1) * $per_page) + 1, $total_items) ?> to <?= min($page * $per_page, $total_items) ?> of <?= $total_items ?> items</p>
              </div>
              <div class="flex items-center gap-2">
                <button type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-slate-800 text-slate-200 hover:bg-slate-700 transition">
                  <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M4 5h3V3H4a1 1 0 00-1 1v3h2V5zm4 0h3V3H8v2zm4 0h3V3h-3v2zM3 9h3V7H3v2zm4 0h3V7H7v2zm4 0h3V7h-3v2zM3 13h3v-2H3v2zm4 0h3v-2H7v2zm4 0h3v-2h-3v2z"/>
                  </svg>
                  Grid view
                </button>
              </div>
            </div>
          </div>

          <div class="p-4 sm:p-6 grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
            <?php while ($item = $items_result->fetch_assoc()): ?>
            <article class="group flex h-full flex-col overflow-hidden rounded-3xl border border-slate-800 bg-slate-950 shadow-xl transition hover:-translate-y-1 hover:shadow-2xl">
              <div class="relative h-56 bg-slate-900">
                <img src="<?= htmlspecialchars($item['image_path'] ?: 'https://via.placeholder.com/450x320?text=No+Image') ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" class="h-full w-full object-cover object-center opacity-95" />
                <span class="absolute left-4 top-4 rounded-full bg-blue-600/90 px-3 py-1 text-xs font-semibold text-white">
                  <?= htmlspecialchars($item['category_name']) ?>
                </span>
              </div>
              <div class="flex flex-1 flex-col p-5">
                <h3 class="text-base font-semibold text-slate-100 mb-3 leading-tight">
                  <?= htmlspecialchars($item['item_name']) ?>
                </h3>
                <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-4 mb-4 flex-1 min-h-0 text-sm text-slate-200">
                  <div class="mb-3 text-xs uppercase tracking-[0.18em] text-slate-400">Description</div>
                  <?php $specs = parseSpecLines($item['description']); ?>
                  <?php if (!empty($specs)): ?>
                    <dl class="space-y-2 pr-1">
                      <?php foreach ($specs as $label => $value): ?>
                        <?php if (is_int($label)): ?>
                          <div class="rounded-xl bg-slate-950/80 px-3 py-2 text-slate-400 break-words">
                            <?= htmlspecialchars($value) ?>
                          </div>
                        <?php else: ?>
                          <div class="grid gap-2 rounded-xl bg-slate-950/80 px-3 py-2 md:grid-cols-[140px_minmax(0,1fr)] break-words">
                            <dt class="font-medium text-slate-300 break-words"><?= htmlspecialchars($label) ?></dt>
                            <dd class="text-slate-400 break-words"><?= htmlspecialchars($value) ?></dd>
                          </div>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </dl>
                  <?php else: ?>
                    <p class="text-slate-500">No description available.</p>
                  <?php endif; ?>
                </div>
                <div class="mt-auto flex items-center justify-between gap-3">
                  <div>
                    <p class="text-xl font-semibold text-slate-100">₱<?= number_format($item['price'], 2) ?></p>
                    <p class="text-xs text-slate-400">Stock: <?= $item['stock'] ?></p>
                  </div>
                  <div class="flex items-center gap-2 text-slate-400">
                    <?php $itemData = htmlspecialchars(json_encode([
                        'id' => $item['item_id'],
                        'image' => $item['image_path'] ?? '',
                        'name' => $item['item_name'],
                        'category_id' => $item['category_id'],
                        'price' => $item['price'],
                        'stock' => $item['stock'],
                        'product_number' => $specs['Product number'] ?? '',
                        'microprocessor' => $specs['Microprocessor'] ?? '',
                        'chipset' => $specs['Chipset'] ?? '',
                        'memory_standard' => $specs['Memory, standard'] ?? '',
                        'video_graphics' => $specs['Video graphics'] ?? '',
                        'hard_drive' => $specs['Hard drive'] ?? '',
                        'display' => $specs['Display'] ?? '',
                        'details' => $specs['Details'] ?? '',
                    ]), ENT_QUOTES, 'UTF-8'); ?>
                    <button data-item='<?= $itemData ?>' onclick="openEditItem(this)"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-800 transition hover:bg-blue-600 hover:text-white">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                      </svg>
                    </button>
                    <a href="?delete=<?= $item['item_id'] ?>" onclick="return confirm('Are you sure you want to delete this item?')"
                       class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-800 transition hover:bg-red-500 hover:text-white">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                      </svg>
                    </a>
                  </div>
                </div>
              </div>
            </article>
            <?php endwhile; ?>
          </div>

          <?php if ($total_pages > 1): ?>
          <div class="px-6 py-4 border-t border-slate-800">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div class="text-sm text-slate-400">
                Showing <?= (($page - 1) * $per_page) + 1 ?> to <?= min($page * $per_page, $total_items) ?> of <?= $total_items ?> items
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&category=<?= $category_filter ?>"
                   class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded text-sm text-slate-300 transition">
                  Previous
                </a>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= $category_filter ?>"
                   class="px-3 py-1 rounded text-sm transition <?= $i == $page ? 'bg-blue-600 text-white' : 'bg-slate-700 hover:bg-slate-600 text-slate-300' ?>">
                  <?= $i ?>
                </a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&category=<?= $category_filter ?>"
                   class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded text-sm text-slate-300 transition">
                  Next
                </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
<?php require_once 'includes/sidebar_script.php'; ?>

document.getElementById('category-id').addEventListener('change', applyCategorySpecVisibility);


function openEditItem(button) {
  const item = JSON.parse(button.dataset.item);

  document.getElementById('form-title').textContent = 'Edit Item';
  document.getElementById('form-action').value = 'edit';
  document.getElementById('item-id').value = item.id;
  document.getElementById('item-name').value = item.name;
  document.getElementById('category-id').value = item.category_id;
  document.getElementById('item-price').value = item.price;
  document.getElementById('item-stock').value = item.stock;
  document.getElementById('current-image').value = item.image || '';
  document.getElementById('item-image').required = !(item.image && item.image.trim() !== '');
  document.getElementById('product-number').value = item.product_number;
  document.getElementById('microprocessor').value = item.microprocessor;
  document.getElementById('chipset').value = item.chipset;
  document.getElementById('memory-standard').value = item.memory_standard;
  document.getElementById('video-graphics').value = item.video_graphics;
  document.getElementById('hard-drive').value = item.hard_drive;
  document.getElementById('display').value = item.display;
  document.getElementById('item-description').value = item.details;
  document.getElementById('submit-text').textContent = 'Update Item';
  applyCategorySpecVisibility();

  // Scroll to form
  document.querySelector('.lg\\:col-span-1').scrollIntoView({ behavior: 'smooth' });
}

function resetForm() {
  document.getElementById('form-title').textContent = 'Add New Item';
  document.getElementById('form-action').value = 'create';
  document.getElementById('item-id').value = '';
  document.getElementById('item-name').value = '';
  document.getElementById('category-id').value = '';
  document.getElementById('item-price').value = '';
  document.getElementById('item-stock').value = '';
  document.getElementById('product-number').value = '';
  document.getElementById('microprocessor').value = '';
  document.getElementById('chipset').value = '';
  document.getElementById('memory-standard').value = '';
  document.getElementById('video-graphics').value = '';
  document.getElementById('hard-drive').value = '';
  document.getElementById('display').value = '';
  document.getElementById('current-image').value = '';
  document.getElementById('item-image').required = true;
  document.getElementById('item-description').value = '';
  document.getElementById('submit-text').textContent = 'Add Item';
  applyCategorySpecVisibility();
}
applyCategorySpecVisibility();
</script>

</body>
</html>
