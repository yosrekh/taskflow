-- Migration 004: Add task_reminders table for idempotent due-date reminders
CREATE TABLE IF NOT EXISTS task_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    reminder_type VARCHAR(50) NOT NULL DEFAULT 'due_tomorrow',
    due_date DATE NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    UNIQUE KEY uq_task_reminder (task_id, reminder_type, due_date),
    INDEX idx_task_reminders_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
