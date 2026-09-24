-- description: صلاحيات المستخدمين وإدارة الحسابات
-- Migration 002: Add role, is_active, must_change_password, and password_changed_at to users table

ALTER TABLE users ADD COLUMN role ENUM('admin','member') NOT NULL DEFAULT 'member';
ALTER TABLE users ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE users ADD COLUMN must_change_password BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE users ADD COLUMN password_changed_at TIMESTAMP NULL;

