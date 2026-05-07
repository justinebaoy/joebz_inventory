<?php
session_start();
require_once 'config/db.php';

function redirect(string $url): void
{
    header("Location: {$url}");
    exit;
}

function ensureItemActiveColumnExists(mysqli $conn): void
{
    $result = $conn->query("SHOW COLUMNS FROM items LIKE 'is_active'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE items ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    }
    if ($result) {
        $result->free();
    }
}

function ensureBarcodeColumnExists(mysqli $conn): void
{
    $result = $conn->query("SHOW COLUMNS FROM items LIKE 'barcode'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE items ADD COLUMN barcode VARCHAR(50) UNIQUE");
    }
    if ($result) {
        $result->free();
    }
}

function getSessionCart(): array
{
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    return $_SESSION['cart'];
}

function saveSessionCart(array $cart): void
{
    $_SESSION['cart'] = array_values($cart);
}

function findCartItemIndex(int $itemId, array $cart): ?int
{
    foreach ($cart as $index => $item) {
        if ((int)$item['item_id'] === $itemId) {
            return $index;
        }
    }
    return null;
}

function getItemById(mysqli $conn, int $itemId): ?array
{
    $stmt = $conn->prepare(
        "SELECT item_name, price, stock
         FROM items
         WHERE item_id = ? AND is_active = 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $item ?: null;
}

function fetchActiveItems(mysqli $conn, string $search = '')
{
    if ($search !== '') {
        $like = '%' . $conn->real_escape_string($search) . '%';
        $stmt = $conn->prepare(
            "SELECT i.item_id, i.item_name, i.price, i.stock, i.image_path, i.barcode, c.category_name
             FROM items i
             JOIN categories c ON i.category_id = c.category_id
             WHERE i.stock > 0 AND i.is_active = 1
               AND (i.item_name LIKE ? OR c.category_name LIKE ? OR i.barcode LIKE ?)
             ORDER BY i.item_name"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sss', $like, $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        return $result;
    }

    return $conn->query(
        "SELECT i.item_id, i.item_name, i.price, i.stock, i.image_path, i.barcode, c.category_name
         FROM items i
         JOIN categories c ON i.category_id = c.category_id
         WHERE i.stock > 0 AND i.is_active = 1
         ORDER BY i.item_name"
    );
}

function fetchTodayStats(mysqli $conn, string $date): array
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total
         FROM sales
         WHERE DATE(sale_date) = ?"
    );
    if (!$stmt) {
        return ['cnt' => 0, 'total' => 0];
    }
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $stats ?: ['cnt' => 0, 'total' => 0];
}

ensureItemActiveColumnExists($conn);
ensureBarcodeColumnExists($conn);

if (!isset($_SESSION['user_id'])) {
    redirect('index.php');
}

$success = '';
$error = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$cart = getSessionCart();

// Handle remove from cart (GET method)
if (isset($_GET['remove'])) {
    $removeId = (int)$_GET['remove'];
    $cart = getSessionCart();
    foreach ($cart as $key => $item) {
        if ($item['item_id'] === $removeId) {
            unset($cart[$key]);
            break;
        }
    }
    saveSessionCart($cart);
    redirect('sales.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_to_cart') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $quantity = max(0, (int)($_POST['quantity'] ?? 0));

        if ($quantity <= 0) {
            $error = 'Quantity must be greater than 0.';
        } else {
            $item = getItemById($conn, $itemId);
            if (!$item) {
                $error = 'Item not found.';
            } else {
                $existingIndex = findCartItemIndex($itemId, $cart);
                $existingQuantity = $existingIndex !== null ? (int)$cart[$existingIndex]['quantity'] : 0;
                $requestedQuantity = $existingQuantity + $quantity;

                if ($item['stock'] < $requestedQuantity) {
                    $error = "Insufficient stock. Only {$item['stock']} available.";
                } else {
                    if ($existingIndex !== null) {
                        $cart[$existingIndex]['quantity'] += $quantity;
                    } else {
                        $cart[] = [
                            'item_id' => $itemId,
                            'item_name' => $item['item_name'],
                            'price' => $item['price'],
                            'quantity' => $quantity,
                        ];
                    }
                    saveSessionCart($cart);
                    $success = 'Item added to cart!';
                    redirect('sales.php');
                }
            }
        }
    }

    if ($action === 'update_cart_qty') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $quantity = max(0, (int)($_POST['quantity'] ?? 0));
        $index = findCartItemIndex($itemId, $cart);

        if ($index === null) {
            redirect('sales.php');
        }

        if ($quantity <= 0) {
            unset($cart[$index]);
            saveSessionCart($cart);
            redirect('sales.php');
        }

        $item = getItemById($conn, $itemId);
        if (!$item || $item['stock'] < $quantity) {
            $error = 'Not enough stock available.';
        } else {
            $cart[$index]['quantity'] = $quantity;
            saveSessionCart($cart);
        }
        redirect('sales.php');
    }

    if ($action === 'clear_cart') {
        saveSessionCart([]);
        $success = 'Cart cleared.';
        redirect('sales.php');
    }

    if ($action === 'process_sale') {
        if (empty($cart)) {
            $error = 'Cart is empty. Add items before processing sale.';
        } else {
            $cashReceived = max(0.0, (float)($_POST['cash_received'] ?? 0));
            $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
            $totalAmount = 0.0;
            foreach ($cart as $cartItem) {
                $totalAmount += $cartItem['price'] * $cartItem['quantity'];
            }

            if ($cashReceived < $totalAmount) {
                $error = 'Cash received (₱' . number_format($cashReceived, 2) . ') is less than total (₱' . number_format($totalAmount, 2) . ').';
            } else {
                $change = $cashReceived - $totalAmount;
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare(
                        'INSERT INTO sales (user_id, customer_id, total_amount, cash_received, change_amount, sale_date) VALUES (?, ?, ?, ?, ?, NOW())'
                    );
                    $stmt->bind_param('iiddd', $_SESSION['user_id'], $customer_id, $totalAmount, $cashReceived, $change);
                    $stmt->execute();
                    $saleId = $conn->insert_id;
                    $stmt->close();

                    foreach ($cart as $cartItem) {
                        $stockStmt = $conn->prepare(
                            'SELECT stock FROM items WHERE item_id = ? AND is_active = 1 FOR UPDATE'
                        );
                        $stockStmt->bind_param('i', $cartItem['item_id']);
                        $stockStmt->execute();
                        $dbItem = $stockStmt->get_result()->fetch_assoc() ?: null;
                        $stockStmt->close();

                        if (!$dbItem || $dbItem['stock'] < $cartItem['quantity']) {
                            throw new Exception('Insufficient stock while processing.');
                        }

                        $saleItemStmt = $conn->prepare(
                            'INSERT INTO sale_items (sale_id, item_id, quantity, price) VALUES (?, ?, ?, ?)'
                        );
                        $saleItemStmt->bind_param('iiid', $saleId, $cartItem['item_id'], $cartItem['quantity'], $cartItem['price']);
                        $saleItemStmt->execute();
                        $saleItemStmt->close();

                        $updateStockStmt = $conn->prepare(
                            'UPDATE items SET stock = stock - ? WHERE item_id = ? AND stock >= ?'
                        );
                        $updateStockStmt->bind_param('iii', $cartItem['quantity'], $cartItem['item_id'], $cartItem['quantity']);
                        $updateStockStmt->execute();
                        if ($updateStockStmt->affected_rows !== 1) {
                            $updateStockStmt->close();
                            throw new Exception('Stock update failed.');
                        }
                        $updateStockStmt->close();
                    }

                    $conn->commit();
                    saveSessionCart([]);
                    
                    // Store the sale ID in session for printing
                    $_SESSION['last_sale_id'] = $saleId;
                    
                    $success = '✅ Sale #' . str_pad($saleId, 4, '0', STR_PAD_LEFT) . ' processed! Change: ₱' . number_format($change, 2);
                    redirect('sales.php?success=' . urlencode($success) . '&sale_id=' . $saleId);
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = 'Failed to process sale: ' . $e->getMessage();
                }
            }
        }
    }
}

// Handle success message from redirect
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
    $last_sale_id = isset($_GET['sale_id']) ? (int)$_GET['sale_id'] : ($_SESSION['last_sale_id'] ?? 0);
}

// ── ITEMS FOR SELECTION ───────────────────────────────
$items_result = fetchActiveItems($conn, $search);

$cart_total = 0.0;
$cart_count = 0;
foreach ($cart as $cartItem) {
    $cart_total += $cartItem['price'] * $cartItem['quantity'];
    $cart_count += $cartItem['quantity'];
}

$today = date('Y-m-d');
$today_stats = fetchTodayStats($conn, $today);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales / POS — JOEBZ Inventory</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        .alert-anim { animation: slideDown 0.25s ease; }
        input[type=number]::-webkit-outer-spin-button,
        input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; }
        input[type=number] { -moz-appearance:textfield; appearance:textfield; }
        .item-tile { transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .item-tile:hover { transform: translateY(-2px); box-shadow: 0 16px 32px rgba(0,0,0,0.3); }
        .modal-backdrop { backdrop-filter: blur(2px); }
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
            <a href="sales.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-blue-600/20 text-blue-200 font-medium text-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>Sales / POS
            </a>
            <a href="items.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>Items
            </a>
            <a href="categories.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-blue-600/20 hover:text-blue-200 text-sm transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>Categories
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
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>Users
            </a>
            <?php endif; ?>
        </nav>
        <div class="px-4 py-4 border-t border-slate-800">
            <div class="flex items-center gap-3 px-3 py-2 rounded-xl bg-slate-900 mb-2">
                <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold">
                    <?= strtoupper(substr($_SESSION['first_name'],0,1)) ?>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-100"><?= htmlspecialchars($_SESSION['first_name']) ?></p>
                    <p class="text-xs text-slate-400 capitalize"><?= $_SESSION['role'] ?></p>
                </div>
            </div>
            <a href="logout.php" class="flex items-center gap-3 px-3 py-2 rounded-xl text-red-300 hover:bg-red-900/40 text-sm transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>Logout
            </a>
        </div>
    </aside>

    <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-black/60 md:hidden"></div>

    <!-- ── MAIN ── -->
    <main class="flex-1 w-full overflow-y-auto p-4 sm:p-6 md:ml-64">

        <div class="mb-4 flex items-center justify-between gap-3 md:hidden">
            <button type="button" id="open-sidebar" class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium text-slate-200">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>Menu
            </button>
            <a href="logout.php" class="inline-flex items-center gap-2 rounded-xl border border-red-700/50 bg-red-900/20 px-3 py-2 text-sm text-red-200">Logout</a>
        </div>

        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-100">Point of Sale</h1>
            <p class="text-sm text-slate-400 mt-1">Process customer transactions</p>
        </div>

        <!-- Alerts with Print Button -->
        <?php if ($success): ?>
            <div class="alert-anim mb-4 flex items-center gap-3 bg-emerald-900/40 border border-emerald-700 text-emerald-200 rounded-xl px-4 py-3 text-sm">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <?= $success ?>
                <?php if (isset($last_sale_id) && $last_sale_id > 0): ?>
                    <button onclick="window.open('print_receipt.php?id=<?= $last_sale_id ?>', '_blank', 'width=400,height=600')" 
                            class="ml-auto bg-purple-600 hover:bg-purple-500 text-white px-4 py-1.5 rounded-lg text-xs font-medium transition">
                        🖨️ Print Receipt
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert-anim mb-4 flex items-center gap-3 bg-red-900/40 border border-red-700 text-red-200 rounded-xl px-4 py-3 text-sm">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- ── PRODUCTS ── -->
            <div class="lg:col-span-2">
                <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl">
                    <div class="p-5 border-b border-slate-800">
                        <form method="GET" class="flex gap-3">
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                   placeholder="Search by name, category, or barcode..."
                                   class="flex-1 px-4 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                            <button type="submit"
                                    class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 active:bg-blue-700 rounded-xl text-white text-sm font-medium transition">
                                Search
                            </button>
                            <?php if ($search): ?>
                            <a href="sales.php" class="px-5 py-2.5 bg-slate-700 hover:bg-slate-600 rounded-xl text-slate-300 text-sm font-medium transition">Clear</a>
                            <?php endif; ?>
                        </form>
                        <p class="text-xs text-slate-500 mt-2">💡 Tip: Scan barcode or search by product name</p>
                    </div>

                    <div class="p-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                        <?php if ($items_result && $items_result->num_rows > 0): ?>
                            <?php while ($item = $items_result->fetch_assoc()): ?>
                            <div class="item-tile bg-slate-800/60 rounded-xl border border-slate-700 overflow-hidden">
                                <?php if (!empty($item['image_path'])): ?>
                                    <div class="h-32 bg-slate-900 overflow-hidden">
                                        <img src="<?= htmlspecialchars($item['image_path']) ?>" alt="<?= htmlspecialchars($item['item_name']) ?>"
                                             class="h-full w-full object-cover">
                                    </div>
                                <?php endif; ?>
                                <div class="p-3">
                                    <p class="text-xs text-blue-400 font-medium mb-0.5"><?= htmlspecialchars($item['category_name']) ?></p>
                                    <h3 class="font-medium text-slate-100 text-sm mb-1 leading-snug"><?= htmlspecialchars($item['item_name']) ?></h3>
                                    <?php if (!empty($item['barcode'])): ?>
                                        <p class="text-xs text-slate-500 font-mono">📷 <?= htmlspecialchars($item['barcode']) ?></p>
                                    <?php endif; ?>
                                    <div class="flex items-center justify-between mb-3">
                                        <p class="text-base font-bold text-blue-300">₱<?= number_format($item['price'],2) ?></p>
                                        <p class="text-xs text-slate-500"><?= $item['stock'] ?> left</p>
                                    </div>
                                    <form method="POST" class="flex gap-2">
                                        <input type="hidden" name="action" value="add_to_cart">
                                        <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                                        <input type="number" name="quantity" value="1" min="1" max="<?= $item['stock'] ?>"
                                               class="w-14 px-2 py-1.5 bg-slate-700 border border-slate-600 rounded-lg text-center text-sm text-slate-100 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                        <button type="submit"
                                                class="flex-1 px-3 py-1.5 bg-blue-600 hover:bg-blue-500 active:bg-blue-700 rounded-lg text-white text-sm font-medium transition">
                                            + Add
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="col-span-3 py-12 text-center text-slate-500">
                                <p class="font-medium">No items available</p>
                                <p class="text-sm mt-1"><?= $search ? 'Try a different search.' : 'Add items to inventory first.' ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── CART & CHECKOUT ── -->
            <div class="space-y-4">

                <!-- Cart -->
                <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl">
                    <div class="p-5 border-b border-slate-800 flex items-center justify-between">
                        <h2 class="text-base font-bold text-slate-100">Shopping Cart</h2>
                        <span class="text-xs bg-blue-600 text-white rounded-full px-2 py-0.5 font-semibold"><?= $cart_count ?></span>
                    </div>

                    <!-- Customer Selection -->
                    <div class="px-5 pt-4 border-b border-slate-800">
                        <label class="block text-xs font-medium text-slate-300 mb-1">Customer</label>
                        <div class="relative">
                            <input type="text" id="customerSearch" 
                                   placeholder="Search customer by name or phone..." 
                                   onkeyup="searchCustomer()"
                                   class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <div id="customerResults" class="hidden absolute z-50 w-full mt-1 bg-slate-800 border border-slate-700 rounded-xl max-h-48 overflow-y-auto"></div>
                        </div>
                        <div id="selectedCustomer" class="mt-2 p-2 bg-slate-800/50 rounded-lg">
                            <p class="text-sm text-slate-400">No customer selected (Walk-in)</p>
                        </div>
                        <button onclick="showAddCustomerForm()" 
                                class="mt-2 mb-3 text-xs text-blue-400 hover:text-blue-300">
                            + Add New Customer
                        </button>
                    </div>

                    <div class="p-5">
                        <?php if (!empty($_SESSION['cart'])): ?>
                            <!-- Cart items -->
                            <div class="space-y-2 mb-4 max-h-64 overflow-y-auto">
                                <?php foreach ($_SESSION['cart'] as $ci): ?>
                                <div class="flex items-start justify-between gap-2 pb-2 border-b border-slate-800">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-slate-100 truncate"><?= htmlspecialchars($ci['item_name']) ?></p>
                                        <p class="text-xs text-slate-500">₱<?= number_format($ci['price'],2) ?> × <?= $ci['quantity'] ?></p>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <p class="text-sm font-bold text-blue-300">₱<?= number_format($ci['price']*$ci['quantity'],2) ?></p>
                                        <a href="?remove=<?= $ci['item_id'] ?>"
                                           class="text-red-400 hover:text-red-300 transition"
                                           title="Remove">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Total -->
                            <div class="flex justify-between items-center py-2 mb-4 border-t border-slate-700">
                                <p class="font-bold text-slate-100">Total</p>
                                <p class="text-xl font-bold text-blue-300">₱<?= number_format($cart_total,2) ?></p>
                            </div>

                            <!-- Cash input -->
                            <div class="mb-3">
                                <label class="block text-xs font-medium text-slate-300 mb-1.5">Cash Received (₱)</label>
                                <input type="number" id="cash-received" step="0.01" min="0"
                                       placeholder="Enter cash amount"
                                       class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                            </div>

                            <!-- Change display -->
                            <div id="change-display" class="mb-4 p-3 rounded-xl bg-slate-800 border border-slate-700 hidden">
                                <div class="flex justify-between items-center">
                                    <p class="text-xs font-medium text-slate-400">Change to give:</p>
                                    <p id="change-amount" class="text-lg font-bold text-emerald-400">₱0.00</p>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex gap-2">
                                <form method="POST" class="flex-1">
                                    <input type="hidden" name="action" value="clear_cart">
                                    <button type="submit"
                                            class="w-full px-3 py-2.5 bg-slate-700 hover:bg-slate-600 active:bg-slate-800 rounded-xl text-slate-300 text-sm font-medium transition">
                                        Clear Cart
                                    </button>
                                </form>

                                <form method="POST" id="sale-form" class="flex-1">
                                    <input type="hidden" name="action" value="process_sale">
                                    <input type="hidden" name="cash_received" id="hidden-cash">
                                    <input type="hidden" name="customer_id" id="customer-id-field">
                                    <button type="submit" id="process-btn" disabled
                                            class="w-full px-3 py-2.5 bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700
                                                   rounded-xl text-white text-sm font-medium transition
                                                   disabled:opacity-40 disabled:cursor-not-allowed disabled:bg-slate-600">
                                        Process Sale
                                    </button>
                                </form>
                            </div>

                        <?php else: ?>
                            <div class="py-10 text-center">
                                <svg class="w-10 h-10 mx-auto text-slate-700 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                <p class="text-slate-500 text-sm">Cart is empty</p>
                                <p class="text-slate-600 text-xs mt-1">Add products from the list</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Today's summary -->
                <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl p-5">
                    <h3 class="text-sm font-bold text-slate-100 mb-3">Today's Summary</h3>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <p class="text-xs text-slate-400">Transactions:</p>
                            <p class="text-sm font-bold text-slate-100"><?= $today_stats['cnt'] ?></p>
                        </div>
                        <div class="flex justify-between items-center">
                            <p class="text-xs text-slate-400">Total Sales:</p>
                            <p class="text-sm font-bold text-blue-300">₱<?= number_format($today_stats['total'],2) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Add Customer Modal -->
<div id="addCustomerModal" class="modal-backdrop fixed inset-0 bg-black/50 hidden flex items-center justify-center z-50">
    <div class="bg-slate-900 rounded-2xl border border-slate-700 shadow-2xl w-full max-w-md mx-4 p-6">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-semibold text-slate-100">Add New Customer</h3>
            <button onclick="closeCustomerModal()" class="text-slate-400 hover:text-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="space-y-3">
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-1">Customer Name *</label>
                <input type="text" id="newCustomerName" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-1">Phone Number</label>
                <input type="tel" id="newCustomerPhone" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-1">Email</label>
                <input type="email" id="newCustomerEmail" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-1">Address</label>
                <textarea id="newCustomerAddress" rows="2" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-sm text-slate-100"></textarea>
            </div>
            <div class="flex gap-3 mt-4">
                <button onclick="closeCustomerModal()" class="flex-1 border border-slate-700 text-slate-300 rounded-xl py-2 text-sm hover:bg-slate-800">Cancel</button>
                <button onclick="addCustomer()" class="flex-1 bg-blue-600 text-white rounded-xl py-2 text-sm font-medium hover:bg-blue-700">Add Customer</button>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================
// SIDEBAR
// ============================================
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

// ============================================
// CASH / CHANGE CALCULATION
// ============================================
const CART_TOTAL = <?= json_encode($cart_total) ?>;
const cashInput = document.getElementById('cash-received');
const hiddenCash = document.getElementById('hidden-cash');
const processBtn = document.getElementById('process-btn');
const changeDisplay = document.getElementById('change-display');
const changeAmount = document.getElementById('change-amount');

if (cashInput) {
    cashInput.addEventListener('input', function () {
        const cash = parseFloat(this.value) || 0;
        const change = cash - CART_TOTAL;

        if (cash > 0) {
            changeDisplay.classList.remove('hidden');
            changeAmount.textContent = '₱' + change.toFixed(2);
            changeAmount.classList.remove('text-red-400', 'text-emerald-400');
            changeAmount.classList.add('text-lg', 'font-bold', change < 0 ? 'text-red-400' : 'text-emerald-400');
        } else {
            changeDisplay.classList.add('hidden');
        }

        if (cash >= CART_TOTAL && cash > 0) {
            processBtn.disabled = false;
            hiddenCash.value = cash;
        } else {
            processBtn.disabled = true;
            hiddenCash.value = '';
        }
    });
}

// ============================================
// CUSTOMER MANAGEMENT MODULE
// ============================================

let currentCustomer = {
    id: null,
    name: 'Walk-in Customer',
    phone: '',
    points: 0
};

function searchCustomer() {
    const searchTerm = document.getElementById('customerSearch').value;
    if (searchTerm.length < 2) return;
    
    fetch(`customer_ajax.php?action=search&term=${encodeURIComponent(searchTerm)}`)
        .then(response => response.json())
        .then(data => {
            const resultsDiv = document.getElementById('customerResults');
            if (data.length === 0) {
                resultsDiv.innerHTML = '<div class="p-2 text-slate-400 text-sm">No customers found. <button onclick="showAddCustomerForm()" class="text-blue-400">Add new?</button></div>';
                resultsDiv.classList.remove('hidden');
                return;
            }
            
            resultsDiv.innerHTML = data.map(c => `
                <div onclick="selectCustomer(${c.customer_id}, '${c.name.replace(/'/g, "\\'")}', '${c.phone || ''}', ${c.loyalty_points || 0})" 
                     class="p-2 hover:bg-slate-700 cursor-pointer border-b border-slate-700">
                    <p class="font-medium text-slate-100">${c.name}</p>
                    <p class="text-xs text-slate-400">${c.phone || 'No phone'} | Points: ${c.loyalty_points || 0}</p>
                </div>
            `).join('');
            resultsDiv.classList.remove('hidden');
        });
}

function selectCustomer(id, name, phone, points) {
    currentCustomer = { id, name, phone, points };
    document.getElementById('selectedCustomer').innerHTML = `
        <div class="flex justify-between items-center">
            <div>
                <p class="text-sm font-medium text-slate-100">${name}</p>
                <p class="text-xs text-slate-400">${phone || 'No phone'} | Points: ${points}</p>
            </div>
            <button onclick="clearCustomer()" class="text-red-400 text-xs">Change</button>
        </div>
    `;
    document.getElementById('customerSearch').value = name;
    document.getElementById('customerResults').classList.add('hidden');
    document.getElementById('customerSearch').disabled = true;
    document.getElementById('customer-id-field').value = id;
}

function clearCustomer() {
    currentCustomer = { id: null, name: 'Walk-in Customer', phone: '', points: 0 };
    document.getElementById('selectedCustomer').innerHTML = `
        <p class="text-sm text-slate-400">No customer selected (Walk-in)</p>
    `;
    document.getElementById('customerSearch').value = '';
    document.getElementById('customerSearch').disabled = false;
    document.getElementById('customer-id-field').value = '';
}

function showAddCustomerForm() {
    const modal = document.getElementById('addCustomerModal');
    if (modal) modal.classList.remove('hidden');
}

function closeCustomerModal() {
    const modal = document.getElementById('addCustomerModal');
    if (modal) modal.classList.add('hidden');
    document.getElementById('newCustomerName').value = '';
    document.getElementById('newCustomerPhone').value = '';
    document.getElementById('newCustomerEmail').value = '';
    document.getElementById('newCustomerAddress').value = '';
}

function addCustomer() {
    const name = document.getElementById('newCustomerName').value;
    const phone = document.getElementById('newCustomerPhone').value;
    const email = document.getElementById('newCustomerEmail').value;
    const address = document.getElementById('newCustomerAddress').value;
    
    if (!name) {
        alert('Customer name is required');
        return;
    }
    
    fetch('customer_ajax.php?action=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `name=${encodeURIComponent(name)}&phone=${encodeURIComponent(phone)}&email=${encodeURIComponent(email)}&address=${encodeURIComponent(address)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            selectCustomer(data.customer_id, name, phone, 0);
            closeCustomerModal();
            alert('Customer added successfully!');
        } else {
            alert('Error: ' + data.error);
        }
    });
}

// ============================================
// BARCODE SCANNING MODULE
// ============================================

let barcodeBuffer = '';
let barcodeTimer = null;

function handleBarcodeScan(e) {
    if (e.key === 'Enter') {
        if (barcodeBuffer.length > 0) {
            e.preventDefault();
            addItemByBarcode(barcodeBuffer);
            barcodeBuffer = '';
        }
    } else if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
        clearTimeout(barcodeTimer);
        barcodeBuffer += e.key;
        barcodeTimer = setTimeout(() => {
            barcodeBuffer = '';
        }, 50);
    }
}

function addItemByBarcode(barcode) {
    fetch(`items.php?action=get_by_barcode&barcode=${encodeURIComponent(barcode)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="add_to_cart">
                    <input type="hidden" name="item_id" value="${data.item_id}">
                    <input type="hidden" name="quantity" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            } else {
                alert('Product not found: ' + barcode);
            }
        });
}

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('keydown', handleBarcodeScan);
});

// Auto-dismiss alerts
document.querySelectorAll('.alert-anim').forEach(el => {
    setTimeout(() => {
        el.style.transition = 'opacity 0.5s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
    }, 5000);
});
</script>

</body>
</html>