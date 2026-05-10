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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_po') {
        $supplier_id = (int)$_POST['supplier_id'];
        $expected_at = $_POST['expected_at'] ?: null;
        $currency = $_POST['currency'] ?: 'PHP';
        $notes = $_POST['notes'] ?? null;
        $po_number = 'PO-' . date('YmdHis');

        $stmt = $conn->prepare('INSERT INTO purchase_orders (po_number, supplier_id, status, expected_at, currency, notes, created_by) VALUES (?, ?, "draft", ?, ?, ?, ?)');
        $stmt->bind_param('sisssi', $po_number, $supplier_id, $expected_at, $currency, $notes, $_SESSION['user_id']);
        $stmt->execute();
        $po_id = $conn->insert_id;

        $item_ids = $_POST['item_id'] ?? [];
        $qtys = $_POST['ordered_qty'] ?? [];
        $costs = $_POST['unit_cost'] ?? [];
        foreach ($item_ids as $i => $item_id) {
            $q = (float)($qtys[$i] ?? 0);
            $c = (float)($costs[$i] ?? 0);
            if ($item_id && $q > 0) {
                $line_total = $q * $c;
                $ins = $conn->prepare('INSERT INTO purchase_order_items (po_id,item_id,ordered_qty,unit_cost,tax,discount,line_total) VALUES (?,?,?,?,0,0,?)');
                $ins->bind_param('iiddd', $po_id, $item_id, $q, $c, $line_total);
                $ins->execute();
            }
        }
        $msg = 'PO created.';
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
        $received_at = $_POST['received_at'];
        $is_backdated = (strtotime($received_at) < strtotime(date('Y-m-d H:i:s')) - 300) ? 1 : 0;
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

$suppliers = $conn->query('SELECT supplier_id, supplier_name FROM suppliers WHERE status = "active" ORDER BY supplier_name');
$items = $conn->query('SELECT item_id, item_name FROM items ORDER BY item_name');
$po_rows = $conn->query('SELECT po.*, s.supplier_name FROM purchase_orders po JOIN suppliers s ON s.supplier_id = po.supplier_id ORDER BY po.created_at DESC LIMIT 100');
?>
<html><body>
<h2>Purchase Orders</h2>
<p><?php echo htmlspecialchars($msg); ?></p>
<form method="post">
<input type="hidden" name="action" value="create_po" />
<select name="supplier_id"><?php while($s=$suppliers->fetch_assoc()){ echo '<option value="'.$s['supplier_id'].'">'.htmlspecialchars($s['supplier_name']).'</option>'; } ?></select>
<input name="expected_at" type="datetime-local"/>
<input name="currency" value="PHP"/>
<textarea name="notes" placeholder="notes"></textarea>
<?php for($i=0;$i<3;$i++): ?>
<div>
<select name="item_id[]"><option value="">item</option><?php $items->data_seek(0); while($it=$items->fetch_assoc()){ echo '<option value="'.$it['item_id'].'">'.htmlspecialchars($it['item_name']).'</option>'; } ?></select>
<input name="ordered_qty[]" type="number" step="0.01" />
<input name="unit_cost[]" type="number" step="0.0001" />
</div>
<?php endfor; ?>
<button>Create PO</button></form>

<h3>Existing POs</h3>
<?php while($po=$po_rows->fetch_assoc()): ?>
<div style="border:1px solid #ccc;margin:8px;padding:8px;">
<strong><?php echo htmlspecialchars($po['po_number']); ?></strong> <?php echo htmlspecialchars($po['supplier_name']); ?> (<?php echo $po['status']; ?>)
<?php if(can_manage_po()): ?>
<form method="post" style="display:inline"><input type="hidden" name="action" value="submit_po"><input type="hidden" name="po_id" value="<?php echo $po['po_id']; ?>"><button>Submit</button></form>
<?php endif; ?>
<form method="post">
<input type="hidden" name="action" value="post_receipt"><input type="hidden" name="po_id" value="<?php echo $po['po_id']; ?>">
<input type="datetime-local" name="received_at" value="<?php echo date('Y-m-d\TH:i'); ?>">
<input name="reference_no" placeholder="ref no"><input name="landed_cost_total" type="number" step="0.0001" placeholder="landed cost total">
<select name="allocation_method"><option value="value">By Line Value</option><option value="qty">By Qty</option></select>
<?php $poi = $conn->query('SELECT poi.po_item_id, i.item_name, (poi.ordered_qty-poi.received_qty) remaining FROM purchase_order_items poi JOIN items i ON i.item_id = poi.item_id WHERE poi.po_id='.(int)$po['po_id']); while($r=$poi->fetch_assoc()): ?>
<div><?php echo htmlspecialchars($r['item_name']); ?> rem=<?php echo $r['remaining']; ?> recv <input type="number" step="0.01" name="recv_<?php echo $r['po_item_id']; ?>"> rej <input type="number" step="0.01" name="rej_<?php echo $r['po_item_id']; ?>"></div>
<?php endwhile; ?>
<button>Post Receipt</button>
</form>
</div>
<?php endwhile; ?>
</body></html>
