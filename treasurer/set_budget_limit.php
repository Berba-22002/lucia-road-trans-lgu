<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'treasurer') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$input = json_decode(file_get_contents('php://input'), true);
$budget_limit = (float)($input['budget_limit'] ?? 0);

try {
    $stmt = $pdo->prepare("INSERT INTO budget_limits (budget_limit) VALUES (?) ON DUPLICATE KEY UPDATE budget_limit = ?");
    $stmt->execute([$budget_limit, $budget_limit]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
