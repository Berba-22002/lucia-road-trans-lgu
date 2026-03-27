<?php
session_start();
$root = dirname(dirname(dirname(__FILE__)));
require_once $root . '/config/database.php';

header('Content-Type: application/json');

$report_id = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;

if (!$report_id) {
    echo json_encode(['photos' => [], 'videos' => []]);
    exit;
}

$photos = [];
$videos = [];

try {
    $stmt = $pdo->prepare("SELECT image_path FROM reports WHERE id = ?");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($report && !empty($report['image_path'])) {
        $imagePath = $report['image_path'];
        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        $videoExts = ['webm', 'mp4', 'mov', 'avi', 'm4v', 'mkv', 'flv', 'wmv', 'ogv'];
        
        // Construct proper path - check if it's already a full path or relative
        if (strpos($imagePath, '/') === 0 || strpos($imagePath, 'http') === 0) {
            $fullPath = $imagePath;
        } else {
            $fullPath = '/uploads/hazard_reports/' . $imagePath;
        }
        
        if (in_array($ext, $videoExts)) {
            $videos[] = $fullPath;
        } else {
            $photos[] = $fullPath;
        }
    }
} catch (Exception $e) {
    error_log("Media fetch error: " . $e->getMessage());
}

echo json_encode(['photos' => $photos, 'videos' => $videos]);
?>
