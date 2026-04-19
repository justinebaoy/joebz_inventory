<?php
session_start();
require_once 'config/db.php';

// Protect page - must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$success = '';
$error   = '';
$modal_to_open = '';

// ── CREATE CATEGORY ────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $category_name = trim($_POST['category_name']);

    if (empty($category_name)) {
        $error = "Category name is required.";
    } elseif (strlen($category_name) > 100) {
        $error = "Category name must not exceed 100 characters.";
    } else {
        // Check if already exists
        $check = $conn->prepare(
            "SELECT category_id FROM categories
             WHERE category_name = ?"
        );
        $check->bind_param("s", $category_name);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Category '{$category_name}' already exists.";
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO categories (category_name) VALUES (?)"
            );
            $stmt->bind_param("s", $category_name);
            if ($stmt->execute()) {
                $success = "Category '{$category_name}' created successfully!";
            } else {
                $error = "Failed to create category. Please try again.";
            }
            $stmt->close();
        }
        if ($error) {
            $modal_to_open = 'createModal';
        }
        $check->close();
    }
}

// ── EDIT CATEGORY ──────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $category_id   = (int)$_POST['category_id'];
    $category_name = trim($_POST['category_name']);

    if (empty($category_name)) {
        $error = "Category name is required.";
    } elseif (strlen($category_name) > 100) {
        $error = "Category name must not exceed 100 characters.";
    } else {
        // Check duplicate excluding current
        $check = $conn->prepare(
            "SELECT category_id FROM categories
             WHERE category_name = ? AND category_id != ?"
        );
        $check->bind_param("si", $category_name, $category_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Category '{$category_name}' already exists.";
        } else {
            $stmt = $conn->prepare(
                "UPDATE categories SET category_name = ?
                 WHERE category_id = ?"
            );
            $stmt->bind_param("si", $category_name, $category_id);
            if ($stmt->execute()) {
                $success = "Category updated successfully!";
            } else {
                $error = "Failed to update category.";
            }
            $stmt->close();
        }
        if ($error) {
            $modal_to_open = 'editModal';
        }
        $check->close();
    }
}

// ── DELETE CATEGORY ────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $category_id = (int)$_POST['category_id'];

    // Check if category has items
    $check = $conn->prepare(
        "SELECT COUNT(*) AS total FROM items
         WHERE category_id = ?"
    );
    $check->bind_param("i", $category_id);
    $check->execute();
    $result = $check->get_result()->fetch_assoc();

    if ($result['total'] > 0) {
        $error = "Cannot delete — this category has {$result['total']} item(s) linked to it. Remove or reassign those items first.";
    } else {
        $stmt = $conn->prepare(
            "DELETE FROM categories WHERE category_id = ?"
        );
        $stmt->bind_param("i", $category_id);
        if ($stmt->execute()) {
            $success = "Category deleted successfully.";
        } else {
            $error = "Failed to delete category.";
        }
        $stmt->close();
    }
    $check->close();
}

// ── SEARCH ─────────────────────────────────────────────
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare(
        "SELECT c.category_id, c.category_name, c.created_at,
                COUNT(i.item_id) AS item_count
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
        "SELECT c.category_id, c.category_name, c.created_at,
                COUNT(i.item_id) AS item_count
         FROM categories c
         LEFT JOIN items i ON c.category_id = i.category_id
         GROUP BY c.category_id
         ORDER BY c.created_at DESC"
    );
}

// Total categories count
$total_cats = $conn->query(
    "SELECT COUNT(*) AS total FROM categories"
)->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Categories — JOEBZ Inventory</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

<div class="flex h-screen overflow-hidden">

  <!-- ── SIDEBAR ── -->
  <aside class="w-64 bg-slate-950 border-r border-slate-800 flex flex-col fixed h-full z-10">
    <div class="flex items-center gap-3 px-6 py-5 border-b border-slate-800">
      <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center">
        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-bold text-slate-100">JOEBZ</p>
        <p class="text-xs text-slate-400">Inventory System</p>
      </div>
    </div>
    <nav class="flex-1 px-4 py-4 space-y-1 overflow-y-auto">
      <a href="dashboard.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300
                hover:bg-slate-800 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10
               a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4
               a1 1 0 001 1m-6 0h6"/>
        </svg>
        Dashboard
      </a>
      <a href="sales.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300
                hover:bg-slate-800 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01
               M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7
               a2 2 0 00-2 2v14a2 2 0 002 2z"/>
        </svg>
        Sales / POS
      </a>
      <a href="items.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300
                hover:bg-slate-800 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>
        </svg>
        Items
      </a>
      <a href="categories.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-blue-600/20
                text-blue-200 font-medium text-sm">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828
               l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
        </svg>
        Categories
      </a>
      <?php if ($_SESSION['role'] === 'admin'): ?>
      <a href="reports.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300
                hover:bg-slate-800 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0
               002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2
               a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2
               a2 2 0 01-2-2z"/>
        </svg>
        Reports
      </a>
      <a href="users.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300
                hover:bg-slate-800 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0
               0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
        </svg>
        Users
      </a>
      <?php endif; ?>
    </nav>
    <div class="px-4 py-4 border-t border-slate-800">
      <div class="flex items-center gap-3 px-3 py-2 rounded-xl bg-slate-900 mb-2">
        <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center
                    justify-center text-white text-xs font-bold">
          <?= strtoupper(substr($_SESSION['first_name'], 0, 1)) ?>
        </div>
        <div>
          <p class="text-sm font-medium text-slate-100">
            <?= htmlspecialchars($_SESSION['first_name']) ?>
          </p>
          <p class="text-xs text-slate-400 capitalize"><?= $_SESSION['role'] ?></p>
        </div>
      </div>
      <a href="logout.php"
         class="flex items-center gap-3 px-3 py-2 rounded-xl text-red-300
                hover:bg-red-800 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0
               01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
        </svg>
        Logout
      </a>
    </div>
  </aside>

  <!-- ── MAIN CONTENT ── -->
  <main class="ml-64 flex-1 overflow-y-auto p-6">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-slate-100">Categories</h1>
        <p class="text-sm text-slate-400 mt-1">
          Manage your product categories —
          <span class="font-medium text-slate-100"><?= $total_cats ?></span> total
        </p>
      </div>
      <button onclick="openModal('createModal')"
              class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700
                     text-white text-sm font-medium px-4 py-2.5 rounded-xl transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 4v16m8-8H4"/>
        </svg>
        Add Category
      </button>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
      <div class="flex items-center gap-3 bg-emerald-900/40 border border-emerald-700
                  text-emerald-200 rounded-xl px-4 py-3 mb-5 text-sm">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="flex items-center gap-3 bg-red-900/40 border border-red-700
                  text-red-200 rounded-xl px-4 py-3 mb-5 text-sm">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <!-- Search Bar -->
    <div class="mb-5">
      <form method="GET" action="">
        <div class="relative max-w-sm">
          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
          </div>
          <input type="text" name="search"
                 placeholder="Search categories..."
                 value="<?= htmlspecialchars($search) ?>"
                 class="w-full pl-10 pr-4 py-2.5 border border-slate-700 rounded-xl
                        text-sm text-slate-100 bg-slate-950 focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
          <?php if ($search): ?>
            <a href="categories.php"
               class="absolute inset-y-0 right-0 pr-3 flex items-center
                      text-slate-400 hover:text-slate-100">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Categories Grid -->
    <?php if ($categories->num_rows === 0): ?>
      <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl
                  flex flex-col items-center justify-center py-16">
        <div class="w-16 h-16 bg-slate-800 rounded-2xl flex items-center
                    justify-center mb-4">
          <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828
                 l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
          </svg>
        </div>
        <p class="text-slate-400 font-medium">No categories found</p>
        <p class="text-slate-400 text-sm mt-1">
          <?= $search ? 'Try a different search term.' : 'Click "Add Category" to get started.' ?>
        </p>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php while ($cat = $categories->fetch_assoc()): ?>
          <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl
                      hover:shadow-slate-800 transition p-5">
            <div class="flex items-start justify-between">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center">
                  <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0
                         010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0
                         013 12V7a4 4 0 014-4z"/>
                  </svg>
                </div>
                <div>
                  <h3 class="font-semibold text-slate-100 text-sm">
                    <?= htmlspecialchars($cat['category_name']) ?>
                  </h3>
                  <p class="text-xs text-slate-400 mt-0.5">
                    <?= date('M d, Y', strtotime($cat['created_at'])) ?>
                  </p>
                </div>
              </div>
              <!-- Action buttons -->
              <div class="flex items-center gap-1">
                <button onclick="openEdit(
                           <?= $cat['category_id'] ?>,
                           '<?= htmlspecialchars($cat['category_name'], ENT_QUOTES) ?>'
                         )"
                        class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50 transition">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5
                         m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                  </svg>
                </button>
                <button onclick="confirmDelete(
                           <?= $cat['category_id'] ?>,
                           '<?= htmlspecialchars($cat['category_name'], ENT_QUOTES) ?>'
                         )"
                        class="p-1.5 rounded-lg text-red-400 hover:bg-red-50 transition">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0
                         01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4
                         a1 1 0 00-1 1v3M4 7h16"/>
                  </svg>
                </button>
              </div>
            </div>

            <!-- Item count badge -->
            <div class="mt-4 pt-4 border-t border-slate-800 flex items-center
                        justify-between">
              <span class="text-xs text-slate-400">Items in category</span>
              <span class="text-sm font-bold
                <?= $cat['item_count'] > 0
                    ? 'text-blue-600'
                    : 'text-slate-400' ?>">
                <?= $cat['item_count'] ?>
                <?= $cat['item_count'] == 1 ? 'item' : 'items' ?>
              </span>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    <?php endif; ?>

  </main>
</div>

<!-- ── CREATE MODAL ── -->
<div id="createModal"
     class="fixed inset-0 bg-black bg-opacity-40 flex items-center
            justify-center z-50 hidden">
  <div class="bg-slate-950 rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
    <div class="flex items-center justify-between mb-5">
      <h3 class="text-lg font-semibold text-slate-100">Add Category</h3>
      <button onclick="closeModal('createModal')"
              class="text-slate-400 hover:text-slate-100">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round"
                stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="create">
      <div class="mb-5">
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Category Name
        </label>
        <input type="text" name="category_name"
               placeholder="e.g. Processors, RAM, Storage..."
               maxlength="100"
               value="<?= htmlspecialchars($_POST['category_name'] ?? '') ?>"
               class="w-full border border-slate-700 rounded-xl bg-slate-950 px-4 py-3 text-sm text-slate-100
                      focus:outline-none focus:ring-2 focus:ring-blue-500 transition"
               required autofocus>
        <p class="text-xs text-slate-400 mt-1">Maximum 100 characters</p>
      </div>
      <div class="flex gap-3">
        <button type="button" onclick="closeModal('createModal')"
                class="flex-1 border border-slate-700 text-slate-200 rounded-xl
                       py-2.5 text-sm hover:bg-slate-800 transition">
          Cancel
        </button>
        <button type="submit"
                class="flex-1 bg-blue-600 hover:bg-blue-700 text-white
                       rounded-xl py-2.5 text-sm font-medium transition">
          Add Category
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── EDIT MODAL ── -->
<div id="editModal"
     class="fixed inset-0 bg-black bg-opacity-40 flex items-center
            justify-center z-50 hidden">
  <div class="bg-slate-950 rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
    <div class="flex items-center justify-between mb-5">
      <h3 class="text-lg font-semibold text-slate-100">Edit Category</h3>
      <button onclick="closeModal('editModal')"
              class="text-slate-400 hover:text-slate-100">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round"
                stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="category_id" id="edit_category_id" value="<?= (int)($_POST['category_id'] ?? 0) ?>">
      <div class="mb-5">
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Category Name
        </label>
        <input type="text" name="category_name"
               id="edit_category_name"
               maxlength="100"
               value="<?= htmlspecialchars($_POST['category_name'] ?? '') ?>"
               class="w-full border border-slate-700 rounded-xl px-4 py-3 text-sm
                      focus:outline-none focus:ring-2 focus:ring-blue-500 transition"
               required>
      </div>
      <div class="flex gap-3">
        <button type="button" onclick="closeModal('editModal')"
                class="flex-1 border border-slate-700 text-slate-200 rounded-xl
                       py-2.5 text-sm hover:bg-slate-800 transition">
          Cancel
        </button>
        <button type="submit"
                class="flex-1 bg-blue-600 hover:bg-blue-700 text-white
                       rounded-xl py-2.5 text-sm font-medium transition">
          Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── DELETE MODAL ── -->
<div id="deleteModal"
     class="fixed inset-0 bg-black bg-opacity-40 flex items-center
            justify-center z-50 hidden">
  <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl w-full max-w-sm mx-4 p-6 text-center">
    <div class="w-14 h-14 bg-red-100 rounded-full flex items-center
                justify-center mx-auto mb-4">
      <svg class="w-7 h-7 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0
             01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4
             a1 1 0 00-1 1v3M4 7h16"/>
      </svg>
    </div>
    <h3 class="text-lg font-semibold text-slate-100 mb-2">Delete Category?</h3>
    <p class="text-sm text-slate-400 mb-6">
      You are about to delete
      <strong id="delete_cat_name" class="text-slate-100"></strong>.
      This cannot be undone.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="category_id" id="delete_category_id">
      <div class="flex gap-3">
        <button type="button" onclick="closeModal('deleteModal')"
                class="flex-1 border border-slate-700 text-slate-200 rounded-xl
                       py-2.5 text-sm hover:bg-slate-800 transition">
          Cancel
        </button>
        <button type="submit"
                class="flex-1 bg-red-500 hover:bg-red-600 text-white
                       rounded-xl py-2.5 text-sm font-medium transition">
          Yes, Delete
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
  }
  function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
  }
  function openEdit(id, name) {
    document.getElementById('edit_category_id').value   = id;
    document.getElementById('edit_category_name').value = name;
    openModal('editModal');
  }
  function confirmDelete(id, name) {
    document.getElementById('delete_category_id').value    = id;
    document.getElementById('delete_cat_name').textContent = name;
    openModal('deleteModal');
  }
  // Close modal on outside click
  window.addEventListener('click', function(e) {
    ['createModal','editModal','deleteModal'].forEach(id => {
      const modal = document.getElementById(id);
      if (e.target === modal) closeModal(id);
    });
  });
  // Auto-submit search on typing
  const searchInput = document.querySelector('input[name="search"]');
  if (searchInput) {
    let timer;
    searchInput.addEventListener('input', function() {
      clearTimeout(timer);
      timer = setTimeout(() => this.closest('form').submit(), 400);
    });
  }
  <?php if (!empty($modal_to_open)): ?>
    openModal('<?= $modal_to_open ?>');
  <?php endif; ?>
</script>

</body>
</html>