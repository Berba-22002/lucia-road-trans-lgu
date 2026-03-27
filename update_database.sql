-- Update the reports table to include 'inspection_ended' status
ALTER TABLE reports MODIFY COLUMN status ENUM('pending', 'in_progress', 'inspection_ended', 'done', 'escalated') DEFAULT 'pending';

-- Update report_inspectors table to ensure it has the correct columns
ALTER TABLE report_inspectors 
MODIFY COLUMN inspection_type ENUM('started', 'ended') DEFAULT 'started';

-- Add inspection_ended_at column if it doesn't exist
ALTER TABLE report_inspectors 
ADD COLUMN inspection_ended_at TIMESTAMP NULL DEFAULT NULL;