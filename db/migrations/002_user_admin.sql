-- Migration 002: Add role, is_active, must_change_password, and password_changed_at to users table
ALTER TABLE users 
    ADD COLUMN role ENUM('admin','member') NOT NULL DEFAULT 'member',
    ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN must_change_password BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN password_changed_at TIMESTAMP NULL;
