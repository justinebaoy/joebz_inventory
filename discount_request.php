<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = [];

// -------------------------------------------------------------------
// 1. Cashier requests a discount
// -------------------------------------------------------------------
if ($action === 'request' && $_SESSION['role'] === 'staff') {
    $type = $_POST['type'];
    $percent = (float)$_POST['percent'];
    $reason = trim($_POST['reason']);
    $cart_total = (float)$_POST['cart_total'];

    // Validate discount type
    if (!in_array($type, ['senior','pwd','promo','manual'])) {
        echo json_encode(['error' => 'Invalid discount type']);
        exit;
    }
    if ($type === 'senior' || $type === 'pwd') {
        $percent = 20; // Fixed 20% for senior/PWD
    }
    if ($percent <= 0 || $percent > 100) {
        echo json_encode(['error' => 'Invalid discount percentage']);
        exit;
    }
    $amount = $cart_total * ($percent / 100);

    $stmt = $conn->prepare("INSERT INTO discount_logs (cashier_id, discount_type, discount_percent, discount_amount, reason, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $stmt->bind_param("isids", $_SESSION['user_id'], $type, $percent, $amount, $reason);
    $stmt->execute();
    $log_id = $conn->insert_id;
    $_SESSION['pending_discount_id'] = $log_id;

    echo json_encode(['success' => true, 'log_id' => $log_id]);
    exit;
}

// -------------------------------------------------------------------
// 2. Manager/Admin fetch pending discount requests
// -------------------------------------------------------------------
if ($action === 'pending' && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'manager')) {
    $pending = $conn->query("
        SELECT l.*, u.first_name, u.last_name, u.username 
        FROM discount_logs l 
        JOIN users u ON l.cashier_id = u.user_id 
        WHERE l.status = 'pending' 
        ORDER BY l.request_time ASC
    ");
    $list = [];
    while ($row = $pending->fetch_assoc()) {
        $list[] = $row;
    }
    echo json_encode($list);
    exit;
}

// -------------------------------------------------------------------
// 3. Manager/Admin approve a discount (requires password)
// -------------------------------------------------------------------
if ($action === 'approve' && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'manager')) {
    $log_id = (int)$_POST['log_id'];
    $password = $_POST['password'];

    // Verify user's password
    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $hash = $stmt->get_result()->fetch_assoc()['password_hash'];
    if (!password_verify($password, $hash)) {
        echo json_encode(['error' => 'Invalid password']);
        exit;
    }

    // Optional: enforce max discount based on role
    $log = $conn->query("SELECT discount_percent FROM discount_logs WHERE log_id = $log_id")->fetch_assoc();
    $max_discount = ($_SESSION['role'] === 'admin') ? 100 : 30;
    if ($log['discount_percent'] > $max_discount) {
        echo json_encode(['error' => "Discount exceeds your limit ($max_discount%)"]);
        exit;
    }

    $update = $conn->prepare("UPDATE discount_logs SET status = 'approved', approver_id = ?, approval_time = NOW() WHERE log_id = ?");
    $update->bind_param("ii", $_SESSION['user_id'], $log_id);
    $update->execute();

    // Store approved discount in session for the cashier's cart
    $approved = $conn->query("SELECT discount_percent, discount_amount FROM discount_logs WHERE log_id = $log_id")->fetch_assoc();
    $_SESSION['approved_discount'] = [
        'percent' => $approved['discount_percent'],
        'amount'  => $approved['discount_amount'],
        'log_id'  => $log_id,
        'approver_id' => $_SESSION['user_id']
    ];

    echo json_encode(['success' => true]);
    exit;
}

// -------------------------------------------------------------------
// 4. Manager/Admin reject a discount
// -------------------------------------------------------------------
if ($action === 'reject' && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'manager')) {
    $log_id = (int)$_POST['log_id'];
    $reason = trim($_POST['reason']);
    $stmt = $conn->prepare("UPDATE discount_logs SET status = 'rejected', rejection_reason = ? WHERE log_id = ?");
    $stmt->bind_param("si", $reason, $log_id);
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit;
}

// -------------------------------------------------------------------
// 5. Cashier checks if a discount request was approved (polling)
// -------------------------------------------------------------------
if ($action === 'check_approved' && $_SESSION['role'] === 'staff') {
    if (isset($_SESSION['approved_discount'])) {
        echo json_encode(['approved' => true, 'discount' => $_SESSION['approved_discount']]);
    } else {
        echo json_encode(['approved' => false]);
    }
    exit;
}

// If action not matched
echo json_encode(['error' => 'Invalid action']);
?>