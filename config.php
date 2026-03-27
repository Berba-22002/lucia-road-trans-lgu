<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'rtim');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    // Create PDO instance
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    
    // Set PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Set default fetch mode
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Log error and display user-friendly message
    error_log("Database connection failed: " . $e->getMessage());
    die("Connection failed. Please try again later.");
}
?>