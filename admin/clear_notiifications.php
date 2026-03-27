<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

try {
    $query = "UPDATE notifications SET is_read = TRUE WHERE user_id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$_SESSION['user_id']]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Clear notifications error: " . $e->getMessage());
    echo json_encode(['success' => false]);
}
?>
