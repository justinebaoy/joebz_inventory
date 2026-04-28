<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$sale_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($sale_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid sale ID']);
    exit;
}

// Fetch sale details
$stmt = $conn->prepare("
    SELECT s.*, u.first_name as cashier, c.name as customer_name
    FROM sales s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    WHERE s.sale_id = ?
");
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();

if (!$sale) {
    echo json_encode(['success' => false, 'error' => 'Sale not found']);
    exit;
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
$items_result = $items_stmt->get_result();

$items = [];
while ($item = $items_result->fetch_assoc()) {
    $items[] = [
        'item_name' => $item['item_name'],
        'quantity' => $item['quantity'],
        'price' => (float)$item['price'],
        'total' => (float)$item['price'] * $item['quantity']
    ];
}

echo json_encode([
    'success' => true,
    'sale_id' => $sale['sale_id'],
    'date' => date('Y-m-d h:i:s A', strtotime($sale['sale_date'])),
    'cashier' => $sale['cashier'],
    'customer_name' => $sale['customer_name'] ?? 'Walk-in Customer',
    'items' => $items,
    'total' => (float)$sale['total_amount'],
    'cash' => (float)$sale['cash_received'],
    'change' => (float)$sale['change_amount']
]);
?>