-- Create the database
CREATE DATABASE IF NOT EXISTS rtim;
USE rtim;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'inspector', 'resident', 'maintenance') NOT NULL,
    address TEXT,
    contact_number VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    hazard_type VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    address VARCHAR(255) NOT NULL,
    contact_number VARCHAR(20),
    image_path VARCHAR(255),
    status ENUM('pending', 'in_progress', 'inspection_ended', 'done', 'escalated') DEFAULT 'pending',
    validation_status ENUM('pending', 'validated', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Add status field to users table
ALTER TABLE users ADD COLUMN status ENUM('active', 'inactive') DEFAULT 'active';

-- Create report_inspectors table to track assignments
CREATE TABLE report_inspectors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    inspector_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('assigned', 'completed', 'cancelled') DEFAULT 'assigned',
    completed_at TIMESTAMP NULL,
    notes TEXT,
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    FOREIGN KEY (inspector_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_active_assignment (report_id, status) 
);

CREATE TABLE inspection_findings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    inspector_id INT NOT NULL,
    severity ENUM('minor', 'major') NOT NULL,
    notes TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    FOREIGN KEY (inspector_id) REFERENCES users(id) ON DELETE CASCADE
);
-- Create report_feedback table
CREATE TABLE report_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    feedback_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    UNIQUE KEY unique_report_feedback (report_id)
);