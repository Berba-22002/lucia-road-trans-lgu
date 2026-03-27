<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(401);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

try {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id IN (SELECT id FROM users WHERE user_role = 'admin') ORDER BY created_at DESC LIMIT 10");
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id IN (SELECT id FROM users WHERE user_role = 'admin') AND is_read = 0");
    $stmt->execute();
    $unread_count = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => $unread_count
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
