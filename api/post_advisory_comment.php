<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$advisory_id = $data['advisory_id'] ?? null;
$comment = trim($data['comment'] ?? '');

if (!$advisory_id || empty($comment)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Advisory ID and comment required']);
    exit();
}

try {
    $database = new Database();
    $pdo = $database->getConnection();

    $insert = $pdo->prepare("INSERT INTO advisory_comments (advisory_id, user_id, comment) 
                            VALUES (:advisory_id, :user_id, :comment)");
    $insert->execute([
        ':advisory_id' => $advisory_id,
        ':user_id' => $_SESSION['user_id'],
        ':comment' => $comment
    ]);

    // Get updated count
    $count = $pdo->prepare("SELECT COUNT(*) as count FROM advisory_comments WHERE advisory_id = :advisory_id");
    $count->execute([':advisory_id' => $advisory_id]);
    $result = $count->fetch();

    echo json_encode(['success' => true, 'comments_count' => $result['count']]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
