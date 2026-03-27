<?php
/**
 * Data Retention Cleanup Script
 * Runs automated cleanup based on retention policies
 * Should be run via cron job daily
 */

require_once __DIR__ . '/config/database.php';

// Initialize database connection
try {
    $database = new Database();
    $pdo = $database->getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    error_log("Data retention cleanup - Database connection failed: " . $e->getMessage());
    exit(1);
}

$cleanup_log = [];

try {
    // Get retention policies
    $policies_stmt = $pdo->query("SELECT * FROM data_retention_schedule");
    $policies = $policies_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($policies as $policy) {
        $data_type = $policy['data_type'];
        $retention_days = $policy['retention_period_days'];
        
        // Skip if retention is -1 (keep forever)
        if ($retention_days == -1) {
            continue;
        }
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));
        $deleted_count = 0;
        
        switch ($data_type) {
            case 'archived_reports':
                // Delete archived reports older than retention period
                $stmt = $pdo->prepare("DELETE FROM archived_reports WHERE archived_at < ?");
                $stmt->execute([$cutoff_date]);
                $deleted_count = $stmt->rowCount();
                break;
                
            case 'system_logs':
                // Clean up old session data and logs (if you have such tables)
                // This is a placeholder - implement based on your logging system
                break;
                
            case 'session_data':
                // Clean up old sessions
                $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE created_at < ? AND expires_at < NOW()");
                $stmt->execute([$cutoff_date]);
                $deleted_count = $stmt->rowCount();
                break;
                
            case 'active_reports':
                // Archive completed reports older than retention period
                $stmt = $pdo->prepare("
                    INSERT INTO archived_reports (original_report_id, user_id, hazard_type, description, address, status, created_at, archived_at)
                    SELECT id, user_id, hazard_type, description, address, status, created_at, NOW()
                    FROM reports 
                    WHERE status IN ('done', 'resolved') AND updated_at < ?
                ");
                $stmt->execute([$cutoff_date]);
                $archived_count = $stmt->rowCount();
                
                // Delete the original reports that were archived
                if ($archived_count > 0) {
                    $stmt = $pdo->prepare("
                        DELETE FROM reports 
                        WHERE status IN ('done', 'resolved') AND updated_at < ?
                    ");
                    $stmt->execute([$cutoff_date]);
                    $deleted_count = $stmt->rowCount();
                }
                break;
        }
        
        if ($deleted_count > 0) {
            $cleanup_log[] = "Cleaned up {$deleted_count} records for {$data_type}";
        }
    }
    
    // Log cleanup activity
    $log_entry = date('Y-m-d H:i:s') . " - Data retention cleanup completed:\n" . implode("\n", $cleanup_log) . "\n";
    file_put_contents(__DIR__ . '/logs/data_retention.log', $log_entry, FILE_APPEND | LOCK_EX);
    
    echo "Data retention cleanup completed successfully\n";
    
} catch (PDOException $e) {
    error_log("Data retention cleanup error: " . $e->getMessage());
    echo "Error during cleanup: " . $e->getMessage() . "\n";
    exit(1);
}
?>