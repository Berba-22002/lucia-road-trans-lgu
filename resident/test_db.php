<?php
session_start();

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "Testing database connection...<br>";

try {
    require_once __DIR__ . '/../config/database.php';
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    echo "Database connection successful!<br>";
    
    // Test query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM reports");
    $result = $stmt->fetch();
    echo "Reports table exists with " . $result['count'] . " records.<br>";
    
    // Test session
    if (isset($_SESSION['user_id'])) {
        echo "User ID in session: " . $_SESSION['user_id'] . "<br>";
        echo "User role: " . ($_SESSION['user_role'] ?? 'not set') . "<br>";
    } else {
        echo "No user session found.<br>";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
?>