<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    // Get recent alerts
    $stmt = $pdo->prepare("SELECT ta.*, u.fullname as sent_by_name FROM traffic_alerts ta LEFT JOIN users u ON ta.sent_by = u.id ORDER BY ta.created_at DESC LIMIT 5");
    $stmt->execute();
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'alerts' => $alerts
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>