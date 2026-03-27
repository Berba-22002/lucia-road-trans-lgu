<?php
header('Content-Type: application/json');

// CORS headers
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    // Validate required fields
    $required_fields = ['report_id', 'rating', 'feedback_text'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            throw new Exception("Field '$field' is required");
        }
    }
    
    // Validate rating range
    if ($input['rating'] < 1 || $input['rating'] > 5) {
        throw new Exception('Rating must be between 1 and 5');
    }
    
    // Check if report exists
    $stmt = $pdo->prepare("SELECT id FROM reports WHERE id = ?");
    $stmt->execute([$input['report_id']]);
    if (!$stmt->fetch()) {
        throw new Exception('Report not found');
    }
    
    // Get or create user_id
    $user_id = $input['user_id'] ?? null;
    if (!$user_id) {
        // Check for anonymous user
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'anonymous@system.local'");
        $stmt->execute();
        $anonymous_user = $stmt->fetch();
        
        if (!$anonymous_user) {
            // Create anonymous user
            $stmt = $pdo->prepare("INSERT INTO users (fullname, email, password, role, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                'Anonymous User',
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
    
    // Check if feedback already exists for this report and user
    $stmt = $pdo->prepare("SELECT id FROM report_feedback WHERE report_id = ? AND user_id = ?");
    $stmt->execute([$input['report_id'], $user_id]);
    if ($stmt->fetch()) {
        throw new Exception('Feedback already submitted for this report');
    }
    
    // Insert feedback
    $stmt = $pdo->prepare("INSERT INTO report_feedback (report_id, user_id, rating, feedback_text) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $input['report_id'],
        $user_id,
        $input['rating'],
        $input['feedback_text']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Feedback submitted successfully',
        'feedback_id' => $pdo->lastInsertId()
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>