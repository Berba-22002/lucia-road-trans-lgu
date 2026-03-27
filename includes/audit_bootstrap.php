<?php
if (!defined('AUDIT_LOGGED')) {
    define('AUDIT_LOGGED', true);
    
    try {
        if (isset($pdo)) {
            $helperPath = __DIR__ . '/audit_helper.php';

            if (!function_exists('logPageAccess')) {
                if (file_exists($helperPath)) {
                    require_once $helperPath;
                } else {
                    throw new Exception("Missing audit_helper.php at: " . $helperPath);
                }
            }

            // Final check before calling
            if (function_exists('logPageAccess')) {
                logPageAccess($pdo);
            } else {
                throw new Exception("logPageAccess function not found after including helper.");
            }
        }
    } catch (Exception $e) {
        error_log("Audit bootstrap error: " . $e->getMessage());
        // Optional: display a user-friendly error if you are in a dev environment
    }
}
?>