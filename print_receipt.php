<?php
session_start();
require_once 'config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Please login first');
}

$sale_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($sale_id <= 0) {
    die('Invalid receipt ID. Please use: print_receipt.php?id=XX');
}

// Fetch sale details
$stmt = $conn->prepare("
    SELECT s.*, u.first_name, u.last_name, c.name as customer_name 
    FROM sales s 
    JOIN users u ON s.user_id = u.user_id 
    LEFT JOIN customers c ON s.customer_id = c.customer_id 
    WHERE s.sale_id = ?
");
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("Sale #$sale_id not found");
}

$sale = $result->fetch_assoc();

// Fetch sale items
$items_stmt = $conn->prepare("
    SELECT si.*, i.item_name 
    FROM sale_items si 
    JOIN items i ON si.item_id = i.item_id 
    WHERE si.sale_id = ?
");
$items_stmt->bind_param("i", $sale_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt #<?= $sale_id ?></title>
    <style>
        body { 
            font-family: 'Courier New', monospace; 
            width: 320px; 
            margin: 0 auto; 
            padding: 15px;
            background: white;
            color: black;
            font-size: 12px;
        }
        .receipt { text-align: center; }
        hr { border: 1px dashed #000; margin: 8px 0; }
        table { width: 100%; margin: 10px 0; }
        th, td { text-align: left; padding: 4px 0; }
        th { border-bottom: 1px dotted #000; }
        .total-row { border-top: 1px dashed #000; padding-top: 8px; margin-top: 8px; font-weight: bold; }
        .thankyou { margin-top: 15px; font-size: 10px; }
        .no-print { text-align: center; margin-top: 20px; }
        .no-print button { padding: 8px 16px; margin: 0 5px; cursor: pointer; border: none; border-radius: 5px; }
        .btn-print { background: #2563eb; color: white; }
        .btn-close { background: #6b7280; color: white; }
        @media print {
            body { margin: 0; padding: 10px; }
            .no-print { display: none; }
            @page { size: 80mm auto; margin: 0mm; }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <h2>JOEBZ Computer Sales & Services</h2>
        <p>Salazar St. Brgy. 14, 6500 Tacloban City, Leyte Philippines</p>
        <p>Tel: (053) 231-2323</p>
        
        <hr>
        
        <div style="text-align: left;">
            <p><strong>RECEIPT #: <?= str_pad($sale_id, 4, '0', STR_PAD_LEFT) ?></strong></p>
            <p>Date: <?= date('Y-m-d', strtotime($sale['sale_date'])) ?></p>
            <p>Time: <?= date('h:i:s A', strtotime($sale['sale_date'])) ?></p>
            <p>Cashier: <?= htmlspecialchars($sale['first_name'] . ' ' . $sale['last_name']) ?></p>
            <p>Customer: <?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer') ?></p>
        </div>
        
        <hr>
        
        <table>
            <thead>
                <tr><th>Item</th><th>Qty</th><th>Amount</th></tr>
            </thead>
            <tbody>
                <?php 
                $total = 0;
                if ($items_result && $items_result->num_rows > 0):
                    while ($item = $items_result->fetch_assoc()): 
                        $item_total = $item['price'] * $item['quantity'];
                        $total += $item_total;
                ?>
                <tr>
                    <td><?= htmlspecialchars(substr($item['item_name'], 0, 20)) ?></td>
                    <td style="text-align:center">x<?= $item['quantity'] ?></td>
                    <td style="text-align:right">₱<?= number_format($item_total, 2) ?></td>
                </tr>
                <?php 
                    endwhile;
                else:
                ?>
                <tr>
                    <td colspan="3" style="text-align:center">No items found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <hr>
        
        <div style="text-align:right">
            <p><strong>TOTAL: ₱<?= number_format($sale['total_amount'], 2) ?></strong></p>
            <p>CASH: ₱<?= number_format($sale['cash_received'], 2) ?></p>
            <p>CHANGE: ₱<?= number_format($sale['change_amount'], 2) ?></p>
        </div>
        
        <hr>
        
        <div class="thankyou">
            <p>Thank you for shopping at JOEBZ!</p>
            <p>⭐ Follow us on Facebook: @joebzstore</p>
            <p>☑ Items sold are non-returnable</p>
        </div>
    </div>
    
    <div class="no-print">
        <button class="btn-print" onclick="window.print()">🖨️ Print</button>
        <button class="btn-close" onclick="window.close()">✖ Close</button>
    </div>
    
    <script>
        setTimeout(function() { window.print(); }, 500);
    </script>
</body>
</html>