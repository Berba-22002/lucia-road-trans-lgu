<?php
/**
 * Global Session Handler
 * Enforces 3-minute session timeout for all user roles
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only check timeout if user is logged in
if (isset($_SESSION['user_id'])) {
    $session_lifetime = 180; // 3 minutes
    $current_time = time();
    $last_activity = $_SESSION['last_activity'] ?? $current_time;
    
    // Check if session has timed out
    if ($current_time - $last_activity > $session_lifetime) {
        // Session expired, destroy it
        session_destroy();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js"></script>
            <link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4/bootstrap-4.min.css" rel="stylesheet">
            <style>
                body { margin: 0; padding: 0; background: #f2f7f5; }
                .swal2-popup { font-family: 'Poppins', sans-serif !important; border-radius: 20px !important; }
                .swal2-title { color: #00473e !important; font-weight: 700 !important; }
                .swal2-html-container { color: #475d5b !important; }
                .swal2-confirm { background: #faae2b !important; color: #00473e !important; font-weight: 700 !important; }
                .swal2-cancel { background: #fa5246 !important; color: white !important; font-weight: 600 !important; }
            </style>
        </head>
        <body>
        <script>
            Swal.fire({
                icon: 'warning',
                title: 'Session Expired',
                text: 'Your session has timed out due to inactivity. Please log in again.',
                
                cancelButtonText: 'Go to Login',
                showCancelButton: true,
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then((result) => {
                window.location.href = '/login.php?timeout=1';
            });
        </script>
        </body>
        </html>
        <?php
        exit();
    }
    
    // Update last activity time on every page load
    $_SESSION['last_activity'] = $current_time;
}
?>
