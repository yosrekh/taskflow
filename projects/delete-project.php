<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
include '../includes/db.php';

$project_id = $_GET['id'] ?? null;

if (!$project_id) {
    die("رقم المشروع غير موجود.");
}

include '../includes/nav.php'; render_nav('../');

try {
    // Get project info for notification
    $project_stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
    $project_stmt->execute([$project_id]);
    $project = $project_stmt->fetch(PDO::FETCH_ASSOC);
    // Delete tasks first due to foreign key constraint
    $pdo->prepare("DELETE FROM tasks WHERE project_id = ?")->execute([$project_id]);

    // Then delete the project
    $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$project_id]);

    // Notify owner
    if ($project) {
        $msg = "تم حذف المشروع '{$project['title']}'";
        $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$project['user_id'], $msg]);
    }

    header("Location: ../dashboard.php");
    exit;
} catch (PDOException $e) {
    die("فشل في حذف المشروع.");
}
?>