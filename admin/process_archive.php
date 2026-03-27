<?php
session_start();

// Set JSON header immediately
header('Content-Type: application/json');

// Check if user is admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check request method
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

    // Check if report exists and meets archive conditions
    $stmt = $pdo->prepare("SELECT status, validation_status FROM reports WHERE id = ?");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        echo json_encode(['success' => false, 'message' => 'Report not found']);
        exit;
    }

    if (!($report['status'] === 'done' && $report['validation_status'] === 'validated') && $report['validation_status'] !== 'rejected') {
        echo json_encode(['success' => false, 'message' => 'Only completed and validated reports or rejected reports can be archived']);
        exit;
    }

    // First, ensure 'archived' status exists in enum
    try {
        $pdo->exec("ALTER TABLE reports MODIFY COLUMN status ENUM('pending', 'in_progress', 'inspection_ended', 'done', 'escalated', 'archived') DEFAULT 'pending'");
    } catch (Exception $e) {
        // Ignore if already exists
    }
    
    // Update report status to archived instead of deleting
    $stmt = $pdo->prepare("UPDATE reports SET status = 'archived' WHERE id = ?");
    $stmt->execute([$report_id]);

    echo json_encode(['success' => true, 'message' => 'Report archived successfully']);

} catch (Exception $e) {
    error_log("Archive error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>