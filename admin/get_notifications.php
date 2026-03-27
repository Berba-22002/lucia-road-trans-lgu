<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

try {
    $check_table = "SHOW TABLES LIKE 'notifications'";
    $stmt = $pdo->query($check_table);
    if (!$stmt->fetch()) {
        $create_table = "CREATE TABLE notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('new_report', 'status_update', 'finding_added', 'inspection_assigned') NOT NULL,
            related_id INT,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX (user_id, is_read, created_at)
        )";
        $pdo->exec($create_table);
    }

    $query = "SELECT id, title, message, type, related_id, is_read, created_at FROM notifications WHERE user_id = ? AND is_read = FALSE ORDER BY created_at DESC LIMIT 20";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$_SESSION['user_id']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'notifications' => $notifications]);
} catch (PDOException $e) {
    error_log("Notification fetch error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
