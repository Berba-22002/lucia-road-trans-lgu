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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../../config/database.php';

// Check database connection
if (!isset($pdo)) {
    error_log('Database connection not available');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Log raw input for debugging
    $raw_input = file_get_contents('php://input');
    error_log('Raw input: ' . $raw_input);
    
    $input = json_decode($raw_input, true);
    
    if (!$input) {
        error_log('JSON decode error: ' . json_last_error_msg());
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }
    
    error_log('Parsed input: ' . print_r($input, true));
    
    // Validate required fields
    $required_fields = ['description', 'location'];
    $missing_fields = [];
    foreach ($required_fields as $field) {
        if (empty($input[$field])) {
            $missing_fields[] = $field;
        }
    }
    
    if (!empty($missing_fields)) {
        error_log('Missing required fields: ' . implode(', ', $missing_fields));
        throw new Exception('Missing required fields: ' . implode(', ', $missing_fields));
    }
    
    // Get a valid user_id or create anonymous user
    $user_id = null;
    if (!empty($input['user_id'])) {
        // Validate provided user_id exists
        $check_user = $pdo->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'");
        $check_user->execute([$input['user_id']]);
        if ($check_user->fetch()) {
            $user_id = $input['user_id'];
        } else {
            throw new Exception('Invalid user ID');
        }
    } else {
        // Check if anonymous user exists, create if not
        $check_user = $pdo->prepare("SELECT id FROM users WHERE email = 'anonymous@system.local' LIMIT 1");
        $check_user->execute();
        $anonymous_user = $check_user->fetch();
        
        if (!$anonymous_user) {
            // Create anonymous user
            $create_user = $pdo->prepare("INSERT INTO users (fullname, email, password, role, status) VALUES (?, ?, ?, ?, ?)");
            $create_user->execute([
                'Anonymous Resident',
                'anonymous@system.local',
                password_hash('anonymous', PASSWORD_DEFAULT),
                'resident',
                'active'
            ]);
            $user_id = $pdo->lastInsertId();
        } else {
            $user_id = $anonymous_user['id'];
        }
    }
    
    $pdo->beginTransaction();
    
    // Insert hazard report into existing reports table
    $stmt = $pdo->prepare("INSERT INTO reports 
                          (user_id, hazard_type, description, address, contact_number, status, validation_status) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->execute([
        $user_id,
        $input['report_type'] ?? 'road',
        $input['description'],
        $input['location'],
        $input['contact_number'] ?? null,
        'pending',
        'pending'
    ]);
    
    $report_id = $pdo->lastInsertId();
    
    // Handle image upload if provided
    if (!empty($input['image_data'])) {
        $upload_dir = '../../../uploads/hazard_reports/';
        $filename = 'hazard_report_' . $report_id . '_' . time() . '.jpg';
        $filepath = $upload_dir . $filename;
        
        // Decode base64 image
        $image_data = base64_decode($input['image_data']);
        if ($image_data === false) {
            throw new Exception('Invalid image data');
        }
        
        // Save image to file
        if (!file_put_contents($filepath, $image_data)) {
            throw new Exception('Failed to save image');
        }
        
        // Store image path in reports table
        $image_stmt = $pdo->prepare("UPDATE reports SET image_path = ? WHERE id = ?");
        $image_stmt->execute([
            'uploads/hazard_reports/' . $filename,
            $report_id
        ]);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'report_id' => $report_id,
        'user_id' => $user_id,
        'message' => 'Hazard report submitted successfully'
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Log the error for debugging
    error_log('SubmitHazardReports error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}
?>