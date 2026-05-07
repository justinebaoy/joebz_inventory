<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$sale_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($sale_id <= 0) {
    exit('Invalid receipt ID');
}

// Fetch sale details
$stmt = $conn->prepare("
    SELECT s.*, u.first_name, u.last_name
    FROM sales s 
    JOIN users u ON s.user_id = u.user_id 
    WHERE s.sale_id = ?
");
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();

if (!$sale) {
    exit('Receipt not found');
}

// Fetch sale items
$items_stmt = $conn->prepare("
    SELECT si.*, i.item_name 
    FROM sale_items si 
    JOIN items i ON si.item_id = i.item_id 
    WHERE si.sale_id = ?
");
$items_stmt->bind_param("i", $sale_id);
$items_stmt->execute();
$items = $items_stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt #<?= str_pad($sale_id, 4, '0', STR_PAD_LEFT) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none; }
            @page { size: 80mm auto; margin: 0mm; }
        }
        body { 
            font-family: 'Courier New', monospace; 
            width: 300px; 
            margin: 0 auto; 
            padding: 15px;
            background: white;
            color: black;
            font-size: 12px;
        }
        .receipt { text-align: center; }
        .receipt h2 { 
            font-size: 16px; 
            margin-bottom: 3px;
            letter-spacing: 1px;
        }
        .website {
            font-size: 10px;
            color: #2563eb;
            margin-bottom: 5px;
        }
        hr { border: 1px dashed #000; margin: 8px 0; }
        .store-info { margin-bottom: 10px; font-size: 10px; }
        .store-info p { margin: 2px 0; }
        table { width: 100%; margin: 10px 0; }
        th { text-align: left; font-size: 11px; padding-bottom: 5px; border-bottom: 1px dotted #000; }
        td { padding: 4px 0; }
        .total-row { margin-top: 8px; padding-top: 8px; border-top: 1px dashed #000; font-weight: bold; }
        .thankyou { margin-top: 15px; font-size: 10px; }
        .barcode { margin: 10px 0; font-family: 'Courier New', monospace; font-size: 14px; letter-spacing: 2px; }
        .no-print { margin-top: 20px; text-align: center; }
        .no-print button { padding: 8px 16px; margin: 0 5px; cursor: pointer; border: none; border-radius: 5px; }
        .btn-print { background: #2563eb; color: white; }
        .btn-close { background: #6b7280; color: white; }
        .footer { font-size: 9px; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="receipt">
        <h2>JOEBZ COMPUTER SALES & SERVICES</h2>
        <div class="website">https://joebz.com</div>
        <div class="store-info">
            <p>Salazar Street Barangay 14</p>
            <p>6500 Tacloban City, Eastern Visayas</p>
            <p>(053) 321 2323</p>
            <p>Open: 9:00 AM - 6:00 PM</p>
        </div>
        
        <hr>
        
        <div style="text-align: left;">
            <p><strong>RECEIPT #: <?= str_pad($sale_id, 4, '0', STR_PAD_LEFT) ?></strong></p>
            <p>Date: <?= date('Y-m-d', strtotime($sale['sale_date'])) ?></p>
            <p>Time: <?= date('h:i:s A', strtotime($sale['sale_date'])) ?></p>
            <p>Cashier: <?= htmlspecialchars($sale['first_name'] . ' ' . $sale['last_name']) ?></p>
        </div>
        
        <hr>
        
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th style="text-align:center">Qty</th>
                    <th style="text-align:right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $subtotal = 0;
                if ($items->num_rows > 0):
                    while ($item = $items->fetch_assoc()): 
                        $total = $item['price'] * $item['quantity'];
                        $subtotal += $total;
                ?>
                <tr>
                    <td style="max-width: 150px;"><?= htmlspecialchars(substr($item['item_name'], 0, 25)) ?></td>
                    <td style="text-align:center">x<?= $item['quantity'] ?></td>
                    <td style="text-align:right">₱<?= number_format($total, 2) ?></td>
                </tr>
                <?php 
                    endwhile;
                else:
                ?>
                <tr>
                    <td colspan="3" style="text-align:center; color:#999;">No items found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if ($items->num_rows > 0): ?>
        <hr>
        <div style="text-align: right;">
            <p><strong>SUBTOTAL:</strong> ₱<?= number_format($subtotal, 2) ?></p>
            <p class="total-row"><strong>TOTAL:</strong> ₱<?= number_format($sale['total_amount'], 2) ?></p>
            <p><strong>CASH:</strong> ₱<?= number_format($sale['cash_received'], 2) ?></p>
            <p><strong>CHANGE:</strong> ₱<?= number_format($sale['change_amount'], 2) ?></p>
        </div>
        <?php endif; ?>
        
        <hr>
        
        <div class="barcode">
            <?= str_pad($sale_id, 8, '0', STR_PAD_LEFT) ?>
        </div>
        
        <div class="thankyou">
            <p>Thank you for shopping at JOEBZ!</p>
            <p>Items sold are non-returnable</p>
            <p>Keep this receipt for warranty</p>
        </div>
        
        <div class="footer">
            <p>Follow us on Facebook: @joebzstore</p>
            <p>Visit our website: https://joebz.com</p>
        </div>
    </div>
    
    <div class="no-print">
        <button class="btn-print" onclick="window.print()">🖨️ Print Receipt</button>
        <button class="btn-close" onclick="window.close()">✖ Close</button>
    </div>
    
    <script>
        setTimeout(function() {
            window.print();
        }, 500);
    </script>
</body>
</html>