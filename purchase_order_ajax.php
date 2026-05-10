<?php
session_start();
require_once 'config/db.php';

header('Content-Type: application/json');

function json_response($ok, $message, $extra = []) {
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

function can_manage_po() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'manager'], true);
}

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

if (!isset($_SESSION['user_id'])) {
    json_response(false, 'Unauthorized');
}

action:
$action = $_POST['action'] ?? '';

if ($action === 'update_status') {
    if (!can_manage_po()) json_response(false, 'Insufficient role');
    $po_id = (int)($_POST['po_id'] ?? 0);
    $new_status = $_POST['status'] ?? '';

    $stmt = $conn->prepare('SELECT status FROM purchase_orders WHERE po_id = ?');
    $stmt->bind_param('i', $po_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) json_response(false, 'PO not found');

    $current = $row['status'];
    if (!validate_po_transition($current, $new_status)) {
        json_response(false, "Invalid transition: {$current} -> {$new_status}");
    }

    $update = $conn->prepare('UPDATE purchase_orders SET status = ?, updated_at = NOW() WHERE po_id = ?');
    $update->bind_param('si', $new_status, $po_id);
    $update->execute();
    json_response(true, 'PO status updated');
}

json_response(false, 'Unknown action');
