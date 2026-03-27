<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'treasurer') {
    echo json_encode(['notifications' => []]);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$user_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT id, message, created_at, is_read
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['notifications' => $notifications]);
} catch (PDOException $e) {
    echo json_encode(['notifications' => []]);
}
?>
