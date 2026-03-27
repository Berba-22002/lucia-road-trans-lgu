<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Users by role
    $stmt = $conn->query("SELECT role, COUNT(*) as count FROM users WHERE status = 'active' GROUP BY role");
    $usersByRole = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Reports statistics
    $stmt = $conn->query(query: "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done,
        SUM(CASE WHEN status = 'escalated' THEN 1 ELSE 0 END) as escalated,
        SUM(CASE WHEN validation_status = 'validated' THEN 1 ELSE 0 END) as validated
    FROM reports");
    $reportsStats = $stmt->fetch();

    // Reports by hazard type
    $stmt = $conn->query("SELECT hazard_type, COUNT(*) as count FROM reports GROUP BY hazard_type");
    $reportsByType = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Projects statistics
    $stmt = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'under_construction' THEN 1 ELSE 0 END) as under_construction,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(estimated_budget) as total_budget,
        SUM(actual_cost) as total_spent
    FROM projects");
    $projectsStats = $stmt->fetch();

    // Fund requests statistics
    $stmt = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'assessed' THEN 1 ELSE 0 END) as assessed,
        SUM(CASE WHEN status = 'endorsed' THEN 1 ELSE 0 END) as endorsed,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(total_cost) as total_requested,
        SUM(approved_amount) as total_approved
    FROM fund_requests");
    $fundRequestsStats = $stmt->fetch();

    // OVR tickets statistics
    $stmt = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid,
        SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid,
        SUM(penalty_amount) as total_penalties,
        SUM(CASE WHEN payment_status = 'paid' THEN penalty_amount ELSE 0 END) as collected
    FROM ovr_tickets");
    $ticketsStats = $stmt->fetch();

    // Maintenance assignments
    $stmt = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM maintenance_assignments");
    $maintenanceStats = $stmt->fetch();

    // Advisories
    $stmt = $conn->query("SELECT COUNT(*) as total FROM advisories WHERE status = 'published'");
    $advisoriesCount = $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'data' => [
            'users' => $usersByRole,
            'reports' => $reportsStats,
            'reportsByType' => $reportsByType,
            'projects' => $projectsStats,
            'fundRequests' => $fundRequestsStats,
            'tickets' => $ticketsStats,
            'maintenance' => $maintenanceStats,
            'advisories' => $advisoriesCount
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
