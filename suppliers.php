<?php
date_default_timezone_set('Asia/Manila');
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
if ($_SESSION['role'] !== 'admin') {
    header('Location: sales.php');
    exit;
}

$success_msg = '';
$error_msg   = '';

// ── Introspect columns ──────────────────────────────────────────────────────
$col_res = $conn->query("SHOW COLUMNS FROM suppliers");
$columns = [];
while ($c = $col_res->fetch_assoc()) $columns[$c['Field']] = true;

$contact_candidates = [
    'contact_person' => 'Contact Person',
    'contact_name'   => 'Contact Name',
    'phone'          => 'Phone',
    'mobile'         => 'Mobile',
    'email'          => 'Email',
    'address'        => 'Address',
];
$contact_fields = [];
foreach ($contact_candidates as $field => $label) {
    if (isset($columns[$field])) $contact_fields[$field] = $label;
}

// ── POST handlers ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ADD
    if ($action === 'add') {
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        if ($supplier_name === '') {
            $error_msg = 'Supplier name cannot be empty.';
        } else {
            $chk = $conn->prepare('SELECT supplier_id FROM suppliers WHERE LOWER(supplier_name)=LOWER(?) LIMIT 1');
            $chk->bind_param('s', $supplier_name);
            $chk->execute();
            $dup = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($dup) {
                $error_msg = 'A supplier with that name already exists.';
            } else {
                $fields       = ['supplier_name'];
                $placeholders = ['?'];
                $types        = 's';
                $values       = [$supplier_name];

                foreach ($contact_fields as $field => $label) {
                    $val = trim($_POST[$field] ?? '');
                    if ($val !== '') {
                        $fields[]       = $field;
                        $placeholders[] = '?';
                        $types         .= 's';
                        $values[]       = $val;
                    }
                }
                if (isset($columns['status'])) {
                    $fields[]       = 'status';
                    $placeholders[] = "'active'";
                }

                $sql  = 'INSERT INTO suppliers (' . implode(',', $fields) . ') VALUES (' . implode(',', $placeholders) . ')';
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$values);
                if ($stmt->execute()) $success_msg = 'Supplier added successfully.';
                else                  $error_msg   = 'Failed to add supplier: ' . $stmt->error;
                $stmt->close();
            }
        }
    }

    // EDIT
    if ($action === 'edit') {
        $supplier_id   = (int)($_POST['supplier_id']   ?? 0);
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        if ($supplier_id <= 0 || $supplier_name === '') {
            $error_msg = 'Supplier name cannot be empty.';
        } else {
            $chk = $conn->prepare('SELECT supplier_id FROM suppliers WHERE LOWER(supplier_name)=LOWER(?) AND supplier_id<>? LIMIT 1');
            $chk->bind_param('si', $supplier_name, $supplier_id);
            $chk->execute();
            $dup = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($dup) {
                $error_msg = 'A supplier with that name already exists.';
            } else {
                $sets   = ['supplier_name=?'];
                $types  = 's';
                $values = [$supplier_name];
                foreach ($contact_fields as $field => $label) {
                    $sets[]   = "$field=?";
                    $types   .= 's';
                    $values[] = trim($_POST[$field] ?? '');
                }
                $types   .= 'i';
                $values[] = $supplier_id;
                $sql      = 'UPDATE suppliers SET ' . implode(',', $sets) . ' WHERE supplier_id=?';
                $stmt     = $conn->prepare($sql);
                $stmt->bind_param($types, ...$values);
                if ($stmt->execute()) $success_msg = 'Supplier updated successfully.';
                else                  $error_msg   = 'Failed to update supplier: ' . $stmt->error;
                $stmt->close();
            }
        }
    }

    // TOGGLE STATUS
    if ($action === 'toggle_status' && isset($columns['status'])) {
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        $new_status  = ($_POST['new_status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        $stmt        = $conn->prepare('UPDATE suppliers SET status=? WHERE supplier_id=?');
        $stmt->bind_param('si', $new_status, $supplier_id);
        if ($stmt->execute()) $success_msg = 'Supplier status updated.';
        else                  $error_msg   = 'Failed to update status.';
        $stmt->close();
    }
}

// ── Fetch data ──────────────────────────────────────────────────────────────
$suppliers = $conn->query('SELECT * FROM suppliers ORDER BY supplier_name');
$all_rows  = [];
while ($r = $suppliers->fetch_assoc()) $all_rows[] = $r;

$total_count    = count($all_rows);
$active_count   = count(array_filter($all_rows, fn($r) => ($r['status'] ?? 'active') === 'active'));
$inactive_count = $total_count - $active_count;

// Icons for contact fields
$field_icons = [
    'contact_person' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
    'contact_name'   => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
    'phone'          => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>',
    'mobile'         => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>',
    'email'          => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
    'address'        => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Suppliers — JOEBZ POS</title>
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

    body  { font-family: 'DM Sans', sans-serif; }
    h1, h2, h3 { font-family: 'Syne', sans-serif; }
    .mono { font-family: 'DM Mono', monospace; }

    /* Staggered reveal */
    @keyframes slideUp {
        from { opacity:0; transform:translateY(18px); }
        to   { opacity:1; transform:translateY(0); }
    }
    .reveal   { opacity:0; animation:slideUp .45s ease forwards; }
    .reveal-1 { animation-delay:.05s; }
    .reveal-2 { animation-delay:.10s; }
    .reveal-3 { animation-delay:.16s; }
    .reveal-4 { animation-delay:.22s; }
    .reveal-5 { animation-delay:.28s; }

    /* Input */
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

    /* Supplier card */
    .supplier-card { transition:border-color .18s, box-shadow .18s; }
    .supplier-card:hover { border-color:#334155; box-shadow:0 4px 24px rgba(0,0,0,.35); }

    /* Filter tabs */
    .ftab { transition:all .15s; }
    .ftab.active { background:#1e40af; color:#bfdbfe; border-color:#3b82f6; }

    /* Modal */
    @keyframes modalIn {
        from { opacity:0; transform:scale(.95) translateY(10px); }
        to   { opacity:1; transform:scale(1) translateY(0); }
    }
    .modal-panel { animation:modalIn .25s ease both; }

    /* Live dot */
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

    /* Stat cards */
    .stat-card { background:rgba(15,23,42,.5); border:1px solid #1e293b; border-radius:1.25rem; padding:1.25rem 1.5rem; }

    /* Loading */
    .loading-overlay { position:fixed; inset:0; background:rgba(0,0,0,.7); display:none; align-items:center; justify-content:center; z-index:1000; }
    .loading-spinner  { border:4px solid #334155; border-top-color:#3b82f6; border-radius:50%; width:48px; height:48px; animation:spin .8s linear infinite; }
    @keyframes spin { to { transform:rotate(360deg); } }

    /* Avatar initial */
    .supplier-avatar {
        width:40px; height:40px; border-radius:.75rem;
        display:flex; align-items:center; justify-content:center;
        font-family:'Syne',sans-serif; font-weight:700; font-size:1rem;
        flex-shrink:0;
    }
</style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen">

<div class="loading-overlay" id="loading"><div class="loading-spinner"></div></div>

<!-- ═══════ CONFIRM MODAL ═══════ -->
<div id="confirmModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div id="confirmPanel" class="modal-panel bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <div class="flex items-start gap-4 mb-5">
            <div id="confirmIcon" class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0"></div>
            <div>
                <h3 id="confirmTitle" class="font-bold text-white text-base"></h3>
                <p  id="confirmBody"  class="text-sm text-slate-400 mt-0.5"></p>
            </div>
        </div>
        <div class="flex gap-3">
            <button onclick="closeConfirm()" class="flex-1 border border-slate-700 hover:bg-slate-800 text-slate-300 py-2.5 rounded-xl text-sm font-medium transition">Cancel</button>
            <button id="confirmOkBtn" class="flex-1 py-2.5 rounded-xl text-sm font-semibold transition"></button>
        </div>
    </div>
</div>

<!-- ═══════ ADD MODAL ═══════ -->
<div id="addModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="modal-panel bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl w-full max-w-lg">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-blue-600/20 border border-blue-500/30 flex items-center justify-center">
                    <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                </div>
                <h2 class="text-base font-bold text-white">Add Supplier</h2>
            </div>
            <button onclick="closeAddModal()" class="w-8 h-8 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition text-xl leading-none">&times;</button>
        </div>
        <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="action" value="add">
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Supplier Name <span class="text-red-400">*</span></label>
                <input name="supplier_name" required placeholder="e.g. Acme Wholesale Co." class="input-field text-sm">
            </div>
            <?php if (!empty($contact_fields)): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <?php foreach ($contact_fields as $field => $label): ?>
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5"><?= htmlspecialchars($label) ?></label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none">
                            <?= $field_icons[$field] ?? '' ?>
                        </span>
                        <input name="<?= $field ?>" placeholder="<?= htmlspecialchars($label) ?>" class="input-field text-sm pl-9">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="flex gap-3 pt-2 border-t border-slate-800">
                <button type="button" onclick="closeAddModal()" class="flex-1 border border-slate-700 hover:bg-slate-800 text-slate-300 py-2.5 rounded-xl text-sm font-medium transition">Cancel</button>
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-500 text-white py-2.5 rounded-xl text-sm font-semibold transition" style="box-shadow:0 4px 16px rgba(59,130,246,.25)">
                    Add Supplier
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════ EDIT MODAL ═══════ -->
<div id="editModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="modal-panel bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl w-full max-w-lg">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-amber-600/20 border border-amber-500/30 flex items-center justify-center">
                    <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </div>
                <h2 class="text-base font-bold text-white">Edit Supplier</h2>
            </div>
            <button onclick="closeEditModal()" class="w-8 h-8 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition text-xl leading-none">&times;</button>
        </div>
        <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="action"      value="edit">
            <input type="hidden" name="supplier_id" id="editSupplierId">
            <div>
                <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5">Supplier Name <span class="text-red-400">*</span></label>
                <input name="supplier_name" id="editSupplierName" required placeholder="Supplier Name" class="input-field text-sm">
            </div>
            <?php if (!empty($contact_fields)): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <?php foreach ($contact_fields as $field => $label): ?>
                <div>
                    <label class="block text-xs text-slate-400 uppercase tracking-wider mb-1.5"><?= htmlspecialchars($label) ?></label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none">
                            <?= $field_icons[$field] ?? '' ?>
                        </span>
                        <input name="<?= $field ?>" id="edit_<?= $field ?>" placeholder="<?= htmlspecialchars($label) ?>" class="input-field text-sm pl-9">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="flex gap-3 pt-2 border-t border-slate-800">
                <button type="button" onclick="closeEditModal()" class="flex-1 border border-slate-700 hover:bg-slate-800 text-slate-300 py-2.5 rounded-xl text-sm font-medium transition">Cancel</button>
                <button type="submit" class="flex-1 bg-amber-600 hover:bg-amber-500 text-white py-2.5 rounded-xl text-sm font-semibold transition" style="box-shadow:0 4px 16px rgba(245,158,11,.2)">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════ SIDEBAR ═══════ -->
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
        <a href="suppliers.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= basename($_SERVER['PHP_SELF'])=='suppliers.php'?'bg-blue-600/20 text-blue-200 font-medium':'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V7a2 2 0 00-2-2h-3V3H9v2H6a2 2 0 00-2 2v6m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-4a2 2 0 00-2 2v1a2 2 0 01-2 2h0a2 2 0 01-2-2v-1a2 2 0 00-2-2H4"/></svg>
            Suppliers
        </a>
        <a href="purchase_order.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= basename($_SERVER['PHP_SELF'])=='purchase_order.php'?'bg-blue-600/20 text-blue-200 font-medium':'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
            Purchase Orders
        </a>
        <a href="users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= basename($_SERVER['PHP_SELF'])=='users.php'?'bg-blue-600/20 text-blue-200 font-medium':'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            Users
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

<!-- ═══════ MAIN ═══════ -->
<div class="flex-1 md:ml-64 min-h-screen">
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
            <h1 class="text-3xl font-bold text-white tracking-tight">Suppliers</h1>
            <p class="text-slate-400 mt-1 text-sm">
                Welcome back, <span class="text-blue-300"><?= htmlspecialchars($_SESSION['first_name']) ?></span>
                &nbsp;·&nbsp; <?= date('l, F j, Y') ?>
            </p>
        </div>
        <div class="flex items-center gap-3">
            <div class="hidden sm:flex items-center gap-2 text-xs text-slate-400 bg-slate-900 border border-slate-800 px-3 py-1.5 rounded-full">
                <span class="live-dot"></span>
                <span id="liveTime" class="mono"></span>
            </div>
            <button onclick="openAddModal()"
                class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-500 transition text-white text-sm font-semibold px-4 py-2.5 rounded-xl"
                style="box-shadow:0 4px 16px rgba(59,130,246,.25)">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Supplier
            </button>
        </div>
    </div>

    <!-- Alert -->
    <?php if ($success_msg || $error_msg): ?>
    <div id="alertMsg" class="mb-6 p-4 rounded-2xl border flex items-center justify-between gap-3 reveal reveal-2
        <?= $success_msg ? 'bg-emerald-900/30 border-emerald-700/50 text-emerald-200' : 'bg-red-900/30 border-red-700/50 text-red-200' ?>">
        <div class="flex items-center gap-3">
            <?php if ($success_msg): ?>
            <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-sm"><?= htmlspecialchars($success_msg) ?></span>
            <?php else: ?>
            <svg class="w-5 h-5 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-sm"><?= htmlspecialchars($error_msg) ?></span>
            <?php endif; ?>
        </div>
        <button onclick="this.parentElement.remove()" class="opacity-40 hover:opacity-80 transition text-xl leading-none shrink-0">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Stat cards -->
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6 reveal reveal-2">
        <div class="stat-card">
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Total</p>
            <p class="text-3xl font-bold text-white mono"><?= $total_count ?></p>
            <p class="text-xs text-slate-500 mt-0.5">Suppliers</p>
        </div>
        <div class="stat-card">
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Active</p>
            <p class="text-3xl font-bold text-emerald-400 mono"><?= $active_count ?></p>
            <p class="text-xs text-slate-500 mt-0.5">Suppliers</p>
        </div>
        <?php if (isset($columns['status'])): ?>
        <div class="stat-card">
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">Inactive</p>
            <p class="text-3xl font-bold text-slate-400 mono"><?= $inactive_count ?></p>
            <p class="text-xs text-slate-500 mt-0.5">Suppliers</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Search + filter bar -->
    <div class="flex flex-col sm:flex-row gap-3 mb-4 reveal reveal-3">
        <div class="relative flex-1">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input id="supplierSearch" type="text" placeholder="Search suppliers…" class="input-field pl-9 text-sm">
        </div>
        <?php if (isset($columns['status'])): ?>
        <div class="flex gap-1.5">
            <button data-filter="all"      class="ftab active px-3 py-2 rounded-xl text-xs font-medium bg-slate-900 border border-slate-800 text-slate-400 hover:text-slate-200 transition">All</button>
            <button data-filter="active"   class="ftab px-3 py-2 rounded-xl text-xs font-medium bg-slate-900 border border-slate-800 text-slate-400 hover:text-slate-200 transition">Active</button>
            <button data-filter="inactive" class="ftab px-3 py-2 rounded-xl text-xs font-medium bg-slate-900 border border-slate-800 text-slate-400 hover:text-slate-200 transition">Inactive</button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Column headers -->
    <div class="hidden md:grid grid-cols-12 gap-4 px-5 py-2 mb-1 text-xs font-medium text-slate-500 uppercase tracking-wider reveal reveal-3">
        <div class="col-span-4">Supplier</div>
        <?php if (!empty($contact_fields)): ?>
        <div class="col-span-4">Contact Info</div>
        <?php endif; ?>
        <div class="col-span-2">Status</div>
        <div class="col-span-2 text-right">Actions</div>
    </div>

    <!-- Supplier list -->
    <div id="supplierList" class="space-y-2 reveal reveal-4">
    <?php
    $colors = ['bg-blue-600','bg-emerald-600','bg-purple-600','bg-amber-600','bg-rose-600','bg-cyan-600','bg-indigo-600'];
    foreach ($all_rows as $idx => $s):
        $status     = $s['status'] ?? 'active';
        $isActive   = $status === 'active';
        $colorClass = $colors[$idx % count($colors)];
        $initial    = strtoupper(substr($s['supplier_name'], 0, 1));

        // Build contact info string
        $contactParts = [];
        foreach ($contact_fields as $field => $label) {
            if (!empty($s[$field])) $contactParts[] = $s[$field];
        }
        $contactStr = implode(' · ', array_slice($contactParts, 0, 3));

        // JSON for edit modal
        $editData = json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
    <div class="supplier-card bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden"
         data-status="<?= $status ?>"
         data-search="<?= strtolower(htmlspecialchars($s['supplier_name'] . ' ' . $contactStr)) ?>">

        <div class="grid grid-cols-12 gap-4 items-center px-5 py-4">

            <!-- Name + avatar -->
            <div class="col-span-7 md:col-span-4 flex items-center gap-3 min-w-0">
                <div class="supplier-avatar <?= $colorClass ?> text-white shrink-0">
                    <?= $initial ?>
                </div>
                <div class="min-w-0">
                    <p class="font-semibold text-white text-sm truncate"><?= htmlspecialchars($s['supplier_name']) ?></p>
                    <?php if ($contactStr): ?>
                    <p class="text-xs text-slate-500 truncate mt-0.5 md:hidden"><?= htmlspecialchars($contactStr) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Contact info (desktop) -->
            <?php if (!empty($contact_fields)): ?>
            <div class="hidden md:block col-span-4 min-w-0">
                <?php if ($contactStr): ?>
                <p class="text-sm text-slate-400 truncate"><?= htmlspecialchars($contactStr) ?></p>
                <?php else: ?>
                <p class="text-sm text-slate-600 italic">No contact info</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Status badge -->
            <div class="hidden md:flex col-span-2 items-center">
                <?php if ($isActive): ?>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border border-emerald-500/40 bg-emerald-500/10 text-emerald-300">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 shrink-0"></span>Active
                </span>
                <?php else: ?>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border border-slate-600 bg-slate-800 text-slate-400">
                    <span class="w-1.5 h-1.5 rounded-full bg-slate-500 shrink-0"></span>Inactive
                </span>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <div class="col-span-5 md:col-span-2 flex items-center justify-end gap-2">
                <!-- Mobile status -->
                <?php if ($isActive): ?>
                <span class="md:hidden inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs border border-emerald-500/40 bg-emerald-500/10 text-emerald-300">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 shrink-0"></span>Active
                </span>
                <?php else: ?>
                <span class="md:hidden inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs border border-slate-600 bg-slate-800 text-slate-400">
                    <span class="w-1.5 h-1.5 rounded-full bg-slate-500 shrink-0"></span>Inactive
                </span>
                <?php endif; ?>

                <!-- Edit -->
                <button onclick='openEditModal(<?= $editData ?>)'
                    class="inline-flex items-center gap-1 text-xs bg-amber-600/20 hover:bg-amber-600/40 border border-amber-500/30 text-amber-300 px-2.5 py-1.5 rounded-xl font-medium transition" title="Edit">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    <span class="hidden sm:inline">Edit</span>
                </button>

                <!-- Toggle status -->
                <?php if (isset($columns['status'])): ?>
                <button onclick="showConfirm('toggle','<?= (int)$s['supplier_id'] ?>','<?= $isActive ? 'inactive' : 'active' ?>','<?= htmlspecialchars($s['supplier_name'], ENT_QUOTES) ?>','<?= $isActive ? 'Disable' : 'Activate' ?>')"
                    class="inline-flex items-center text-xs p-1.5 rounded-xl border transition
                    <?= $isActive
                        ? 'bg-red-600/10 hover:bg-red-600/25 border-red-500/30 text-red-400'
                        : 'bg-emerald-600/10 hover:bg-emerald-600/25 border-emerald-500/30 text-emerald-400' ?>"
                    title="<?= $isActive ? 'Disable' : 'Activate' ?>">
                    <?php if ($isActive): ?>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                    <?php else: ?>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?php endif; ?>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Expandable contact detail (desktop, extra fields) -->
        <?php if (count($contactParts) > 3): ?>
        <div class="px-5 pb-4 pt-0 border-t border-slate-800/50">
            <div class="flex flex-wrap gap-3 pt-3">
                <?php foreach ($contact_fields as $field => $label):
                    if (empty($s[$field])) continue; ?>
                <div class="flex items-center gap-1.5 text-xs text-slate-400">
                    <span class="text-slate-600"><?= $field_icons[$field] ?? '' ?></span>
                    <span><?= htmlspecialchars($s[$field]) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.supplier-card -->
    <?php endforeach; ?>
    </div>

    <!-- Empty state -->
    <?php if ($total_count === 0): ?>
    <div class="text-center py-16 text-slate-600 reveal reveal-4">
        <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V7a2 2 0 00-2-2h-3V3H9v2H6a2 2 0 00-2 2v6m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-4a2 2 0 00-2 2v1a2 2 0 01-2 2h0a2 2 0 01-2-2v-1a2 2 0 00-2-2H4"/></svg>
        <p class="text-sm">No suppliers yet.</p>
        <button onclick="openAddModal()" class="mt-3 text-blue-400 text-xs hover:text-blue-300 transition">+ Add your first supplier</button>
    </div>
    <?php endif; ?>

    <p id="noResults" class="hidden text-center text-sm text-slate-600 py-12">No suppliers match your search or filter.</p>

</div><!-- /max-w -->
</div><!-- /main -->

<!-- Toggle status hidden form -->
<form method="post" id="toggleForm" class="hidden">
    <input type="hidden" name="action"      value="toggle_status">
    <input type="hidden" name="supplier_id" id="toggleSupplierId">
    <input type="hidden" name="new_status"  id="toggleNewStatus">
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
    a.style.transition = 'opacity .5s'; a.style.opacity = '0';
    setTimeout(() => a.remove(), 500);
}, 5000);

// ── Add Modal ──────────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('addModal').classList.remove('hidden');
}
function closeAddModal() {
    document.getElementById('addModal').classList.add('hidden');
}

// ── Edit Modal ─────────────────────────────────────────────────────────────
const editableFields = <?= json_encode(array_keys($contact_fields)) ?>;

function openEditModal(data) {
    document.getElementById('editSupplierId').value   = data.supplier_id;
    document.getElementById('editSupplierName').value = data.supplier_name;
    editableFields.forEach(field => {
        const el = document.getElementById('edit_' + field);
        if (el) el.value = data[field] ?? '';
    });
    document.getElementById('editModal').classList.remove('hidden');
}
function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}

// ── Confirm Modal (toggle status) ─────────────────────────────────────────
function showConfirm(type, supplierId, newStatus, name, label) {
    const isDisable = newStatus === 'inactive';

    document.getElementById('confirmTitle').textContent = (isDisable ? 'Disable' : 'Activate') + ' Supplier';
    document.getElementById('confirmBody').innerHTML    = (isDisable
        ? 'Disable <strong>' + name + '</strong>? They won\'t appear in new POs.'
        : 'Activate <strong>' + name + '</strong>? They\'ll be available for POs again.');

    const icon = document.getElementById('confirmIcon');
    icon.className = 'w-11 h-11 rounded-xl flex items-center justify-center shrink-0 border ' +
        (isDisable ? 'bg-red-900/30 border-red-800/40' : 'bg-emerald-900/30 border-emerald-800/40');
    icon.innerHTML = isDisable
        ? '<svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>'
        : '<svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';

    const okBtn = document.getElementById('confirmOkBtn');
    okBtn.textContent = label;
    okBtn.className   = 'flex-1 py-2.5 rounded-xl text-sm font-semibold transition text-white ' +
        (isDisable ? 'bg-red-600 hover:bg-red-500' : 'bg-emerald-600 hover:bg-emerald-500');

    // Re-trigger animation
    const panel = document.getElementById('confirmPanel');
    panel.style.animation = 'none'; panel.offsetHeight; panel.style.animation = '';

    okBtn.onclick = () => {
        document.getElementById('toggleSupplierId').value = supplierId;
        document.getElementById('toggleNewStatus').value  = newStatus;
        closeConfirm();
        document.getElementById('loading').style.display = 'flex';
        document.getElementById('toggleForm').submit();
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

// ── Search + filter ────────────────────────────────────────────────────────
let activeFilter = 'all';
const searchEl   = document.getElementById('supplierSearch');

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
    const rows = document.querySelectorAll('.supplier-card');
    let vis = 0;
    rows.forEach(row => {
        const statusOk = activeFilter === 'all' || row.dataset.status === activeFilter;
        const searchOk = !q || (row.dataset.search || '').includes(q);
        row.style.display = (statusOk && searchOk) ? '' : 'none';
        if (statusOk && searchOk) vis++;
    });
    document.getElementById('noResults').classList.toggle('hidden', vis > 0);
}

// ── ESC closes modals ──────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeConfirm();
        closeAddModal();
        closeEditModal();
    }
});
</script>
</body>
</html>