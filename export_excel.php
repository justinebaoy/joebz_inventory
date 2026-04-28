<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit('Unauthorized');
}

$export_type = $_GET['type'] ?? 'sales';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="joebz_sales_report_' . date('Y-m-d') . '.xls"');

// Sales Report
echo "SALE ID\tDATE\tTIME\tTOTAL\tCASH\tCHANGE\tCASHIER\tCUSTOMER\n";

$stmt = $conn->prepare("
    SELECT s.sale_id, s.sale_date, s.total_amount, s.cash_received, s.change_amount,
           u.first_name as cashier, c.name as customer_name
    FROM sales s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    ORDER BY s.sale_date DESC
");

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    echo implode("\t", [
        $row['sale_id'],
        date('Y-m-d', strtotime($row['sale_date'])),
        date('H:i:s', strtotime($row['sale_date'])),
        number_format($row['total_amount'], 2),
        number_format($row['cash_received'], 2),
        number_format($row['change_amount'], 2),
        $row['cashier'],
        $row['customer_name'] ?? 'Walk-in'
    ]) . "\n";
}
?>