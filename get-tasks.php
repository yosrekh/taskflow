<?php
require_once __DIR__ . '/includes/auth.php';
require_login_json();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
global $pdo;

$project_id = $_GET['project_id'] ?? null;
$user_id = (int)($_SESSION['user_id'] ?? 0);

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

    $checklistByTask = [];

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

        // ONE extra query for all visible tasks to fetch checklist preview items
        if (!empty($tasks)) {
            $taskIds = array_column($tasks, 'id');
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $ciStmt = $pdo->prepare("
                SELECT id, task_id, title, is_done, sort_order 
                FROM task_checklist_items 
                WHERE task_id IN ({$placeholders}) 
                ORDER BY sort_order ASC, id ASC
            ");
            $ciStmt->execute($taskIds);
            while ($row = $ciStmt->fetch(PDO::FETCH_ASSOC)) {
                $tId = (int)$row['task_id'];
                $checklistByTask[$tId][] = [
                    'id' => (int)$row['id'],
                    'task_id' => $tId,
                    'title' => $row['title'],
                    'is_done' => (int)$row['is_done'],
                    'sort_order' => (int)$row['sort_order']
                ];
            }
        }
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

    $isAdminUser = is_admin();
    // Add permissions, normalized fields, and checklist preview items
    foreach ($tasks as &$task) {
        $taskId = (int)$task['id'];
        $task['can_edit'] = $canManage;
        $task['can_delete'] = $canManage;
        $task['comments_count'] = (int)($task['comments_count'] ?? 0);
        $task['phase_id'] = isset($task['phase_id']) && $task['phase_id'] !== null ? (int)$task['phase_id'] : null;
        $task['phase_title'] = $task['phase_title'] ?? null;
        $task['checklist_total'] = (int)($task['checklist_total'] ?? 0);
        $task['checklist_done'] = (int)($task['checklist_done'] ?? 0);

        // Checklist edit permissions: owner, admin, or task assignee
        $isAssignee = isset($task['assigned_to']) && (int)$task['assigned_to'] === $user_id;
        $task['can_edit_checklist'] = $canManage || $isAdminUser || $isAssignee;

        // Up to 3 preview items: unfinished first in sort_order, then done ones in sort_order
        $items = $checklistByTask[$taskId] ?? [];
        if (!empty($items)) {
            $unfinished = [];
            $done = [];
            foreach ($items as $item) {
                if ($item['is_done']) {
                    $done[] = $item;
                } else {
                    $unfinished[] = $item;
                }
            }
            $task['checklist_items'] = array_slice(array_merge($unfinished, $done), 0, 3);
        } else {
            $task['checklist_items'] = [];
        }
    }

    echo json_encode(['success' => true, 'tasks' => $tasks]);
} catch (PDOException $e) {
    error_log("Get tasks error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_error']);
}
