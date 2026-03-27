<?php
session_start();

// Set JSON header immediately
header('Content-Type: application/json');

// Check if user is admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$report_id = (int)($_POST['report_id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!$report_id || !$action) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

try {
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $pdo = $database->getConnection();

    if ($action === 'approve') {
        // Get report details for notification
        $stmt = $pdo->prepare("SELECT user_id, hazard_type FROM reports WHERE id = ?");
        $stmt->execute([$report_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("UPDATE reports SET validation_status = 'validated' WHERE id = ?");
        $stmt->execute([$report_id]);
        
        // Send notification to report sender
        if ($report) {
            try {
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $report['user_id'],
                    'Report Approved',
                    'Your ' . ucfirst($report['hazard_type']) . ' report #' . $report_id . ' has been validated and approved.',
                    'success'
                ]);
            } catch (Exception $e) {
                // Ignore notification errors
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Report approved']);
    } elseif ($action === 'reject') {
        $reason = $_POST['reason'] ?? '';
        
        // Get report details for notification
        $stmt = $pdo->prepare("SELECT user_id, hazard_type FROM reports WHERE id = ?");
        $stmt->execute([$report_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("UPDATE reports SET validation_status = 'rejected', status = 'rejected' WHERE id = ?");
        $stmt->execute([$report_id]);
        
        // Send notification to report sender
        if ($report) {
            try {
                $message = 'Your ' . ucfirst($report['hazard_type']) . ' report #' . $report_id . ' has been rejected.';
                if ($reason) {
                    $message .= ' Reason: ' . $reason;
                }
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $report['user_id'],
                    'Report Rejected',
                    $message,
                    'error'
                ]);
            } catch (Exception $e) {
                // Ignore notification errors
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Report rejected']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('Validation error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>