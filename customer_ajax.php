<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.0 403 Forbidden');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Search customers (returns loyalty_points as well)
if ($action === 'search' && isset($_GET['term'])) {
    $term = '%' . $_GET['term'] . '%';
    $stmt = $conn->prepare("SELECT customer_id, name, phone, email, loyalty_points, total_purchases FROM customers WHERE name LIKE ? OR phone LIKE ? OR email LIKE ? LIMIT 10");
    $stmt->bind_param("sss", $term, $term, $term);
    $stmt->execute();
    $result = $stmt->get_result();
    $customers = [];
    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode($customers);
    exit;
}

// Get single customer
if ($action === 'get' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM customers WHERE customer_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    header('Content-Type: application/json');
    echo json_encode($customer ?: null);
    exit;
}

// Create new customer
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Name required']);
        exit;
    }
    $stmt = $conn->prepare("INSERT INTO customers (name, phone, email, address) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $name, $phone, $email, $address);
    if ($stmt->execute()) {
        $id = $conn->insert_id;
        echo json_encode(['success' => true, 'customer_id' => $id, 'name' => $name]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Update loyalty points (called after a purchase)
if ($action === 'update_points' && isset($_POST['customer_id']) && isset($_POST['points'])) {
    $cid = (int)$_POST['customer_id'];
    $points = (int)$_POST['points'];
    $stmt = $conn->prepare("UPDATE customers SET loyalty_points = loyalty_points + ? WHERE customer_id = ?");
    $stmt->bind_param("ii", $points, $cid);
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['error' => 'Invalid action']);
?>