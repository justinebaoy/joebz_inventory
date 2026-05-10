<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$id = (int)$_GET['id'];
$result = $conn->query("SELECT category_name as name FROM categories WHERE category_id = $id");
echo json_encode($result->fetch_assoc());
?>