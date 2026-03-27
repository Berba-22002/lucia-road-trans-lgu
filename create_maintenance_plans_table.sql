-- Create maintenance_plans table for the maintenance planner
CREATE TABLE IF NOT EXISTS maintenance_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NULL,
    maintenance_type VARCHAR(100) NOT NULL,
    location VARCHAR(255) NOT NULL,
    assigned_to INT NOT NULL,
    scheduled_date DATETIME NOT NULL,
    estimated_duration INT NOT NULL,
    priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    description TEXT NOT NULL,
    status ENUM('scheduled', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_scheduled_date (scheduled_date),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_status (status),
    INDEX idx_priority (priority)
);