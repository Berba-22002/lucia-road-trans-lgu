<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'comments' => []]);
    exit();
}

$advisory_id = $_GET['advisory_id'] ?? null;

if (!$advisory_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'comments' => []]);
    exit();
}

try {
    $database = new Database();
    $pdo = $database->getConnection();

    $sql = "SELECT ac.*, u.fullname FROM advisory_comments ac
            JOIN users u ON ac.user_id = u.id
            WHERE ac.advisory_id = :advisory_id
            ORDER BY ac.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':advisory_id' => $advisory_id]);
    $comments = $stmt->fetchAll();

    echo json_encode(['success' => true, 'comments' => $comments]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'comments' => []]);
}
