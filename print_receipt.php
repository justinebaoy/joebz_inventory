<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$sale_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($sale_id <= 0) exit('Invalid receipt ID');

// Fetch sale details (including discount)
$stmt = $conn->prepare("
    SELECT s.*, u.first_name, u.last_name
    FROM sales s 
    JOIN users u ON s.user_id = u.user_id 
    WHERE s.sale_id = ?
");
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();
if (!$sale) exit('Receipt not found');

// Fetch items
$items_stmt = $conn->prepare("
    SELECT si.*, i.item_name 
    FROM sale_items si 
    JOIN items i ON si.item_id = i.item_id 
    WHERE si.sale_id = ?
");
$items_stmt->bind_param("i", $sale_id);
$items_stmt->execute();
$items = $items_stmt->get_result();

$subtotal = 0;
while($it = $items->fetch_assoc()) {
    $subtotal += $it['price'] * $it['quantity'];
}
$items->data_seek(0);
$discount_percent = $sale['discount_percent'] ?? 0;
$discount_amount = $sale['discount_amount'] ?? 0;
$total = $sale['total_amount'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt #<?= str_pad($sale_id,4,'0',STR_PAD_LEFT) ?></title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        @media print{body{margin:0;padding:0}.no-print{display:none}@page{size:80mm auto;margin:0mm}}
        body{font-family:'Courier New',monospace;width:300px;margin:0 auto;padding:15px;background:#fff;color:#000;font-size:12px}
        .receipt{text-align:center}
        .receipt h2{font-size:16px;margin-bottom:3px}
        .website{font-size:10px;color:#2563eb}
        .store-info{font-size:10px;margin:5px 0}
        hr{border:1px dashed #000;margin:8px 0}
        table{width:100%;margin:10px 0}
        th{text-align:left;border-bottom:1px dotted #000}
        td{padding:4px 0}
        .total-row{border-top:1px dashed #000;padding-top:8px;margin-top:8px;font-weight:bold}
        .thankyou{margin-top:15px;font-size:10px}
        .barcode{margin:10px 0;font-size:14px;letter-spacing:2px}
        .no-print button{padding:8px 16px;margin:0 5px;cursor:pointer;border:none;border-radius:5px}
        .btn-print{background:#2563eb;color:#fff}
        .btn-close{background:#6b7280;color:#fff}
    </style>
</head>
<body>
<div class="receipt">
    <h2>JOEBZ COMPUTER SALES & SERVICES</h2>
    <div class="website">https://joebz.com</div>
    <div class="store-info">Salazar Street Barangay 14, 6500 Tacloban City<br>📞 (053) 321 2323</div>
    <hr>
    <div style="text-align:left">
        <strong>RECEIPT #: <?= str_pad($sale_id,4,'0',STR_PAD_LEFT) ?></strong><br>
        Date: <?= date('Y-m-d',strtotime($sale['sale_date'])) ?><br>
        Time: <?= date('h:i:s A',strtotime($sale['sale_date'])) ?><br>
        Cashier: <?= htmlspecialchars($sale['first_name'].' '.$sale['last_name']) ?>
    </div>
    <hr>
    <table>
        <thead><tr><th>Item</th><th style="text-align:center">Qty</th><th style="text-align:right">Amount</th></tr></thead>
        <tbody>
            <?php while($item = $items->fetch_assoc()): $totalRow = $item['price'] * $item['quantity']; ?>
            <tr><td><?= htmlspecialchars(substr($item['item_name'],0,25)) ?></td><td style="text-align:center">x<?= $item['quantity'] ?></td><td style="text-align:right">₱<?= number_format($totalRow,2) ?></td></tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    <hr>
    <div style="text-align:right">
        <strong>SUBTOTAL:</strong> ₱<?= number_format($subtotal,2) ?><br>
        <?php if ($discount_percent > 0): ?>
            <strong>DISCOUNT (<?= $discount_percent ?>%):</strong> -₱<?= number_format($discount_amount,2) ?><br>
        <?php endif; ?>
        <strong>TOTAL:</strong> ₱<?= number_format($total,2) ?><br>
        <strong>CASH:</strong> ₱<?= number_format($sale['cash_received'],2) ?><br>
        <strong>CHANGE:</strong> ₱<?= number_format($sale['change_amount'],2) ?>
    </div>
    <hr>
    <div class="barcode"><?= str_pad($sale_id,8,'0',STR_PAD_LEFT) ?></div>
    <div class="thankyou">Thank you for shopping at JOEBZ!<br>☑ Items sold are non-returnable<br>Follow us on Facebook: @joebzstore</div>
</div>
<div class="no-print"><button class="btn-print" onclick="window.print()">🖨️ Print Receipt</button><button class="btn-close" onclick="window.close()">✖ Close</button></div>
<script>setTimeout(function(){window.print();},500);</script>
</body>
</html>