<?php
session_start();
require_once 'config/db.php';

// Protect page - must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$success = '';
$error = '';

// ── ADD TO CART ───────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
    $item_id = (int)$_POST['item_id'];
    $quantity = (int)$_POST['quantity'];

    if ($quantity <= 0) {
        $error = "Quantity must be greater than 0.";
    } else {
        // Check if item exists and has enough stock
        $stmt = $conn->prepare("SELECT item_name, price, stock FROM items WHERE item_id = ?");
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $item = $result->fetch_assoc();
            $current_cart_qty = 0;

            if (isset($_SESSION['cart'])) {
                foreach ($_SESSION['cart'] as $cart_item) {
                    if ((int)$cart_item['item_id'] === $item_id) {
                        $current_cart_qty = (int)$cart_item['quantity'];
                        break;
                    }
                }
            }

            $requested_total_qty = $current_cart_qty + $quantity;

            if ($item['stock'] < $requested_total_qty) {
                $error = "Insufficient stock. Only {$item['stock']} available.";
            } else {
                // Initialize cart if not exists
                if (!isset($_SESSION['cart'])) {
                    $_SESSION['cart'] = [];
                }

                // Check if item already in cart
                $found = false;
                foreach ($_SESSION['cart'] as &$cart_item) {
                    if ($cart_item['item_id'] == $item_id) {
                        $cart_item['quantity'] += $quantity;
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $_SESSION['cart'][] = [
                        'item_id' => $item_id,
                        'item_name' => $item['item_name'],
                        'price' => $item['price'],
                        'quantity' => $quantity
                    ];
                }

                $success = "Item added to cart successfully!";
            }
        } else {
            $error = "Item not found.";
        }
        $stmt->close();
    }
}

// ── REMOVE FROM CART ──────────────────────────────────
if (isset($_GET['remove']) && isset($_SESSION['cart'])) {
    $remove_id = (int)$_GET['remove'];
    foreach ($_SESSION['cart'] as $key => $item) {
        if ($item['item_id'] == $remove_id) {
            unset($_SESSION['cart'][$key]);
            $_SESSION['cart'] = array_values($_SESSION['cart']); // Reindex
            break;
        }
    }
    header("Location: sales.php");
    exit;
}

// ── CLEAR CART ────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'clear_cart') {
    unset($_SESSION['cart']);
    $success = "Cart cleared successfully!";
}

// ── PROCESS SALE ──────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'process_sale') {
    if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
        $error = "Cart is empty. Add items before processing sale.";
    } else {
        $cash_received = (float)$_POST['cash_received'];

        $total_amount = 0;
        foreach ($_SESSION['cart'] as $item) {
            $total_amount += $item['price'] * $item['quantity'];
        }

        if ($cash_received < $total_amount) {
            $error = "Cash received (₱" . number_format($cash_received, 2) . ") is less than total amount (₱" . number_format($total_amount, 2) . ").";
        } else {
            $change_amount = $cash_received - $total_amount;

            $conn->begin_transaction();

            try {
                // Insert sale
                $stmt = $conn->prepare("INSERT INTO sales (user_id, total_amount, cash_received, change_amount, sale_date) VALUES (?, ?, ?, ?, NOW())");
                $stmt->bind_param("iddd", $_SESSION['user_id'], $total_amount, $cash_received, $change_amount);
                $stmt->execute();
                $sale_id = $conn->insert_id;
                $stmt->close();

                // Insert sale items and update stock
                foreach ($_SESSION['cart'] as $item) {
                    $stock_check = $conn->prepare("SELECT stock FROM items WHERE item_id = ? FOR UPDATE");
                    $stock_check->bind_param("i", $item['item_id']);
                    $stock_check->execute();
                    $stock_result = $stock_check->get_result();

                    if ($stock_result->num_rows !== 1) {
                        throw new Exception('Item no longer exists.');
                    }

                    $available_stock = (int)$stock_result->fetch_assoc()['stock'];
                    $stock_check->close();

                    if ($available_stock < (int)$item['quantity']) {
                        throw new Exception('Insufficient stock while processing sale.');
                    }

                    // Insert sale item (assuming sale_items table exists)
                    $stmt = $conn->prepare("INSERT INTO sale_items (sale_id, item_id, quantity, price) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("iiid", $sale_id, $item['item_id'], $item['quantity'], $item['price']);
                    $stmt->execute();
                    $stmt->close();

                    // Update stock
                    $stmt = $conn->prepare("UPDATE items SET stock = stock - ? WHERE item_id = ? AND stock >= ?");
                    $stmt->bind_param("iii", $item['quantity'], $item['item_id'], $item['quantity']);
                    $stmt->execute();
                    if ($stmt->affected_rows !== 1) {
                        $stmt->close();
                        throw new Exception('Stock update failed.');
                    }
                    $stmt->close();
                }

                $conn->commit();
                unset($_SESSION['cart']);
                $success = "Sale processed successfully! Sale ID: {$sale_id} | Change: ₱" . number_format($change_amount, 2);

            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to process sale. Please try again.";
            }
        }
    }
}

// ── GET ITEMS FOR SELECTION ───────────────────────────
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($search !== '') {
    $like = '%' . $conn->real_escape_string($search) . '%';
    $stmt = $conn->prepare("
        SELECT i.item_id, i.item_name, i.price, i.stock, c.category_name
        FROM items i
        JOIN categories c ON i.category_id = c.category_id
        WHERE i.stock > 0 AND (i.item_name LIKE ? OR c.category_name LIKE ?)
        ORDER BY i.item_name
    ");
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $items_result = $stmt->get_result();
    $stmt->close();
} else {
    $items_result = $conn->query("
        SELECT i.item_id, i.item_name, i.price, i.stock, c.category_name
        FROM items i
        JOIN categories c ON i.category_id = c.category_id
        WHERE i.stock > 0
        ORDER BY i.item_name
    ");
}

// Calculate cart total
$cart_total = 0;
$cart_items_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_total += $item['price'] * $item['quantity'];
        $cart_items_count += $item['quantity'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales / POS — JOEBZ Inventory</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

<!-- ── SIDEBAR ── -->
<div class="flex min-h-screen">
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 transform bg-slate-950 border-r border-slate-800 flex flex-col transition-transform duration-200 ease-out -translate-x-full md:translate-x-0">

    <!-- Logo -->
    <div class="flex items-center gap-3 px-6 py-5 border-b border-slate-800">
      <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center">
        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-bold text-slate-100">JOEBZ</p>
        <p class="text-xs text-slate-400">POINT-OF-SALE SYSTEM</p>
      </div>
    </div>

    <!-- Nav -->
    <nav class="flex-1 px-4 py-4 space-y-1 overflow-y-auto">

      <!-- Dashboard -->
      <a href="dashboard.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
        </svg>
        Dashboard
      </a>

      <!-- Sales -->
      <a href="sales.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-blue-900 text-white font-medium text-sm shadow-sm shadow-blue-900/20">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
        </svg>
        Sales / POS
      </a>

      <!-- Items -->
      <a href="items.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>
        </svg>
        Items
      </a>

      <!-- Categories -->
      <a href="categories.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
        </svg>
        Categories
      </a>

      <!-- Reports - Admin only -->
      <?php if ($_SESSION['role'] === 'admin'): ?>
      <a href="reports.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
        </svg>
        Reports
      </a>

      <!-- Users - Admin only -->
      <a href="users.php"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
        </svg>
        Users
      </a>
      <?php endif; ?>

    </nav>

    <!-- User Info + Logout -->
    <div class="px-4 py-4 border-t border-slate-800">
      <div class="flex items-center gap-3 px-3 py-2 rounded-xl bg-slate-900 mb-2">
        <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold">
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
         class="flex items-center gap-3 px-3 py-2 rounded-xl text-red-300 hover:bg-red-800 text-sm transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
        </svg>
        Logout
      </a>
    </div>
  </aside>

  <!-- ── MAIN CONTENT ── -->
  <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-black/60 md:hidden"></div>

  <!-- ── MAIN CONTENT ── -->
  <main class="flex-1 w-full overflow-y-auto p-4 sm:p-6 md:ml-64 bg-slate-950/40">

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
      <h1 class="text-2xl font-bold text-slate-100">Sales / Point of Sale</h1>
      <p class="text-sm text-slate-400 mt-1">
        Process customer transactions and manage sales
      </p>
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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

      <!-- ── PRODUCT SELECTION ── -->
      <div class="lg:col-span-2">
        <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20">

          <!-- Search -->
          <div class="p-6 border-b border-slate-800">
            <form method="GET" class="flex gap-3">
              <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                     placeholder="Search products..."
                     class="flex-1 px-4 py-2 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500">
              <button type="submit"
                      class="px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded-xl text-white font-medium transition">
                Search
              </button>
            </form>
          </div>

          <!-- Products Grid -->
          <div class="p-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
              <?php while ($item = $items_result->fetch_assoc()): ?>
              <div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700 hover:border-slate-600 transition">
                <h3 class="font-medium text-slate-100 mb-1"><?= htmlspecialchars($item['item_name']) ?></h3>
                <p class="text-xs text-slate-400 mb-2"><?= htmlspecialchars($item['category_name']) ?></p>
                <p class="text-lg font-bold text-blue-400 mb-3">₱<?= number_format($item['price'], 2) ?></p>
                <p class="text-xs text-slate-400 mb-3">Stock: <?= $item['stock'] ?></p>

                <form method="POST" class="flex gap-2">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                  <input type="number" name="quantity" value="1" min="1" max="<?= $item['stock'] ?>"
                         class="w-16 px-2 py-1 bg-slate-700 border border-slate-600 rounded text-center text-sm">
                  <button type="submit"
                          class="flex-1 px-3 py-1 bg-blue-600 hover:bg-blue-700 rounded text-white text-sm font-medium transition">
                    Add
                  </button>
                </form>
              </div>
              <?php endwhile; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- ── CART & CHECKOUT ── -->
      <div class="space-y-6">

        <!-- Cart -->
        <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20">
          <div class="p-6 border-b border-slate-800">
            <div class="flex items-center justify-between">
              <h2 class="text-lg font-bold text-slate-100">Cart</h2>
              <span class="text-sm text-slate-400"><?= $cart_items_count ?> items</span>
            </div>
          </div>

          <div class="p-6">
            <?php if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])): ?>
              <div class="space-y-3 mb-4">
                <?php foreach ($_SESSION['cart'] as $cart_item): ?>
                <div class="flex items-center justify-between py-2 border-b border-slate-800">
                  <div class="flex-1">
                    <p class="text-sm font-medium text-slate-100">
                      <?= htmlspecialchars($cart_item['item_name']) ?>
                    </p>
                    <p class="text-xs text-slate-400">
                      ₱<?= number_format($cart_item['price'], 2) ?> × <?= $cart_item['quantity'] ?>
                    </p>
                  </div>
                  <div class="flex items-center gap-2">
                    <p class="text-sm font-bold text-blue-400">
                      ₱<?= number_format($cart_item['price'] * $cart_item['quantity'], 2) ?>
                    </p>
                    <a href="?remove=<?= $cart_item['item_id'] ?>"
                       class="text-red-400 hover:text-red-300 text-sm">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M6 18L18 6M6 6l12 12"/>
                      </svg>
                    </a>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>

              <div class="border-t border-slate-800 pt-4">
                <div class="flex justify-between items-center mb-4">
                  <p class="text-lg font-bold text-slate-100">Total:</p>
                  <p class="text-xl font-bold text-blue-400">₱<?= number_format($cart_total, 2) ?></p>
                </div>

                <!-- Cash Received Input -->
                <div class="mb-4">
                  <label class="block text-sm font-medium text-slate-300 mb-2">Cash Received (₱)</label>
                  <input type="number" name="cash_received" id="cash-received" step="0.01" min="<?= $cart_total ?>"
                         class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-xl text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                         placeholder="Enter cash amount">
                </div>

                <!-- Change Display -->
                <div class="mb-4 p-3 bg-slate-700 rounded-xl">
                  <div class="flex justify-between items-center">
                    <p class="text-sm font-medium text-slate-300">Change:</p>
                    <p class="text-lg font-bold text-green-400" id="change-amount">₱0.00</p>
                  </div>
                </div>

                <div class="flex gap-2">
                  <form method="POST" class="flex-1">
                    <input type="hidden" name="action" value="clear_cart">
                    <button type="submit"
                            class="w-full px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-xl text-slate-300 font-medium transition">
                      Clear Cart
                    </button>
                  </form>

                  <form method="POST" class="flex-1" id="sale-form">
                    <input type="hidden" name="action" value="process_sale">
                    <input type="hidden" name="cash_received" id="hidden-cash-received">
                    <button type="submit" id="process-sale-btn"
                            class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 rounded-xl text-white font-medium transition disabled:opacity-50 disabled:cursor-not-allowed"
                            disabled>
                      Process Sale
                    </button>
                  </form>
                </div>
              </div>
            <?php else: ?>
              <p class="text-slate-400 text-center py-8">Cart is empty</p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Quick Stats -->
        <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl shadow-black/20 p-6">
          <h3 class="text-lg font-bold text-slate-100 mb-4">Today's Summary</h3>
          <?php
          $today = date('Y-m-d');
          $today_sales_q = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM sales WHERE DATE(sale_date) = '$today'");
          $today_stats = $today_sales_q->fetch_assoc();
          ?>
          <div class="space-y-3">
            <div class="flex justify-between">
              <p class="text-sm text-slate-400">Transactions:</p>
              <p class="text-sm font-medium text-slate-100"><?= $today_stats['count'] ?></p>
            </div>
            <div class="flex justify-between">
              <p class="text-sm text-slate-400">Total Sales:</p>
              <p class="text-sm font-medium text-blue-400">₱<?= number_format($today_stats['total'], 2) ?></p>
            </div>
          </div>
        </div>
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

if (openSidebarBtn) {
  openSidebarBtn.addEventListener('click', openSidebar);
}
if (sidebarOverlay) {
  sidebarOverlay.addEventListener('click', closeSidebar);
}
window.addEventListener('resize', () => {
  if (!sidebar || !sidebarOverlay) return;
  if (window.innerWidth >= 768) {
    sidebar.classList.remove('-translate-x-full');
    sidebarOverlay.classList.add('hidden');
  } else {
    sidebar.classList.add('-translate-x-full');
  }
});

// Cash received and change calculation
document.getElementById('cash-received').addEventListener('input', function() {
  const cashReceived = parseFloat(this.value) || 0;
  const total = <?= $cart_total ?>;
  const change = cashReceived - total;

  document.getElementById('change-amount').textContent = '₱' + change.toFixed(2);

  const processBtn = document.getElementById('process-sale-btn');
  const hiddenCash = document.getElementById('hidden-cash-received');

  if (cashReceived >= total && cashReceived > 0) {
    processBtn.disabled = false;
    processBtn.classList.remove('disabled:opacity-50', 'disabled:cursor-not-allowed');
    hiddenCash.value = cashReceived;
  } else {
    processBtn.disabled = true;
    processBtn.classList.add('disabled:opacity-50', 'disabled:cursor-not-allowed');
    hiddenCash.value = '';
  }

  // Color coding for change
  const changeElement = document.getElementById('change-amount');
  if (change < 0) {
    changeElement.className = 'text-lg font-bold text-red-400';
  } else {
    changeElement.className = 'text-lg font-bold text-green-400';
  }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('cash-received').dispatchEvent(new Event('input'));
});
</script>

</body>
</html>
