<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in and is an inspector
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'inspector') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Validate report_id parameter
if (!isset($_GET['report_id']) || empty($_GET['report_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Report ID is required']);
    exit();
}

// Validate that report_id is numeric
$report_id = filter_var($_GET['report_id'], FILTER_VALIDATE_INT);
if ($report_id === false || $report_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid Report ID']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

try {
    // First, verify that the current inspector has access to this report
    $verify_sql = "
        SELECT COUNT(*) as has_access 
        FROM report_inspectors 
        WHERE report_id = ? AND inspector_id = ? AND status = 'assigned'
    ";
    $verify_stmt = $pdo->prepare($verify_sql);
    $verify_stmt->execute([$report_id, $_SESSION['user_id']]);
    $access_result = $verify_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$access_result || $access_result['has_access'] == 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this report']);
        exit();
    }
    
    // Fetch inspection findings for the report
    $sql = "
        SELECT 
            if.id,
            if.report_id,
            if.inspector_id,
            if.severity,
            if.notes,
            if.created_at,
            u.fullname as inspector_name,
            DATE_FORMAT(if.created_at, '%M %d, %Y at %h:%i %p') as formatted_date
        FROM inspection_findings if
        LEFT JOIN users u ON if.inspector_id = u.id
        WHERE if.report_id = ?
        ORDER BY if.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$report_id]);
    $findings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response
    $response = [
        'success' => true,
        'findings' => $findings,
        'count' => count($findings),
        'report_id' => $report_id
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("Get inspection findings error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: Failed to fetch inspection findings',
        'debug' => (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') ? $e->getMessage() : null
    ]);
} catch (Exception $e) {
    error_log("General error in get_inspection_findings: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'An unexpected error occurred'
    ]);
}
?>