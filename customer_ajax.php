<?php
session_start();
require_once 'config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.0 403 Forbidden');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================
// SEARCH CUSTOMERS (for autocomplete)
// ============================================
if ($action === 'search' && isset($_GET['term'])) {
    $term = '%' . $_GET['term'] . '%';
    
    $stmt = $conn->prepare("
        SELECT customer_id, name, phone, email, loyalty_points, total_purchases 
        FROM customers 
        WHERE name LIKE ? 
           OR phone LIKE ? 
           OR email LIKE ?
        ORDER BY total_purchases DESC, name ASC
        LIMIT 10
    ");
    
    $stmt->bind_param("sss", $term, $term, $term);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $customers = [];
    while ($row = $result->fetch_assoc()) {
        $customers[] = [
            'customer_id' => $row['customer_id'],
            'name' => $row['name'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'loyalty_points' => (int)$row['loyalty_points'],
            'total_purchases' => (float)$row['total_purchases']
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($customers);
    exit;
}

// ============================================
// GET CUSTOMER BY ID
// ============================================
if ($action === 'get' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $stmt = $conn->prepare("
        SELECT customer_id, name, phone, email, address, tax_id, 
               loyalty_points, total_purchases, created_at
        FROM customers 
        WHERE customer_id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    
    header('Content-Type: application/json');
    echo json_encode($customer ?: null);
    exit;
}

// ============================================
// CREATE NEW CUSTOMER
// ============================================
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $tax_id = trim($_POST['tax_id'] ?? '');
    
    // Validate required fields
    if (empty($name)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Customer name is required']);
        exit;
    }
    
    // Check if customer with same phone already exists
    if (!empty($phone)) {
        $check = $conn->prepare("SELECT customer_id FROM customers WHERE phone = ?");
        $check->bind_param("s", $phone);
        $check->execute();
        $check->store_result();
        
        if ($check->num_rows > 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'A customer with this phone number already exists']);
            exit;
        }
        $check->close();
    }
    
    // Check if customer with same email already exists
    if (!empty($email)) {
        $check = $conn->prepare("SELECT customer_id FROM customers WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();
        
        if ($check->num_rows > 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'A customer with this email already exists']);
            exit;
        }
        $check->close();
    }
    
    // Insert new customer
    $stmt = $conn->prepare("
        INSERT INTO customers (name, phone, email, address, tax_id, loyalty_points, total_purchases) 
        VALUES (?, ?, ?, ?, ?, 0, 0)
    ");
    $stmt->bind_param("sssss", $name, $phone, $email, $address, $tax_id);
    
    if ($stmt->execute()) {
        $customer_id = $conn->insert_id;
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'customer_id' => $customer_id, 
            'name' => $name,
            'phone' => $phone,
            'email' => $email
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to create customer: ' . $conn->error]);
    }
    
    $stmt->close();
    exit;
}

// ============================================
// UPDATE CUSTOMER LOYALTY POINTS
// ============================================
if ($action === 'update_points' && isset($_POST['customer_id']) && isset($_POST['points'])) {
    $customer_id = (int)$_POST['customer_id'];
    $points = (int)$_POST['points'];
    
    $stmt = $conn->prepare("
        UPDATE customers 
        SET loyalty_points = loyalty_points + ? 
        WHERE customer_id = ?
    ");
    $stmt->bind_param("ii", $points, $customer_id);
    
    if ($stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to update points']);
    }
    
    $stmt->close();
    exit;
}

// ============================================
// UPDATE CUSTOMER TOTAL PURCHASES
// ============================================
if ($action === 'update_purchases' && isset($_POST['customer_id']) && isset($_POST['amount'])) {
    $customer_id = (int)$_POST['customer_id'];
    $amount = (float)$_POST['amount'];
    
    $stmt = $conn->prepare("
        UPDATE customers 
        SET total_purchases = total_purchases + ?,
            loyalty_points = loyalty_points + FLOOR(? / 100)  -- 1 point per ₱100 spent
        WHERE customer_id = ?
    ");
    $stmt->bind_param("ddi", $amount, $amount, $customer_id);
    
    if ($stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to update purchases']);
    }
    
    $stmt->close();
    exit;
}

// ============================================
// GET CUSTOMER PURCHASE HISTORY
// ============================================
if ($action === 'history' && isset($_GET['id'])) {
    $customer_id = (int)$_GET['id'];
    
    $stmt = $conn->prepare("
        SELECT s.sale_id, s.total_amount, s.sale_date, 
               COUNT(si.sale_item_id) as item_count
        FROM sales s
        LEFT JOIN sale_items si ON s.sale_id = si.sale_id
        WHERE s.customer_id = ?
        GROUP BY s.sale_id
        ORDER BY s.sale_date DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    
    header('Content-Type: application/json');
    echo json_encode($history);
    exit;
}

// ============================================
// GET TOP CUSTOMERS (for reports)
// ============================================
if ($action === 'top_customers' && isset($_GET['limit'])) {
    $limit = (int)$_GET['limit'];
    $limit = min($limit, 50); // Max 50 customers
    
    $result = $conn->query("
        SELECT customer_id, name, phone, email, 
               total_purchases, loyalty_points
        FROM customers 
        WHERE total_purchases > 0
        ORDER BY total_purchases DESC
        LIMIT $limit
    ");
    
    $customers = [];
    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }
    
    header('Content-Type: application/json');
    echo json_encode($customers);
    exit;
}

// ============================================
// DELETE CUSTOMER (only if no sales history)
// ============================================
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only admin can delete customers
    if ($_SESSION['role'] !== 'admin') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Only administrators can delete customers']);
        exit;
    }
    
    $customer_id = (int)$_POST['customer_id'];
    
    // Check if customer has sales
    $check = $conn->prepare("SELECT COUNT(*) as cnt FROM sales WHERE customer_id = ?");
    $check->bind_param("i", $customer_id);
    $check->execute();
    $result = $check->get_result()->fetch_assoc();
    $sales_count = (int)($result['cnt'] ?? 0);
    $check->close();
    
    if ($sales_count > 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => "Cannot delete customer with {$sales_count} sale(s) on record"]);
        exit;
    }
    
    // Delete customer
    $stmt = $conn->prepare("DELETE FROM customers WHERE customer_id = ?");
    $stmt->bind_param("i", $customer_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to delete customer']);
    }
    
    $stmt->close();
    exit;
}

// ============================================
// BULK IMPORT CUSTOMERS (from CSV)
// ============================================
if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only admin can import customers
    if ($_SESSION['role'] !== 'admin') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Only administrators can import customers']);
        exit;
    }
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Please upload a valid CSV file']);
        exit;
    }
    
    $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
    $headers = fgetcsv($file);
    
    $imported = 0;
    $errors = 0;
    $errorMessages = [];
    
    while (($row = fgetcsv($file)) !== false) {
        $data = array_combine($headers, $row);
        
        $name = trim($data['name'] ?? $data['Name'] ?? '');
        $phone = trim($data['phone'] ?? $data['Phone'] ?? '');
        $email = trim($data['email'] ?? $data['Email'] ?? '');
        $address = trim($data['address'] ?? $data['Address'] ?? '');
        
        if (empty($name)) {
            $errors++;
            $errorMessages[] = "Missing name in row " . ($imported + $errors + 1);
            continue;
        }
        
        // Check for duplicates
        $check = $conn->prepare("SELECT customer_id FROM customers WHERE phone = ? OR email = ?");
        $check->bind_param("ss", $phone, $email);
        $check->execute();
        $check->store_result();
        
        if ($check->num_rows > 0) {
            $errors++;
            $errorMessages[] = "Duplicate customer: {$name}";
            $check->close();
            continue;
        }
        $check->close();
        
        $stmt = $conn->prepare("
            INSERT INTO customers (name, phone, email, address, loyalty_points, total_purchases) 
            VALUES (?, ?, ?, ?, 0, 0)
        ");
        $stmt->bind_param("ssss", $name, $phone, $email, $address);
        
        if ($stmt->execute()) {
            $imported++;
        } else {
            $errors++;
            $errorMessages[] = "Failed to import: {$name}";
        }
        $stmt->close();
    }
    
    fclose($file);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'imported' => $imported,
        'errors' => $errors,
        'messages' => $errorMessages
    ]);
    exit;
}

// ============================================
// DEFAULT RESPONSE (invalid action)
// ============================================
header('Content-Type: application/json');
echo json_encode(['error' => 'Invalid action']);
exit;
?>