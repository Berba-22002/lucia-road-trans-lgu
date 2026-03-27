<?php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$user_id = $_POST['user_id'] ?? null;
$face_image = $_POST['face_image'] ?? null;

if (!$user_id || !$face_image) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

try {
    $upload_dir = 'uploads/face_recognition_uploads/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    
    $filename = 'face_' . $user_id . '_' . time() . '.jpg';
    $filepath = $upload_dir . $filename;
    
    $image_data = base64_decode($face_image);
    if (!file_put_contents($filepath, $image_data)) {
        throw new Exception('Failed to save image');
    }
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Check if face already exists for this user
    $check = $pdo->prepare("SELECT id FROM face_recognition WHERE user_id = ?");
    $check->execute([$user_id]);
    
    if ($check->rowCount() > 0) {
        // Update existing record
        $stmt = $pdo->prepare("UPDATE face_recognition SET image_path = ?, status = 'verified', updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([$filepath, $user_id]);
    } else {
        // Insert new record
        $stmt = $pdo->prepare("INSERT INTO face_recognition (user_id, image_path, status, created_at) VALUES (?, ?, 'verified', NOW())");
        $stmt->execute([$user_id, $filepath]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Face registered successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error saving face: ' . $e->getMessage()]);
}
?>
