<?php
require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    // Create advisories table
    $pdo->exec("CREATE TABLE IF NOT EXISTS advisories (
        id INT PRIMARY KEY AUTO_INCREMENT,
        admin_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        content LONGTEXT NOT NULL,
        image_path VARCHAR(255),
        status ENUM('published', 'draft') DEFAULT 'published',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX (created_at),
        INDEX (status)
    )");

    // Create advisory likes table
    $pdo->exec("CREATE TABLE IF NOT EXISTS advisory_likes (
        id INT PRIMARY KEY AUTO_INCREMENT,
        advisory_id INT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_like (advisory_id, user_id),
        FOREIGN KEY (advisory_id) REFERENCES advisories(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Create advisory comments table
    $pdo->exec("CREATE TABLE IF NOT EXISTS advisory_comments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        advisory_id INT NOT NULL,
        user_id INT NOT NULL,
        comment TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (advisory_id) REFERENCES advisories(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX (advisory_id),
        INDEX (created_at)
    )");

    echo "✓ Database tables created successfully!";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage();
}
