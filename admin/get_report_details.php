<?php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$report_id = isset($_GET['report_id']) ? intval($_GET['report_id']) : 0;
$tomtom_api_key = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';

try {
    $query = "SELECT r.*, u.fullname as reporter_name, u.contact_number as reporter_contact
              FROM reports r
              INNER JOIN users u ON r.user_id = u.id
              WHERE r.id = ?";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        echo json_encode(['success' => false, 'message' => 'Report not found']);
        exit();
    }

    // Geocode address to get exact coordinates
    $location = null;
    if (!empty($report['address'])) {
        $geocode_url = "https://api.tomtom.com/search/2/geocode/" . urlencode($report['address']) . ".json?key=" . $tomtom_api_key . "&countrySet=PH";
        $response = @file_get_contents($geocode_url);
        if ($response) {
            $geo_data = json_decode($response, true);
            if (!empty($geo_data['results'])) {
                $result = $geo_data['results'][0];
                $location = [
                    'latitude' => $result['position']['lat'],
                    'longitude' => $result['position']['lon'],
                    'address' => $result['address']['freeformAddress'] ?? $report['address']
                ];
            }
        }
    }

    $query = "SELECT f.*, u.fullname as inspector_name, u.contact_number as inspector_contact, u.email as inspector_email
              FROM inspection_findings f
              LEFT JOIN users u ON f.inspector_id = u.id
              WHERE f.report_id = ?
              ORDER BY f.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$report_id]);
    $findings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $inspector = null;
    if (count($findings) > 0) {
        $inspector = [
            'name' => $findings[0]['inspector_name'],
            'contact' => $findings[0]['inspector_contact'],
            'email' => $findings[0]['inspector_email']
        ];
    }

    $ai_analysis = null;
    if (!empty($report['ai_analysis_result'])) {
        $ai_analysis = json_decode($report['ai_analysis_result'], true);
    }

    echo json_encode([
        'success' => true,
        'report' => $report,
        'findings' => $findings,
        'inspector' => $inspector,
        'ai_analysis' => $ai_analysis,
        'location' => $location
    ]);

} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
