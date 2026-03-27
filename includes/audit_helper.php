<?php
function logAudit($pdo, $action, $description = '', $portal = '', $path = '') {
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    try {
        $user_id = $_SESSION['user_id'];
        $fullname = $_SESSION['user_name'] ?? 'Unknown';
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        
        if (!$portal) {
            $portal = $_SESSION['user_role'] ?? 'unknown';
        }
        
        if (!$path) {
            $path = basename($_SERVER['PHP_SELF']);
        }

        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, fullname, action, description, portal, path, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $fullname, $action, $description, $portal, $path, $ip_address]);
    } catch (PDOException $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}
?>
