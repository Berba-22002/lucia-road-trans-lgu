-- Create archived_reports table
CREATE TABLE archived_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    original_report_id INT NOT NULL,
    user_id INT NOT NULL,
    hazard_type VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    address VARCHAR(255) NOT NULL,
    contact_number VARCHAR(20),
    image_path VARCHAR(255),
    status VARCHAR(50) NOT NULL,
    validation_status VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    archived_by INT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (archived_by) REFERENCES users(id)
);