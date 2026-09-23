<?php
require_once __DIR__ . '/../includes/auth.php';
require_login_json();
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$csrfToken = get_csrf_token_from_request();
if (!verify_csrf($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
    exit;
}

$task_id = $_POST['task_id'] ?? null;
$status = $_POST['status'] ?? null;

$user_id = $_SESSION['user_id'];
if (!$task_id || !can_update_task_status($pdo, $user_id, $task_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

if ($task_id && in_array($status, ['Pending', 'In Progress', 'Completed'])) {
    $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
    if ($stmt->execute([$status, $task_id])) {
        // Get task info
        $task_stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
        $task_stmt->execute([$task_id]);
        $task = $task_stmt->fetch(PDO::FETCH_ASSOC);
        // Get project owner
        $owner_stmt = $pdo->prepare("SELECT user_id FROM projects WHERE id = ?");
        $owner_stmt->execute([$task['project_id']]);
        $owner_id = $owner_stmt->fetchColumn();
        // Get actor name
        $actor_id = $_SESSION['user_id'];
        $actor_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $actor_stmt->execute([$actor_id]);
        $actor_name = $actor_stmt->fetchColumn();
        // Get project title
        $project_stmt = $pdo->prepare("SELECT title FROM projects WHERE id = ?");
        $project_stmt->execute([$task['project_id']]);
        $project_title = $project_stmt->fetchColumn();
        $action_time = date('Y-m-d H:i');
        // Notify owner, assignee, and actor (unique to avoid duplicates)
        $msg = "[${action_time}] ${actor_name} غيّر حالة المهمة '{$task['title']}' إلى {$status} في مشروع '{$project_title}'";
        $notify_users = array_unique(array_filter([$owner_id, $task['assigned_to'], $actor_id]));
        foreach ($notify_users as $uid) {
            $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$uid, $msg]);
        }
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'db']);
} else {
    echo json_encode(['success' => false, 'error' => 'invalid']);
}
?>