<?php
// config/db.php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');      // default XAMPP username
define('DB_PASS', '');          // blank by default in XAMPP
define('DB_NAME', 'inventory_joebz');
define('BASE_URL', 'http://localhost/Inventory_Joebz');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>