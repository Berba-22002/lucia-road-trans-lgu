<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$user_id = $_SESSION['user_id'];

try {
    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
    } elseif ($action === 'mark_single_read') {
        $notification_id = $input['notification_id'] ?? 0;
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $user_id]);
    } elseif ($action === 'clear_all') {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$user_id]);
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>