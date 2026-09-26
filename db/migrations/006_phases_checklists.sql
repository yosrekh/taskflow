-- description: المراحل والمهام الفرعية
-- Migration 006: Add project_phases, tasks.phase_id, and task_checklist_items

CREATE TABLE IF NOT EXISTS project_phases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_phases_order (project_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tasks ADD COLUMN phase_id INT NULL DEFAULT NULL;

ALTER TABLE tasks ADD CONSTRAINT fk_tasks_phase FOREIGN KEY (phase_id) REFERENCES project_phases(id) ON DELETE SET NULL;

ALTER TABLE tasks ADD INDEX idx_tasks_phase_id (phase_id);

CREATE TABLE IF NOT EXISTS task_checklist_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    done_at TIMESTAMP NULL DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    INDEX idx_checklist_task_order (task_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
