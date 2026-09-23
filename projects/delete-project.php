<?php
require_once __DIR__ . '/../includes/auth.php';
require_login('../');
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("طريقة الطلب غير مسموح بها. يجب استخدام POST.");
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die("رمز التحقق غير صالح أو انتهت صلاحية الجلسة.");
}

$project_id = $_POST['id'] ?? null;

if (!$project_id) {
    http_response_code(400);
    die("رقم المشروع غير موجود.");
}

try {
    // Get project info for notification
    $project_stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
    $project_stmt->execute([$project_id]);
    $project = $project_stmt->fetch();

    if (!$project) {
        http_response_code(404);
        die("المشروع غير موجود.");
    }

    $user_id = $_SESSION['user_id'];
    if (!can_manage_project($pdo, $user_id, $project_id)) {
        http_response_code(403);
        die("غير مصرح لك بحذف هذا المشروع.");
    }

    // Delete tasks first due to foreign key constraint
    $pdo->prepare("DELETE FROM tasks WHERE project_id = ?")->execute([$project_id]);

    // Then delete the project
    $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$project_id]);

    // Notify owner
    $msg = "تم حذف المشروع '{$project['title']}'";
    $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$project['user_id'], $msg]);

    header("Location: ../dashboard.php");
    exit;
} catch (PDOException $e) {
    error_log("Delete project error: " . $e->getMessage());
    die("فشل في حذف المشروع.");
}