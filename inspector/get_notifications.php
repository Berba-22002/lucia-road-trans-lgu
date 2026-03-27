<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Only allow inspector role
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'inspector') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$inspector_id = $_SESSION['user_id'];

try {
    // Get notifications from notifications table
    $stmt = $pdo->prepare("
        SELECT id, title, message, type, is_read, related_id, related_type, created_at
        FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $stmt->execute([$inspector_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count unread notifications
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$inspector_id]);
    $unread_count = $stmt->fetch(PDO::FETCH_ASSOC)['unread'];
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => $unread_count
    ]);

} catch (PDOException $e) {
    error_log("Notification fetch error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>