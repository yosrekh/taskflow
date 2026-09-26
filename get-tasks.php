<?php
require_once __DIR__ . '/includes/auth.php';
require_login_json();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
global $pdo;

$project_id = $_GET['project_id'] ?? null;

$user_id = $_SESSION['user_id'];

if (!$project_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'no_project']);
    exit;
}

$projectStmt = $pdo->prepare("SELECT 1 FROM projects WHERE id = ?");
$projectStmt->execute([$project_id]);
if (!$projectStmt->fetchColumn() || !can_view_project($pdo, $user_id, $project_id)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

try {
    $canManage = can_manage_project($pdo, $user_id, $project_id);

    // Safe upgrade check for migration 006
    $has006 = false;
    try {
        $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'task_checklist_items'");
        $has006 = ((int)$chk->fetchColumn() > 0);
    } catch (\Throwable $e) {
        $has006 = false;
    }

    if ($has006) {
        $sql = "
            SELECT t.*, u.name AS assignee_name,
                   pp.title AS phase_title,
                   (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id = t.id) AS comments_count,
                   (SELECT COUNT(*) FROM task_checklist_items ci WHERE ci.task_id = t.id) AS checklist_total,
                   (SELECT COUNT(*) FROM task_checklist_items ci WHERE ci.task_id = t.id AND ci.is_done = 1) AS checklist_done
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN project_phases pp ON t.phase_id = pp.id
            WHERE t.project_id = ? " . ($canManage ? "" : " AND t.assigned_to = ? ") . "
            ORDER BY t.created_at DESC
        ";
        $params = $canManage ? [$project_id] : [$project_id, $user_id];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();
    } else {
        $sql = "
            SELECT t.*, u.name AS assignee_name,
                   (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id = t.id) AS comments_count
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            WHERE t.project_id = ? " . ($canManage ? "" : " AND t.assigned_to = ? ") . "
            ORDER BY t.created_at DESC
        ";
        $params = $canManage ? [$project_id] : [$project_id, $user_id];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();
    }

    // Add permissions and normalized fields
    foreach ($tasks as &$task) {
        $task['can_edit'] = $canManage;
        $task['can_delete'] = $canManage;
        $task['comments_count'] = (int)($task['comments_count'] ?? 0);
        $task['phase_id'] = isset($task['phase_id']) && $task['phase_id'] !== null ? (int)$task['phase_id'] : null;
        $task['phase_title'] = $task['phase_title'] ?? null;
        $task['checklist_total'] = (int)($task['checklist_total'] ?? 0);
        $task['checklist_done'] = (int)($task['checklist_done'] ?? 0);
    }

    echo json_encode(['success' => true, 'tasks' => $tasks]);
} catch (PDOException $e) {
    error_log("Get tasks error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_error']);
}
