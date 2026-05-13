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
$error_msg = '';

$col_res = $conn->query("SHOW COLUMNS FROM suppliers");
$columns = [];
while ($c = $col_res->fetch_assoc()) $columns[$c['Field']] = true;

$contact_candidates = [
    'contact_person' => 'Contact Person',
    'contact_name' => 'Contact Name',
    'phone' => 'Phone',
    'mobile' => 'Mobile',
    'email' => 'Email',
    'address' => 'Address'
];
$contact_fields = [];
foreach ($contact_candidates as $field => $label) {
    if (isset($columns[$field])) $contact_fields[$field] = $label;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

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
                $fields = ['supplier_name'];
                $placeholders = ['?'];
                $types = 's';
                $values = [$supplier_name];

                foreach ($contact_fields as $field => $label) {
                    $val = trim($_POST[$field] ?? '');
                    if ($val !== '') {
                        $fields[] = $field;
                        $placeholders[] = '?';
                        $types .= 's';
                        $values[] = $val;
                    }
                }
                if (isset($columns['status'])) {
                    $fields[] = 'status';
                    $placeholders[] = "'active'";
                }

                $sql = 'INSERT INTO suppliers (' . implode(',', $fields) . ') VALUES (' . implode(',', $placeholders) . ')';
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$values);
                if ($stmt->execute()) $success_msg = 'Supplier added successfully.';
                else $error_msg = 'Failed to add supplier.';
                $stmt->close();
            }
        }
    }

    if ($action === 'edit') {
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
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
                $sets = ['supplier_name=?'];
                $types = 's';
                $values = [$supplier_name];
                foreach ($contact_fields as $field => $label) {
                    $sets[] = "$field=?";
                    $types .= 's';
                    $values[] = trim($_POST[$field] ?? '');
                }
                $types .= 'i';
                $values[] = $supplier_id;
                $sql = 'UPDATE suppliers SET ' . implode(',', $sets) . ' WHERE supplier_id=?';
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$values);
                if ($stmt->execute()) $success_msg = 'Supplier updated successfully.';
                else $error_msg = 'Failed to update supplier.';
                $stmt->close();
            }
        }
    }

    if ($action === 'toggle_status' && isset($columns['status'])) {
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        $new_status = ($_POST['new_status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        $stmt = $conn->prepare('UPDATE suppliers SET status=? WHERE supplier_id=?');
        $stmt->bind_param('si', $new_status, $supplier_id);
        if ($stmt->execute()) $success_msg = 'Supplier status updated.';
        else $error_msg = 'Failed to update supplier status.';
        $stmt->close();
    }
}

$suppliers = $conn->query('SELECT * FROM suppliers ORDER BY supplier_name');
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Suppliers — JOEBZ POS</title><script src="https://cdn.tailwindcss.com"></script><link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet"></head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen" style="font-family:'DM Sans',sans-serif">
<aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 transform bg-slate-950 border-r border-slate-800 flex flex-col transition-transform duration-200 ease-out -translate-x-full md:translate-x-0"><div class="flex items-center gap-3 px-6 py-5 border-b border-slate-800"><img src="assets/logo.png" class="w-10 h-10 rounded-xl object-cover"><span class="text-lg font-bold" style="font-family:'Syne',sans-serif">JOEBZ</span></div><nav class="flex-1 px-4 py-4 space-y-1 overflow-y-auto">
<?php foreach ([['dashboard.php','Dashboard'],['items.php','Items'],['categories.php','Categories'],['reports.php','Reports'],['suppliers.php','Suppliers'],['purchase_order.php','Purchase Orders'],['users.php','Users']] as $n): ?><a href="<?= $n[0] ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition <?= basename($_SERVER['PHP_SELF'])==$n[0]?'bg-blue-600/20 text-blue-200 font-medium':'text-slate-300 hover:bg-blue-600/20 hover:text-blue-200' ?>"><?= htmlspecialchars($n[1]) ?></a><?php endforeach; ?>
</nav></aside>
<div class="md:ml-64 p-6"><h1 class="text-3xl font-bold mb-6" style="font-family:'Syne',sans-serif">Suppliers</h1>
<?php if ($success_msg): ?><div class="mb-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-emerald-300"><?= $success_msg ?></div><?php endif; ?>
<?php if ($error_msg): ?><div class="mb-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-rose-300"><?= $error_msg ?></div><?php endif; ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
<form method="post" class="rounded-2xl border border-slate-800 bg-slate-900/80 p-4 space-y-3"><input type="hidden" name="action" value="add"><h2 class="font-semibold">Add Supplier</h2><input name="supplier_name" placeholder="Supplier Name" class="w-full bg-slate-800 border border-slate-700 rounded-xl p-2" required>
<?php foreach ($contact_fields as $field => $label): ?><input name="<?= $field ?>" placeholder="<?= htmlspecialchars($label) ?>" class="w-full bg-slate-800 border border-slate-700 rounded-xl p-2"><?php endforeach; ?>
<button class="w-full rounded-xl bg-blue-600 hover:bg-blue-500 p-2">Add Supplier</button></form>
<div class="lg:col-span-2 rounded-2xl border border-slate-800 bg-slate-900/80 p-4 overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-slate-400"><th class="text-left py-2">Name</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php while ($s = $suppliers->fetch_assoc()): $status = $s['status'] ?? 'active'; ?>
<tr class="border-t border-slate-800"><td class="py-2"><?= htmlspecialchars($s['supplier_name']) ?></td><td><?= htmlspecialchars($status) ?></td><td>
<form method="post" class="inline-flex gap-2 items-center flex-wrap"><input type="hidden" name="action" value="edit"><input type="hidden" name="supplier_id" value="<?= (int)$s['supplier_id'] ?>"><input name="supplier_name" value="<?= htmlspecialchars($s['supplier_name']) ?>" class="bg-slate-800 border border-slate-700 rounded p-1">
<?php foreach ($contact_fields as $field => $label): ?><input name="<?= $field ?>" value="<?= htmlspecialchars($s[$field] ?? '') ?>" placeholder="<?= htmlspecialchars($label) ?>" class="bg-slate-800 border border-slate-700 rounded p-1"><?php endforeach; ?>
<button class="rounded bg-emerald-700 px-2 py-1">Save</button></form>
<?php if (isset($columns['status'])): ?><form method="post" class="inline"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="supplier_id" value="<?= (int)$s['supplier_id'] ?>"><input type="hidden" name="new_status" value="<?= $status==='active'?'inactive':'active' ?>"><button class="rounded bg-amber-700 px-2 py-1"><?= $status==='active'?'Disable':'Activate' ?></button></form><?php endif; ?>
</td></tr><?php endwhile; ?>
</tbody></table></div></div></div>
<script>const sidebar=document.getElementById('sidebar');</script></body></html>
