<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'resident') {
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
    // Get user email
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_email = $stmt->fetch(PDO::FETCH_ASSOC)['email'];

    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications n INNER JOIN users u ON n.user_id = u.id SET n.is_read = 1 WHERE u.email = ?");
        $stmt->execute([$user_email]);
    } elseif ($action === 'mark_single_read') {
        $notification_id = $input['notification_id'] ?? 0;
        $stmt = $pdo->prepare("UPDATE notifications n INNER JOIN users u ON n.user_id = u.id SET n.is_read = 1 WHERE n.id = ? AND u.email = ?");
        $stmt->execute([$notification_id, $user_email]);
    } elseif ($action === 'clear_all') {
        $stmt = $pdo->prepare("DELETE n FROM notifications n INNER JOIN users u ON n.user_id = u.id WHERE u.email = ?");
        $stmt->execute([$user_email]);
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>