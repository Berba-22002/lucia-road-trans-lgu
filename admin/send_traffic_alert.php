<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Only allow admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin'])) {
    header("Location: ../login.php");
    exit();
}

// Create traffic_alerts table if it doesn't exist
$create_table = "
CREATE TABLE IF NOT EXISTS traffic_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    location VARCHAR(255),
    priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    resident_id INT NULL,
    sent_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (resident_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE CASCADE
)
";
$pdo->exec($create_table);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = $_POST['alert_title'];
    $message = $_POST['alert_message'];
    $location = $_POST['alert_location'] ?? null;
    $priority = $_POST['alert_priority'];
    
    try {
        // Remove all old alerts when admin submits new ones
        $pdo->exec("DELETE FROM traffic_alerts");
        
        // Send alert to all residents (resident_id = NULL means broadcast)
        $insert_alert = "
            INSERT INTO traffic_alerts (title, message, location, priority, resident_id, sent_by, created_at)
            VALUES (?, ?, ?, ?, NULL, ?, NOW())
        ";
        $stmt = $pdo->prepare($insert_alert);
        $stmt->execute([$title, $message, $location, $priority, $_SESSION['user_id']]);
        
        $_SESSION['success'] = "Traffic alert sent successfully to all residents!";
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Error sending alert: " . $e->getMessage();
    }
}

header('Location: traffic_dashboard.php');
exit();
?>