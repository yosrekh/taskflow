<?php
// admin/get-user-stats.php
require_once __DIR__ . '/../includes/auth.php';
require_admin('../');
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
if (!$user_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_user_id']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, role, is_active FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'user_not_found']);
        exit;
    }

    // Number of projects they own
    $stmtProj = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE user_id = ?");
    $stmtProj->execute([$user_id]);
    $projects_count = (int)$stmtProj->fetchColumn();

    // Number of open tasks assigned to them (Pending or In Progress)
    $stmtTasks = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status != 'Completed'");
    $stmtTasks->execute([$user_id]);
    $open_tasks_count = (int)$stmtTasks->fetchColumn();

    echo json_encode([
        'success' => true,
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'is_active' => (int)$user['is_active']
        ],
        'projects_count' => $projects_count,
        'open_tasks_count' => $open_tasks_count
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'database_error']);
}
