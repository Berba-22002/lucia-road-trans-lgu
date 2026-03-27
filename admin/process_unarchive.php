<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$report_id = (int)($_POST['report_id'] ?? 0);

if (!$report_id) {
    echo json_encode(['success' => false, 'message' => 'Missing report ID']);
    exit;
}

try {
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $pdo = $database->getConnection();

    $stmt = $pdo->prepare("SELECT status FROM reports WHERE id = ?");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        echo json_encode(['success' => false, 'message' => 'Report not found']);
        exit;
    }

    if ($report['status'] !== 'archived') {
        echo json_encode(['success' => false, 'message' => 'Report is not archived']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE reports SET status = 'done' WHERE id = ?");
    $stmt->execute([$report_id]);

    echo json_encode(['success' => true, 'message' => 'Report unarchived successfully']);

} catch (Exception $e) {
    error_log("Unarchive error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>