<?php

$httpHost = $_SERVER['HTTP_HOST'] ?? '';
$serverName = $_SERVER['SERVER_NAME'] ?? '';
$currentHost = strtolower(trim($httpHost !== '' ? $httpHost : $serverName));

$isLocalhost = in_array($currentHost, ['localhost', '127.0.0.1', '::1'], true)
    || str_ends_with($currentHost, '.local');

if ($isLocalhost) {
    // Local XAMPP defaults
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'inventory_joebz');
    define('APP_ENV', 'local');
} else {
    // InfinityFree production credentials
    // Normally the credentials should be in a .env file, but im too lazy
    define('DB_HOST', 'sql105.infinityfree.com');
    define('DB_USER', 'if0_41701878');
    define('DB_PASS', 'justine150609');
    define('DB_NAME', 'if0_41701878_inventory_system');
    define('APP_ENV', 'production');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
define('BASE_URL', $isLocalhost
    ? 'http://localhost/Inventory_Joebz'
    : $scheme . '://' . ($currentHost !== '' ? $currentHost : 'localhost'));


$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
?>