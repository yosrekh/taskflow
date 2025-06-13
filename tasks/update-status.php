<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

include '../includes/db.php';

$task_id = $_POST['task_id'] ?? null;
$status = $_POST['status'] ?? null;

if ($task_id && in_array($status, ['Pending', 'In Progress', 'Completed'])) {
    $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
    if ($stmt->execute([$status, $task_id])) {
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'db']);
} else {
    echo json_encode(['success' => false, 'error' => 'invalid']);
}
?>