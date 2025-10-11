<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}
include 'includes/db.php';
$project_id = $_GET['project_id'] ?? null;
$user_id = $_SESSION['user_id'];
if (!$project_id) {
    echo json_encode(['success' => false, 'error' => 'no_project']);
    exit;
}
// Get project owner
$owner_stmt = $pdo->prepare("SELECT user_id FROM projects WHERE id = ?");
$owner_stmt->execute([$project_id]);
$project_owner_id = $owner_stmt->fetchColumn();
if ($user_id == $project_owner_id) {
    $stmt = $pdo->prepare("
        SELECT t.*, u.name AS assignee_name
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.project_id = ?
    ");
    $stmt->execute([$project_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT t.*, u.name AS assignee_name
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.project_id = ? AND t.assigned_to = ?
    ");
    $stmt->execute([$project_id, $user_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Add permissions
foreach ($tasks as &$task) {
    $task['can_edit'] = $user_id == $project_owner_id;
    $task['can_delete'] = $user_id == $project_owner_id;
}
echo json_encode(['success' => true, 'tasks' => $tasks]);
?>
