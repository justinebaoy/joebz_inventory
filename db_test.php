<?php
// Simple database test without using config/db.php
$host = 'sql105.infinityfree.com';
$user = 'if0_41701878';
$pass = 'justine150609';
$db   = 'if0_41701878_joebz_db';

echo "<h1>Database Connection Test</h1>";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    echo "<p style='color:red'>❌ Connection failed: " . $conn->connect_error . "</p>";
} else {
    echo "<p style='color:green'>✅ Database connected successfully!</p>";
    
    // Check if users table exists
    $result = $conn->query("SHOW TABLES LIKE 'users'");
    if ($result && $result->num_rows > 0) {
        echo "<p style='color:green'>✅ Users table found!</p>";
        
        // Count users
        $count = $conn->query("SELECT COUNT(*) as total FROM users");
        $row = $count->fetch_assoc();
        echo "<p>Total users in database: " . $row['total'] . "</p>";
    } else {
        echo "<p style='color:orange'>⚠️ No tables found. Please import your SQL file via phpMyAdmin.</p>";
    }
    
    $conn->close();
}
?>