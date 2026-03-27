<?php
session_start();
require_once 'config/database.php';
require_once 'includes/face_recognition.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$user_id = $_POST['user_id'] ?? null;
$captured_image = $_POST['captured_image'] ?? null;
$email = $_POST['email'] ?? null;

if (!$user_id || !$captured_image || !$email) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    $stmt = $pdo->prepare("SELECT image_path FROM face_recognition WHERE user_id = ? AND status = 'verified' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $stored = $stmt->fetch();
    
    if (!$stored) {
        echo json_encode(['success' => false, 'message' => 'No registered face found']);
        exit;
    }
    
    $stored_image = base64_encode(file_get_contents($stored['image_path']));
    $result = compareFaces($stored_image, $captured_image);
    
    if ($result['success'] && $result['match']) {
        $_SESSION['face_verified'] = $email;
        echo json_encode(['success' => true, 'confidence' => $result['confidence']]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Face does not match']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Verification error']);
}
?>
