<?php
session_start();
require_once 'config/db.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

function can_manage_po() { return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','manager'], true); }
function can_post_backdated() { return can_manage_po(); }
function validate_po_transition($from, $to) {
    $allowed = [
        'draft' => ['submitted', 'cancelled'],
        'submitted' => ['partial_received', 'received', 'closed', 'cancelled'],
        'partial_received' => ['received', 'closed', 'cancelled'],
        'received' => ['closed'],
        'closed' => [],
        'cancelled' => []
    ];
    return in_array($to, $allowed[$from] ?? [], true);
}

$msg = '';
$msg_is_error = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_po') {
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        $expected_date = trim($_POST['expected_date'] ?? '');
        $expected_time = trim($_POST['expected_time'] ?? '');
        $expected_at_legacy = trim($_POST['expected_at'] ?? '');
        $expected_at = null;

        if ($expected_date !== '' || $expected_time !== '' || $expected_at_legacy !== '') {
            if ($expected_date !== '') {
                $expected_time = $expected_time !== '' ? $expected_time : '00:00';
                $expected_dt = DateTime::createFromFormat('Y-m-d H:i', $expected_date . ' ' . $expected_time);
                $expected_errors = DateTime::getLastErrors();
                if ($expected_dt === false || $expected_errors['warning_count'] > 0 || $expected_errors['error_count'] > 0) {
                    $msg = 'Invalid expected date/time.';
                    $msg_is_error = true;
                } else {
                    $expected_at = $expected_dt->format('Y-m-d H:i:s');
                }
            } elseif ($expected_at_legacy !== '') {
                $expected_at_legacy = str_replace('T', ' ', $expected_at_legacy);
                $expected_dt = DateTime::createFromFormat('Y-m-d H:i:s', $expected_at_legacy);
                if ($expected_dt === false) {
                    $expected_dt = DateTime::createFromFormat('Y-m-d H:i', $expected_at_legacy);
                }
                $expected_errors = DateTime::getLastErrors();
                if ($expected_dt === false || $expected_errors['warning_count'] > 0 || $expected_errors['error_count'] > 0) {
                    $msg = 'Invalid expected date/time.';
                    $msg_is_error = true;
                } else {
                    $expected_at = $expected_dt->format('Y-m-d H:i:s');
                }
            }
        }

        $item_ids = $_POST['item_id'] ?? [];
        $qtys = $_POST['ordered_qty'] ?? [];
        $costs = $_POST['unit_cost'] ?? [];
        $has_valid_item = false;

        if (!$msg_is_error && $supplier_id <= 0) {
            $msg = 'Please select a supplier.';
            $msg_is_error = true;
        }

        if (!$msg_is_error) {
            foreach ($item_ids as $i => $item_id) {
                $item_id = (int)$item_id;
                $q_raw = trim((string)($qtys[$i] ?? ''));
                $c_raw = trim((string)($costs[$i] ?? ''));

                if ($q_raw !== '' && (!is_numeric($q_raw) || (float)$q_raw < 0)) {
                    $msg = 'Ordered quantity must be a non-negative number.';
                    $msg_is_error = true;
                    break;
                }
                if ($c_raw !== '' && (!is_numeric($c_raw) || (float)$c_raw < 0)) {
                    $msg = 'Unit cost must be a non-negative number.';
                    $msg_is_error = true;
                    break;
                }

                if ($item_id > 0 && is_numeric($q_raw) && (float)$q_raw > 0) {
                    $has_valid_item = true;
                }
            }
        }

        if (!$msg_is_error && !$has_valid_item) {
            $msg = 'Please add at least one item with quantity greater than 0.';
            $msg_is_error = true;
        }

        if (!$msg_is_error) {
            $currency = $_POST['currency'] ?: 'PHP';
            $notes = $_POST['notes'] ?? null;
            $po_number = 'PO-' . date('YmdHis');

            $stmt = $conn->prepare('INSERT INTO purchase_orders (po_number, supplier_id, status, expected_at, currency, notes, created_by) VALUES (?, ?, "draft", ?, ?, ?, ?)');
            $stmt->bind_param('sisssi', $po_number, $supplier_id, $expected_at, $currency, $notes, $_SESSION['user_id']);
            $stmt->execute();
            $po_id = $conn->insert_id;

            foreach ($item_ids as $i => $item_id) {
                $item_id = (int)$item_id;
                $q = (float)($qtys[$i] ?? 0);
                $c = (float)($costs[$i] ?? 0);
                if ($item_id > 0 && $q > 0) {
                    $line_total = $q * $c;
                    $ins = $conn->prepare('INSERT INTO purchase_order_items (po_id,item_id,ordered_qty,unit_cost,tax,discount,line_total) VALUES (?,?,?,?,0,0,?)');
                    $ins->bind_param('iiddd', $po_id, $item_id, $q, $c, $line_total);
                    $ins->execute();
                }
            }
            $msg = 'PO created.';
        }
    }

    if ($action === 'submit_po' && can_manage_po()) {
        $po_id = (int)$_POST['po_id'];
        $row = $conn->query("SELECT status FROM purchase_orders WHERE po_id = {$po_id}")->fetch_assoc();
        if ($row && validate_po_transition($row['status'], 'submitted')) {
            $stmt = $conn->prepare('UPDATE purchase_orders SET status = "submitted", ordered_at = NOW(), submitted_by = ?, submitted_at = NOW() WHERE po_id = ?');
            $stmt->bind_param('ii', $_SESSION['user_id'], $po_id);
            $stmt->execute();
            $msg = 'PO submitted.';
        }
    }

    if ($action === 'post_receipt') {
        $po_id = (int)$_POST['po_id'];
        $received_date = trim($_POST['received_date'] ?? '');
        $received_time = trim($_POST['received_time'] ?? '');
        $received_at_legacy = trim($_POST['received_at'] ?? '');
        $received_dt = null;
        if ($received_date !== '') {
            $received_time = $received_time !== '' ? $received_time : '00:00';
            $received_dt = DateTime::createFromFormat('Y-m-d H:i', $received_date . ' ' . $received_time);
            $received_errors = DateTime::getLastErrors();
            if ($received_dt === false || $received_errors['warning_count'] > 0 || $received_errors['error_count'] > 0) {
                $msg = 'Invalid received date/time.';
                $msg_is_error = true;
            }
        } elseif ($received_at_legacy !== '') {
            $received_at_legacy = str_replace('T', ' ', $received_at_legacy);
            $received_dt = DateTime::createFromFormat('Y-m-d H:i:s', $received_at_legacy);
            if ($received_dt === false) {
                $received_dt = DateTime::createFromFormat('Y-m-d H:i', $received_at_legacy);
            }
            $received_errors = DateTime::getLastErrors();
            if ($received_dt === false || $received_errors['warning_count'] > 0 || $received_errors['error_count'] > 0) {
                $msg = 'Invalid received date/time.';
                $msg_is_error = true;
            }
        } else {
            $received_dt = new DateTime();
        }

        if ($msg_is_error) {
            // Skip DB actions when received date/time is malformed.
        } else {
            $received_at = $received_dt->format('Y-m-d H:i:s');
            $now_ts = time();
            $received_ts = $received_dt->getTimestamp();
            $is_backdated = ($received_ts < ($now_ts - 300)) ? 1 : 0;
            if ($is_backdated && !can_post_backdated()) {
            $msg = 'Only admin/manager can post backdated receipts.';
        } else {
            $method = $_POST['allocation_method'] === 'qty' ? 'qty' : 'value';
            $landed_total = (float)($_POST['landed_cost_total'] ?? 0);
            $ref = $_POST['reference_no'] ?? null;
            $notes = $_POST['notes'] ?? null;

            $gr = $conn->prepare('INSERT INTO goods_receipts (po_id, received_at, received_by, reference_no, notes, allocation_method, landed_cost_total, is_backdated) VALUES (?,?,?,?,?,?,?,?)');
            $gr->bind_param('isisssdi', $po_id, $received_at, $_SESSION['user_id'], $ref, $notes, $method, $landed_total, $is_backdated);
            $gr->execute();
            $receipt_id = $conn->insert_id;

            $items = $conn->query("SELECT poi.po_item_id, poi.item_id, poi.ordered_qty, poi.received_qty, poi.unit_cost, poi.line_total, po.supplier_id FROM purchase_order_items poi JOIN purchase_orders po ON po.po_id = poi.po_id WHERE poi.po_id = {$po_id}");
            $rows = [];
            $base = 0;
            while ($r = $items->fetch_assoc()) {
                $recv = (float)($_POST['recv_' . $r['po_item_id']] ?? 0);
                $rej = (float)($_POST['rej_' . $r['po_item_id']] ?? 0);
                $acc = max(0, $recv - $rej);
                if ($recv <= 0) continue;
                $den = $method === 'qty' ? $recv : ($r['unit_cost'] * $recv);
                $base += $den;
                $r['recv'] = $recv; $r['rej'] = $rej; $r['acc'] = $acc; $r['den'] = $den;
                $rows[] = $r;
            }

            foreach ($rows as $r) {
                $alloc = $base > 0 ? $landed_total * ($r['den'] / $base) : 0;
                $adj_unit = $r['acc'] > 0 ? ($r['unit_cost'] + ($alloc / $r['acc'])) : $r['unit_cost'];
                $line_val = $adj_unit * $r['acc'];

                $ins = $conn->prepare('INSERT INTO goods_receipt_items (receipt_id, po_item_id, received_qty, accepted_qty, rejected_qty, landed_cost_alloc, adjusted_unit_cost, line_valuation_total) VALUES (?,?,?,?,?,?,?,?)');
                $ins->bind_param('iidddddd', $receipt_id, $r['po_item_id'], $r['recv'], $r['acc'], $r['rej'], $alloc, $adj_unit, $line_val);
                $ins->execute();

                $upPoi = $conn->prepare('UPDATE purchase_order_items SET received_qty = received_qty + ?, last_adjusted_unit_cost = ? WHERE po_item_id = ?');
                $upPoi->bind_param('ddi', $r['acc'], $adj_unit, $r['po_item_id']);
                $upPoi->execute();

                $upStock = $conn->prepare('UPDATE items SET stock = stock + ? WHERE item_id = ?');
                $accepted_int = (int)round($r['acc']);
                $upStock->bind_param('ii', $accepted_int, $r['item_id']);
                $upStock->execute();

                $log = $conn->prepare('INSERT INTO inventory_logs (item_id, user_id, action, quantity) VALUES (?, ?, "inbound_receipt", ?)');
                $log->bind_param('iii', $r['item_id'], $_SESSION['user_id'], $accepted_int);
                $log->execute();

                $costHis = $conn->prepare('INSERT INTO supplier_item_cost_history (supplier_id, item_id, cost, effective_at, source_receipt_item_id) VALUES (?, ?, ?, ?, LAST_INSERT_ID())');
                $costHis->bind_param('iids', $r['supplier_id'], $r['item_id'], $adj_unit, $received_at);
                $costHis->execute();
            }

            $statusRow = $conn->query("SELECT SUM(ordered_qty) o, SUM(received_qty) r FROM purchase_order_items WHERE po_id = {$po_id}")->fetch_assoc();
            $next = ((float)$statusRow['r'] <= 0) ? 'submitted' : (((float)$statusRow['r'] < (float)$statusRow['o']) ? 'partial_received' : 'received');
            $conn->query("UPDATE purchase_orders SET status = '{$next}' WHERE po_id = {$po_id}");
            $msg = 'Receipt posted and inventory updated.';
            }
        }
    }
}

$suppliers = $conn->query('SELECT supplier_id, supplier_name FROM suppliers WHERE status = "active" ORDER BY supplier_name');
$items = $conn->query('SELECT item_id, item_name FROM items ORDER BY item_name');
$po_rows = $conn->query('SELECT po.*, s.supplier_name FROM purchase_orders po JOIN suppliers s ON s.supplier_id = po.supplier_id ORDER BY po.created_at DESC LIMIT 100');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Purchase Orders - JOEBZ</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">
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
<div class="flex-1 md:ml-64 min-h-screen">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6">
        <div class="mb-5 md:hidden">
            <button id="open-sidebar" class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium text-slate-200">Menu</button>
        </div>
        <h1 class="text-3xl font-bold">Purchase Orders</h1>
        <?php if ($msg): ?><p class="mt-3 rounded-lg border px-4 py-2 text-sm <?= $msg_is_error ? 'border-red-700/40 bg-red-900/30 text-red-200' : 'border-emerald-700/40 bg-emerald-900/30 text-emerald-200' ?>"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>

        <div class="mt-6 rounded-2xl border border-slate-800 bg-slate-900/60 p-4">
            <h2 class="text-lg font-semibold mb-3">Create PO</h2>
            <form method="post" class="space-y-3">
                <input type="hidden" name="action" value="create_po" />
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label for="supplier_id" class="block text-sm text-slate-200 mb-1">Supplier</label>
                        <input id="supplier_id" name="supplier_id" type="text" placeholder="Enter supplier ID" class="w-full rounded-lg bg-slate-800 border border-slate-700 p-2 text-slate-100 placeholder-slate-300" />
                    </div>
                    <div>
                        <label for="expected_date" class="block text-sm text-slate-200 mb-1">Expected date/time</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <input id="expected_date" name="expected_date" type="date" placeholder="YYYY-MM-DD" class="w-full rounded-lg bg-slate-800 border border-slate-700 p-2 text-slate-100 placeholder-slate-300"/>
                            <input id="expected_time" name="expected_time" type="time" placeholder="HH:MM" class="w-full rounded-lg bg-slate-800 border border-slate-700 p-2 text-slate-100 placeholder-slate-300"/>
                        </div>
                        <p class="mt-1 text-xs text-slate-400">Use 24-hour time (HH:MM).</p>
                    </div>
                    <div>
                        <label for="currency" class="block text-sm text-slate-200 mb-1">Currency</label>
                        <input id="currency" name="currency" value="PHP" placeholder="e.g. PHP" maxlength="3" class="w-full rounded-lg bg-slate-800 border border-slate-700 p-2 text-slate-100 placeholder-slate-300"/>
                    </div>
                </div>
                <div>
                    <label for="po_notes" class="block text-sm text-slate-200 mb-1">Notes</label>
                    <textarea id="po_notes" name="notes" placeholder="Add purchase order notes" class="w-full rounded-lg bg-slate-800 border border-slate-700 p-2 text-slate-100 placeholder-slate-300"></textarea>
                </div>
                <?php for($i=0;$i<3;$i++): ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label for="item_id_<?= $i ?>" class="block text-sm text-slate-200 mb-1">Item</label>
                        <select id="item_id_<?= $i ?>" name="item_id[]" class="w-full rounded-lg bg-slate-800 border border-slate-700 p-2 text-slate-100">
                            <option value="">Select item</option>
                            <?php $items->data_seek(0); while($it=$items->fetch_assoc()){ echo '<option value="'.$it['item_id'].'">'.htmlspecialchars($it['item_name']).'</option>'; } ?>
                        </select>
                    </div>
                    <div>
                        <label for="ordered_qty_<?= $i ?>" class="block text-sm text-slate-200 mb-1">Ordered quantity</label>
                        <input id="ordered_qty_<?= $i ?>" name="ordered_qty[]" type="number" step="0.01" min="0" placeholder="Qty (e.g. 10.5)" class="w-full rounded-lg bg-slate-800 border border-slate-700 p-2 text-slate-100 placeholder-slate-300" />
                    </div>
                    <div>
                        <label for="unit_cost_<?= $i ?>" class="block text-sm text-slate-200 mb-1">Unit cost</label>
                        <input id="unit_cost_<?= $i ?>" name="unit_cost[]" type="number" step="0.0001" min="0" placeholder="Unit cost (e.g. 125.75)" class="w-full rounded-lg bg-slate-800 border border-slate-700 p-2 text-slate-100 placeholder-slate-300" />
                    </div>
                </div>
                <?php endfor; ?>
                <button class="rounded-lg bg-blue-600 hover:bg-blue-500 px-4 py-2 font-medium">Create PO</button>
            </form>
        </div>

        <h3 class="mt-8 mb-3 text-xl font-semibold">Existing POs</h3>
        <?php while($po=$po_rows->fetch_assoc()): ?>
        <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-4 mb-3">
            <strong><?php echo htmlspecialchars($po['po_number']); ?></strong> <?php echo htmlspecialchars($po['supplier_name']); ?> (<?php echo $po['status']; ?>)
            <?php if(can_manage_po()): ?>
            <form method="post" class="inline"><input type="hidden" name="action" value="submit_po"><input type="hidden" name="po_id" value="<?php echo $po['po_id']; ?>"><button class="ml-2 rounded bg-indigo-600 px-2 py-1 text-xs">Submit</button></form>
            <?php endif; ?>
            <form method="post" class="mt-3 space-y-2">
                <input type="hidden" name="action" value="post_receipt"><input type="hidden" name="po_id" value="<?php echo $po['po_id']; ?>">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <input type="date" name="received_date" value="<?php echo date('Y-m-d'); ?>" placeholder="YYYY-MM-DD" class="rounded-lg bg-slate-800 border border-slate-700 p-2 placeholder-slate-300">
                        <input type="time" name="received_time" value="<?php echo date('H:i'); ?>" placeholder="HH:MM" class="rounded-lg bg-slate-800 border border-slate-700 p-2 placeholder-slate-300">
                    </div>
                    <p class="text-xs text-slate-400 md:col-span-2">Use 24-hour time (HH:MM).</p>
                    <input name="reference_no" placeholder="ref no" class="rounded-lg bg-slate-800 border border-slate-700 p-2">
                    <input name="landed_cost_total" type="number" step="0.0001" placeholder="landed cost total" class="rounded-lg bg-slate-800 border border-slate-700 p-2">
                    <select name="allocation_method" class="rounded-lg bg-slate-800 border border-slate-700 p-2"><option value="value">By Line Value</option><option value="qty">By Qty</option></select>
                </div>
                <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-950/40">
                    <div class="grid min-w-[700px] grid-cols-12 gap-2 border-b border-slate-800 bg-slate-900/70 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-300">
                        <div class="col-span-5">Item</div>
                        <div class="col-span-2 text-right">Remaining</div>
                        <div class="col-span-2 text-right">Receive Qty</div>
                        <div class="col-span-2 text-right">Reject Qty</div>
                        <div class="col-span-1 text-right">Unit</div>
                    </div>
                    <?php $poi = $conn->query('SELECT poi.po_item_id, i.item_name, i.unit, (poi.ordered_qty-poi.received_qty) remaining FROM purchase_order_items poi JOIN items i ON i.item_id = poi.item_id WHERE poi.po_id='.(int)$po['po_id']); while($r=$poi->fetch_assoc()): ?>
                    <div class="grid min-w-[700px] grid-cols-12 gap-2 border-b border-slate-800/70 px-3 py-2 text-sm text-slate-200 last:border-b-0">
                        <div class="col-span-5"><?php echo htmlspecialchars($r['item_name']); ?></div>
                        <div class="col-span-2 text-right tabular-nums"><?php echo number_format((float)$r['remaining'], 2); ?></div>
                        <div class="col-span-2">
                            <input class="w-full rounded bg-slate-800 border border-slate-700 p-1 text-right tabular-nums placeholder-slate-400" type="number" step="0.01" placeholder="0.00" name="recv_<?php echo $r['po_item_id']; ?>">
                        </div>
                        <div class="col-span-2">
                            <input class="w-full rounded bg-slate-800 border border-slate-700 p-1 text-right tabular-nums placeholder-slate-400" type="number" step="0.01" placeholder="0.00" name="rej_<?php echo $r['po_item_id']; ?>">
                        </div>
                        <div class="col-span-1 text-right text-slate-400"><?php echo htmlspecialchars($r['unit'] ?: '—'); ?></div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <button class="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-3 py-2 text-sm">Post Receipt</button>
            </form>
        </div>
        <?php endwhile; ?>
    </div>
</div>
<script>
var sidebar = document.getElementById('sidebar');
var openBtn = document.getElementById('open-sidebar');
if (openBtn) openBtn.addEventListener('click', function() { sidebar.classList.remove('-translate-x-full'); });
document.addEventListener('click', function(e) { if (window.innerWidth < 768 && sidebar && openBtn && !sidebar.contains(e.target) && !openBtn.contains(e.target)) sidebar.classList.add('-translate-x-full'); });
</script>
</body></html>
