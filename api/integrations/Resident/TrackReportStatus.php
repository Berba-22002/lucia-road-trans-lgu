<?php
header('Content-Type: application/json');

// Check origin and set CORS header
$allowed_origins = [
    'https://lucia-road-trans.local-government-unit-1-ph.com',
    'https://local-government-unit-1-ph.com'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: https://local-government-unit-1-ph.com');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $location = $_GET['location'] ?? null;
    $report_id = $_GET['report_id'] ?? null;
    
    if (!$location && !$report_id) {
        throw new Exception('Location or report_id is required');
    }
    
    // Build query based on parameters
    if ($report_id) {
        // Search by specific report ID
        $stmt = $pdo->prepare("SELECT 
                                r.*,
                                ma.completion_deadline,
                                ma.status as maintenance_status,
                                u.fullname as assigned_team
                              FROM reports r
                              LEFT JOIN maintenance_assignments ma ON r.id = ma.report_id
                              LEFT JOIN users u ON ma.assigned_to = u.id
                              WHERE r.id = ?");
        $stmt->execute([$report_id]);
    } else {
        // Search by location with fuzzy matching
        $stmt = $pdo->prepare("SELECT 
                                r.*,
                                ma.completion_deadline,
                                ma.status as maintenance_status,
                                u.fullname as assigned_team
                              FROM reports r
                              LEFT JOIN maintenance_assignments ma ON r.id = ma.report_id
                              LEFT JOIN users u ON ma.assigned_to = u.id
                              WHERE r.address LIKE ?
                              ORDER BY r.created_at DESC");
        $stmt->execute(['%' . $location . '%']);
    }
    
    $reports = $stmt->fetchAll();
    
    if (empty($reports)) {
        throw new Exception('No reports found');
    }
    
    $result = [];
    foreach ($reports as $report) {
        $result[] = [
            'report_id' => $report['id'],
            'hazard_type' => $report['hazard_type'],
            'location' => $report['address'],
            'description' => $report['description'],
            'status' => $report['status'],
            'validation_status' => $report['validation_status'],
            'created_at' => $report['created_at'],
            'maintenance' => [
                'scheduled_date' => $report['completion_deadline'],
                'status' => $report['maintenance_status'],
                'assigned_team' => $report['assigned_team']
            ],
            'image_path' => $report['image_path']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>