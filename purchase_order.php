<?php
date_default_timezone_set('Asia/Manila');
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

function can_manage_po()      { return in_array($_SESSION['role'], ['admin','manager'], true); }
function can_post_backdated() { return can_manage_po(); }

function validate_po_transition($from, $to) {
    $allowed = [
        'draft'            => ['submitted','cancelled'],
        'submitted'        => ['partial_received','received','closed','cancelled'],
        'partial_received' => ['received','closed','cancelled'],
        'received'         => ['closed'],
        'closed'           => [],
        'cancelled'        => [],
    ];
    return in_array($to, $allowed[$from] ?? [], true);
}

// ── Parse a date+time pair from POST ───────────────────────────────────────
function parse_datetime($date_key, $time_key, $legacy_key, $default_now = false) {
    $date   = trim($_POST[$date_key]   ?? '');
    $time   = trim($_POST[$time_key]   ?? '');
    $legacy = trim($_POST[$legacy_key] ?? '');
    if ($date !== '') {
        $time = $time !== '' ? $time : '00:00';
        return $date . ' ' . $time . ':00';
    }
    if ($legacy !== '') {
        $dt = str_replace('T', ' ', $legacy);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dt)) $dt .= ':00';
        return $dt;
    }
    return $default_now ? date('Y-m-d H:i:s') : null;
}

$msg = $msg_type = '';

// ══════════════════════════════════════════════════════════════════════════
// POST HANDLERS
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Create PO ──────────────────────────────────────────────────────────
    if ($action === 'create_po') {
        $supplier_id = (int)$_POST['supplier_id'];
        $expected_at = parse_datetime('expected_date', 'expected_time', 'expected_at');
        $currency    = strtoupper(trim($_POST['currency'] ?? 'PHP')) ?: 'PHP';
        $notes       = trim($_POST['notes'] ?? '') ?: null;
        $po_number   = 'PO-' . date('YmdHis');

        if ($supplier_id <= 0) {
            $msg = 'Please select a supplier.'; $msg_type = 'error';
        } else {
        $stmt = $conn->prepare('INSERT INTO purchase_orders (po_number,supplier_id,expected_at,currency,notes,created_by) VALUES (?,?,?,?,?,?)');
        $stmt->bind_param('sisssi', $po_number, $supplier_id, $expected_at, $currency, $notes, $_SESSION['user_id']);
        $stmt->execute();
        $po_id = $conn->insert_id;
        $stmt->close();

        $item_ids = $_POST['item_id']      ?? [];
        $qtys     = $_POST['ordered_qty']  ?? [];
        $costs    = $_POST['unit_cost']    ?? [];
        $saved    = 0;

        foreach ($item_ids as $i => $item_id) {
            $q = (float)($qtys[$i]  ?? 0);
            $c = (float)($costs[$i] ?? 0);
            if ($item_id && $q > 0) {
                $lt  = $q * $c;
                $ins = $conn->prepare('INSERT INTO purchase_order_items (po_id,item_id,ordered_qty,unit_cost,tax,discount,line_total) VALUES (?,?,?,?,0,0,?)');
                $ins->bind_param('iiddd', $po_id, $item_id, $q, $c, $lt);
                $ins->execute();
                $ins->close();
                $saved++;
            }
        }
        $msg      = "PO <strong>{$po_number}</strong> created with {$saved} line item(s).";
        $msg_type = 'success';
        }
    }

    // ── Submit PO ──────────────────────────────────────────────────────────
    if ($action === 'submit_po' && can_manage_po()) {
        $po_id = (int)$_POST['po_id'];
        $row   = $conn->query("SELECT status FROM purchase_orders WHERE po_id={$po_id} LIMIT 1")->fetch_assoc();
        if ($row && validate_po_transition($row['status'], 'submitted')) {
            $stmt = $conn->prepare('UPDATE purchase_orders SET status="submitted", ordered_at=NOW(), submitted_by=?, submitted_at=NOW() WHERE po_id=?');
            $stmt->bind_param('ii', $_SESSION['user_id'], $po_id);
            $stmt->execute();
            $stmt->close();
            $msg = 'PO submitted successfully.'; $msg_type = 'success';
        } else {
            $msg = 'Cannot submit PO from its current status.'; $msg_type = 'error';
        }
    }

    // ── Cancel PO ──────────────────────────────────────────────────────────
    if ($action === 'cancel_po' && can_manage_po()) {
        $po_id = (int)$_POST['po_id'];
        $row   = $conn->query("SELECT status FROM purchase_orders WHERE po_id={$po_id} LIMIT 1")->fetch_assoc();
        if ($row && validate_po_transition($row['status'], 'cancelled')) {
            $conn->query("UPDATE purchase_orders SET status='cancelled' WHERE po_id={$po_id}");
            $msg = 'PO cancelled.'; $msg_type = 'success';
        } else {
            $msg = 'Cannot cancel PO from its current status.'; $msg_type = 'error';
        }
    }

    // ── Post Receipt ───────────────────────────────────────────────────────
    if ($action === 'post_receipt') {
        $po_id       = (int)$_POST['po_id'];
        $received_at = parse_datetime('received_date', 'received_time', 'received_at', true);
        $is_backdated = (strtotime($received_at) < time() - 300) ? 1 : 0;

        if ($is_backdated && !can_post_backdated()) {
            $msg = 'Only admin/manager can post backdated receipts.'; $msg_type = 'error';
        } else {
            $method       = ($_POST['allocation_method'] ?? 'value') === 'qty' ? 'qty' : 'value';
            $landed_total = (float)($_POST['landed_cost_total'] ?? 0);
            $ref          = trim($_POST['reference_no']  ?? '') ?: null;
            $notes        = trim($_POST['receipt_notes'] ?? '') ?: null;

            $gr = $conn->prepare('INSERT INTO goods_receipts (po_id,received_at,received_by,reference_no,notes,allocation_method,landed_cost_total,is_backdated) VALUES (?,?,?,?,?,?,?,?)');
            $gr->bind_param('isisssdi', $po_id, $received_at, $_SESSION['user_id'], $ref, $notes, $method, $landed_total, $is_backdated);
            $gr->execute();
            $receipt_id = $conn->insert_id;
            $gr->close();

            $items_q = $conn->query(
                "SELECT poi.po_item_id, poi.item_id, poi.ordered_qty, poi.received_qty,
                        poi.unit_cost, poi.line_total, po.supplier_id
                 FROM purchase_order_items poi
                 JOIN purchase_orders po ON po.po_id = poi.po_id
                 WHERE poi.po_id = {$po_id}"
            );

            $rows = []; $base = 0;
            while ($r = $items_q->fetch_assoc()) {
                $recv = (float)($_POST['recv_' . $r['po_item_id']] ?? 0);
                $rej  = (float)($_POST['rej_'  . $r['po_item_id']] ?? 0);
                $acc  = max(0, $recv - $rej);
                if ($recv <= 0) continue;
                $den   = $method === 'qty' ? $recv : ($r['unit_cost'] * $recv);
                $base += $den;
                $r['recv'] = $recv; $r['rej'] = $rej; $r['acc'] = $acc; $r['den'] = $den;
                $rows[] = $r;
            }

            foreach ($rows as $r) {
                $alloc    = $base > 0 ? $landed_total * ($r['den'] / $base) : 0;
                $adj_unit = $r['acc'] > 0 ? ($r['unit_cost'] + ($alloc / $r['acc'])) : $r['unit_cost'];
                $line_val = $adj_unit * $r['acc'];

                $ins = $conn->prepare('INSERT INTO goods_receipt_items (receipt_id,po_item_id,received_qty,accepted_qty,rejected_qty,landed_cost_alloc,adjusted_unit_cost,line_valuation_total) VALUES (?,?,?,?,?,?,?,?)');
                $ins->bind_param('iidddddd', $receipt_id, $r['po_item_id'], $r['recv'], $r['acc'], $r['rej'], $alloc, $adj_unit, $line_val);
                $ins->execute(); $ins->close();

                $u1 = $conn->prepare('UPDATE purchase_order_items SET received_qty=received_qty+?,last_adjusted_unit_cost=? WHERE po_item_id=?');
                $u1->bind_param('ddi', $r['acc'], $adj_unit, $r['po_item_id']);
                $u1->execute(); $u1->close();

                $accepted_int = (int)round($r['acc']);
                $u2 = $conn->prepare('UPDATE items SET stock=stock+? WHERE item_id=?');
                $u2->bind_param('ii', $accepted_int, $r['item_id']);
                $u2->execute(); $u2->close();

                $lg = $conn->prepare('INSERT INTO inventory_logs (item_id,user_id,action,quantity) VALUES (?,?,"inbound_receipt",?)');
                $lg->bind_param('iii', $r['item_id'], $_SESSION['user_id'], $accepted_int);
                $lg->execute(); $lg->close();

                $ch = $conn->prepare('INSERT INTO supplier_item_cost_history (supplier_id,item_id,cost,effective_at,source_receipt_item_id) VALUES (?,?,?,?,LAST_INSERT_ID())');
                $ch->bind_param('iids', $r['supplier_id'], $r['item_id'], $adj_unit, $received_at);
                $ch->execute(); $ch->close();
            }

            // Update PO status
            $sr   = $conn->query("SELECT SUM(ordered_qty) o, SUM(received_qty) r FROM purchase_order_items WHERE po_id={$po_id}")->fetch_assoc();
            $next = ((float)$sr['r'] <= 0) ? 'submitted'
                  : (((float)$sr['r'] < (float)$sr['o']) ? 'partial_received' : 'received');
            $conn->query("UPDATE purchase_orders SET status='{$next}' WHERE po_id={$po_id}");

            $msg = 'Receipt posted and inventory updated.'; $msg_type = 'success';
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════
// DATA FETCHING
// ══════════════════════════════════════════════════════════════════════════

// FIX: fetch suppliers fresh (not consumed by earlier loop)
$suppliers = $conn->query('SELECT supplier_id, supplier_name FROM suppliers WHERE status="active" ORDER BY supplier_name');

// FIX: fetch items into array once, reuse everywhere
$items_arr = [];
$ir = $conn->query('SELECT item_id, item_name FROM items ORDER BY item_name');
while ($it = $ir->fetch_assoc()) $items_arr[] = $it;

// PO list with summary stats
$po_rows = $conn->query('
    SELECT po.*, s.supplier_name,
           (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.po_id) AS item_count,
           (SELECT COALESCE(SUM(line_total),0) FROM purchase_order_items WHERE po_id = po.po_id) AS po_total
    FROM purchase_orders po
    JOIN suppliers s ON s.supplier_id = po.supplier_id
    ORDER BY po.created_at DESC
    LIMIT 100
');

// Status config
$status_cfg = [
    'draft'            => ['label'=>'Draft',     'ring'=>'border-slate-600',      'bg'=>'bg-slate-800',       'text'=>'text-slate-300',   'dot'=>'bg-slate-400'],
    'submitted'        => ['label'=>'Submitted',  'ring'=>'border-blue-500/40',    'bg'=>'bg-blue-500/10',     'text'=>'text-blue-300',    'dot'=>'bg-blue-400'],
    'partial_received' => ['label'=>'Partial',    'ring'=>'border-amber-500/40',   'bg'=>'bg-amber-500/10',    'text'=>'text-amber-300',   'dot'=>'bg-amber-400'],
    'received'         => ['label'=>'Received',   'ring'=>'border-emerald-500/40', 'bg'=>'bg-emerald-500/10',  'text'=>'text-emerald-300', 'dot'=>'bg-emerald-400'],
    'closed'           => ['label'=>'Closed',     'ring'=>'border-purple-500/40',  'bg'=>'bg-purple-500/10',   'text'=>'text-purple-300',  'dot'=>'bg-purple-400'],
    'cancelled'        => ['label'=>'Cancelled',  'ring'=>'border-red-500/40',     'bg'=>'bg-red-500/10',      'text'=>'text-red-400',     'dot'=>'bg-red-400'],
];

function status_badge($st, $cfg) {
    $c = $cfg[$st] ?? ['label'=>$st,'ring'=>'border-slate-700','bg'=>'bg-slate-800','text'=>'text-slate-400','dot'=>'bg-slate-500'];
    return "<span class=\"inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border {$c['ring']} {$c['bg']} {$c['text']}\"><span class=\"w-1.5 h-1.5 rounded-full {$c['dot']} shrink-0\"></span>{$c['label']}</span>";
}

// Build supplier options once
$supplier_opts = '<option value="">— Select supplier —</option>';
while ($s = $suppliers->fetch_assoc()) {
    $supplier_opts .= '<option value="' . $s['supplier_id'] . '">' . htmlspecialchars($s['supplier_name']) . '</option>';
}

// Build item options once
$item_opts = '<option value="">— Select item —</option>';
foreach ($items_arr as $it) {
    $item_opts .= '<option value="' . $it['item_id'] . '">' . htmlspecialchars($it['item_name']) . '</option>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Purchase Orders — JOEBZ POS</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
    :root {
        --accent:      #3b82f6;
        --accent-glow: rgba(59,130,246,0.18);
        --up:          #10b981;
        --down:        #f43f5e;
        --warn:        #f59e0b;
    }

    /* Sidebar: system font (consistent with all other pages) */
    aside { font-family: ui-sans-serif, system-ui, sans-serif; }

    /* Main content: DM Sans + Syne headings */
    .main-content { font-family: 'DM Sans', sans-serif; }
    .main-content h1,
    .main-content h2,
    .main-content h3 { font-family: 'Syne', sans-serif; }
    .mono { font-family: 'DM Mono', monospace; }

    /* Staggered reveal */
    @keyframes slideUp {
        from { opacity: 0; transform: translateY(18px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .reveal   { opacity: 0; animation: slideUp 0.45s ease forwards; }
    .reveal-1 { animation-delay: .05s; }
    .reveal-2 { animation-delay: .10s; }
    .reveal-3 { animation-delay: .16s; }
    .reveal-4 { animation-delay: .22s; }
    .reveal-5 { animation-delay: .28s; }

    /* Loading overlay */
    .loading-overlay { position:fixed; inset:0; background:rgba(0,0,0,.7); display:none; align-items:center; justify-content:center; z-index:1000; }
    .loading-spinner  { border:4px solid #334155; border-top-color:#3b82f6; border-radius:50%; width:48px; height:48px; animation:spin .8s linear infinite; }
    @keyframes spin { to { transform:rotate(360deg); } }

    /* Accordion */
    .accordion-panel { max-height:0; overflow:hidden; transition:max-height .38s cubic-bezier(.4,0,.2,1); }
    .accordion-panel.open { max-height:2400px; }
    .chevron { transition:transform .25s ease; }
    .chevron.open { transform:rotate(180deg); }

    /* Input style */
    .input-field {
        width:100%; background:rgba(30,41,59,.85);
        border:1px solid #334155; border-radius:.75rem;
        color:#f1f5f9; padding:.5rem .75rem;
        font-family:'DM Sans',sans-serif;
        transition:border-color .15s, box-shadow .15s;
        outline:none;
    }
    .input-field:focus { border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-glow); }
    .input-field::placeholder { color:#475569; }
    select.input-field option { background:#1e293b; }

    /* PO card */
    .po-card { transition:border-color .18s, box-shadow .18s; }
    .po-card:hover { border-color:#334155; box-shadow:0 4px 24px rgba(0,0,0,.35); }

    /* Filter tabs */
    .ftab { transition:all .15s; }
    .ftab.active { background:#1e40af; color:#bfdbfe; border-color:#3b82f6; }

    /* Live pulse dot */
    @keyframes pulse-ring {
        0%   { transform:scale(.8); opacity:1; }
        100% { transform:scale(1.8); opacity:0; }
    }
    .live-dot { position:relative; width:8px; height:8px; border-radius:50%; background:var(--up); display:inline-block; }
    .live-dot::after { content:''; display:block; position:absolute; inset:0; border-radius:50%; background:var(--up); animation:pulse-ring 1.6s ease infinite; }

    /* Scrollbar */
    ::-webkit-scrollbar { width:5px; height:5px; }
    ::-webkit-scrollbar-track { background:transparent; }
    ::-webkit-scrollbar-thumb { background:#334155; border-radius:9999px; }

    /* Item row animation */
    @keyframes itemFade { from{opacity:0;transform:translateY(5px)} to{opacity:1;transform:none} }
    .item-row { animation:itemFade .18s ease both; }

    /* Custom confirm modal */
    @keyframes modalIn { from{opacity:0;transform:scale(.95) translateY(10px)} to{opacity:1;transform:scale(1) translateY(0)} }

    /* Stat cards on header row */
    .stat-pill {
        display:flex; align-items:center; gap:.5rem;
        background:rgba(15,23,42,.6); border:1px solid #1e293b;
        border-radius:1rem; padding:.5rem 1rem;
    }
</style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

<div class="loading-overlay" id="loading"><div class="loading-spinner"></div></div>

<!-- ═══════════ CONFIRM MODAL ═══════════ -->
<div id="confirmModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div id="confirmPanel" class="bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl w-full max-w-sm p-6" style="animation:modalIn .25s ease both">
        <div class="flex items-start gap-4 mb-5">
            <div id="confirmIcon" class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0"></div>
            <div>
                <h3 id="confirmTitle" class="font-bold text-white text-base"></h3>
                <p  id="confirmBody"  class="text-sm text-slate-400 mt-0.5"></p>
            </div>
        </div>
        <div class="flex gap-3">
            <button onclick="closeConfirm()" class="flex-1 border border-slate-700 hover:bg-slate-800 text-slate-300 py-2.5 rounded-xl text-sm font-medium transition">Cancel</button>
            <button id="confirmOkBtn"        class="flex-1 py-2.5 rounded-xl text-sm font-semibold transition">Confirm</button>
        </div>
    </div>
</div>

<!-- ═══════════ SIDEBAR ═══════════ -->
<aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 transform bg-slate-950 border-r border-slate-800 flex flex-col transition-transform duration-200 ease-out -translate-x-full md:translate-x-0">
    <div class="flex items-center gap-3 px-6 py-5 border-b border-slate-800">
        <img src="assets/logo.png" alt="JOEBZ Logo" class="w-10 h-10 rounded-xl object-cover">
        <span class="text-lg font-bold text-slate-100 tracking-tight">JOEBZ</span>
    </div>
    <nav class="flex-1 px-4 py-4 space-y-1 overflow-y-auto">
        <?php
        $cur = basename($_SERVER['PHP_SELF']);
        if ($_SESSION['role'] === 'admin'):
            $nav = [
                ['dashboard.php',       'Dashboard',       'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                ['items.php',           'Items',           'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10'],
                ['categories.php',      'Categories',      'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
                ['reports.php',         'Reports',         'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                ['users.php',           'Users',           'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
                // FIX: correct purchase orders icon (clipboard/document)
                ['purchase_orders.php', 'Purchase Orders', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'],
            ];
            foreach ($nav as [$href, $label, $path]):
                $active = $cur === $href;
        ?>
        <a href="<?= $href ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= $active ? 'bg-blue-600/20 text-blue-200 font-medium' : 'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $path ?>"/></svg>
            <?= $label ?>
        </a>
        <?php endforeach; endif; ?>
        <a href="sales.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= $cur === 'sales.php' ? 'bg-blue-600/20 text-blue-200 font-medium' : 'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
            Point of Sale
        </a>
    </nav>
    <div class="px-4 py-4 border-t border-slate-800">
        <div class="flex items-center gap-3 px-3 py-2 rounded-xl bg-slate-900 mb-2">
            <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0">
                <?= strtoupper(substr($_SESSION['first_name'], 0, 1)) ?>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-medium text-slate-100 truncate"><?= htmlspecialchars($_SESSION['first_name']) ?></p>
                <p class="text-xs text-slate-400 capitalize"><?= $_SESSION['role'] ?></p>
            </div>
        </div>
        <a href="logout.php" class="flex items-center gap-3 px-3 py-2 rounded-xl text-red-300 hover:bg-red-900/40 text-sm transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </div>
</aside>

<!-- ═══════════ MAIN ═══════════ -->
<div class="flex-1 md:ml-64 min-h-screen main-content">
<div class="max-w-7xl mx-auto px-4 sm:px-6 py-6">

    <!-- Mobile top bar -->
    <div class="mb-5 flex items-center justify-between md:hidden">
        <button id="open-sidebar" class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium text-slate-200">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            Menu
        </button>
        <a href="logout.php" class="text-sm text-red-300 border border-red-800/40 bg-red-900/20 px-3 py-2 rounded-xl">Logout</a>
    </div>

    <!-- Page header -->
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4 reveal reveal-1">
        <div>
            <h1 class="text-3xl font-bold text-white tracking-tight">Purchase Orders</h1>
            <p class="text-slate-400 mt-1 text-sm">
                Welcome back, <span class="text-blue-300"><?= htmlspecialchars($_SESSION['first_name']) ?></span>
                &nbsp;·&nbsp; <?= date('l, F j, Y') ?>
            </p>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <!-- Live clock -->
            <div class="hidden sm:flex items-center gap-2 text-xs text-slate-400 bg-slate-900 border border-slate-800 px-3 py-1.5 rounded-full">
                <span class="live-dot"></span>
                <span id="liveTime" class="mono"></span>
            </div>
            <?php if (can_manage_po()): ?>
            <button onclick="openCreatePanel()"
                class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-500 transition text-white text-sm font-semibold px-4 py-2.5 rounded-xl"
                style="box-shadow:0 4px 16px rgba(59,130,246,.25)">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New PO
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert banner -->
    <?php if ($msg): ?>
    <div id="alertMsg" class="mb-6 p-4 rounded-2xl border flex items-center justify-between gap-3 reveal reveal-2
        <?= $msg_type === 'error' ? 'bg-red-900/30 border-red-700/50 text-red-200' : 'bg-emerald-900/30 border-emerald-700/50 text-emerald-200' ?>">
        <div class="flex items-center gap-3">
            <?php if ($msg_type === 'error'): ?>
            <svg class="w-5 h-5 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?php else: ?>
            <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?php endif; ?>
            <span class="text-sm"><?= $msg ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="opacity-40 hover:opacity-80 transition text-xl leading-none shrink-0">&times;</button>
    </div>
    <?php endif; ?>

    <!-- ══ CREATE PO PANEL ══ -->
    <div id="createPanel" class="hidden mb-6 reveal reveal-2">
        <div class="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-xl bg-blue-600/20 border border-blue-500/30 flex items-center justify-center">
                        <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <h2 class="text-base font-bold text-white">Create Purchase Order</h2>
                </div>
                <button onclick="closeCreatePanel()" class="w-8 h-8 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition text-xl leading-none">&times;</button>
            </div>
            <form method="post" id="createForm" class="p-6 space-y-5">
                <input type="hidden" name="action" value="create_po">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Supplier <span class="text-red-400">*</span></label>
                        <select name="supplier_id" required class="input-field text-sm">
                            <?= $supplier_opts ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Expected Delivery</label>
                        <div class="flex gap-2">
                            <input name="expected_date" type="date" class="input-field text-sm flex-1">
                            <input name="expected_time" type="time" class="input-field text-sm w-28">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Currency</label>
                        <input name="currency" value="PHP" maxlength="3" class="input-field text-sm mono uppercase tracking-widest">
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Notes</label>
                    <textarea name="notes" rows="2" placeholder="Optional notes…" class="input-field text-sm resize-none"></textarea>
                </div>

                <!-- Line items -->
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <label class="text-xs text-slate-400 uppercase tracking-wider font-medium">Line Items</label>
                        <button type="button" id="addItemBtn"
                            class="inline-flex items-center gap-1.5 text-xs text-blue-400 hover:text-blue-300 font-medium transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Row
                        </button>
                    </div>
                    <div class="hidden md:grid grid-cols-12 gap-3 mb-1.5 px-1 text-xs text-slate-500 font-medium uppercase tracking-wider">
                        <div class="col-span-6">Item</div>
                        <div class="col-span-3">Qty</div>
                        <div class="col-span-3">Unit Cost</div>
                    </div>
                    <div id="itemLines" class="space-y-2">
                        <?php for ($i = 0; $i < 3; $i++): ?>
                        <div class="item-row grid grid-cols-12 gap-3 items-center" style="animation-delay:<?= $i * .06 ?>s">
                            <div class="col-span-12 md:col-span-6">
                                <select name="item_id[]" class="input-field text-sm"><?= $item_opts ?></select>
                            </div>
                            <div class="col-span-6 md:col-span-3">
                                <input name="ordered_qty[]" type="number" step="0.01" min="0" placeholder="0.00" class="input-field text-sm mono">
                            </div>
                            <div class="col-span-5 md:col-span-2">
                                <input name="unit_cost[]" type="number" step="0.0001" min="0" placeholder="0.0000" class="input-field text-sm mono">
                            </div>
                            <div class="col-span-1 flex justify-end">
                                <button type="button" onclick="removeRow(this)" class="text-slate-600 hover:text-red-400 transition p-1.5 rounded-lg hover:bg-red-900/20">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <!-- Line total preview -->
                    <div id="lineTotalPreview" class="mt-3 px-1 flex justify-end">
                        <span class="text-xs text-slate-500">Est. PO Total: <span id="estTotal" class="mono text-slate-300">₱0.00</span></span>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2 border-t border-slate-800">
                    <button type="button" onclick="closeCreatePanel()" class="px-4 py-2.5 text-sm text-slate-400 hover:text-slate-200 transition">Cancel</button>
                    <button type="submit"
                        class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-500 transition text-white text-sm font-semibold px-5 py-2.5 rounded-xl"
                        style="box-shadow:0 4px 16px rgba(59,130,246,.25)">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Create PO
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══ PO LIST ══ -->
    <div class="reveal reveal-3">

        <!-- Search + filter bar -->
        <div class="flex flex-col sm:flex-row gap-3 mb-4">
            <div class="relative flex-1">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input id="poSearch" type="text" placeholder="Search by PO number or supplier…" class="input-field pl-9 text-sm">
            </div>
            <div class="flex gap-1.5 flex-wrap">
                <?php foreach ([
                    'all'              => 'All',
                    'draft'            => 'Draft',
                    'submitted'        => 'Submitted',
                    'partial_received' => 'Partial',
                    'received'         => 'Received',
                    'closed'           => 'Closed',
                    'cancelled'        => 'Cancelled',
                ] as $fv => $fl): ?>
                <button data-filter="<?= $fv ?>"
                    class="ftab <?= $fv === 'all' ? 'active' : '' ?> px-3 py-2 rounded-xl text-xs font-medium bg-slate-900 border border-slate-800 text-slate-400 hover:text-slate-200 transition">
                    <?= $fl ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Column headers -->
        <div class="hidden md:grid grid-cols-12 gap-4 px-5 py-2 mb-1 text-xs font-medium text-slate-500 uppercase tracking-wider">
            <div class="col-span-3">PO Number</div>
            <div class="col-span-3">Supplier</div>
            <div class="col-span-2">Status</div>
            <div class="col-span-2">Total</div>
            <div class="col-span-2 text-right">Actions</div>
        </div>

        <!-- PO cards -->
        <div id="poList" class="space-y-2">
        <?php
        $po_count = 0;
        while ($po = $po_rows->fetch_assoc()):
            $po_count++;
            $st          = $po['status'];
            $created_fmt = date('M j, Y', strtotime($po['created_at']));
            $can_submit  = can_manage_po() && validate_po_transition($st, 'submitted');
            $can_cancel  = can_manage_po() && validate_po_transition($st, 'cancelled');
            $can_receipt = in_array($st, ['submitted', 'partial_received'], true);

            // FIX: fetch line items directly here (no data_seek needed since each PO gets its own query)
            $poi_items = $conn->query('
                SELECT poi.po_item_id, i.item_name,
                       poi.ordered_qty, poi.received_qty,
                       (poi.ordered_qty - poi.received_qty) AS remaining,
                       poi.unit_cost, poi.line_total
                FROM purchase_order_items poi
                JOIN items i ON i.item_id = poi.item_id
                WHERE poi.po_id = ' . (int)$po['po_id']
            );

            $poi_recv = null;
            if ($can_receipt) {
                $poi_recv = $conn->query('
                    SELECT poi.po_item_id, i.item_name,
                           (poi.ordered_qty - poi.received_qty) AS remaining
                    FROM purchase_order_items poi
                    JOIN items i ON i.item_id = poi.item_id
                    WHERE poi.po_id = ' . (int)$po['po_id'] . '
                      AND (poi.ordered_qty - poi.received_qty) > 0'
                );
            }
        ?>
        <div class="po-card bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden"
             data-status="<?= $st ?>"
             data-search="<?= strtolower(htmlspecialchars($po['po_number'] . ' ' . $po['supplier_name'])) ?>">

            <!-- ── Summary row ── -->
            <div class="grid grid-cols-12 gap-4 items-center px-5 py-4">

                <!-- PO number (+ mobile supplier) -->
                <div class="col-span-7 md:col-span-3">
                    <p class="mono text-sm font-medium text-blue-300"><?= htmlspecialchars($po['po_number']) ?></p>
                    <p class="md:hidden text-xs text-slate-400 mt-0.5 truncate"><?= htmlspecialchars($po['supplier_name']) ?></p>
                    <p class="text-xs text-slate-600 mt-0.5"><?= $created_fmt ?></p>
                </div>

                <!-- Supplier (desktop) -->
                <div class="hidden md:block col-span-3 text-sm text-slate-300 truncate">
                    <?= htmlspecialchars($po['supplier_name']) ?>
                </div>

                <!-- Status badge (desktop) -->
                <div class="hidden md:flex col-span-2 items-center">
                    <?= status_badge($st, $status_cfg) ?>
                </div>

                <!-- Total (desktop) -->
                <div class="hidden md:block col-span-2">
                    <p class="mono text-sm text-white">₱<?= number_format((float)$po['po_total'], 2) ?></p>
                    <p class="text-xs text-slate-500"><?= $po['item_count'] ?> item<?= $po['item_count'] != 1 ? 's' : '' ?></p>
                </div>

                <!-- Actions -->
                <div class="col-span-5 md:col-span-2 flex items-center justify-end gap-1.5 flex-wrap">

                    <!-- Mobile status -->
                    <span class="md:hidden"><?= status_badge($st, $status_cfg) ?></span>

                    <?php if ($can_submit): ?>
                    <button type="button"
                        onclick="showConfirm('submit','<?= $po['po_id'] ?>','Submit PO','Submit <strong><?= htmlspecialchars($po['po_number']) ?></strong> to the supplier?','Submit','bg-blue-600 hover:bg-blue-500 text-white')"
                        class="inline-flex items-center gap-1 text-xs bg-blue-600/20 hover:bg-blue-600/40 border border-blue-500/30 text-blue-300 px-2.5 py-1.5 rounded-xl font-medium transition">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Submit
                    </button>
                    <?php endif; ?>

                    <?php if ($can_cancel): ?>
                    <button type="button"
                        onclick="showConfirm('cancel','<?= $po['po_id'] ?>','Cancel PO','Cancel <strong><?= htmlspecialchars($po['po_number']) ?></strong>? This cannot be undone.','Cancel PO','bg-red-600 hover:bg-red-500 text-white')"
                        class="inline-flex items-center text-xs bg-red-600/10 hover:bg-red-600/25 border border-red-500/30 text-red-400 p-1.5 rounded-xl transition" title="Cancel PO">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                    <?php endif; ?>

                    <?php if ($can_receipt): ?>
                    <button onclick="toggleReceipt(<?= $po['po_id'] ?>)"
                        class="inline-flex items-center gap-1 text-xs bg-emerald-600/20 hover:bg-emerald-600/40 border border-emerald-500/30 text-emerald-300 px-2.5 py-1.5 rounded-xl font-medium transition">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Receive
                    </button>
                    <?php endif; ?>

                    <!-- Toggle details -->
                    <button onclick="toggleDetails(<?= $po['po_id'] ?>)"
                        class="text-slate-500 hover:text-slate-300 p-1.5 rounded-xl hover:bg-slate-800 transition" title="View line items">
                        <svg id="chev-<?= $po['po_id'] ?>" class="chevron w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                </div>
            </div>

            <!-- ── Details accordion ── -->
            <div class="accordion-panel border-t border-slate-800/50" id="details-<?= $po['po_id'] ?>">
                <div class="px-5 py-5">
                    <p class="text-xs text-slate-500 uppercase tracking-wider font-medium mb-3">Line Items</p>
                    <?php if ($poi_items && $poi_items->num_rows > 0): ?>
                    <div class="rounded-xl overflow-hidden border border-slate-800">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-800/60">
                                <tr class="text-xs text-slate-500 font-medium uppercase tracking-wider">
                                    <th class="text-left px-4 py-2.5">Item</th>
                                    <th class="text-right px-4 py-2.5">Ordered</th>
                                    <th class="text-right px-4 py-2.5">Received</th>
                                    <th class="text-right px-4 py-2.5">Remaining</th>
                                    <th class="text-right px-4 py-2.5">Unit Cost</th>
                                    <th class="text-right px-4 py-2.5">Line Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60">
                            <?php while ($r = $poi_items->fetch_assoc()):
                                $rem = (float)$r['remaining'];
                                $rc  = $rem <= 0
                                    ? 'text-emerald-400'
                                    : ($rem < (float)$r['ordered_qty'] ? 'text-amber-400' : 'text-slate-300');
                            ?>
                            <tr class="hover:bg-slate-800/30 transition">
                                <td class="px-4 py-3 text-slate-200"><?= htmlspecialchars($r['item_name']) ?></td>
                                <td class="px-4 py-3 text-right mono text-slate-300"><?= number_format($r['ordered_qty'],  2) ?></td>
                                <td class="px-4 py-3 text-right mono text-slate-300"><?= number_format($r['received_qty'], 2) ?></td>
                                <td class="px-4 py-3 text-right mono <?= $rc ?>"><?= number_format($rem, 2) ?></td>
                                <td class="px-4 py-3 text-right mono text-slate-300">₱<?= number_format($r['unit_cost'],  4) ?></td>
                                <td class="px-4 py-3 text-right mono text-slate-300">₱<?= number_format($r['line_total'], 2) ?></td>
                            </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-xs text-slate-600 italic py-3">No line items on this PO.</p>
                    <?php endif; ?>

                    <!-- Metadata row -->
                    <div class="mt-4 pt-4 border-t border-slate-800/60 grid grid-cols-2 md:grid-cols-4 gap-4">
                        <?php foreach ([
                            ['Currency',  $po['currency'] ?? 'PHP'],
                            ['Expected',  $po['expected_at'] ? date('M j, Y H:i', strtotime($po['expected_at'])) : '—'],
                            ['Ordered At', $po['ordered_at'] ? date('M j, Y H:i', strtotime($po['ordered_at'])) : '—'],
                            ['Notes',     $po['notes'] ?: '—'],
                        ] as [$lbl, $val]): ?>
                        <div>
                            <p class="text-xs text-slate-500 mb-0.5"><?= $lbl ?></p>
                            <p class="text-sm text-slate-300 break-words"><?= htmlspecialchars($val) ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ── Receipt accordion ── -->
            <?php if ($can_receipt): ?>
            <div class="accordion-panel border-t border-slate-800/50" id="receipt-<?= $po['po_id'] ?>">
                <div class="px-5 py-5">
                    <div class="flex items-center gap-2.5 mb-5">
                        <div class="w-1.5 h-5 bg-emerald-500 rounded-full"></div>
                        <h3 class="text-sm font-bold text-white">Post Goods Receipt</h3>
                    </div>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="action" value="post_receipt">
                        <input type="hidden" name="po_id"  value="<?= $po['po_id'] ?>">

                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                            <div>
                                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Date</label>
                                <input type="date" name="received_date" value="<?= date('Y-m-d') ?>" class="input-field text-sm">
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Time</label>
                                <input type="time" name="received_time" value="<?= date('H:i') ?>" class="input-field text-sm">
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Reference No.</label>
                                <input name="reference_no" placeholder="e.g. DR-12345" class="input-field text-sm">
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Allocation</label>
                                <select name="allocation_method" class="input-field text-sm">
                                    <option value="value">By Line Value</option>
                                    <option value="qty">By Quantity</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Landed Cost Total</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm mono pointer-events-none">₱</span>
                                    <input name="landed_cost_total" type="number" step="0.0001" min="0" placeholder="0.00" class="input-field pl-7 text-sm mono">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Notes</label>
                                <input name="receipt_notes" placeholder="Optional receipt notes" class="input-field text-sm">
                            </div>
                        </div>

                        <?php if ($poi_recv && $poi_recv->num_rows > 0): ?>
                        <div>
                            <label class="block text-xs text-slate-400 uppercase tracking-wider mb-2">Items to Receive</label>
                            <div class="rounded-xl overflow-hidden border border-slate-800">
                                <div class="hidden md:grid grid-cols-12 gap-3 px-4 py-2.5 bg-slate-800/60 text-xs text-slate-500 font-medium uppercase tracking-wider">
                                    <div class="col-span-5">Item</div>
                                    <div class="col-span-2 text-right">Remaining</div>
                                    <div class="col-span-2 text-center">Received</div>
                                    <div class="col-span-3 text-center">Rejected</div>
                                </div>
                                <?php while ($r = $poi_recv->fetch_assoc()): ?>
                                <div class="grid grid-cols-12 gap-3 items-center px-4 py-3 border-t border-slate-800/60 hover:bg-slate-800/30 transition">
                                    <div class="col-span-12 md:col-span-5 text-sm text-slate-200 font-medium">
                                        <?= htmlspecialchars($r['item_name']) ?>
                                        <span class="md:hidden text-xs text-slate-500 ml-1">(rem: <?= number_format((float)$r['remaining'], 2) ?>)</span>
                                    </div>
                                    <div class="hidden md:flex col-span-2 justify-end">
                                        <span class="mono text-sm text-amber-400"><?= number_format((float)$r['remaining'], 2) ?></span>
                                    </div>
                                    <div class="col-span-6 md:col-span-2">
                                        <label class="md:hidden text-xs text-slate-500 mb-0.5 block">Received</label>
                                        <input type="number" step="0.01" min="0" max="<?= (float)$r['remaining'] ?>"
                                            name="recv_<?= $r['po_item_id'] ?>" placeholder="0"
                                            class="input-field text-sm mono text-center">
                                    </div>
                                    <div class="col-span-6 md:col-span-3">
                                        <label class="md:hidden text-xs text-slate-500 mb-0.5 block">Rejected</label>
                                        <input type="number" step="0.01" min="0"
                                            name="rej_<?= $r['po_item_id'] ?>" placeholder="0"
                                            class="input-field text-sm mono text-center" style="color:#f87171">
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="flex items-center gap-2.5 p-4 bg-emerald-900/20 border border-emerald-700/30 rounded-xl">
                            <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <p class="text-sm text-emerald-300">All items on this PO have been fully received.</p>
                        </div>
                        <?php endif; ?>

                        <div class="flex justify-end pt-1">
                            <button type="submit"
                                class="inline-flex items-center gap-2 text-sm font-semibold text-white px-5 py-2.5 rounded-xl transition"
                                style="background:linear-gradient(135deg,#059669,#10b981);box-shadow:0 4px 16px rgba(16,185,129,.25)">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Post Receipt & Update Stock
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.po-card -->
        <?php endwhile; ?>
        </div><!-- /#poList -->

        <!-- Empty state -->
        <?php if ($po_count === 0): ?>
        <div class="text-center py-16 text-slate-600">
            <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            <p class="text-sm">No purchase orders yet.</p>
            <?php if (can_manage_po()): ?>
            <button onclick="openCreatePanel()" class="mt-3 text-blue-400 text-xs hover:text-blue-300 transition">+ Create your first PO</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <p id="noResults" class="hidden text-center text-sm text-slate-600 py-12">No purchase orders match your search or filter.</p>
    </div><!-- /.reveal -->

</div>
</div>

<!-- Hidden action forms (submitted by confirm modal) -->
<form method="post" id="actionForm" class="hidden">
    <input type="hidden" name="action" id="actionFormAction">
    <input type="hidden" name="po_id"  id="actionFormPoId">
</form>

<script>
// ── Sidebar ────────────────────────────────────────────────────────────────
const sidebar = document.getElementById('sidebar');
const openBtn = document.getElementById('open-sidebar');
if (openBtn) openBtn.addEventListener('click', () => sidebar.classList.remove('-translate-x-full'));
document.addEventListener('click', e => {
    if (window.innerWidth < 768 && sidebar && openBtn &&
        !sidebar.contains(e.target) && !openBtn.contains(e.target))
        sidebar.classList.add('-translate-x-full');
});
window.addEventListener('resize', () => {
    sidebar.classList.toggle('-translate-x-full', window.innerWidth < 768);
});

// ── Live clock ─────────────────────────────────────────────────────────────
function tick() {
    const el = document.getElementById('liveTime');
    if (el) el.textContent = new Date().toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
}
tick(); setInterval(tick, 1000);

// ── Auto-dismiss alert ─────────────────────────────────────────────────────
setTimeout(() => {
    const a = document.getElementById('alertMsg');
    if (!a) return;
    a.style.transition = 'opacity .5s ease'; a.style.opacity = '0';
    setTimeout(() => a.remove(), 500);
}, 5000);

// ── Create panel ───────────────────────────────────────────────────────────
function openCreatePanel() {
    const p = document.getElementById('createPanel');
    p.classList.remove('hidden');
    setTimeout(() => p.scrollIntoView({ behavior:'smooth', block:'start' }), 50);
}
function closeCreatePanel() {
    document.getElementById('createPanel').classList.add('hidden');
}

// ── Confirm modal (replaces browser confirm()) ─────────────────────────────
function showConfirm(type, poId, title, body, okLabel, okClass) {
    const icons = {
        submit: { bg:'bg-blue-900/30 border-blue-800/40', svg:'<svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' },
        cancel: { bg:'bg-red-900/30 border-red-800/40',   svg:'<svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>' },
    };
    const ic = icons[type] || icons.submit;

    document.getElementById('confirmTitle').textContent  = title;
    document.getElementById('confirmBody').innerHTML     = body;
    document.getElementById('confirmOkBtn').textContent  = okLabel;
    document.getElementById('confirmOkBtn').className    = 'flex-1 py-2.5 rounded-xl text-sm font-semibold transition ' + okClass;
    document.getElementById('confirmIcon').className     = 'w-11 h-11 rounded-xl flex items-center justify-center shrink-0 border ' + ic.bg;
    document.getElementById('confirmIcon').innerHTML     = ic.svg;

    // Re-trigger panel animation
    const panel = document.getElementById('confirmPanel');
    panel.style.animation = 'none'; panel.offsetHeight; panel.style.animation = '';

    document.getElementById('confirmOkBtn').onclick = () => {
        document.getElementById('actionFormAction').value = type === 'submit' ? 'submit_po' : 'cancel_po';
        document.getElementById('actionFormPoId').value   = poId;
        closeConfirm();
        document.getElementById('loading').style.display = 'flex';
        document.getElementById('actionForm').submit();
    };

    const m = document.getElementById('confirmModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeConfirm() {
    const m = document.getElementById('confirmModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
document.getElementById('confirmModal').addEventListener('click', e => {
    if (e.target === document.getElementById('confirmModal')) closeConfirm();
});

// ── Details accordion (independent per PO) ─────────────────────────────────
function toggleDetails(id) {
    const panel = document.getElementById('details-' + id);
    const chev  = document.getElementById('chev-' + id);
    const open  = panel.classList.contains('open');
    panel.classList.toggle('open', !open);
    if (chev) chev.classList.toggle('open', !open);
}

// ── Receipt accordion (only one open at a time) ────────────────────────────
function toggleReceipt(id) {
    const panel  = document.getElementById('receipt-' + id);
    if (!panel) return;
    const wasOpen = panel.classList.contains('open');
    document.querySelectorAll('[id^="receipt-"]').forEach(p => p.classList.remove('open'));
    if (!wasOpen) {
        panel.classList.add('open');
        setTimeout(() => panel.scrollIntoView({ behavior:'smooth', block:'nearest' }), 80);
    }
}

// ── Search + filter ────────────────────────────────────────────────────────
let activeFilter = 'all';
const searchEl   = document.getElementById('poSearch');

document.querySelectorAll('.ftab').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.ftab').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        activeFilter = this.dataset.filter;
        applyFilters();
    });
});
if (searchEl) searchEl.addEventListener('input', applyFilters);

function applyFilters() {
    const q    = searchEl ? searchEl.value.toLowerCase().trim() : '';
    const rows = document.querySelectorAll('.po-card');
    let vis = 0;
    rows.forEach(row => {
        const ok = (activeFilter === 'all' || row.dataset.status === activeFilter)
                && (!q || (row.dataset.search || '').includes(q));
        row.style.display = ok ? '' : 'none';
        if (ok) vis++;
    });
    document.getElementById('noResults').classList.toggle('hidden', vis > 0);
}

// ── Dynamic item rows (Create PO) ──────────────────────────────────────────
const itemOptions = <?= json_encode(array_map(fn($it) => ['id' => $it['item_id'], 'name' => $it['item_name']], $items_arr)) ?>;

function makeItemSelect() {
    let s = '<select name="item_id[]" class="input-field text-sm"><option value="">— Select item —</option>';
    itemOptions.forEach(o => { s += `<option value="${o.id}">${o.name}</option>`; });
    return s + '</select>';
}

function removeRow(btn) {
    btn.closest('.item-row').remove();
    updateEstTotal();
}

document.getElementById('addItemBtn').addEventListener('click', () => {
    const div = document.createElement('div');
    div.className = 'item-row grid grid-cols-12 gap-3 items-center';
    div.innerHTML =
        `<div class="col-span-12 md:col-span-6">${makeItemSelect()}</div>` +
        `<div class="col-span-6 md:col-span-3"><input name="ordered_qty[]" type="number" step="0.01" min="0" placeholder="0.00" class="input-field text-sm mono" oninput="updateEstTotal()"></div>` +
        `<div class="col-span-5 md:col-span-2"><input name="unit_cost[]" type="number" step="0.0001" min="0" placeholder="0.0000" class="input-field text-sm mono" oninput="updateEstTotal()"></div>` +
        `<div class="col-span-1 flex justify-end"><button type="button" onclick="removeRow(this)" class="text-slate-600 hover:text-red-400 transition p-1.5 rounded-lg hover:bg-red-900/20">` +
        `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div>`;
    document.getElementById('itemLines').appendChild(div);
});

// Attach live total to initial rows too
document.getElementById('itemLines').addEventListener('input', updateEstTotal);

function updateEstTotal() {
    const qtys  = document.querySelectorAll('#itemLines input[name="ordered_qty[]"]');
    const costs = document.querySelectorAll('#itemLines input[name="unit_cost[]"]');
    let total = 0;
    qtys.forEach((q, i) => { total += (parseFloat(q.value) || 0) * (parseFloat(costs[i]?.value) || 0); });
    document.getElementById('estTotal').textContent = '₱' + total.toLocaleString('en-PH', { minimumFractionDigits:2, maximumFractionDigits:2 });
}

// Escape key closes panels/modals
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeConfirm(); closeCreatePanel(); }
});

</script>
</body>
</html>
 