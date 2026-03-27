<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$notification_id = $data['notification_id'] ?? null;

if (!$notification_id) {
    echo json_encode(['success' => false]);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

try {
    $query = "UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$notification_id, $_SESSION['user_id']]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Mark notification read error: " . $e->getMessage());
    echo json_encode(['success' => false]);
}
?>
