<?php
require_once __DIR__ . '/../includes/auth.php';
require_login('../');
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    render_error(405, "طريقة الطلب غير مسموح بها. يجب استخدام POST.");
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    render_error(419, "رمز التحقق غير صالح أو انتهت صلاحية الجلسة.");
}

$project_id = $_POST['id'] ?? null;

if (!$project_id) {
    render_error(400, "رقم المشروع غير موجود.");
}

try {
    // Get project info for notification
    $project_stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
    $project_stmt->execute([$project_id]);
    $project = $project_stmt->fetch();

    $user_id = $_SESSION['user_id'];

    if (!$project || !can_view_project($pdo, $user_id, $project_id)) {
        render_error(404, "المشروع غير موجود.");
    }

    if (!can_manage_project($pdo, $user_id, $project_id)) {
        render_error(403, "غير مصرح لك بحذف هذا المشروع.");
    }

    $pdo->beginTransaction();

    // Delete tasks first due to foreign key constraint
    $pdo->prepare("DELETE FROM tasks WHERE project_id = ?")->execute([$project_id]);

    // Then delete the project
    $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$project_id]);

    // Notify owner only if actor is not the project owner
    if ((int)$project['user_id'] !== (int)$user_id) {
        $actor_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $actor_stmt->execute([$user_id]);
        $actor_name = $actor_stmt->fetchColumn() ?: 'المسؤول';
        $action_time = date('Y-m-d H:i');
        $msg = "[{$action_time}] {$actor_name} حذف المشروع '{$project['title']}'";
        $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$project['user_id'], $msg]);
    }

    $pdo->commit();

    header("Location: ../dashboard.php");
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Delete project error: " . $e->getMessage());
    render_error(500, "فشل في حذف المشروع.");
}