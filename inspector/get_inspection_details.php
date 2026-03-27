<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

// Only allow inspector
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'inspector') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['report_id'])) {
    echo json_encode(['success' => false, 'message' => 'Report ID required']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$inspector_id = $_SESSION['user_id'];
$report_id = (int)$_GET['report_id'];

try {
    $sql = "SELECT 
                ri.id as assignment_id,
                ri.report_id,
                ri.assigned_at,
                ri.completed_at,
                ri.status as assignment_status,
                ri.notes as assignment_notes,
                r.hazard_type,
                r.description,
                r.address,
                r.status as report_status,
                r.image_path,
                r.created_at as report_date,
                if_.severity,
                if_.notes as finding_notes,
                if_.created_at as inspection_date,
                u.fullname as reporter_name
            FROM report_inspectors ri
            INNER JOIN reports r ON ri.report_id = r.id
            LEFT JOIN inspection_findings if_ ON if_.report_id = r.id AND if_.inspector_id = ri.inspector_id
            LEFT JOIN users u ON r.user_id = u.id
            WHERE ri.report_id = ? AND ri.inspector_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$report_id, $inspector_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($data) {
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Report not found or not assigned to you']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>