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
header('Content-Disposition: attachment; filename="joebz_' . $export_type . '_report_' . date('Y-m-d') . '.xls"');

if ($export_type === 'discounts') {
    echo "LOG ID	REQUEST TIME	STATUS	TYPE	PERCENT	AMOUNT	CASHIER	APPROVER	REASON	REJECTION REASON
";

    $stmt = $conn->prepare("
        SELECT l.log_id, l.request_time, l.status, l.discount_type, l.discount_percent, l.discount_amount,
               CONCAT(c.first_name, ' ', c.last_name) AS cashier_name,
               CONCAT(a.first_name, ' ', a.last_name) AS approver_name,
               l.reason, l.rejection_reason
        FROM discount_logs l
        JOIN users c ON l.cashier_id = c.user_id
        LEFT JOIN users a ON l.approver_id = a.user_id
        WHERE DATE(l.request_time) BETWEEN ? AND ?
        ORDER BY l.request_time DESC
    ");
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        echo implode("	", [
            $row['log_id'],
            date('Y-m-d H:i:s', strtotime($row['request_time'])),
            ucfirst($row['status']),
            strtoupper($row['discount_type']),
            number_format($row['discount_percent'], 2),
            number_format($row['discount_amount'], 2),
            $row['cashier_name'],
            $row['approver_name'] ?? '-',
            str_replace(["", "
", "	"], ' ', $row['reason'] ?? ''),
            str_replace(["", "
", "	"], ' ', $row['rejection_reason'] ?? '')
        ]) . "
";
    }
    exit;
}

// Sales Report
echo "SALE ID	DATE	TIME	TOTAL	CASH	CHANGE	CASHIER	CUSTOMER
";

$stmt = $conn->prepare("
    SELECT s.sale_id, s.sale_date, s.total_amount, s.cash_received, s.change_amount,
           u.first_name as cashier, c.name as customer_name
    FROM sales s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    ORDER BY s.sale_date DESC
");
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    echo implode("	", [
        $row['sale_id'],
        date('Y-m-d', strtotime($row['sale_date'])),
        date('H:i:s', strtotime($row['sale_date'])),
        number_format($row['total_amount'], 2),
        number_format($row['cash_received'], 2),
        number_format($row['change_amount'], 2),
        $row['cashier'],
        $row['customer_name'] ?? 'Walk-in'
    ]) . "
";
}
?>
