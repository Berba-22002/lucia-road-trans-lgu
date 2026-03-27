-- Add archived_at column to reports table
ALTER TABLE reports ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL;