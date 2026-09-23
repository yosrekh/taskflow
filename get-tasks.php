<?php
require_once __DIR__ . '/includes/auth.php';
require_login_json();
require_once __DIR__ . '/includes/db.php';

$project_id = $_GET['project_id'] ?? null;
$user_id = $_SESSION['user_id'];

if (!$project_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'no_project']);
    exit;
}

if (!can_view_project($pdo, $user_id, $project_id)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

try {
    $canManage = can_manage_project($pdo, $user_id, $project_id);
    if ($canManage) {
        $stmt = $pdo->prepare("
            SELECT t.*, u.name AS assignee_name
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            WHERE t.project_id = ?
        ");
        $stmt->execute([$project_id]);
        $tasks = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("
            SELECT t.*, u.name AS assignee_name
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            WHERE t.project_id = ? AND t.assigned_to = ?
        ");
        $stmt->execute([$project_id, $user_id]);
        $tasks = $stmt->fetchAll();
    }

    // Add permissions based on authorization helper
    foreach ($tasks as &$task) {
        $task['can_edit'] = $canManage;
        $task['can_delete'] = $canManage;
    }

    echo json_encode(['success' => true, 'tasks' => $tasks]);
} catch (PDOException $e) {
    error_log("Get tasks error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_error']);
}
